<?php
/**
 * iCredit (Rivhit) payment integration (1.14.36).
 *
 * Encapsulates the iCredit GetUrl / Verify API. Used by the onboarding
 * Step 3 to redirect new accounts to a hosted payment page.
 *
 * Flow:
 *   1. cmms_payment_init AJAX → CMMS_Icredit::create_sale(...)
 *      - POSTs to PaymentPageRequest.svc/GetUrl with sale details
 *      - Receives URL + PublicSaleToken + PrivateSaleToken
 *      - Saves a pending row in cmms_payments
 *      - Returns the URL to redirect to
 *   2. User pays on iCredit's hosted page (we don't see card details)
 *   3. iCredit sends IPN webhook → CMMS_Icredit::handle_ipn(...)
 *      - Updates the payment row with the result
 *      - On success: marks account.subscription_status = 'active'
 *   4. User redirected back to our return_url → CMMS_Icredit::handle_return(...)
 *      - Cross-checks PublicSaleToken in URL against our payment row
 *      - Forwards user to dashboard (success) or pricing (failure)
 *
 * Why the IPN + return URL handle different concerns:
 *   - IPN is the AUTHORITATIVE event from iCredit (server-to-server).
 *     If it says approved, it's approved, even if the user closed the
 *     browser before returning.
 *   - Return URL is just UX — show success/failure to the user. Cannot
 *     be trusted alone (user could manipulate it).
 *
 * Modes:
 *   - 'test': hits https://testicredit.rivhit.co.il, uses the public
 *     sandbox GroupPrivateToken seeded in the activator.
 *   - 'live': hits https://icredit.rivhit.co.il, requires real token
 *     set via WP Admin → CMMS Light → iCredit settings.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Icredit {

    const ENDPOINT_TEST = 'https://testicredit.rivhit.co.il/API/PaymentPageRequest.svc/';
    const ENDPOINT_LIVE = 'https://icredit.rivhit.co.il/API/PaymentPageRequest.svc/';

    /** Returns the saved iCredit settings, with defaults. */
    public static function settings() {
        $defaults = array(
            'mode'                 => 'test',
            'test_token'           => 'a1408bfc-18da-49dc-aa77-d65870f7943e',
            'live_token'           => '',
            'ipn_url'              => '',
            'return_url'           => '',
        );
        $opt = get_option( 'cmms_icredit_settings', array() );
        return wp_parse_args( is_array( $opt ) ? $opt : array(), $defaults );
    }

    /** Active GroupPrivateToken for the current mode. */
    public static function active_token() {
        $s = self::settings();
        return $s['mode'] === 'live' ? $s['live_token'] : $s['test_token'];
    }

    /** Active API base URL for the current mode. */
    public static function api_base() {
        $s = self::settings();
        return $s['mode'] === 'live' ? self::ENDPOINT_LIVE : self::ENDPOINT_TEST;
    }

    /** Are we configured to actually attempt payments? */
    public static function is_configured() {
        $token = self::active_token();
        return ! empty( $token );
    }

    /**
     * Default IPN webhook URL. iCredit POSTs here when a payment
     * concludes (either way). Uses admin-post.php for predictability
     * — no theme rendering, no auth check on the action handler.
     */
    public static function ipn_url() {
        $s = self::settings();
        if ( ! empty( $s['ipn_url'] ) ) return $s['ipn_url'];
        return add_query_arg( 'action', 'cmms_icredit_ipn', admin_url( 'admin-post.php' ) );
    }

    /**
     * Default return URL — where the user is sent after paying (or
     * cancelling) on iCredit's hosted page. The PublicSaleToken is
     * appended by iCredit to the URL.
     */
    public static function return_url() {
        $s = self::settings();
        if ( ! empty( $s['return_url'] ) ) return $s['return_url'];
        return add_query_arg( 'action', 'cmms_icredit_return', admin_url( 'admin-post.php' ) );
    }

    /**
     * Create a sale on iCredit and return the redirect URL.
     *
     * @param array $params {
     *   account_id    int     Required.
     *   package       array   The CMMS_Plans package row.
     *   email         string  Customer email.
     *   name          string  Customer full name.
     *   phone         string  Customer phone.
     * }
     * @return array|WP_Error  On success: { url, payment_id }
     */
    public static function create_sale( $params ) {
        if ( ! self::is_configured() ) {
            return new WP_Error( 'not_configured',
                'התשלום לחבילה זו עדיין לא הוגדר. ניתן לפנות אלינו להשלמת פתיחת החשבון.' );
        }

        $account_id = (int) ( $params['account_id'] ?? 0 );
        $package    = $params['package'] ?? null;
        if ( ! $account_id || ! is_array( $package ) ) {
            return new WP_Error( 'invalid_input', 'בקשת תשלום שגויה.' );
        }

        $amount = (float) $package['price'];
        if ( $amount <= 0 ) {
            return new WP_Error( 'no_price',
                'התשלום לחבילה זו עדיין לא הוגדר. ניתן לפנות אלינו להשלמת פתיחת החשבון.' );
        }

        // 1.14.55: Fetch the company name from the account row. iCredit
        // uses this to generate tax invoices with the company's name as
        // the recipient, which is critical for business customers who
        // need to claim VAT back. Without it, invoices are issued to the
        // individual's name and customers have to ask us to reissue them
        // every single month — operationally untenable at scale.
        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $company_name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM $accounts_t WHERE id = %d",
            $account_id
        ) );
        $company_name = trim( (string) $company_name );

        // Create a pending payment row BEFORE calling iCredit. If the
        // API call succeeds we update with the tokens; if it fails we
        // still have a record of the attempt for debugging.
        $payments_t = CMMS_DB::table( 'payments' );
        $now = current_time( 'mysql' );
        $settings = self::settings();

        // 1.14.60: Plan-change callers (Upgrade flow) create their own
        // pending payment row first so they can track the delta with a
        // specific charge_type. If override_payment_id is supplied, we
        // reuse it instead of inserting. Otherwise we create a fresh row.
        $override_pid = (int) ( $params['override_payment_id'] ?? 0 );
        if ( $override_pid > 0 ) {
            $payment_id = $override_pid;
            // Touch updated_at so the row reflects the actual sale attempt.
            $wpdb->update( $payments_t,
                array( 'provider_mode' => $settings['mode'], 'updated_at' => $now ),
                array( 'id' => $payment_id )
            );
        } else {
            $insert_data = array(
                'account_id'         => $account_id,
                'package_id'         => isset( $package['id'] ) ? (int) $package['id'] : null,
                'plan_type'          => $package['plan_type'] ?? null,
                'billing_cycle'      => $package['billing_cycle'] ?? null,
                'amount'             => $amount,
                'currency'           => $package['currency'] ?? 'ILS',
                'status'             => 'pending',
                'provider'           => 'icredit',
                'provider_mode'      => $settings['mode'],
                'created_at'         => $now,
                'updated_at'         => $now,
            );
            $wpdb->insert( $payments_t, $insert_data );
            $payment_id = (int) $wpdb->insert_id;
            if ( ! $payment_id ) {
                return new WP_Error( 'db_error', 'אירעה שגיאה בשמירת הבקשה. נסה שוב.' );
            }
        }

        // Build the GetUrl payload. The iCredit API uses PascalCase
        // and accepts JSON. Field reference:
        //   GroupPrivateToken — auth (per-merchant)
        //   Items[]           — line items (we send one)
        //   RedirectURL       — where to send user after payment (success or fail)
        //   IPNURL            — server-to-server webhook
        //   CustomerLastName / FirstName / Email / PhoneNumber — pre-fill
        //   SaleType          — 1 = standard; 6 = subscription/token-only
        //   Order             — our internal order id (we use payment_id)
        //   Custom1..3        — custom fields, returned back in IPN
        //
        // 1.14.68: Per customer guidance, send ONLY the company name
        // (in LastName) and leave FirstName empty. The invoice template
        // in rivhit only reads LastName for the "לכבוד" header, so
        // FirstName has no impact on the visible invoice.
        //
        // Risk note: in 1.14.39 we found iCredit rejected payments
        // when LastName was empty ("שם הלקוח אינו תקין"). We don't know
        // if the same validation applies to FirstName being empty. If
        // payment is rejected, the IPN handler will log the full response
        // and we'll switch to sending a space or "." as a placeholder.
        $full_name = trim( (string) ( $params['name'] ?? '' ) );
        if ( $full_name === '' ) $full_name = 'לקוח'; // last-resort fallback

        if ( $company_name !== '' ) {
            // Company name in LastName drives the "לכבוד" header.
            // FirstName left EMPTY — invoice template ignores it.
            $first = '';
            $last  = $company_name;
        } else {
            // Legacy fallback: split person name into first/last.
            $first = $full_name;
            $last  = '';
            if ( strpos( $full_name, ' ' ) !== false ) {
                $parts = explode( ' ', $full_name, 2 );
                $first = trim( $parts[0] );
                $last  = trim( $parts[1] );
            }
            if ( $last === '' ) $last = $first;
        }

        $item_description = sprintf(
            '%s — %s',
            $package['display_name'] ?? 'CMMS',
            $package['billing_cycle'] === 'yearly' ? 'שנתי' : 'חודשי'
        );

        $payload = array(
            'GroupPrivateToken' => self::active_token(),
            'Items'             => array(
                array(
                    'Description' => $item_description,
                    'Quantity'    => 1,
                    'UnitPrice'   => round( $amount, 2 ),
                ),
            ),
            'RedirectURL'       => self::return_url(),
            'IPNURL'            => self::ipn_url(),
            // 1.14.66: Critical mapping (see comment above):
            //   LastName  → company name (becomes "לכבוד" in invoice)
            //   FirstName → person name (display only)
            'FirstName'         => $first,
            'LastName'          => $last,
            'CustomerFirstName' => $first,
            'CustomerLastName'  => $last,
            // Also still send CompanyName fields in case iCredit's
            // configuration is later changed to use them. Harmless
            // to include; they're ignored by GetURL today.
            'CompanyName'         => $company_name,
            'CustomerCompanyName' => $company_name,
            // Send email under multiple field names — iCredit's docs are
            // inconsistent about which one they read.
            'Email'             => (string) ( $params['email'] ?? '' ),
            'CustomerEmail'     => (string) ( $params['email'] ?? '' ),
            'PhoneNumber'       => (string) ( $params['phone'] ?? '' ),
            'CustomerPhone'     => (string) ( $params['phone'] ?? '' ),
            // 1.14.41: SaleType depends on whether recurring billing is
            // both REQUESTED (billing_mode='recurring') AND CONFIGURED
            // (icredit_page_id present). Without a pre-built recurring
            // page in the iCredit dashboard, SaleType=6 returns 404 from
            // the iCredit sandbox — the engine has nowhere to attach the
            // token to. So we degrade gracefully: charge ad-hoc as a
            // standard sale (SaleType=1), then a future version (once
            // the merchant has configured the recurring pages) will
            // switch to SaleType=6.
            //
            // The TYPE intent (recurring) is still recorded on the
            // subscription row, so when the page id is added later we
            // can hook the next iCredit recurring webhook to the right
            // subscription.
            'SaleType'          => (
                ( ( $package['billing_mode'] ?? '' ) === 'recurring' )
                && ! empty( $package['icredit_page_id'] )
            ) ? 6 : 1,
            'Order'             => (string) $payment_id,
            // Carry our payment_id and account_id back in custom fields
            // so the IPN handler can match the webhook to our row even
            // if PublicSaleToken matching ever fails.
            'Custom1'           => (string) $payment_id,
            'Custom2'           => (string) $account_id,
            'Custom3'           => $package['internal_name'] ?? '',
            // Pre-set currency, defaults to ILS at iCredit anyway.
            'Currency'          => 1, // 1 = ILS, 2 = USD
        );

        // 1.14.42: if the package has a specific iCredit page configured,
        // bind the sale to that page. iCredit uses HostedPagePrivateToken
        // for this. When provided, iCredit knows which configured page
        // (with its recurring settings, branding, etc.) to process the
        // sale on. We pass the same value the merchant entered in
        // Admin → Packages → iCredit Page ID.
        //
        // The page should be configured as accepting dynamic amounts so
        // each plan/cycle can charge its own price through the single
        // shared page. The Items[].UnitPrice we send overrides any
        // default amount configured on the page.
        if ( ! empty( $package['icredit_page_id'] ) ) {
            $payload['HostedPagePrivateToken'] = $package['icredit_page_id'];
        }

        $url = self::api_base() . 'GetUrl';
        $response = wp_remote_post( $url, array(
            'timeout' => 20,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            self::log_payment_error( $payment_id, 'http_error', array(
                'wp_error_message' => $response->get_error_message(),
                'request_url'      => $url,
            ) );
            return new WP_Error( 'icredit_unreachable',
                'לא הצלחנו ליצור קשר עם שירות התשלום. נסה שוב בעוד מספר רגעים.' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
            self::log_payment_error( $payment_id, 'bad_response', array(
                'http_code' => $code,
                'raw_body'  => substr( (string) $body, 0, 2000 ),
                'request_url' => $url,
            ) );
            return new WP_Error( 'icredit_bad_response',
                'תגובה לא תקינה משירות התשלום. נסה שוב.' );
        }

        // Successful GetUrl response shape:
        //   URL, PublicSaleToken, PrivateSaleToken, Status
        // Status == 0 means OK. Anything else is an error.
        $status_ok = isset( $data['Status'] ) ? (int) $data['Status'] : -1;
        $sale_url  = $data['URL'] ?? '';
        $public_t  = $data['PublicSaleToken'] ?? '';
        $private_t = $data['PrivateSaleToken'] ?? '';

        if ( $status_ok !== 0 || empty( $sale_url ) ) {
            // 1.14.52: log the ENTIRE iCredit response, not just the
            // Message field. Some error paths return error info in
            // different fields (Description, ErrorMessage, etc.) and we
            // want to see the full picture for debugging.
            self::log_payment_error( $payment_id, 'icredit_status_' . $status_ok, array(
                'icredit_response' => $data,
                'request_payload'  => array(
                    // Redact the token from the saved log — privacy.
                    // The rest of the fields are useful for debugging.
                    'GroupPrivateToken' => '***REDACTED***',
                    'Items'             => $payload['Items'] ?? null,
                    'RedirectURL'       => $payload['RedirectURL'] ?? null,
                    'IPNURL'            => $payload['IPNURL'] ?? null,
                    'FirstName'         => $payload['FirstName'] ?? null,
                    'LastName'          => $payload['LastName'] ?? null,
                    'Email'             => $payload['Email'] ?? null,
                    'PhoneNumber'       => $payload['PhoneNumber'] ?? null,
                    'SaleType'          => $payload['SaleType'] ?? null,
                    'Order'             => $payload['Order'] ?? null,
                    'Custom1'           => $payload['Custom1'] ?? null,
                    'Custom2'           => $payload['Custom2'] ?? null,
                    'Custom3'           => $payload['Custom3'] ?? null,
                    'Currency'          => $payload['Currency'] ?? null,
                    'HostedPagePrivateToken' => isset( $payload['HostedPagePrivateToken'] )
                        ? substr( $payload['HostedPagePrivateToken'], 0, 8 ) . '...'
                        : null,
                ),
                'request_url' => $url,
                'http_code'   => $code,
            ) );
            return new WP_Error( 'icredit_rejected',
                'שירות התשלום דחה את הבקשה. נסה שוב או פנה לתמיכה.' );
        }

        // Persist the tokens on the payment row so the IPN handler can
        // find this row when the webhook arrives.
        $wpdb->update(
            $payments_t,
            array(
                'icredit_public_token'  => $public_t,
                'icredit_private_token' => $private_t,
                'updated_at'            => current_time( 'mysql' ),
            ),
            array( 'id' => $payment_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        return array(
            'url'        => $sale_url,
            'payment_id' => $payment_id,
        );
    }

    /**
     * Handle the IPN webhook from iCredit. This is the AUTHORITATIVE
     * confirmation of payment success/failure.
     *
     * iCredit posts form-encoded data here. The exact fields can vary
     * by configuration; we capture everything for audit and look for
     * the ones we documented:
     *   - SaleId
     *   - PublicSaleToken
     *   - PrivateSaleToken
     *   - Status (0 = success)
     *   - Custom1 (our payment_id)
     *   - Custom2 (our account_id)
     */
    public static function handle_ipn() {
        // Capture raw POST for audit. Even if we can't match the row,
        // we want a record.
        $raw_post = file_get_contents( 'php://input' );
        $params   = $_POST; // form-encoded from iCredit

        // 1.14.54: iCredit's IPN payload uses different field names than
        // their GetUrl API response. The IPN we receive in production
        // has:
        //   TransactionStatus (0 = OK)  — NOT just "Status"
        //   SaleId
        //   Custom1, Custom2, Custom3
        //   CustomerFirstName, CustomerLastName
        //   ErrorMessage, ErrorDescription
        //   DocumentNum (not DocumentNumber)
        //   GroupPrivateToken (echo of the page id)
        //
        // We check both field names for forward/backward compat.
        $sale_id_in_ipn = $params['SaleId'] ?? '';
        $our_payment_id = isset( $params['Custom1'] ) ? (int) $params['Custom1'] : 0;
        $our_account_id = isset( $params['Custom2'] ) ? (int) $params['Custom2'] : 0;

        // Status — prefer TransactionStatus (what iCredit actually sends
        // in IPN), fall back to Status (their older docs).
        $status = -1;
        if ( isset( $params['TransactionStatus'] ) ) {
            $status = (int) $params['TransactionStatus'];
        } elseif ( isset( $params['Status'] ) ) {
            $status = (int) $params['Status'];
        }

        // PublicSaleToken doesn't appear in production IPN payloads (we
        // only get SaleId). Try both for safety.
        $public_token = $params['PublicSaleToken'] ?? '';

        global $wpdb;
        $payments_t = CMMS_DB::table( 'payments' );

        // 1.14.40: classify the event. Three possibilities:
        //   1. Initial payment IPN — matches an existing pending row
        //      via Custom1 or PublicSaleToken
        //   2. Recurring success IPN — no matching pending row, but
        //      Custom2 (account_id) matches an active subscription
        //   3. Recurring failure IPN — same as #2 but Status != 0
        //
        // We try to find the original payment row first. If found, it's
        // event type #1 (or an idempotent retry of one). If not, fall
        // back to looking up the account's subscription.
        $payment = null;
        if ( $our_payment_id ) {
            $payment = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $payments_t WHERE id = %d",
                $our_payment_id
            ), ARRAY_A );
        }
        if ( ! $payment && $public_token ) {
            $payment = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $payments_t WHERE icredit_public_token = %s",
                $public_token
            ), ARRAY_A );
        }

        // Determine charge type. If we have a payment row that was
        // 'pending', this IPN is the initial response. If the payment
        // is already 'approved' and this IPN's SaleId is different,
        // we treat it as a recurring charge (iCredit's recurring engine
        // reuses the original PublicSaleToken sometimes).
        $is_recurring = false;
        if ( $payment && $payment['status'] === 'approved'
             && $sale_id_in_ipn
             && ! empty( $payment['icredit_sale_id'] )
             && $sale_id_in_ipn !== $payment['icredit_sale_id'] ) {
            $is_recurring = true;
        }
        // No payment row at all + we have an account_id from Custom2
        // → this must be a recurring event for an existing account.
        if ( ! $payment && $our_account_id ) {
            $is_recurring = true;
        }

        $new_status = ( $status === 0 ) ? 'approved' : 'declined';
        // 1.14.54: iCredit IPN uses "DocumentNum" not "DocumentNumber".
        // Check both, prefer the actual field.
        $document   = $params['DocumentNum'] ?? ( $params['DocumentNumber'] ?? '' );
        // 1.14.56: RecurringId is iCredit's identifier for the recurring
        // subscription. Without storing this we cannot cancel later.
        // It's only present when the page is configured for recurring.
        $recurring_id = $params['RecurringId'] ?? '';
        $now        = current_time( 'mysql' );

        if ( $payment && ! $is_recurring ) {
            // INITIAL payment — update the existing row.
            $wpdb->update(
                $payments_t,
                array(
                    'status'                  => $new_status,
                    'icredit_sale_id'         => $sale_id_in_ipn,
                    'icredit_document_number' => $document,
                    'charge_type'             => 'initial',
                    'ipn_raw'                 => wp_json_encode( $params ),
                    'ipn_received_at'         => $now,
                    'updated_at'              => $now,
                ),
                array( 'id' => $payment['id'] ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );

            if ( $new_status === 'approved' && ! empty( $payment['account_id'] ) ) {
                $payment_fresh = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM $payments_t WHERE id = %d",
                    $payment['id']
                ), ARRAY_A );

                // 1.14.60: If this payment is the prorated delta of an
                // upgrade, apply the plan change now that we know the
                // customer actually paid the difference. The upgrade
                // flow stored the new plan in subscription's pending_*
                // fields with type='upgrade_in_progress'.
                if ( ( $payment_fresh['charge_type'] ?? '' ) === 'plan_change_delta' ) {
                    $subs_t_x = CMMS_DB::table( 'subscriptions' );
                    $sub_x = $wpdb->get_row( $wpdb->prepare(
                        "SELECT * FROM $subs_t_x WHERE account_id = %d ORDER BY id DESC LIMIT 1",
                        (int) $payment_fresh['account_id']
                    ), ARRAY_A );
                    if ( $sub_x && $sub_x['pending_change_type'] === 'upgrade_in_progress' ) {
                        $new_pkg_x = CMMS_Plans::get_package(
                            $sub_x['pending_plan_type'],
                            $sub_x['pending_billing_cycle']
                        );
                        if ( $new_pkg_x ) {
                            // Apply the upgrade to the subscription row.
                            $wpdb->update( $subs_t_x, array(
                                'plan_type'                   => $sub_x['pending_plan_type'],
                                'billing_cycle'               => $sub_x['pending_billing_cycle'],
                                'package_id'                  => (int) $new_pkg_x['id'],
                                'amount'                      => (float) $new_pkg_x['price'],
                                'pending_plan_type'           => null,
                                'pending_billing_cycle'       => null,
                                'pending_change_effective_at' => null,
                                'pending_change_type'         => null,
                                'updated_at'                  => $now,
                            ), array( 'id' => (int) $sub_x['id'] ) );

                            // Mirror to account.
                            $accounts_t_x = CMMS_DB::table( 'accounts' );
                            $wpdb->update( $accounts_t_x, array(
                                'plan_type'     => $sub_x['pending_plan_type'],
                                'billing_cycle' => $sub_x['pending_billing_cycle'],
                                'max_users'     => isset( $new_pkg_x['max_users'] ) ? (int) $new_pkg_x['max_users'] : null,
                                'updated_at'    => $now,
                            ), array( 'id' => (int) $sub_x['account_id'] ) );

                            // NOTE: We don't recreate iCredit's recurring
                            // here in 1.14.60. The OLD recurring at iCredit
                            // continues to charge the OLD amount monthly.
                            // The mismatch is detected next month and a
                            // follow-up swap is performed in 1.14.61+.
                            error_log( 'CMMS PlanChange: upgrade applied for account ' . $sub_x['account_id'] . ' to ' . $sub_x['pending_plan_type'] );
                        }
                    }
                    // Done — skip the regular activate_from_payment path.
                } elseif ( class_exists( 'CMMS_Subscriptions' ) ) {
                    // 1.14.56: also pass the RecurringId from this IPN so
                    // we can store it on the subscription row. Without it,
                    // we can't call iCredit's RecurringSaleCancel later.
                    $sub_id = CMMS_Subscriptions::activate_from_payment( $payment_fresh, $recurring_id );
                    if ( $sub_id ) {
                        $wpdb->update( $payments_t,
                            array( 'subscription_id' => $sub_id ),
                            array( 'id' => $payment['id'] )
                        );
                    }
                } else {
                    // Fallback if subscriptions class not loaded for any reason.
                    $accounts_t = CMMS_DB::table( 'accounts' );
                    $wpdb->update(
                        $accounts_t,
                        array( 'subscription_status' => 'active' ),
                        array( 'id' => (int) $payment['account_id'] )
                    );
                }

                // 1.14.43: send the welcome email AFTER successful initial
                // payment + activation. This is the only place we trigger
                // it — the mailer itself is idempotent (checks
                // welcome_email_sent_at) so a duplicate IPN won't cause
                // a second email. Errors here are logged inside the
                // mailer and never bubble up; we don't want a failing
                // wp_mail to break the payment confirmation flow.
                if ( class_exists( 'CMMS_Mailer' ) ) {
                    CMMS_Mailer::send_welcome( (int) $payment['account_id'] );
                }
            }
        } elseif ( $is_recurring && $our_account_id ) {
            // RECURRING charge — insert a fresh payment row tagged as
            // recurring, then drive the subscription lifecycle.
            $sub = class_exists( 'CMMS_Subscriptions' )
                ? CMMS_Subscriptions::for_account( $our_account_id )
                : null;
            $rec_amount = isset( $params['Amount'] ) ? (float) $params['Amount']
                        : ( $sub ? (float) $sub['amount'] : 0 );

            $wpdb->insert( $payments_t, array(
                'account_id'              => $our_account_id,
                'package_id'              => $sub ? (int) ( $sub['package_id'] ?? 0 ) : null,
                'plan_type'               => $sub['plan_type']     ?? null,
                'billing_cycle'           => $sub['billing_cycle'] ?? null,
                'amount'                  => $rec_amount,
                'currency'                => $sub['currency'] ?? 'ILS',
                'status'                  => $new_status,
                'provider'                => 'icredit',
                'provider_mode'           => self::settings()['mode'] ?? 'test',
                'icredit_public_token'    => $public_token,
                'icredit_sale_id'         => $sale_id_in_ipn,
                'icredit_document_number' => $document,
                'charge_type'             => 'recurring',
                'subscription_id'         => $sub ? (int) $sub['id'] : null,
                'ipn_raw'                 => wp_json_encode( $params ),
                'ipn_received_at'         => $now,
                'created_at'              => $now,
                'updated_at'              => $now,
            ) );

            if ( class_exists( 'CMMS_Subscriptions' ) ) {
                if ( $new_status === 'approved' ) {
                    CMMS_Subscriptions::record_recurring_success(
                        $our_account_id,
                        array( 'billing_cycle' => $sub['billing_cycle'] ?? 'monthly' )
                    );
                } else {
                    CMMS_Subscriptions::record_recurring_failure(
                        $our_account_id,
                        'iCredit recurring charge declined: ' . ( $params['ErrorMessage'] ?? $params['Message'] ?? '' )
                    );
                }
            }
        } else {
            // Unmatched IPN — log and respond OK so iCredit doesn't retry.
            error_log( 'CMMS iCredit IPN: no matching payment or subscription. POST=' . substr( wp_json_encode( $params ), 0, 500 ) );
        }

        // Respond 200 OK to acknowledge receipt.
        status_header( 200 );
        echo 'OK';
        exit;
    }

    /**
     * Handle the browser return from iCredit. This is just UX —
     * the authoritative state is set by the IPN. We look at our
     * own payment row (set by IPN) and route accordingly.
     *
     * URL params from iCredit may include: PublicSaleToken, SaleId, Status
     * But we don't trust them — we look up by PublicSaleToken to read
     * our own row.
     */
    public static function handle_return() {
        // 1.14.54: iCredit's Return redirect may include different
        // parameters depending on the page configuration. We try in
        // order of reliability:
        //   1. Order (we set this to our payment_id in the request)
        //   2. Custom1 (also payment_id, set in payload)
        //   3. SaleId (iCredit's unique transaction id, matches what
        //      the IPN would write to icredit_sale_id)
        //   4. PublicSaleToken (only set on some return URLs)
        $public_token = isset( $_GET['PublicSaleToken'] ) ? sanitize_text_field( wp_unslash( $_GET['PublicSaleToken'] ) ) : '';
        $order_id     = isset( $_GET['Order'] )           ? (int) $_GET['Order']     : 0;
        $custom1      = isset( $_GET['Custom1'] )         ? (int) $_GET['Custom1']   : 0;
        $sale_id      = isset( $_GET['SaleId'] )          ? sanitize_text_field( wp_unslash( $_GET['SaleId'] ) ) : '';

        global $wpdb;
        $payments_t = CMMS_DB::table( 'payments' );

        $payment = null;

        // Try Order/Custom1 first — most reliable (it's our own row id).
        $our_id = $order_id ?: $custom1;
        if ( $our_id ) {
            $payment = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $payments_t WHERE id = %d",
                $our_id
            ), ARRAY_A );
        }
        // Fall back to SaleId — the IPN would have written this.
        if ( ! $payment && $sale_id ) {
            $payment = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $payments_t WHERE icredit_sale_id = %s ORDER BY id DESC LIMIT 1",
                $sale_id
            ), ARRAY_A );
        }
        // Last try: PublicSaleToken (legacy/sandbox).
        if ( ! $payment && $public_token ) {
            $payment = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $payments_t WHERE icredit_public_token = %s",
                $public_token
            ), ARRAY_A );
        }

        // 1.14.54: If we still couldn't find the row but we know the
        // user is logged in to a CMMS account, fall back to looking
        // up the most recent pending/approved payment for this account.
        // This covers the case where iCredit's return URL strips ALL
        // identifying query params.
        if ( ! $payment && is_user_logged_in() ) {
            $cu = CMMS_Auth::current_cmms_user();
            if ( $cu && $cu->account_id ) {
                $payment = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM $payments_t
                      WHERE account_id = %d
                        AND status IN ('pending','approved')
                      ORDER BY id DESC LIMIT 1",
                    (int) $cu->account_id
                ), ARRAY_A );
            }
        }

        // No token, no payment — send back to pricing.
        if ( ! $payment ) {
            wp_safe_redirect( home_url( '/start/' ) );
            exit;
        }

        // 1.14.47: For approved/pending, route through the Workspace
        // Animation step (step 4) instead of dropping the user directly
        // on the dashboard. This gives a SaaS-grade post-payment
        // experience and lets us do any async setup work in parallel
        // before the dashboard loads. The animation page auto-redirects
        // to /cmms-dashboard/?welcome=1 after ~5s (or sooner if the user
        // taps to skip).
        //
        // For declined, still go straight back to step 3 with an error
        // — no point in showing a "preparing your workspace" screen for
        // a failed payment.
        if ( $payment['status'] === 'approved' ) {
            wp_safe_redirect( home_url( '/start/?step=4&payment=success' ) );
            exit;
        }
        if ( $payment['status'] === 'declined' ) {
            wp_safe_redirect( home_url( '/start/?step=3&payment=failed' ) );
            exit;
        }
        // Pending: IPN hasn't arrived yet, but the browser is back.
        // Still go through the animation (it's only 5 seconds) so the
        // dashboard isn't shown until IPN catches up. The page itself
        // shows a "processing" sub-status during the animation.
        wp_safe_redirect( home_url( '/start/?step=4&payment=processing' ) );
        exit;
    }

    /** Append a debug note to a payment row (in ipn_raw) for failed
     *  attempts. Cheap diagnostic without a separate log table.
     *  1.14.52: $detail can now be an array — encoded as JSON. */
    private static function log_payment_error( $payment_id, $code, $detail ) {
        if ( ! $payment_id ) return;
        global $wpdb;
        $payments_t = CMMS_DB::table( 'payments' );
        $note = wp_json_encode( array(
            'error_code'  => $code,
            'detail'      => $detail,
            'at'          => current_time( 'mysql' ),
        ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        $wpdb->update(
            $payments_t,
            array(
                'ipn_raw'    => $note,
                'status'     => 'declined',
                'updated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $payment_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * 1.14.56: Cancel a recurring subscription at iCredit.
     *
     * Calls iCredit's RecurringSaleCancel endpoint with the RecurringId
     * we received from the original IPN. Returns:
     *   - array on success: { status: 'ok' }
     *   - WP_Error on any failure (network, auth, iCredit rejection)
     *
     * NOTE: iCredit's recurring engine, on cancel, will NOT charge
     * future renewals. The current period that's already been paid
     * still applies — we keep the user's access until period end.
     */
    public static function cancel_recurring( $recurring_id ) {
        if ( empty( $recurring_id ) ) {
            return new WP_Error( 'no_recurring_id', 'מזהה הוראת קבע חסר.' );
        }
        if ( ! self::is_configured() ) {
            return new WP_Error( 'not_configured', 'שירות התשלום לא הוגדר.' );
        }

        // 1.14.58: iCredit's RecurringSaleCancel expects the field name
        // "RecurringSaleId" (NOT "RecurringId" like the IPN uses). Per
        // their docs at /reference/post_api-paymentpagerequest-svc-recurringsalecancel:
        //   Body Params: RecurringSaleId (uuid, required)
        // We also include GroupPrivateToken because their docs are
        // inconsistent across endpoints — belt and braces.
        $url = self::api_base() . 'RecurringSaleCancel';
        $payload = array(
            'GroupPrivateToken' => self::active_token(),
            'RecurringSaleId'   => $recurring_id,
        );

        $response = wp_remote_post( $url, array(
            'timeout' => 20,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'icredit_unreachable',
                'לא הצלחנו ליצור קשר עם שירות התשלום. נסה שוב בעוד מספר רגעים.' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
            // Log the full response for debugging cancel failures.
            error_log( 'CMMS iCredit Cancel HTTP ' . $code . ': ' . substr( (string) $body, 0, 500 ) );
            return new WP_Error( 'icredit_bad_response',
                'תגובה לא תקינה משירות התשלום (HTTP ' . $code . '): ' . substr( (string) $body, 0, 200 ) );
        }

        // iCredit returns Status=0 on success. Any other value is failure.
        $status = isset( $data['Status'] ) ? (int) $data['Status'] : -1;
        if ( $status !== 0 ) {
            $msg = $data['ErrorMessage'] ?? $data['Message'] ?? $data['DebugMessage'] ?? 'unknown';
            return new WP_Error( 'icredit_rejected',
                'שירות התשלום דחה את הביטול: ' . $msg );
        }

        return array( 'status' => 'ok' );
    }
}
