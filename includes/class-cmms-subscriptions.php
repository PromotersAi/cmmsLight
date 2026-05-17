<?php
/**
 * CMMS Subscriptions (1.14.40).
 *
 * Manages the lifecycle of recurring subscriptions.
 *
 * Lifecycle states:
 *   active   — currently paid up, full system access
 *   past_due — last recurring charge failed, in grace period
 *   frozen   — grace expired, system access blocked
 *   canceled — admin or user canceled, no further charges
 *
 * Grace period: 7 days from last_failure_at. This is the standard for
 * SaaS — immediate freeze loses customers, 30+ days loses money. 7 days
 * is the sweet spot.
 *
 * We do NOT initiate charges ourselves — iCredit's recurring engine
 * does that. Our role is:
 *   1. Record the subscription when the first payment succeeds
 *   2. Update lifecycle state when IPN events arrive
 *   3. Cron job: transition past_due → frozen after grace expires
 *
 * The account.subscription_status column mirrors subscription.status for
 * backward compat with code that reads from the account row directly.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Subscriptions {

    /** How many days to wait after a failed charge before freezing. */
    const GRACE_DAYS = 7;

    /**
     * Get the subscription for an account, if any.
     * Returns assoc or null.
     */
    public static function for_account( $account_id ) {
        global $wpdb;
        $t = CMMS_DB::table( 'subscriptions' );
        if ( ! $t || ! $account_id ) return null;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE account_id = %d LIMIT 1",
            (int) $account_id
        ), ARRAY_A );
    }

    /**
     * Activate a subscription after a successful initial payment.
     *
     * Idempotent — if a subscription row already exists for this
     * account, refresh it (status=active, push next_charge_at, clear
     * grace_until). This handles the case where a previously frozen
     * account pays again to reactivate.
     */
    public static function activate_from_payment( $payment_row, $recurring_id = '' ) {
        if ( ! is_array( $payment_row ) ) return null;
        $account_id   = (int) ( $payment_row['account_id'] ?? 0 );
        if ( ! $account_id ) return null;

        global $wpdb;
        $t = CMMS_DB::table( 'subscriptions' );
        $existing = self::for_account( $account_id );
        $now = current_time( 'mysql' );
        $next_charge = self::compute_next_charge( $payment_row['billing_cycle'] ?? 'monthly', $now );

        $data = array(
            'account_id'      => $account_id,
            'package_id'      => isset( $payment_row['package_id'] ) ? (int) $payment_row['package_id'] : null,
            'plan_type'       => $payment_row['plan_type'] ?? null,
            'billing_cycle'   => $payment_row['billing_cycle'] ?? null,
            'amount'          => (float) ( $payment_row['amount'] ?? 0 ),
            'currency'        => $payment_row['currency'] ?? 'ILS',
            'provider'        => 'icredit',
            'status'          => 'active',
            'last_charge_at'  => $now,
            'next_charge_at'  => $next_charge,
            'grace_until'     => null,
            'updated_at'      => $now,
        );

        // 1.14.56: Store iCredit's RecurringId on the subscription so we
        // can later call RecurringSaleCancel. external_subscription_id
        // is the generic provider-agnostic field. Only set if we have
        // a non-empty value — otherwise leave whatever was there.
        if ( ! empty( $recurring_id ) ) {
            $data['external_subscription_id'] = $recurring_id;
        }

        if ( $existing ) {
            // Reactivation: bump charges + clear failure fields.
            $data['last_failure_at'] = null;
            $wpdb->update( $t, $data, array( 'id' => (int) $existing['id'] ) );
            $sub_id = (int) $existing['id'];
        } else {
            $data['started_at'] = $now;
            $data['created_at'] = $now;
            $wpdb->insert( $t, $data );
            $sub_id = (int) $wpdb->insert_id;
        }

        self::sync_account_status( $account_id, 'active' );
        return $sub_id;
    }

    /**
     * Record a successful recurring charge (iCredit sent IPN for it).
     * Pushes next_charge_at forward, clears grace_until if it was set.
     */
    public static function record_recurring_success( $account_id, $payment_row ) {
        $sub = self::for_account( $account_id );
        if ( ! $sub ) return;

        global $wpdb;
        $t = CMMS_DB::table( 'subscriptions' );
        $now = current_time( 'mysql' );
        $next_charge = self::compute_next_charge( $sub['billing_cycle'] ?? 'monthly', $now );

        $wpdb->update( $t, array(
            'status'          => 'active',
            'last_charge_at'  => $now,
            'next_charge_at'  => $next_charge,
            'last_failure_at' => null,
            'grace_until'     => null,
            'updated_at'      => $now,
        ), array( 'id' => (int) $sub['id'] ) );

        self::sync_account_status( (int) $account_id, 'active' );
    }

    /**
     * Record a failed recurring charge. Moves the subscription to
     * past_due and sets grace_until = now + GRACE_DAYS. The cron job
     * later flips it to frozen when grace expires.
     */
    public static function record_recurring_failure( $account_id, $reason = '' ) {
        $sub = self::for_account( $account_id );
        if ( ! $sub ) return;

        // If already past_due, don't extend the grace period — we want
        // the original 7-day clock to keep ticking.
        if ( $sub['status'] === 'past_due' && ! empty( $sub['grace_until'] ) ) {
            return;
        }

        global $wpdb;
        $t = CMMS_DB::table( 'subscriptions' );
        $now = current_time( 'mysql' );
        $grace_until = gmdate( 'Y-m-d H:i:s', time() + ( self::GRACE_DAYS * DAY_IN_SECONDS ) );

        $wpdb->update( $t, array(
            'status'          => 'past_due',
            'last_failure_at' => $now,
            'grace_until'     => $grace_until,
            'updated_at'      => $now,
        ), array( 'id' => (int) $sub['id'] ) );

        self::sync_account_status( (int) $account_id, 'past_due' );
    }

    /**
     * Daily cron job: find past_due subscriptions whose grace period
     * expired, and freeze them. After freeze, the dashboard shows a
     * blocking screen and the user can pay again to reactivate.
     */
    public static function process_grace_expirations() {
        global $wpdb;
        $t = CMMS_DB::table( 'subscriptions' );
        if ( ! $t ) return;
        $now = current_time( 'mysql' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, account_id FROM $t
              WHERE status = 'past_due'
                AND grace_until IS NOT NULL
                AND grace_until <= %s",
            $now
        ), ARRAY_A );

        foreach ( $rows as $row ) {
            $wpdb->update( $t, array(
                'status'     => 'frozen',
                'updated_at' => $now,
            ), array( 'id' => (int) $row['id'] ) );
            self::sync_account_status( (int) $row['account_id'], 'frozen' );
        }
    }

    /**
     * Cancel a subscription (admin action or user request).
     * Keeps the row for history — does NOT delete.
     */
    public static function cancel( $account_id, $reason = '' ) {
        $sub = self::for_account( $account_id );
        if ( ! $sub ) return;

        global $wpdb;
        $t = CMMS_DB::table( 'subscriptions' );
        $now = current_time( 'mysql' );
        $wpdb->update( $t, array(
            'status'              => 'canceled',
            'canceled_at'         => $now,
            'cancellation_reason' => substr( (string) $reason, 0, 255 ),
            'updated_at'          => $now,
        ), array( 'id' => (int) $sub['id'] ) );
        self::sync_account_status( (int) $account_id, 'canceled' );
    }

    /**
     * Mirror subscription status onto account.subscription_status so
     * existing code (dashboard checks, AJAX gates) keeps working
     * without needing to learn about subscriptions table.
     */
    private static function sync_account_status( $account_id, $status ) {
        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $wpdb->update( $accounts_t, array(
            'subscription_status' => $status,
        ), array( 'id' => (int) $account_id ) );
    }

    /** Add 1 month or 1 year to a base date depending on cycle. */
    private static function compute_next_charge( $billing_cycle, $base_mysql ) {
        $ts = strtotime( $base_mysql ?: 'now' );
        if ( ! $ts ) $ts = time();
        $offset = ( $billing_cycle === 'yearly' ) ? '+1 year' : '+1 month';
        $next = strtotime( $offset, $ts );
        return gmdate( 'Y-m-d H:i:s', $next );
    }
}
