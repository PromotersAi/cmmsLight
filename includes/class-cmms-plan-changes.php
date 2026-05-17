<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CMMS_PlanChanges (1.14.60)
 *
 * Handles plan upgrades and downgrades on existing subscriptions.
 *
 * Design principles:
 *   - Stability over sophistication.
 *   - All amounts use days-proration: fair to the customer, fair to
 *     the business, mathematically transparent.
 *   - Upgrades happen immediately (prorated charge + new recurring).
 *   - Downgrades are scheduled for the next billing cycle boundary.
 *     The current subscription keeps its full benefits until then.
 *
 * State machine (subscriptions.status + pending_* fields):
 *
 *   active                                 → normal, no pending change
 *   active + pending_change_type=downgrade → downgrade scheduled
 *   canceled                                → fully terminated
 *   canceled_pending                        → user clicked Cancel; access
 *                                             until next_charge_at
 *
 * Recurring lifecycle on UPGRADE:
 *
 *   1. Compute proration (credit on old plan + charge on new plan).
 *   2. Charge the prorated delta via iCredit ChargeSimple.
 *   3. Cancel the OLD RecurringSale at iCredit.
 *   4. Create a NEW RecurringSale at iCredit with the new plan amount.
 *   5. Update our DB: plan_type, billing_cycle, amount,
 *      external_subscription_id, next_charge_at (next month from today).
 *
 * Recurring lifecycle on DOWNGRADE:
 *
 *   1. Mark pending_plan_type / pending_billing_cycle /
 *      pending_change_effective_at = next_charge_at.
 *   2. DO NOT touch iCredit yet.
 *   3. A cron job (or the next recurring IPN handler) checks if
 *      pending_change_effective_at <= now, and if so, applies the
 *      downgrade: cancels old recurring, creates new one.
 *
 * Failure handling:
 *   - Each iCredit call returns success/error.
 *   - If the prorated charge succeeded but the recurring create failed:
 *     we leave the subscription in 'active' with the OLD plan, log the
 *     mismatch as a payment error, and notify the admin. The customer
 *     paid a small extra fee — Super Admin can refund manually if needed.
 *   - We never leave the subscription in an undefined state.
 */
class CMMS_PlanChanges {

    /**
     * Calculate proration between an existing subscription's remaining
     * paid time and a new plan starting today.
     *
     * @param array $current_sub  Row from cmms_subscriptions
     * @param array $new_package  Row from cmms_packages
     * @return array {
     *   days_remaining    int     Days left in current billing period
     *   days_total        int     Total days in billing period (30/365)
     *   credit_old        float   Unused value on the old plan
     *   charge_new        float   Cost of new plan for the remainder
     *   net_charge        float   What to actually charge now (≥0)
     *   is_upgrade        bool    new price > old price (within cycle)
     *   is_downgrade      bool    new price < old price (within cycle)
     *   same_price        bool    new price == old price
     *   note              string  Human-readable explanation
     * }
     */
    public static function calculate_proration( $current_sub, $new_package ) {
        $old_amount = (float) ( $current_sub['amount'] ?? 0 );
        $new_amount = (float) ( $new_package['price'] ?? 0 );
        $cycle = $current_sub['billing_cycle'] ?? 'monthly';
        $total_days = ( $cycle === 'yearly' ) ? 365 : 30;

        // Compute remaining days in current period.
        $now_ts = current_time( 'timestamp' );
        $period_end_ts = ! empty( $current_sub['next_charge_at'] )
            ? strtotime( $current_sub['next_charge_at'] )
            : ( $now_ts + ( $total_days * DAY_IN_SECONDS ) );
        $remaining_seconds = max( 0, $period_end_ts - $now_ts );
        $days_remaining = (int) ceil( $remaining_seconds / DAY_IN_SECONDS );
        // Cap at total_days (sanity).
        if ( $days_remaining > $total_days ) $days_remaining = $total_days;

        $ratio = $total_days > 0 ? ( $days_remaining / $total_days ) : 0;

        // Round to 2 decimal places — iCredit + accounting both work in
        // 2 decimals. We do NOT round up; if the customer is owed a few
        // agorot, they get them.
        $credit_old  = round( $old_amount * $ratio, 2 );
        $charge_new  = round( $new_amount * $ratio, 2 );
        $net_charge  = round( $charge_new - $credit_old, 2 );

        $is_upgrade   = $new_amount > $old_amount;
        $is_downgrade = $new_amount < $old_amount;
        $same_price   = abs( $new_amount - $old_amount ) < 0.005;

        $note = '';
        if ( $is_upgrade ) {
            $note = sprintf(
                'שדרוג: %d ימים מתוך %d בחבילה הישנה (זיכוי ₪%s) מול %d ימים בחבילה החדשה (₪%s). חיוב מיידי על ההפרש: ₪%s.',
                $days_remaining, $total_days, number_format( $credit_old, 2 ),
                $days_remaining, number_format( $charge_new, 2 ),
                number_format( $net_charge, 2 )
            );
        } elseif ( $is_downgrade ) {
            $effective_date = ! empty( $current_sub['next_charge_at'] )
                ? mysql2date( 'd/m/Y', $current_sub['next_charge_at'] )
                : 'תום התקופה';
            $note = sprintf(
                'הורדה: השינוי ייכנס לתוקף ב-%s. עד אז ממשיך עם החבילה הנוכחית, ללא חיוב נוסף.',
                $effective_date
            );
        }

        return array(
            'days_remaining' => $days_remaining,
            'days_total'     => $total_days,
            'credit_old'     => $credit_old,
            'charge_new'     => $charge_new,
            'net_charge'     => max( 0, $net_charge ), // never negative for upgrade billing
            'raw_net'        => $net_charge,            // signed, for display
            'is_upgrade'     => $is_upgrade,
            'is_downgrade'   => $is_downgrade,
            'same_price'     => $same_price,
            'old_amount'     => $old_amount,
            'new_amount'     => $new_amount,
            'old_plan'       => $current_sub['plan_type'] ?? '',
            'new_plan'       => $new_package['plan_type'] ?? '',
            'cycle'          => $cycle,
            'note'           => $note,
        );
    }

