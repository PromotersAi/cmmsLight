<?php
/**
 * Admin Payments + Subscriptions screen (1.14.44).
 *
 * Super Admin tool for visibility into the billing system.
 *
 * Two tabs:
 *   - Payments: ledger of every payment attempt (initial + recurring)
 *   - Subscriptions: active/past_due/frozen/canceled subscriptions
 *
 * Pure read-only on the Payments tab. The Subscriptions tab supports 
 * cancellation — but only marks the row as canceled in our DB; the
 * admin must separately cancel the recurring sale in iCredit (we show
 * a clear warning about this).
 *
 * Detail views are URL-driven (?action=payment_view&id=X) rather than
 * modals — easier to bookmark/share with support and respects
 * standard WP admin patterns.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Admin_Payments {

    const CAP  = 'manage_options';
    const SLUG = 'cmms-light-payments';

    /** Page size for the list tables. Adjust if needed. */
    const PER_PAGE = 25;

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_handle_cancel' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_handle_seat_action' ) );
    }

    public static function render() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'cmms-light' ) );
        }
        $action = isset( $_GET['cmms_action'] ) ? sanitize_key( wp_unslash( $_GET['cmms_action'] ) ) : '';
        if ( $action === 'payment_view' ) {
            self::render_payment_detail();
            return;
        }
        if ( $action === 'subscription_view' ) {
            self::render_subscription_detail();
            return;
        }

        // Tab selection.
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'payments';
        if ( ! in_array( $tab, array( 'payments', 'subscriptions' ), true ) ) $tab = 'payments';

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Payments & Subscriptions', 'cmms-light' ); ?></h1>
            <hr class="wp-header-end">

            <?php if ( ! empty( $_GET['canceled'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Subscription marked as canceled in CMMS. Remember to also cancel the recurring sale in iCredit.', 'cmms-light' ); ?></p>
                </div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=payments' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'payments' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Payments', 'cmms-light' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=subscriptions' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'subscriptions' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Subscriptions', 'cmms-light' ); ?>
                </a>
            </h2>

            <?php
            if ( $tab === 'subscriptions' ) {
                self::render_subscriptions_tab();
            } else {
                self::render_payments_tab();
            }
            ?>
        </div>
        <?php
    }

    /* ============================================================
       PAYMENTS TAB
    ============================================================ */
    private static function render_payments_tab() {
        global $wpdb;
        $payments_t = CMMS_DB::table( 'payments' );
        $accounts_t = CMMS_DB::table( 'accounts' );
        $cmms_users_t = CMMS_DB::table( 'users' );
        $wp_users_t   = $wpdb->users;

        // Filters from query string.
        $f_status      = isset( $_GET['f_status'] ) ? sanitize_key( wp_unslash( $_GET['f_status'] ) ) : '';
        $f_charge_type = isset( $_GET['f_charge_type'] ) ? sanitize_key( wp_unslash( $_GET['f_charge_type'] ) ) : '';
        $f_search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $paged         = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );

        // Build WHERE — parameterized for safety.
        // 1.14.48: chain to wp_users for email/display_name (cmms_users
        // links to WordPress users via wp_user_id; email lives there,
        // not on the cmms_users row itself).
        $where  = array( '1=1' );
        $params = array();
        if ( in_array( $f_status, array( 'pending', 'approved', 'declined', 'expired', 'refunded' ), true ) ) {
            $where[]  = 'p.status = %s';
            $params[] = $f_status;
        }
        if ( in_array( $f_charge_type, array( 'initial', 'recurring' ), true ) ) {
            $where[]  = 'p.charge_type = %s';
            $params[] = $f_charge_type;
        }
        if ( $f_search !== '' ) {
            $like = '%' . $wpdb->esc_like( $f_search ) . '%';
            $where[]  = "( a.name LIKE %s OR wpu.user_email LIKE %s OR p.icredit_sale_id LIKE %s OR p.id = %d )";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = (int) $f_search; // numeric search = payment id
        }
        $where_sql = implode( ' AND ', $where );

        // Count total for pagination.
        $count_sql = "SELECT COUNT(*) FROM $payments_t p
                      LEFT JOIN $accounts_t a ON a.id = p.account_id
                      LEFT JOIN $cmms_users_t cu ON cu.id = a.owner_user_id
                      LEFT JOIN $wp_users_t wpu ON wpu.ID = cu.wp_user_id
                      WHERE $where_sql";
        $total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
                         : (int) $wpdb->get_var( $count_sql );

        // Paginated rows.
        $offset = ( $paged - 1 ) * self::PER_PAGE;
        $list_sql = "SELECT p.*, a.name AS company_name,
                            wpu.user_email AS owner_email,
                            COALESCE(cu.display_name, wpu.display_name) AS owner_name
                     FROM $payments_t p
                     LEFT JOIN $accounts_t a ON a.id = p.account_id
                     LEFT JOIN $cmms_users_t cu ON cu.id = a.owner_user_id
                     LEFT JOIN $wp_users_t wpu ON wpu.ID = cu.wp_user_id
                     WHERE $where_sql
                     ORDER BY p.id DESC
                     LIMIT %d OFFSET %d";
        $list_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

        // Filters form
        ?>
        <form method="get" action="" style="margin: 16px 0;">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
            <input type="hidden" name="tab" value="payments">

            <select name="f_status">
                <option value=""><?php esc_html_e( 'All statuses', 'cmms-light' ); ?></option>
                <?php foreach ( array( 'pending', 'approved', 'declined', 'expired', 'refunded' ) as $s ) : ?>
                    <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $f_status, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
                <?php endforeach; ?>
            </select>

            <select name="f_charge_type">
                <option value=""><?php esc_html_e( 'All types', 'cmms-light' ); ?></option>
                <option value="initial" <?php selected( $f_charge_type, 'initial' ); ?>>Initial</option>
                <option value="recurring" <?php selected( $f_charge_type, 'recurring' ); ?>>Recurring</option>
            </select>

            <input type="search" name="s" value="<?php echo esc_attr( $f_search ); ?>"
                   placeholder="<?php esc_attr_e( 'Search email, company, sale ID, or payment #', 'cmms-light' ); ?>"
                   style="min-width:280px;">

            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'cmms-light' ); ?></button>
            <?php if ( $f_status || $f_charge_type || $f_search ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=payments' ) ); ?>" class="button">
                    <?php esc_html_e( 'Reset', 'cmms-light' ); ?>
                </a>
            <?php endif; ?>

            <span style="float:left; color:#50575e; line-height:30px;">
                <?php
                /* translators: %d: total payment count */
                printf( esc_html__( '%d payments total', 'cmms-light' ), (int) $total );
                ?>
            </span>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:60px">#</th>
                    <th style="width:140px"><?php esc_html_e( 'Date', 'cmms-light' ); ?></th>
                    <th><?php esc_html_e( 'Customer', 'cmms-light' ); ?></th>
                    <th><?php esc_html_e( 'Package', 'cmms-light' ); ?></th>
                    <th style="width:100px"><?php esc_html_e( 'Amount', 'cmms-light' ); ?></th>
                    <th style="width:110px"><?php esc_html_e( 'Status', 'cmms-light' ); ?></th>
                    <th style="width:100px"><?php esc_html_e( 'Type', 'cmms-light' ); ?></th>
                    <th style="width:80px"><?php esc_html_e( 'Actions', 'cmms-light' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="8"><?php esc_html_e( 'No payments found.', 'cmms-light' ); ?></td></tr>
            <?php else : foreach ( $rows as $r ) :
                $detail_url = add_query_arg( array(
                    'page'        => self::SLUG,
                    'cmms_action' => 'payment_view',
                    'id'          => $r['id'],
                ), admin_url( 'admin.php' ) );
            ?>
                <tr>
                    <td>#<?php echo (int) $r['id']; ?></td>
                    <td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $r['created_at'] ) ); ?></td>
                    <td>
                        <strong><?php echo esc_html( $r['company_name'] ?? '—' ); ?></strong><br>
                        <span style="color:#646970; font-size:12px;"><?php echo esc_html( $r['owner_email'] ?? '' ); ?></span>
                    </td>
                    <td>
                        <?php echo esc_html( ucfirst( $r['plan_type'] ?? '—' ) ); ?>
                        <?php if ( ! empty( $r['billing_cycle'] ) ) : ?>
                            <span style="color:#646970;">/ <?php echo esc_html( $r['billing_cycle'] === 'yearly' ? 'Yearly' : 'Monthly' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>₪<?php echo esc_html( number_format( (float) $r['amount'], 2 ) ); ?></td>
                    <td><?php echo self::status_badge( $r['status'] ); ?></td>
                    <td>
                        <?php if ( $r['charge_type'] === 'recurring' ) : ?>
                            <span style="background:#e0e7ff; color:#3730a3; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">RECURRING</span>
                        <?php else : ?>
                            <span style="background:#f3f4f6; color:#374151; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">INITIAL</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small">
                            <?php esc_html_e( 'View', 'cmms-light' ); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php
        // Pagination
        $total_pages = (int) ceil( $total / self::PER_PAGE );
        if ( $total_pages > 1 ) {
            $base_url = add_query_arg( array_filter( array(
                'page'          => self::SLUG,
                'tab'           => 'payments',
                'f_status'      => $f_status,
                'f_charge_type' => $f_charge_type,
                's'             => $f_search,
            ) ), admin_url( 'admin.php' ) );
            ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( array(
                        'base'      => $base_url . '%_%',
                        'format'    => '&paged=%#%',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'prev_text' => '«',
                        'next_text' => '»',
                    ) );
                    ?>
                </div>
            </div>
            <?php
        }
    }

    /* ============================================================
       SUBSCRIPTIONS TAB
    ============================================================ */
    private static function render_subscriptions_tab() {
        global $wpdb;
        $subs_t       = CMMS_DB::table( 'subscriptions' );
        $accounts_t   = CMMS_DB::table( 'accounts' );
        $cmms_users_t = CMMS_DB::table( 'users' );
        $wp_users_t   = $wpdb->users;

        $f_status = isset( $_GET['f_status'] ) ? sanitize_key( wp_unslash( $_GET['f_status'] ) ) : '';
        $f_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $paged    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );

        $where  = array( '1=1' );
        $params = array();
        if ( in_array( $f_status, array( 'active', 'past_due', 'frozen', 'canceled' ), true ) ) {
            $where[]  = 's.status = %s';
            $params[] = $f_status;
        }
        if ( $f_search !== '' ) {
            $like = '%' . $wpdb->esc_like( $f_search ) . '%';
            $where[]  = '( a.name LIKE %s OR wpu.user_email LIKE %s )';
            $params[] = $like;
            $params[] = $like;
        }
        $where_sql = implode( ' AND ', $where );

        $count_sql = "SELECT COUNT(*) FROM $subs_t s
                      LEFT JOIN $accounts_t a ON a.id = s.account_id
                      LEFT JOIN $cmms_users_t cu ON cu.id = a.owner_user_id
                      LEFT JOIN $wp_users_t wpu ON wpu.ID = cu.wp_user_id
                      WHERE $where_sql";
        $total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
                         : (int) $wpdb->get_var( $count_sql );

        $offset = ( $paged - 1 ) * self::PER_PAGE;
        $list_sql = "SELECT s.*, a.name AS company_name,
                            wpu.user_email AS owner_email,
                            COALESCE(cu.display_name, wpu.display_name) AS owner_name
                     FROM $subs_t s
                     LEFT JOIN $accounts_t a ON a.id = s.account_id
                     LEFT JOIN $cmms_users_t cu ON cu.id = a.owner_user_id
                     LEFT JOIN $wp_users_t wpu ON wpu.ID = cu.wp_user_id
                     WHERE $where_sql
                     ORDER BY s.id DESC
                     LIMIT %d OFFSET %d";
        $list_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

        // 1.14.74: Pending Recurring Updates section.
        //
        // Show subscriptions where pending_seats_change != 0. These are
        // subs that have had a self-service seat addon paid (positive)
        // or a scheduled seat removal (negative), and the iCredit
        // recurring still needs to be updated manually by Super Admin
        // to reflect the new monthly amount.
        //
        // Once Super Admin updates iCredit and clears the pending,
        // the row disappears.
        $packages_t   = CMMS_DB::table( 'packages' );
        $pending_subs = $wpdb->get_results(
            "SELECT s.*, a.name AS company_name,
                    p.seat_addon_price AS seat_price,
                    p.price AS base_price,
                    p.included_seats AS included
               FROM $subs_t s
               LEFT JOIN $accounts_t a ON a.id = s.account_id
               LEFT JOIN $packages_t p
                      ON p.plan_type = s.plan_type
                     AND p.billing_cycle = s.billing_cycle
              WHERE s.pending_seats_change IS NOT NULL
                AND s.pending_seats_change <> 0
                AND s.status IN ('active','past_due')
              ORDER BY s.updated_at DESC",
            ARRAY_A
        );

        // Handle clear-pending POST action (admin marked as updated).
        if ( isset( $_POST['cmms_clear_pending_sub_id'] )
             && check_admin_referer( 'cmms_clear_pending_' . (int) $_POST['cmms_clear_pending_sub_id'], 'cmms_clear_pending_nonce' )
             && current_user_can( self::CAP ) ) {
            $clear_sub_id = (int) $_POST['cmms_clear_pending_sub_id'];
            $sub_to_clear = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $subs_t WHERE id = %d", $clear_sub_id
            ), ARRAY_A );
            if ( $sub_to_clear ) {
                $pkg_for_sub = $wpdb->get_row( $wpdb->prepare(
                    "SELECT price, seat_addon_price FROM $packages_t
                      WHERE plan_type = %s AND billing_cycle = %s LIMIT 1",
                    $sub_to_clear['plan_type'], $sub_to_clear['billing_cycle']
                ), ARRAY_A );
                $new_amount = $sub_to_clear['amount'];
                if ( $pkg_for_sub ) {
                    $new_amount = (float) $pkg_for_sub['price']
                                + ( (int) $sub_to_clear['seats_purchased'] )
                                  * (float) ( $pkg_for_sub['seat_addon_price'] ?: 0 );
                }
                $wpdb->update( $subs_t, array(
                    'pending_seats_change' => null,
                    'amount'               => $new_amount,
                    'updated_at'           => current_time( 'mysql' ),
                ), array( 'id' => $clear_sub_id ) );
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Marked as updated in iCredit.', 'cmms-light' ) . '</p></div>';
                $pending_subs = $wpdb->get_results(
                    "SELECT s.*, a.name AS company_name,
                            p.seat_addon_price AS seat_price,
                            p.price AS base_price,
                            p.included_seats AS included
                       FROM $subs_t s
                       LEFT JOIN $accounts_t a ON a.id = s.account_id
                       LEFT JOIN $packages_t p
                              ON p.plan_type = s.plan_type
                             AND p.billing_cycle = s.billing_cycle
                      WHERE s.pending_seats_change IS NOT NULL
                        AND s.pending_seats_change <> 0
                        AND s.status IN ('active','past_due')
                      ORDER BY s.updated_at DESC",
                    ARRAY_A
                );
            }
        }
        ?>

        <?php if ( ! empty( $pending_subs ) ) : ?>
        <div style="background:#fffbeb;border:1.5px solid #fcd34d;border-radius:8px;padding:14px 18px;margin:16px 0;">
            <h2 style="margin:0 0 4px;font-size:15px;color:#78350f;">
                ⚠️ <?php esc_html_e( 'Subscriptions Awaiting iCredit Recurring Update', 'cmms-light' ); ?>
                (<?php echo count( $pending_subs ); ?>)
            </h2>
            <p style="margin:0 0 12px;color:#78350f;font-size:13px;">
                <?php esc_html_e( "These customers paid for additional seats. Update their iCredit recurring amount to match the new monthly total, then click 'Mark as updated'.", 'cmms-light' ); ?>
            </p>
            <table class="wp-list-table widefat fixed striped" style="background:#fff;">
                <thead>
                    <tr>
                        <th style="width:200px"><?php esc_html_e( 'Company', 'cmms-light' ); ?></th>
                        <th style="width:120px"><?php esc_html_e( 'Plan', 'cmms-light' ); ?></th>
                        <th style="width:130px"><?php esc_html_e( 'Recurring (Old)', 'cmms-light' ); ?></th>
                        <th style="width:130px"><?php esc_html_e( 'Recurring (New)', 'cmms-light' ); ?></th>
                        <th style="width:120px"><?php esc_html_e( 'Pending change', 'cmms-light' ); ?></th>
                        <th style="width:140px"><?php esc_html_e( 'Next billing', 'cmms-light' ); ?></th>
                        <th style="width:140px"><?php esc_html_e( 'iCredit RecurringId', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'cmms-light' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $pending_subs as $psub ) :
                        $pc = (int) $psub['pending_seats_change'];
                        $current_seats = (int) $psub['seats_purchased'];
                        $current_recurring = (float) $psub['amount'];
                        $new_recurring = (float) $psub['base_price']
                                       + $current_seats * (float) ( $psub['seat_price'] ?: 0 );
                        $sub_view_url = add_query_arg( array(
                            'page'        => self::SLUG,
                            'tab'         => 'subscriptions',
                            'cmms_action' => 'subscription_view',
                            'id'          => (int) $psub['id'],
                        ), admin_url( 'admin.php' ) );
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url( $sub_view_url ); ?>"><strong><?php echo esc_html( $psub['company_name'] ?: '—' ); ?></strong></a></td>
                        <td><?php echo esc_html( ucfirst( $psub['plan_type'] ) ); ?> / <?php echo esc_html( $psub['billing_cycle'] ); ?></td>
                        <td>₪<?php echo esc_html( number_format( $current_recurring, 2 ) ); ?></td>
                        <td><strong style="color:#047857;">₪<?php echo esc_html( number_format( $new_recurring, 2 ) ); ?></strong></td>
                        <td>
                            <?php if ( $pc > 0 ) : ?>
                                <span style="color:#047857;">+<?php echo (int) $pc; ?> <?php esc_html_e( 'seats', 'cmms-light' ); ?></span>
                            <?php else : ?>
                                <span style="color:#a00;"><?php echo (int) $pc; ?> <?php esc_html_e( 'seats', 'cmms-light' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( ! empty( $psub['next_charge_at'] ) ) : ?>
                                <?php echo esc_html( mysql2date( 'd/m/Y', $psub['next_charge_at'] ) ); ?>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( ! empty( $psub['external_subscription_id'] ) ) : ?>
                                <code style="font-size:11px;"><?php echo esc_html( $psub['external_subscription_id'] ); ?></code>
                            <?php else : ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="" style="margin:0;">
                                <?php wp_nonce_field( 'cmms_clear_pending_' . (int) $psub['id'], 'cmms_clear_pending_nonce' ); ?>
                                <input type="hidden" name="cmms_clear_pending_sub_id" value="<?php echo (int) $psub['id']; ?>">
                                <button type="submit" class="button button-small"
                                        onclick="return confirm('<?php echo esc_js( __( 'Confirm: you have updated iCredit to charge the new amount?', 'cmms-light' ) ); ?>');">
                                    <?php esc_html_e( 'Mark as updated', 'cmms-light' ); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <form method="get" action="" style="margin: 16px 0;">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
            <input type="hidden" name="tab" value="subscriptions">

            <select name="f_status">
                <option value=""><?php esc_html_e( 'All statuses', 'cmms-light' ); ?></option>
                <?php foreach ( array( 'active', 'past_due', 'frozen', 'canceled' ) as $s ) : ?>
                    <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $f_status, $s ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $s ) ) ); ?></option>
                <?php endforeach; ?>
            </select>

            <input type="search" name="s" value="<?php echo esc_attr( $f_search ); ?>"
                   placeholder="<?php esc_attr_e( 'Search email or company', 'cmms-light' ); ?>"
                   style="min-width:280px;">

            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'cmms-light' ); ?></button>
            <?php if ( $f_status || $f_search ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=subscriptions' ) ); ?>" class="button">
                    <?php esc_html_e( 'Reset', 'cmms-light' ); ?>
                </a>
            <?php endif; ?>

            <span style="float:left; color:#50575e; line-height:30px;">
                <?php printf( esc_html__( '%d subscriptions total', 'cmms-light' ), (int) $total ); ?>
            </span>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:60px">#</th>
                    <th><?php esc_html_e( 'Customer', 'cmms-light' ); ?></th>
                    <th><?php esc_html_e( 'Package', 'cmms-light' ); ?></th>
                    <th style="width:90px"><?php esc_html_e( 'Amount', 'cmms-light' ); ?></th>
                    <th style="width:110px"><?php esc_html_e( 'Status', 'cmms-light' ); ?></th>
                    <th style="width:130px"><?php esc_html_e( 'Last Charge', 'cmms-light' ); ?></th>
                    <th style="width:130px"><?php esc_html_e( 'Next Charge', 'cmms-light' ); ?></th>
                    <th style="width:120px"><?php esc_html_e( 'Actions', 'cmms-light' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="8"><?php esc_html_e( 'No subscriptions found.', 'cmms-light' ); ?></td></tr>
            <?php else : foreach ( $rows as $r ) :
                $detail_url = add_query_arg( array(
                    'page'        => self::SLUG,
                    'cmms_action' => 'subscription_view',
                    'id'          => $r['id'],
                ), admin_url( 'admin.php' ) );
                $cancel_url = wp_nonce_url(
                    add_query_arg( array(
                        'page'             => self::SLUG,
                        'tab'              => 'subscriptions',
                        'cmms_sub_cancel'  => $r['id'],
                    ), admin_url( 'admin.php' ) ),
                    'cmms_sub_cancel_' . $r['id']
                );
            ?>
                <tr>
                    <td>#<?php echo (int) $r['id']; ?></td>
                    <td>
                        <strong><?php echo esc_html( $r['company_name'] ?? '—' ); ?></strong><br>
                        <span style="color:#646970; font-size:12px;"><?php echo esc_html( $r['owner_email'] ?? '' ); ?></span>
                    </td>
                    <td>
                        <?php echo esc_html( ucfirst( $r['plan_type'] ?? '—' ) ); ?>
                        <?php if ( ! empty( $r['billing_cycle'] ) ) : ?>
                            <span style="color:#646970;">/ <?php echo esc_html( $r['billing_cycle'] === 'yearly' ? 'Yearly' : 'Monthly' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>₪<?php echo esc_html( number_format( (float) $r['amount'], 2 ) ); ?></td>
                    <td><?php echo self::status_badge( $r['status'] ); ?></td>
                    <td><?php echo $r['last_charge_at'] ? esc_html( mysql2date( 'd/m/Y', $r['last_charge_at'] ) ) : '—'; ?></td>
                    <td><?php echo $r['next_charge_at'] ? esc_html( mysql2date( 'd/m/Y', $r['next_charge_at'] ) ) : '—'; ?></td>
                    <td>
                        <a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small">
                            <?php esc_html_e( 'View', 'cmms-light' ); ?>
                        </a>
                        <?php if ( $r['status'] !== 'canceled' ) : ?>
                            <a href="<?php echo esc_url( $cancel_url ); ?>"
                               class="button button-small"
                               onclick="return confirm('<?php echo esc_js( __( 'Cancel this subscription? You must also cancel the recurring sale in iCredit dashboard separately.', 'cmms-light' ) ); ?>');"
                               style="color:#a00;">
                                <?php esc_html_e( 'Cancel', 'cmms-light' ); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php
        $total_pages = (int) ceil( $total / self::PER_PAGE );
        if ( $total_pages > 1 ) {
            $base_url = add_query_arg( array_filter( array(
                'page'     => self::SLUG,
                'tab'      => 'subscriptions',
                'f_status' => $f_status,
                's'        => $f_search,
            ) ), admin_url( 'admin.php' ) );
            ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( array(
                        'base'      => $base_url . '%_%',
                        'format'    => '&paged=%#%',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'prev_text' => '«',
                        'next_text' => '»',
                    ) );
                    ?>
                </div>
            </div>
            <?php
        }
    }

    /* ============================================================
       PAYMENT DETAIL VIEW
    ============================================================ */
    private static function render_payment_detail() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        if ( ! $id ) {
            self::render_payments_tab();
            return;
        }

        global $wpdb;
        $payments_t   = CMMS_DB::table( 'payments' );
        $accounts_t   = CMMS_DB::table( 'accounts' );
        $cmms_users_t = CMMS_DB::table( 'users' );
        $wp_users_t   = $wpdb->users;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.*, a.name AS company_name,
                    wpu.user_email AS owner_email,
                    COALESCE(cu.display_name, wpu.display_name) AS owner_name
             FROM $payments_t p
             LEFT JOIN $accounts_t a ON a.id = p.account_id
             LEFT JOIN $cmms_users_t cu ON cu.id = a.owner_user_id
             LEFT JOIN $wp_users_t wpu ON wpu.ID = cu.wp_user_id
             WHERE p.id = %d",
            $id
        ), ARRAY_A );

        $back_url = admin_url( 'admin.php?page=' . self::SLUG . '&tab=payments' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php printf( esc_html__( 'Payment #%d', 'cmms-light' ), $id ); ?></h1>
            <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back', 'cmms-light' ); ?></a>
            <hr class="wp-header-end">

            <?php if ( ! $row ) : ?>
                <p><?php esc_html_e( 'Payment not found.', 'cmms-light' ); ?></p>
            <?php else : ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr><th scope="row">Status</th><td><?php echo self::status_badge( $row['status'] ); ?></td></tr>
                        <tr><th scope="row">Charge Type</th><td><?php echo esc_html( ucfirst( $row['charge_type'] ?? 'initial' ) ); ?></td></tr>
                        <tr><th scope="row">Created</th><td><?php echo esc_html( $row['created_at'] ); ?></td></tr>
                        <tr><th scope="row">IPN Received</th><td><?php echo esc_html( $row['ipn_received_at'] ?: '—' ); ?></td></tr>
                        <tr><th scope="row">Account</th><td><?php echo esc_html( $row['company_name'] ?? '—' ); ?> (#<?php echo (int) $row['account_id']; ?>)</td></tr>
                        <tr><th scope="row">Owner</th><td><?php echo esc_html( $row['owner_name'] ?? '' ); ?> &lt;<?php echo esc_html( $row['owner_email'] ?? '' ); ?>&gt;</td></tr>
                        <tr><th scope="row">Package</th><td><?php echo esc_html( $row['plan_type'] ); ?> / <?php echo esc_html( $row['billing_cycle'] ); ?></td></tr>
                        <tr><th scope="row">Amount</th><td>₪<?php echo esc_html( number_format( (float) $row['amount'], 2 ) ); ?> <?php echo esc_html( $row['currency'] ); ?></td></tr>
                        <tr><th scope="row">Provider Mode</th><td><?php echo esc_html( $row['provider_mode'] ); ?></td></tr>
                        <tr><th scope="row">iCredit Sale ID</th><td><code><?php echo esc_html( $row['icredit_sale_id'] ?: '—' ); ?></code></td></tr>
                        <tr><th scope="row">Document Number</th><td><?php echo esc_html( $row['icredit_document_number'] ?: '—' ); ?></td></tr>
                        <tr><th scope="row">Public Token</th><td><code style="font-size:11px;"><?php echo esc_html( $row['icredit_public_token'] ?: '—' ); ?></code></td></tr>
                        <tr><th scope="row">Subscription ID</th><td><?php echo $row['subscription_id'] ? '#' . (int) $row['subscription_id'] : '—'; ?></td></tr>
                    </tbody>
                </table>

                <h2 style="margin-top:32px;"><?php esc_html_e( 'IPN Raw Payload', 'cmms-light' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Full payload received from iCredit. For debugging only.', 'cmms-light' ); ?></p>
                <textarea readonly rows="14" class="large-text code" style="font-family:monospace; font-size:11px;"><?php
                    if ( $row['ipn_raw'] ) {
                        // Pretty-print if it's JSON.
                        $decoded = json_decode( $row['ipn_raw'], true );
                        echo esc_textarea( is_array( $decoded ) ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : $row['ipn_raw'] );
                    } else {
                        esc_html_e( 'No IPN received yet.', 'cmms-light' );
                    }
                ?></textarea>

                <?php // 1.14.79.1 — Debug payload from the iCredit request.
                      // Captures the exact JSON we sent to iCredit GetUrl
                      // and the response we got back. Helps diagnose
                      // failures where the customer landed on rivhit.net
                      // Error.htm with no IPN ever received. ?>
                <h2 style="margin-top:32px;">🔍 <?php esc_html_e( 'Debug Info — Request to iCredit', 'cmms-light' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Snapshot of what was sent to iCredit and what came back. The GroupPrivateToken is redacted for security.', 'cmms-light' ); ?></p>
                <?php
                $has_debug_col = ! empty( $row['debug_payload'] );
                if ( $has_debug_col ) {
                    $debug_decoded = json_decode( $row['debug_payload'], true );
                    if ( is_array( $debug_decoded ) ) {
                        // Pretty summary box at the top.
                        $http_code = $debug_decoded['http_code'] ?? '?';
                        $duration  = $debug_decoded['duration_ms'] ?? '?';
                        $outcome   = $debug_decoded['http_outcome'] ?? '?';
                        $parsed    = $debug_decoded['response_parsed'] ?? null;
                        $icredit_status = is_array( $parsed ) && isset( $parsed['Status'] ) ? (int) $parsed['Status'] : null;
                        $icredit_url    = is_array( $parsed ) ? ( $parsed['URL'] ?? '' ) : '';
                        ?>
                        <table class="widefat" style="max-width:800px;margin-bottom:14px;">
                            <tbody>
                                <tr><th style="width:200px;"><?php esc_html_e( 'Attempted at', 'cmms-light' ); ?></th>
                                    <td><?php echo esc_html( $debug_decoded['attempted_at'] ?? '—' ); ?></td></tr>
                                <tr><th>HTTP outcome</th>
                                    <td><code><?php echo esc_html( $outcome ); ?></code></td></tr>
                                <tr><th>HTTP status code</th>
                                    <td><strong style="color:<?php echo ( is_numeric( $http_code ) && $http_code >= 200 && $http_code < 300 ) ? '#047857' : '#b91c1c'; ?>;"><?php echo esc_html( $http_code ); ?></strong></td></tr>
                                <tr><th>Duration</th>
                                    <td><?php echo esc_html( $duration ); ?> ms</td></tr>
                                <?php if ( $icredit_status !== null ) : ?>
                                <tr><th>iCredit Status field</th>
                                    <td>
                                        <strong style="color:<?php echo $icredit_status === 0 ? '#047857' : '#b91c1c'; ?>;">
                                            <?php echo (int) $icredit_status; ?>
                                            <?php echo $icredit_status === 0 ? ' ✓ (OK)' : ' ✗ (rejected)'; ?>
                                        </strong>
                                    </td></tr>
                                <?php endif; ?>
                                <?php if ( $icredit_url ) : ?>
                                <tr><th>Returned URL</th>
                                    <td style="word-break:break-all;"><a href="<?php echo esc_url( $icredit_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $icredit_url ); ?></a></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <details style="margin-top:14px;">
                            <summary style="cursor:pointer;font-weight:600;padding:6px 0;"><?php esc_html_e( 'Full Request Payload (click to expand)', 'cmms-light' ); ?></summary>
                            <textarea readonly rows="20" class="large-text code" style="font-family:monospace; font-size:11px; margin-top:8px;"><?php
                                echo esc_textarea( wp_json_encode( $debug_decoded['request_payload'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
                            ?></textarea>
                        </details>
                        <details style="margin-top:8px;">
                            <summary style="cursor:pointer;font-weight:600;padding:6px 0;"><?php esc_html_e( 'Full Response Body (click to expand)', 'cmms-light' ); ?></summary>
                            <textarea readonly rows="20" class="large-text code" style="font-family:monospace; font-size:11px; margin-top:8px;"><?php
                                $body_to_show = $debug_decoded['response_parsed']
                                    ?? $debug_decoded['response_body']
                                    ?? '(empty)';
                                echo esc_textarea( is_array( $body_to_show )
                                    ? wp_json_encode( $body_to_show, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
                                    : $body_to_show );
                            ?></textarea>
                        </details>
                        <?php
                    } else {
                        echo '<pre style="background:#f6f7f7;padding:12px;border-radius:6px;overflow:auto;">' . esc_html( $row['debug_payload'] ) . '</pre>';
                    }
                } else {
                    echo '<p><em>' . esc_html__( 'No debug data captured (payment was created before 1.14.79.1, or the request never reached iCredit).', 'cmms-light' ) . '</em></p>';
                }
                ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ============================================================
       SUBSCRIPTION DETAIL VIEW
    ============================================================ */
    private static function render_subscription_detail() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        if ( ! $id ) {
            self::render_subscriptions_tab();
            return;
        }

        global $wpdb;
        $subs_t       = CMMS_DB::table( 'subscriptions' );
        $payments_t   = CMMS_DB::table( 'payments' );
        $accounts_t   = CMMS_DB::table( 'accounts' );
        $cmms_users_t = CMMS_DB::table( 'users' );
        $wp_users_t   = $wpdb->users;

        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, a.name AS company_name,
                    wpu.user_email AS owner_email,
                    COALESCE(cu.display_name, wpu.display_name) AS owner_name
             FROM $subs_t s
             LEFT JOIN $accounts_t a ON a.id = s.account_id
             LEFT JOIN $cmms_users_t cu ON cu.id = a.owner_user_id
             LEFT JOIN $wp_users_t wpu ON wpu.ID = cu.wp_user_id
             WHERE s.id = %d",
            $id
        ), ARRAY_A );

        $back_url = admin_url( 'admin.php?page=' . self::SLUG . '&tab=subscriptions' );

        // Recent payments for this subscription.
        $sub_payments = $sub ? $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $payments_t WHERE subscription_id = %d ORDER BY id DESC LIMIT 25",
            $id
        ), ARRAY_A ) : array();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php printf( esc_html__( 'Subscription #%d', 'cmms-light' ), $id ); ?></h1>
            <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( '← Back', 'cmms-light' ); ?></a>
            <hr class="wp-header-end">

            <?php if ( ! $sub ) : ?>
                <p><?php esc_html_e( 'Subscription not found.', 'cmms-light' ); ?></p>
            <?php else : ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr><th scope="row">Status</th><td><?php echo self::status_badge( $sub['status'] ); ?></td></tr>
                        <tr><th scope="row">Started</th><td><?php echo esc_html( $sub['started_at'] ); ?></td></tr>
                        <tr><th scope="row">Account</th><td><?php echo esc_html( $sub['company_name'] ?? '—' ); ?> (#<?php echo (int) $sub['account_id']; ?>)</td></tr>
                        <tr><th scope="row">Owner</th><td><?php echo esc_html( $sub['owner_name'] ?? '' ); ?> &lt;<?php echo esc_html( $sub['owner_email'] ?? '' ); ?>&gt;</td></tr>
                        <tr><th scope="row">Package</th><td><?php echo esc_html( $sub['plan_type'] ); ?> / <?php echo esc_html( $sub['billing_cycle'] ); ?></td></tr>
                        <tr><th scope="row">Amount</th><td>₪<?php echo esc_html( number_format( (float) $sub['amount'], 2 ) ); ?> <?php echo esc_html( $sub['currency'] ); ?></td></tr>
                        <tr><th scope="row">Last Charge</th><td><?php echo esc_html( $sub['last_charge_at'] ?: '—' ); ?></td></tr>
                        <tr><th scope="row">Next Charge (estimated)</th><td><?php echo esc_html( $sub['next_charge_at'] ?: '—' ); ?></td></tr>
                        <tr><th scope="row">Last Failure</th><td><?php echo esc_html( $sub['last_failure_at'] ?: '—' ); ?></td></tr>
                        <tr><th scope="row">Grace Until</th><td><?php echo esc_html( $sub['grace_until'] ?: '—' ); ?></td></tr>
                        <?php if ( $sub['canceled_at'] ) : ?>
                            <tr><th scope="row">Canceled At</th><td><?php echo esc_html( $sub['canceled_at'] ); ?></td></tr>
                            <tr><th scope="row">Reason</th><td><?php echo esc_html( $sub['cancellation_reason'] ?: '—' ); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <h2 style="margin-top:32px;"><?php esc_html_e( 'Payment History', 'cmms-light' ); ?></h2>
                <?php if ( empty( $sub_payments ) ) : ?>
                    <p><?php esc_html_e( 'No payments recorded for this subscription yet.', 'cmms-light' ); ?></p>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:60px">#</th>
                                <th style="width:140px">Date</th>
                                <th style="width:100px">Type</th>
                                <th style="width:100px">Amount</th>
                                <th style="width:110px">Status</th>
                                <th>iCredit Sale ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $sub_payments as $p ) :
                                $pview = add_query_arg( array( 'page' => self::SLUG, 'cmms_action' => 'payment_view', 'id' => $p['id'] ), admin_url( 'admin.php' ) );
                            ?>
                                <tr>
                                    <td><a href="<?php echo esc_url( $pview ); ?>">#<?php echo (int) $p['id']; ?></a></td>
                                    <td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $p['created_at'] ) ); ?></td>
                                    <td><?php echo esc_html( ucfirst( $p['charge_type'] ?? 'initial' ) ); ?></td>
                                    <td>₪<?php echo esc_html( number_format( (float) $p['amount'], 2 ) ); ?></td>
                                    <td><?php echo self::status_badge( $p['status'] ); ?></td>
                                    <td><code style="font-size:11px;"><?php echo esc_html( $p['icredit_sale_id'] ?: '—' ); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php // ─────────────────────────────────────────────────
                      // 1.14.71 — Seat Management section (Super Admin)
                      //
                      // Lets the admin:
                      //   - See current seat status (used / allowed / hard limit)
                      //   - Add seats immediately (DB-only; manual billing)
                      //   - Schedule seat removal for next billing cycle
                      //   - Cancel a scheduled removal
                      //
                      // No iCredit calls happen here in Phase 1. Admin
                      // handles invoicing manually.
                      // ─────────────────────────────────────────────────
                      $seat_info = class_exists( 'CMMS_Seats' )
                          ? CMMS_Seats::get_account_seats( (int) $sub['account_id'] )
                          : null;

                      // Status messages from previous action.
                      $seat_msg = isset( $_GET['cmms_seat_msg'] ) ? sanitize_key( wp_unslash( $_GET['cmms_seat_msg'] ) ) : '';
                      $seat_err = isset( $_GET['cmms_seat_err'] ) ? wp_unslash( $_GET['cmms_seat_err'] ) : '';
                ?>
                <h2 style="margin-top:32px;"><?php esc_html_e( 'Seat Management', 'cmms-light' ); ?></h2>

                <?php if ( $seat_msg ) : ?>
                    <div class="notice notice-success inline" style="margin:0 0 12px;">
                        <p>
                        <?php
                        if     ( $seat_msg === 'added' )            esc_html_e( 'Seats added successfully.', 'cmms-light' );
                        elseif ( $seat_msg === 'removal_scheduled' ) esc_html_e( 'Seat removal scheduled for next billing cycle.', 'cmms-light' );
                        elseif ( $seat_msg === 'change_cancelled' ) esc_html_e( 'Scheduled seat change cancelled.', 'cmms-light' );
                        ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if ( $seat_err ) : ?>
                    <div class="notice notice-error inline" style="margin:0 0 12px;">
                        <p><?php echo esc_html( urldecode( $seat_err ) ); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ( ! $seat_info || ! $seat_info['is_managed'] ) : ?>
                    <div style="background:#f0f0f1;border:1px solid #ccd0d4;border-radius:6px;padding:14px 18px;">
                        <p style="margin:0;">
                            <strong><?php esc_html_e( 'Unmanaged plan', 'cmms-light' ); ?></strong> —
                            <?php esc_html_e( 'This account is on Enterprise / custom pricing. Seats are not managed automatically. Edit max_users on the account directly to change limits.', 'cmms-light' ); ?>
                        </p>
                    </div>
                <?php else : ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Plan', 'cmms-light' ); ?></th>
                                <td>
                                    <?php echo esc_html( ucfirst( $seat_info['plan_type'] ) ); ?>
                                    / <?php echo esc_html( $seat_info['billing_cycle'] ); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Included seats', 'cmms-light' ); ?></th>
                                <td><?php echo (int) $seat_info['included']; ?></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Purchased extras', 'cmms-light' ); ?></th>
                                <td>
                                    <?php echo (int) $seat_info['purchased']; ?>
                                    <?php if ( $seat_info['seat_price'] !== null ) : ?>
                                        <span style="color:#646970;">
                                            (₪<?php echo esc_html( number_format( (float) $seat_info['seat_price'], 2 ) ); ?>
                                            <?php esc_html_e( 'per seat / cycle', 'cmms-light' ); ?>)
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><strong><?php esc_html_e( 'Total allowed', 'cmms-light' ); ?></strong></th>
                                <td>
                                    <strong style="font-size:16px;"><?php echo (int) $seat_info['total_allowed']; ?></strong>
                                    <?php if ( $seat_info['hard_limit'] !== null ) : ?>
                                        <span style="color:#646970;">
                                            (<?php esc_html_e( 'hard limit:', 'cmms-light' ); ?> <?php echo (int) $seat_info['hard_limit']; ?>)
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Currently in use', 'cmms-light' ); ?></th>
                                <td>
                                    <strong><?php echo (int) $seat_info['currently_used']; ?></strong>
                                    /
                                    <?php echo (int) $seat_info['total_allowed']; ?>
                                    <?php if ( $seat_info['upgrade_recommended'] ) : ?>
                                        <span style="color:#dba617;margin-inline-start:8px;">⚠ <?php esc_html_e( 'Approaching hard limit — upgrade recommended', 'cmms-light' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ( $seat_info['pending_change'] != 0 ) :
                                $pc = (int) $seat_info['pending_change']; ?>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Pending change', 'cmms-light' ); ?></th>
                                    <td>
                                        <?php if ( $pc < 0 ) : ?>
                                            <span style="color:#a00;">
                                                <?php printf( esc_html__( 'Removal of %d seats scheduled for next billing cycle', 'cmms-light' ), abs( $pc ) ); ?>
                                            </span>
                                            <?php
                                            $cancel_url = wp_nonce_url(
                                                add_query_arg( array(
                                                    'page'                  => self::SLUG,
                                                    'cmms_action'           => 'subscription_view',
                                                    'id'                    => $id,
                                                    'cmms_seat_action'      => 'cancel_change',
                                                ), admin_url( 'admin.php' ) ),
                                                'cmms_seat_cancel_' . $id
                                            );
                                            ?>
                                            <a href="<?php echo esc_url( $cancel_url ); ?>" class="button button-small" style="margin-inline-start:8px;">
                                                <?php esc_html_e( 'Cancel scheduled change', 'cmms-light' ); ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:24px;">

                        <!-- Add seats form -->
                        <div style="flex:1 1 320px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:6px;padding:18px;">
                            <h3 style="margin-top:0;"><?php esc_html_e( 'Add seats', 'cmms-light' ); ?></h3>
                            <p class="description" style="margin-top:0;">
                                <?php esc_html_e( 'Adds seats immediately. Billing is manual — invoice the customer separately.', 'cmms-light' ); ?>
                            </p>
                            <?php
                            $room_left = ( $seat_info['hard_limit'] !== null )
                                ? max( 0, $seat_info['hard_limit'] - $seat_info['total_allowed'] )
                                : 999;
                            ?>
                            <?php if ( $room_left <= 0 ) : ?>
                                <p style="color:#a00;">
                                    <strong><?php esc_html_e( 'Hard limit reached.', 'cmms-light' ); ?></strong>
                                    <?php esc_html_e( 'Upgrade the plan to add more seats.', 'cmms-light' ); ?>
                                </p>
                            <?php else : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                                    <?php wp_nonce_field( 'cmms_seat_add_' . $id, 'cmms_seat_nonce' ); ?>
                                    <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
                                    <input type="hidden" name="cmms_action" value="subscription_view">
                                    <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                                    <input type="hidden" name="cmms_seat_action" value="add">
                                    <div>
                                        <label for="seat_count" style="display:block;font-weight:600;margin-bottom:4px;">
                                            <?php esc_html_e( 'How many to add', 'cmms-light' ); ?>
                                        </label>
                                        <input type="number" id="seat_count" name="seat_count"
                                               min="1" max="<?php echo (int) $room_left; ?>" value="1"
                                               style="width:100px;">
                                    </div>
                                    <button type="submit" class="button button-primary"
                                            onclick="return confirm('<?php echo esc_js( __( 'Add seats immediately? Bill the customer separately.', 'cmms-light' ) ); ?>');">
                                        <?php esc_html_e( 'Add seats', 'cmms-light' ); ?>
                                    </button>
                                </form>
                                <p class="description" style="margin-bottom:0;">
                                    <?php printf(
                                        esc_html__( 'Room left until hard limit: %d', 'cmms-light' ),
                                        $room_left
                                    ); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <!-- Remove seats form -->
                        <?php if ( $seat_info['purchased'] > 0 && $seat_info['pending_change'] == 0 ) : ?>
                        <div style="flex:1 1 320px;background:#f6f7f7;border:1px solid #ccd0d4;border-radius:6px;padding:18px;">
                            <h3 style="margin-top:0;"><?php esc_html_e( 'Remove seats (scheduled)', 'cmms-light' ); ?></h3>
                            <p class="description" style="margin-top:0;">
                                <?php esc_html_e( 'Schedules seat removal for next billing cycle. No refunds. The customer keeps full access until then.', 'cmms-light' ); ?>
                            </p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                                <?php wp_nonce_field( 'cmms_seat_remove_' . $id, 'cmms_seat_nonce' ); ?>
                                <input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
                                <input type="hidden" name="cmms_action" value="subscription_view">
                                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                                <input type="hidden" name="cmms_seat_action" value="remove">
                                <div>
                                    <label for="seat_count_rm" style="display:block;font-weight:600;margin-bottom:4px;">
                                        <?php esc_html_e( 'How many to schedule', 'cmms-light' ); ?>
                                    </label>
                                    <input type="number" id="seat_count_rm" name="seat_count"
                                           min="1" max="<?php echo (int) $seat_info['purchased']; ?>" value="1"
                                           style="width:100px;">
                                </div>
                                <button type="submit" class="button"
                                        onclick="return confirm('<?php echo esc_js( __( 'Schedule seat removal for next billing cycle?', 'cmms-light' ) ); ?>');">
                                    <?php esc_html_e( 'Schedule removal', 'cmms-light' ); ?>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>

                <?php if ( $sub['status'] !== 'canceled' ) :
                    $cancel_url = wp_nonce_url(
                        add_query_arg( array(
                            'page'            => self::SLUG,
                            'tab'             => 'subscriptions',
                            'cmms_sub_cancel' => $sub['id'],
                        ), admin_url( 'admin.php' ) ),
                        'cmms_sub_cancel_' . $sub['id']
                    );
                ?>
                <div style="background:#fff8e1; border:1px solid #ffd54f; border-radius:8px; padding:16px 20px; margin-top:28px;">
                    <h3 style="margin-top:0;"><?php esc_html_e( 'Cancel Subscription', 'cmms-light' ); ?></h3>
                    <p><?php esc_html_e( 'Marking a subscription as canceled in CMMS stops the system from treating it as active. It does NOT automatically cancel the recurring charge in iCredit — you must do that separately in the iCredit dashboard, or the customer will keep being billed.', 'cmms-light' ); ?></p>
                    <a href="<?php echo esc_url( $cancel_url ); ?>"
                       class="button button-secondary"
                       onclick="return confirm('<?php echo esc_js( __( 'Cancel this subscription in CMMS? Remember to also cancel in iCredit.', 'cmms-light' ) ); ?>');"
                       style="color:#a00; border-color:#a00;">
                        <?php esc_html_e( 'Cancel in CMMS', 'cmms-light' ); ?>
                    </a>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ============================================================
       CANCEL HANDLER (subscriptions tab)
    ============================================================ */
    public static function maybe_handle_cancel() {
        if ( ! isset( $_GET['cmms_sub_cancel'] ) ) return;
        if ( ! current_user_can( self::CAP ) ) return;

        $sub_id = (int) $_GET['cmms_sub_cancel'];
        if ( ! $sub_id ) return;

        check_admin_referer( 'cmms_sub_cancel_' . $sub_id );

        // Look up the account for this subscription so we can route the
        // cancel through the canonical Subscriptions API (which also
        // mirrors account.subscription_status).
        global $wpdb;
        $subs_t = CMMS_DB::table( 'subscriptions' );
        $account_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT account_id FROM $subs_t WHERE id = %d",
            $sub_id
        ) );

        if ( $account_id && class_exists( 'CMMS_Subscriptions' ) ) {
            CMMS_Subscriptions::cancel( $account_id, 'Canceled by admin' );
        }

        wp_safe_redirect( add_query_arg( array(
            'page'     => self::SLUG,
            'tab'      => 'subscriptions',
            'canceled' => 1,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ============================================================
       1.14.71 — SEAT ACTION HANDLER

       Handles POST submissions (and GET for cancel_change) from the
       Seat Management panel in the subscription detail view.

       Actions:
         - add             : POST, ?cmms_seat_action=add
         - remove          : POST, ?cmms_seat_action=remove
         - cancel_change   : GET,  ?cmms_seat_action=cancel_change

       Nonces:
         - cmms_seat_add_{id}     for add
         - cmms_seat_remove_{id}  for remove
         - cmms_seat_cancel_{id}  for cancel_change

       After processing, always redirects back to the subscription
       detail view with cmms_seat_msg or cmms_seat_err set so the
       user sees feedback.
    ============================================================ */
    public static function maybe_handle_seat_action() {
        if ( empty( $_REQUEST['cmms_seat_action'] ) ) return;
        if ( ! current_user_can( self::CAP ) ) return;

        $sub_id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;
        $action = sanitize_key( wp_unslash( $_REQUEST['cmms_seat_action'] ) );
        if ( ! $sub_id || ! $action ) return;
        if ( ! class_exists( 'CMMS_Seats' ) ) return;

        // Resolve account_id from subscription_id.
        global $wpdb;
        $subs_t     = CMMS_DB::table( 'subscriptions' );
        $account_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT account_id FROM $subs_t WHERE id = %d",
            $sub_id
        ) );
        if ( ! $account_id ) return;

        $redirect_base = add_query_arg( array(
            'page'        => self::SLUG,
            'cmms_action' => 'subscription_view',
            'id'          => $sub_id,
        ), admin_url( 'admin.php' ) );

        $result = null;

        if ( $action === 'add' ) {
            check_admin_referer( 'cmms_seat_add_' . $sub_id, 'cmms_seat_nonce' );
            $count = isset( $_POST['seat_count'] ) ? (int) $_POST['seat_count'] : 0;
            $result = CMMS_Seats::add_seats_admin( $account_id, $count );

            if ( is_wp_error( $result ) ) {
                wp_safe_redirect( add_query_arg(
                    'cmms_seat_err',
                    rawurlencode( $result->get_error_message() ),
                    $redirect_base
                ) );
                exit;
            }
            wp_safe_redirect( add_query_arg( 'cmms_seat_msg', 'added', $redirect_base ) );
            exit;
        }

        if ( $action === 'remove' ) {
            check_admin_referer( 'cmms_seat_remove_' . $sub_id, 'cmms_seat_nonce' );
            $count = isset( $_POST['seat_count'] ) ? (int) $_POST['seat_count'] : 0;
            $result = CMMS_Seats::remove_seats_admin( $account_id, $count );

            if ( is_wp_error( $result ) ) {
                wp_safe_redirect( add_query_arg(
                    'cmms_seat_err',
                    rawurlencode( $result->get_error_message() ),
                    $redirect_base
                ) );
                exit;
            }
            wp_safe_redirect( add_query_arg( 'cmms_seat_msg', 'removal_scheduled', $redirect_base ) );
            exit;
        }

        if ( $action === 'cancel_change' ) {
            check_admin_referer( 'cmms_seat_cancel_' . $sub_id );
            CMMS_Seats::cancel_scheduled_change( $account_id );
            wp_safe_redirect( add_query_arg( 'cmms_seat_msg', 'change_cancelled', $redirect_base ) );
            exit;
        }
    }

    /* ============================================================
       STATUS BADGE HELPER
    ============================================================ */
    private static function status_badge( $status ) {
        $colors = array(
            'pending'  => array( 'bg' => '#fef3c7', 'fg' => '#92400e' ),
            'approved' => array( 'bg' => '#d1fae5', 'fg' => '#065f46' ),
            'declined' => array( 'bg' => '#fee2e2', 'fg' => '#991b1b' ),
            'expired'  => array( 'bg' => '#f3f4f6', 'fg' => '#374151' ),
            'refunded' => array( 'bg' => '#e0e7ff', 'fg' => '#3730a3' ),
            'active'   => array( 'bg' => '#d1fae5', 'fg' => '#065f46' ),
            'past_due' => array( 'bg' => '#fef3c7', 'fg' => '#92400e' ),
            'frozen'   => array( 'bg' => '#fee2e2', 'fg' => '#991b1b' ),
            'canceled' => array( 'bg' => '#f3f4f6', 'fg' => '#374151' ),
        );
        $c = isset( $colors[ $status ] ) ? $colors[ $status ] : array( 'bg' => '#f3f4f6', 'fg' => '#374151' );
        return sprintf(
            '<span style="background:%s; color:%s; padding:3px 10px; border-radius:4px; font-size:11px; font-weight:700; text-transform:uppercase;">%s</span>',
            esc_attr( $c['bg'] ),
            esc_attr( $c['fg'] ),
            esc_html( str_replace( '_', ' ', $status ) )
        );
    }
}

CMMS_Admin_Payments::init();