    /**
     * Apply an upgrade immediately.
     *
     * Flow:
     *   1. Charge the prorated delta (if > 0) via iCredit ChargeSimple.
     *      Uses the existing customer's stored card via RecurringId.
     *   2. Cancel the old RecurringSale at iCredit.
     *   3. (Phase 1 simplification: we DON'T auto-create a new
     *      RecurringSale through API in 1.14.60 because creating a new
     *      recurring requires running the customer back through a
     *      hosted page. Instead we update DB to the new plan and mark
     *      a flag for the user to "confirm" the new recurring on the
     *      next visit. NOTE: this is intentional Phase 1 simplification.)
     *
     * Returns:
     *   array on success: { 'ok' => true, 'charged' => float, 'subscription_id' => int }
     *   WP_Error on failure with context-specific error code
     */
    public static function apply_upgrade( $account_id, $new_plan_type, $new_billing_cycle ) {
        global $wpdb;
        $subs_t = CMMS_DB::table( 'subscriptions' );

        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $subs_t WHERE account_id = %d ORDER BY id DESC LIMIT 1",
            (int) $account_id
        ), ARRAY_A );

        if ( ! $sub ) {
            return new WP_Error( 'no_subscription', 'לא נמצאה הוראת קבע לחשבון.' );
        }
        if ( ! in_array( $sub['status'], array( 'active', 'past_due' ), true ) ) {
            return new WP_Error( 'inactive_subscription',
                'לא ניתן לשדרג חבילה כשהוראת הקבע אינה פעילה.' );
        }

        $new_pkg = CMMS_Plans::get_package( $new_plan_type, $new_billing_cycle );
        if ( ! $new_pkg ) {
            return new WP_Error( 'invalid_package', 'החבילה הנבחרת אינה קיימת.' );
        }

        $proration = self::calculate_proration( $sub, $new_pkg );
        if ( ! $proration['is_upgrade'] ) {
            return new WP_Error( 'not_upgrade',
                'השינוי המבוקש אינו שדרוג. עבור הורדה — שמור על אופציית "הורדת חבילה".' );
        }

        // Step 1: Mark the change as in-progress in DB before touching
        // iCredit, so a partial failure leaves us an audit trail.
        $now = current_time( 'mysql' );
        $wpdb->update( $subs_t,
            array(
                'pending_change_type'         => 'upgrade_in_progress',
                'pending_plan_type'           => $new_plan_type,
                'pending_billing_cycle'       => $new_billing_cycle,
                'pending_change_effective_at' => $now,
                'updated_at'                  => $now,
            ),
            array( 'id' => (int) $sub['id'] )
        );

        // Step 2: Phase 1 — we do NOT charge proration through API
        // automatically because reusing a stored card requires the
        // CreditCardToken flow which iCredit only enables in some
        // merchant configurations. Instead, we issue a HOSTED PAGE
        // for the delta amount, and apply the plan change only after
        // the customer completes it. The IPN handler does the rest.
        //
        // This keeps the upgrade flow consistent with the existing
        // signup payment flow: customer always goes through hosted
        // page, never any silent backend charge that could surprise.
        //
        // The caller (AJAX endpoint) receives the hosted page URL and
        // redirects the customer to it.

        // Create a payment row for the delta charge.
        $payments_t = CMMS_DB::table( 'payments' );
        $wpdb->insert( $payments_t, array(
            'account_id'    => (int) $account_id,
            'package_id'    => (int) $new_pkg['id'],
            'plan_type'     => $new_plan_type,
            'billing_cycle' => $new_billing_cycle,
            'amount'        => $proration['net_charge'],
            'currency'      => 'ILS',
            'status'        => 'pending',
            'provider'      => 'icredit',
            'charge_type'   => 'plan_change_delta',
            'created_at'    => $now,
            'updated_at'    => $now,
        ) );
        $payment_id = (int) $wpdb->insert_id;

        // Build a sale via the standard create_sale path. We piggyback
        // on the existing infrastructure (IPN handler, hosted page,
        // etc.) — this is the most reliable approach for Phase 1.
        $sale = CMMS_Icredit::create_sale( array(
            'account_id'      => (int) $account_id,
            'package'         => array(
                'id'              => (int) $new_pkg['id'],
                'plan_type'       => $new_plan_type,
                'billing_cycle'   => $new_billing_cycle,
                'display_name'    => $new_pkg['display_name'],
                'price'           => $proration['net_charge'],
                'currency'        => 'ILS',
                'billing_mode'    => 'one_time',  // delta is NOT recurring
                'icredit_page_id' => '', // ad-hoc, no recurring page
            ),
            'name'  => '', // pulled from account by create_sale
            'email' => '',
            'phone' => '',
            'override_payment_id' => $payment_id, // reuse our row
            'charge_context'      => 'plan_change',
        ) );

        if ( is_wp_error( $sale ) ) {
            // Rollback pending fields, mark payment as declined.
            $wpdb->update( $subs_t,
                array(
                    'pending_change_type'         => null,
                    'pending_plan_type'           => null,
                    'pending_billing_cycle'       => null,
                    'pending_change_effective_at' => null,
                    'updated_at'                  => current_time( 'mysql' ),
                ),
                array( 'id' => (int) $sub['id'] )
            );
            $wpdb->update( $payments_t,
                array( 'status' => 'declined', 'updated_at' => current_time( 'mysql' ) ),
                array( 'id' => $payment_id )
            );
            return $sale;
        }

        return array(
            'ok'              => true,
            'mode'            => 'redirect_to_payment',
            'redirect_url'    => $sale['url'],
            'payment_id'      => $payment_id,
            'proration'       => $proration,
        );
    }

    /**
     * Schedule a downgrade for the next billing cycle.
     *
     * Does NOT touch iCredit immediately — the customer keeps their
     * current plan benefits until the next charge date. At that date,
     * the recurring IPN handler (or a daily cron) detects the pending
     * change and applies it: cancels old recurring at iCredit, swaps
     * DB to new plan, and (in 1.14.61+) creates the new recurring.
     */
    public static function schedule_downgrade( $account_id, $new_plan_type, $new_billing_cycle ) {
        global $wpdb;
        $subs_t = CMMS_DB::table( 'subscriptions' );

        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $subs_t WHERE account_id = %d ORDER BY id DESC LIMIT 1",
            (int) $account_id
        ), ARRAY_A );

        if ( ! $sub ) {
            return new WP_Error( 'no_subscription', 'לא נמצאה הוראת קבע לחשבון.' );
        }
        if ( ! in_array( $sub['status'], array( 'active', 'past_due' ), true ) ) {
            return new WP_Error( 'inactive_subscription',
                'לא ניתן לשנות חבילה כשהוראת הקבע אינה פעילה.' );
        }

        $new_pkg = CMMS_Plans::get_package( $new_plan_type, $new_billing_cycle );
        if ( ! $new_pkg ) {
            return new WP_Error( 'invalid_package', 'החבילה הנבחרת אינה קיימת.' );
        }

        $proration = self::calculate_proration( $sub, $new_pkg );
        if ( ! $proration['is_downgrade'] ) {
            return new WP_Error( 'not_downgrade',
                'השינוי המבוקש אינו הורדה. עבור שדרוג — שמור על אופציית "שדרוג חבילה".' );
        }

        // Effective date = the existing next_charge_at (where the next
        // recurring would naturally happen anyway). At that point the
        // cron switches plan.
        $effective_at = $sub['next_charge_at'] ?? null;
        if ( ! $effective_at ) {
            // Defensive fallback: 30 days from now.
            $effective_at = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 30 * DAY_IN_SECONDS );
        }

        $wpdb->update( $subs_t,
            array(
                'pending_change_type'         => 'downgrade',
                'pending_plan_type'           => $new_plan_type,
                'pending_billing_cycle'       => $new_billing_cycle,
                'pending_change_effective_at' => $effective_at,
                'updated_at'                  => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $sub['id'] )
        );

        return array(
            'ok'           => true,
            'effective_at' => $effective_at,
            'proration'    => $proration,
        );
    }

    /**
     * Cancel a pending (not-yet-applied) downgrade.
     * Customer changes their mind before the effective date.
     */
    public static function cancel_pending_change( $account_id ) {
        global $wpdb;
        $subs_t = CMMS_DB::table( 'subscriptions' );

        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $subs_t WHERE account_id = %d ORDER BY id DESC LIMIT 1",
            (int) $account_id
        ), ARRAY_A );
        if ( ! $sub ) {
            return new WP_Error( 'no_subscription', 'לא נמצאה הוראת קבע.' );
        }
        if ( empty( $sub['pending_change_type'] ) ) {
            return new WP_Error( 'no_pending_change', 'אין שינוי ממתין לביטול.' );
        }
        // Don't allow canceling an in-progress upgrade (it has money
        // moving through iCredit).
        if ( $sub['pending_change_type'] === 'upgrade_in_progress' ) {
            return new WP_Error( 'upgrade_in_progress',
                'השדרוג נמצא בעיבוד. אנא המתן לאישור התשלום או פנה לתמיכה.' );
        }

        $wpdb->update( $subs_t,
            array(
                'pending_change_type'         => null,
                'pending_plan_type'           => null,
                'pending_billing_cycle'       => null,
                'pending_change_effective_at' => null,
                'updated_at'                  => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $sub['id'] )
        );
        return array( 'ok' => true );
    }

    /**
     * Cron-style sweep: apply any pending changes whose effective_at
     * has passed. Called from a scheduled hook.
     *
     * Phase 1 behavior:
     *   - For downgrades: swap DB to the new plan, update accounts.max_users,
     *     mark the subscription's amount/plan fields. Leave the iCredit
     *     RecurringSale alone for now — the next charge from iCredit will
     *     use the OLD amount, and the IPN handler detects the mismatch
     *     and triggers a recurring swap then.
     *
     * NOTE: in Phase 2 we will use RecurringSaleUpdate or cancel+create
     * to align iCredit with the new amount BEFORE the next charge.
     */
    public static function apply_pending_changes_now() {
        global $wpdb;
        $subs_t = CMMS_DB::table( 'subscriptions' );

        $now = current_time( 'mysql' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $subs_t
              WHERE pending_change_type = 'downgrade'
                AND pending_change_effective_at IS NOT NULL
                AND pending_change_effective_at <= %s",
            $now
        ), ARRAY_A );

        if ( empty( $rows ) ) return 0;

        $applied = 0;
        foreach ( $rows as $row ) {
            $new_pkg = CMMS_Plans::get_package(
                $row['pending_plan_type'],
                $row['pending_billing_cycle']
            );
            if ( ! $new_pkg ) continue;

            // Swap the subscription to the new plan.
            $wpdb->update( $subs_t, array(
                'plan_type'                    => $row['pending_plan_type'],
                'billing_cycle'                => $row['pending_billing_cycle'],
                'package_id'                   => (int) $new_pkg['id'],
                'amount'                       => (float) $new_pkg['price'],
                'pending_plan_type'            => null,
                'pending_billing_cycle'        => null,
                'pending_change_effective_at'  => null,
                'pending_change_type'          => null,
                'updated_at'                   => $now,
            ), array( 'id' => (int) $row['id'] ) );

            // Mirror to account: plan_type, billing_cycle, max_users.
            $accounts_t = CMMS_DB::table( 'accounts' );
            $wpdb->update( $accounts_t, array(
                'plan_type'     => $row['pending_plan_type'],
                'billing_cycle' => $row['pending_billing_cycle'],
                'max_users'     => isset( $new_pkg['max_users'] ) ? (int) $new_pkg['max_users'] : null,
                'updated_at'    => $now,
            ), array( 'id' => (int) $row['account_id'] ) );

            $applied++;
        }
        return $applied;
    }
}
