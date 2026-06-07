<?php
/**
 * CMMS Seats — Seat management service (1.14.71).
 *
 * Purpose:
 *   The single source of truth for "how many users does an account have,
 *   how many is it allowed, and how do we change that?"
 *
 * Architecture:
 *   - cmms_subscriptions.seats_purchased is the source of truth for
 *     extra seats beyond the package's included_seats.
 *   - cmms_accounts.max_users is the materialised view — it reflects
 *     (included_seats + seats_purchased). It exists so the cheap
 *     enforcement check in within_user_limit() can do a single SELECT
 *     without joining tables every time a user is created.
 *   - When subscription.seats_purchased changes, accounts.max_users
 *     must be kept in sync via sync_account_max_users().
 *
 * Phase 1 scope (1.14.71):
 *   - Read methods (status display): get_account_seats, can_add_user,
 *     calculate_seat_addon_charge.
 *   - Super Admin write methods: add_seats_admin, remove_seats_admin.
 *     These DO NOT touch iCredit recurring — they only mutate the DB
 *     and rely on manual billing for now. Phase 3 will wire them to
 *     automatic recurring updates.
 *
 * Stability rules:
 *   - All DB writes are guarded by capability + nonce in the caller.
 *   - All numeric inputs are cast and validated.
 *   - Methods return WP_Error on failure (never throw exceptions).
 *   - No method here calls into iCredit — that's a Phase 3 concern.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Seats {

    /**
     * Get a complete seat snapshot for an account.
     *
     * Returns an associative array:
     *   array(
     *     'included'        => int|null,    // from package
     *     'purchased'       => int,         // from subscription
     *     'total_allowed'   => int|null,    // included + purchased
     *     'currently_used'  => int,         // active + invited users
     *     'available'       => int|null,    // total_allowed - currently_used
     *     'hard_limit'      => int|null,    // package ceiling
     *     'plan_type'       => string|null,
     *     'billing_cycle'   => string|null,
     *     'seat_price'      => float|null,
     *     'upgrade_recommended' => bool,    // currently_used / hard_limit >= threshold
     *     'at_hard_limit'   => bool,        // total_allowed >= hard_limit
     *   )
     *
     * If the account has no subscription or no plan_type, returns a
     * "legacy" snapshot with null values — callers should display
     * "unmanaged" in that case.
     */
    public static function get_account_seats( $account_id ) {
        $account_id = (int) $account_id;
        if ( $account_id <= 0 ) return self::empty_snapshot();

        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $users_t    = CMMS_DB::table( 'users' );
        $subs_t     = CMMS_DB::table( 'subscriptions' );

        $account = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, plan_type, billing_cycle, max_users
               FROM $accounts_t WHERE id = %d LIMIT 1",
            $account_id
        ), ARRAY_A );
        if ( ! $account ) return self::empty_snapshot();

        $plan  = $account['plan_type'];
        $cycle = $account['billing_cycle'] ?: 'monthly';

        // Subscription (may not exist for very old / manually-created accounts).
        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, seats_purchased, pending_seats_change
               FROM $subs_t WHERE account_id = %d LIMIT 1",
            $account_id
        ), ARRAY_A );

        // Current user count (active + invited only; suspended don't count).
        $currently_used = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $users_t
              WHERE account_id = %d
                AND status IN ('active','invited')",
            $account_id
        ) );

        $included   = $plan ? CMMS_Plans::included_seats( $plan, $cycle )    : null;
        $hard_limit = $plan ? CMMS_Plans::hard_user_limit( $plan, $cycle )   : null;
        $seat_price = $plan ? CMMS_Plans::seat_addon_price( $plan, $cycle )  : null;
        $rec_at     = $plan ? CMMS_Plans::upgrade_recommended_at( $plan, $cycle ) : null;

        $purchased = ( $sub && isset( $sub['seats_purchased'] ) ) ? (int) $sub['seats_purchased'] : 0;
        $pending_change = ( $sub && $sub['pending_seats_change'] !== null && $sub['pending_seats_change'] !== '' )
            ? (int) $sub['pending_seats_change'] : 0;

        // Total allowed:
        //  - If we know included_seats, total_allowed = included + purchased
        //  - Otherwise fall back to accounts.max_users (legacy / Enterprise)
        if ( $included !== null ) {
            $total_allowed = $included + $purchased;
        } elseif ( $account['max_users'] !== null && $account['max_users'] !== '' ) {
            $total_allowed = (int) $account['max_users'];
        } else {
            $total_allowed = null; // unlimited
        }

        $available = ( $total_allowed === null ) ? null : max( 0, $total_allowed - $currently_used );

        // upgrade_recommended: only meaningful when we have both
        // currently_used and a hard_limit, and the threshold was configured.
        $upgrade_recommended = false;
        if ( $hard_limit !== null && $hard_limit > 0 && $rec_at !== null && $rec_at > 0 ) {
            $pct = ( $currently_used / $hard_limit ) * 100;
            if ( $pct >= $rec_at ) $upgrade_recommended = true;
        }

        $at_hard_limit = false;
        if ( $hard_limit !== null && $total_allowed !== null ) {
            $at_hard_limit = ( $total_allowed >= $hard_limit );
        }

        return array(
            'included'              => $included,
            'purchased'             => $purchased,
            'pending_change'        => $pending_change,
            'total_allowed'         => $total_allowed,
            'currently_used'        => $currently_used,
            'available'             => $available,
            'hard_limit'            => $hard_limit,
            'plan_type'             => $plan,
            'billing_cycle'         => $cycle,
            'seat_price'            => $seat_price,
            'upgrade_recommended'   => $upgrade_recommended,
            'at_hard_limit'         => $at_hard_limit,
            'is_managed'            => ( $included !== null ), // false for Enterprise / legacy
        );
    }

    /**
     * Fast check: is this account allowed to add ONE more user right now?
     *
     * Used by the user-creation flow (AJAX) to gate inserts. Equivalent
     * to "available > 0", but with explicit handling for unlimited
     * (NULL total_allowed → always true).
     */
    public static function can_add_user( $account_id ) {
        $s = self::get_account_seats( $account_id );
        if ( $s['total_allowed'] === null ) return true; // unlimited
        return $s['currently_used'] < $s['total_allowed'];
    }

    /**
     * Super Admin: add N seats to an account immediately.
     *
     * Updates:
     *   - subscriptions.seats_purchased += $count
     *   - subscriptions.seats_amount    = seats_purchased * seat_addon_price
     *   - accounts.max_users            = included + new seats_purchased
     *
     * Does NOT touch iCredit. Does NOT generate a payment row. Phase 1
     * model assumes Super Admin handles invoicing manually after the
     * customer agrees to the change.
     *
     * Returns:
     *   array on success: array( 'seats_purchased' => int, 'max_users' => int, 'seats_amount' => float )
     *   WP_Error on failure (no subscription, missing plan, hard limit exceeded, etc.)
     */
    public static function add_seats_admin( $account_id, $count ) {
        $account_id = (int) $account_id;
        $count      = (int) $count;
        if ( $account_id <= 0 ) return new WP_Error( 'invalid_account', 'Invalid account id.' );
        if ( $count <= 0 )      return new WP_Error( 'invalid_count', 'Seat count must be > 0.' );

        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $subs_t     = CMMS_DB::table( 'subscriptions' );

        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, a.plan_type AS acc_plan, a.billing_cycle AS acc_cycle
               FROM $subs_t s
               LEFT JOIN $accounts_t a ON a.id = s.account_id
              WHERE s.account_id = %d
              LIMIT 1",
            $account_id
        ), ARRAY_A );

        if ( ! $sub ) {
            return new WP_Error(
                'no_subscription',
                'לחשבון זה אין מנוי פעיל. יש ליצור מנוי לפני הוספת משתמשים.'
            );
        }

        $plan       = $sub['plan_type'] ?: $sub['acc_plan'];
        $cycle      = $sub['billing_cycle'] ?: ( $sub['acc_cycle'] ?: 'monthly' );
        $included   = CMMS_Plans::included_seats( $plan, $cycle );
        $hard_limit = CMMS_Plans::hard_user_limit( $plan, $cycle );
        $seat_price = CMMS_Plans::seat_addon_price( $plan, $cycle );

        if ( $included === null ) {
            return new WP_Error(
                'unmanaged_plan',
                'חבילה זו (Enterprise / מותאמת אישית) אינה תומכת בהוספת משתמשים אוטומטית. יש לעדכן את החשבון ידנית.'
            );
        }

        $current_purchased = (int) ( $sub['seats_purchased'] ?? 0 );
        $new_purchased     = $current_purchased + $count;
        $new_total_allowed = $included + $new_purchased;

        // Block above hard_user_limit. Super Admin can change the
        // package on the account first if they want to exceed it.
        if ( $hard_limit !== null && $new_total_allowed > $hard_limit ) {
            return new WP_Error(
                'hard_limit_exceeded',
                sprintf(
                    'הוספה זו תחרוג מגג המשתמשים של החבילה (%d). לעלייה גבוהה יותר יש לשדרג חבילה.',
                    $hard_limit
                )
            );
        }

        $new_seats_amount = ( $seat_price !== null ) ? $new_purchased * (float) $seat_price : 0.0;

        // Update subscription.
        $wpdb->update(
            $subs_t,
            array(
                'seats_purchased' => $new_purchased,
                'seats_amount'    => $new_seats_amount,
                'updated_at'      => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $sub['id'] ),
            array( '%d', '%f', '%s' ),
            array( '%d' )
        );

        // Sync the materialised max_users on accounts.
        self::sync_account_max_users( $account_id );

        return array(
            'seats_purchased' => $new_purchased,
            'max_users'       => $new_total_allowed,
            'seats_amount'    => $new_seats_amount,
        );
    }

    /**
     * Super Admin: schedule seat removal at the next billing cycle.
     *
     * Phase 1 model: removals are NEVER immediate (no refunds policy).
     * We record pending_seats_change = -$count on the subscription so
     * the recurring update at next_charge_at can decrement seats then.
     *
     * Phase 3 (cron) will pick up the pending_seats_change and apply it.
     * For now this only records the intent — admin can clear it later
     * if customer changes their mind.
     */
    public static function remove_seats_admin( $account_id, $count ) {
        $account_id = (int) $account_id;
        $count      = (int) $count;
        if ( $account_id <= 0 ) return new WP_Error( 'invalid_account', 'Invalid account id.' );
        if ( $count <= 0 )      return new WP_Error( 'invalid_count', 'Seat count must be > 0.' );

        global $wpdb;
        $subs_t = CMMS_DB::table( 'subscriptions' );

        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, seats_purchased FROM $subs_t WHERE account_id = %d LIMIT 1",
            $account_id
        ), ARRAY_A );
        if ( ! $sub ) return new WP_Error( 'no_subscription', 'לחשבון זה אין מנוי פעיל.' );

        $current = (int) $sub['seats_purchased'];
        if ( $count > $current ) {
            return new WP_Error(
                'too_many',
                sprintf( 'לא ניתן להסיר %d משתמשים — לחשבון יש רק %d משתמשים נרכשים.', $count, $current )
            );
        }

        // Schedule (negative number = pending removal).
        $wpdb->update(
            $subs_t,
            array(
                'pending_seats_change' => -1 * $count,
                'updated_at'           => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $sub['id'] ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        return array(
            'scheduled_removal' => $count,
            'effective'         => 'next_billing_cycle',
        );
    }

    /**
     * Clear a scheduled removal (admin "oops" recovery).
     */
    public static function cancel_scheduled_change( $account_id ) {
        $account_id = (int) $account_id;
        if ( $account_id <= 0 ) return new WP_Error( 'invalid_account', 'Invalid account id.' );

        global $wpdb;
        $subs_t = CMMS_DB::table( 'subscriptions' );
        $wpdb->update(
            $subs_t,
            array(
                'pending_seats_change' => null,
                'updated_at'           => current_time( 'mysql' ),
            ),
            array( 'account_id' => $account_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
        return true;
    }

    /**
     * Compute what a proration charge for $count new seats would cost
     * (one-time, until the next renewal). Used to SHOW the number to
     * Super Admin / customer — does NOT charge anything.
     *
     * Monthly model: prorate from today until next_charge_at.
     * Yearly  model: same — days remaining × per-day cost.
     *
     * Returns an array suitable for display:
     *   array(
     *     'amount'           => float,   // ₪
     *     'days_remaining'   => int,
     *     'days_in_cycle'    => int,
     *     'per_day_per_seat' => float,
     *     'seat_count'       => int,
     *     'currency'         => 'ILS',
     *   )
     * Or WP_Error if we can't compute.
     */
    public static function calculate_seat_addon_charge( $account_id, $count ) {
        $account_id = (int) $account_id;
        $count      = (int) $count;
        if ( $count <= 0 ) return new WP_Error( 'invalid_count', 'Seat count must be > 0.' );

        $s = self::get_account_seats( $account_id );
        if ( ! $s['is_managed'] ) {
            return new WP_Error( 'unmanaged_plan', 'חבילה אינה תומכת בהוספת משתמשים אוטומטית.' );
        }
        if ( $s['seat_price'] === null ) {
            return new WP_Error( 'no_seat_price', 'מחיר משתמש נוסף לא מוגדר לחבילה זו.' );
        }

        global $wpdb;
        $subs_t = CMMS_DB::table( 'subscriptions' );
        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT last_charge_at, next_charge_at, billing_cycle
               FROM $subs_t WHERE account_id = %d LIMIT 1",
            $account_id
        ), ARRAY_A );

        $cycle = $sub['billing_cycle'] ?? $s['billing_cycle'];
        $days_in_cycle = ( $cycle === 'yearly' ) ? 365 : 30;

        // Days remaining: prefer real next_charge_at - today.
        $now_ts = current_time( 'timestamp' );
        $days_remaining = null;
        if ( ! empty( $sub['next_charge_at'] ) ) {
            $next_ts = strtotime( $sub['next_charge_at'] );
            if ( $next_ts && $next_ts > $now_ts ) {
                $days_remaining = max( 1, (int) ceil( ( $next_ts - $now_ts ) / DAY_IN_SECONDS ) );
            }
        }
        // Fallback: assume full cycle (so prorating becomes a no-op price-wise).
        if ( $days_remaining === null ) {
            $days_remaining = $days_in_cycle;
        }

        $seat_price_per_cycle = (float) $s['seat_price'];
        $per_day_per_seat     = $seat_price_per_cycle / $days_in_cycle;
        $amount               = round( $per_day_per_seat * $days_remaining * $count, 2 );

        return array(
            'amount'              => $amount,
            'days_remaining'      => $days_remaining,
            'days_in_cycle'       => $days_in_cycle,
            'per_day_per_seat'    => round( $per_day_per_seat, 4 ),
            'seat_count'          => $count,
            'currency'            => 'ILS',
            'seat_price_per_cycle'=> $seat_price_per_cycle,
        );
    }

    /**
     * 1.14.74: Apply a paid seat add-on to an account.
     *
     * Called by the IPN handler when a payment with charge_type='seat_addon'
     * is approved. This is the self-service equivalent of add_seats_admin —
     * but it's the OUTCOME of a successful customer payment, not an admin
     * action, so it does NOT need extra validation or hard-limit checks
     * (those were done earlier when the prorated charge was created).
     *
     * Steps:
     *   1. Increase subscriptions.seats_purchased by $seats_added.
     *   2. Update subscriptions.seats_amount based on the new total.
     *   3. Set subscriptions.pending_seats_change = +N as a flag for
     *      Super Admin to know the recurring needs updating in iCredit.
     *   4. Sync accounts.max_users to the new total.
     *
     * The pending_seats_change field is documented as "negative number =
     * scheduled removal" in 1.14.71. We extend its semantics here:
     *   - Negative = scheduled removal (existing semantics)
     *   - Positive = paid addon that needs recurring update in iCredit
     *
     * Returns:
     *   array on success: ['seats_purchased' => N, 'max_users' => M]
     *   WP_Error if something is malformed (caller logs but doesn't fail
     *     the IPN — the customer already paid).
     */
    public static function apply_paid_addon( $account_id, $seats_added ) {
        $account_id  = (int) $account_id;
        $seats_added = (int) $seats_added;
        if ( $account_id <= 0 ) return new WP_Error( 'invalid_account', 'Invalid account id.' );
        if ( $seats_added <= 0 ) return new WP_Error( 'invalid_count', 'Seats added must be > 0.' );

        global $wpdb;
        $subs_t = CMMS_DB::table( 'subscriptions' );

        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, a.plan_type AS acc_plan, a.billing_cycle AS acc_cycle
               FROM $subs_t s
               LEFT JOIN " . CMMS_DB::table( 'accounts' ) . " a ON a.id = s.account_id
              WHERE s.account_id = %d LIMIT 1",
            $account_id
        ), ARRAY_A );

        if ( ! $sub ) {
            return new WP_Error( 'no_subscription', 'לחשבון אין מנוי פעיל.' );
        }

        $plan       = $sub['plan_type'] ?: $sub['acc_plan'];
        $cycle      = $sub['billing_cycle'] ?: ( $sub['acc_cycle'] ?: 'monthly' );
        $seat_price = CMMS_Plans::seat_addon_price( $plan, $cycle );

        $current_purchased = (int) ( $sub['seats_purchased'] ?? 0 );
        $new_purchased     = $current_purchased + $seats_added;
        $new_seats_amount  = ( $seat_price !== null ) ? $new_purchased * (float) $seat_price : 0.0;

        // Sum the positive pending change. If admin already has a pending
        // recurring update queued (e.g. previous addon), add to it.
        $current_pending = (int) ( $sub['pending_seats_change'] ?? 0 );
        // Only accumulate if the existing pending is also positive
        // (i.e. another pending addon). Negative values = scheduled
        // removals get replaced by the addon, since the addon is now
        // larger and overrides the removal intent.
        $new_pending = ( $current_pending > 0 )
            ? $current_pending + $seats_added
            : $seats_added;

        $wpdb->update(
            $subs_t,
            array(
                'seats_purchased'      => $new_purchased,
                'seats_amount'         => $new_seats_amount,
                'pending_seats_change' => $new_pending,
                'updated_at'           => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $sub['id'] ),
            array( '%d', '%f', '%d', '%s' ),
            array( '%d' )
        );

        // Materialise the new max_users on accounts.
        self::sync_account_max_users( $account_id );

        return array(
            'seats_purchased' => $new_purchased,
            'max_users'       => $new_purchased + ( CMMS_Plans::included_seats( $plan, $cycle ) ?: 0 ),
        );
    }

    /**
     * Resync accounts.max_users from the current subscription state.
     * Called after add_seats_admin and during plan changes.
     *
     * Formula:
     *   max_users = included_seats + seats_purchased
     *   (NULL when the package is unlimited/Enterprise)
     */
    public static function sync_account_max_users( $account_id ) {
        $account_id = (int) $account_id;
        if ( $account_id <= 0 ) return false;

        global $wpdb;
        $accounts_t = CMMS_DB::table( 'accounts' );
        $subs_t     = CMMS_DB::table( 'subscriptions' );

        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT seats_purchased, plan_type, billing_cycle
               FROM $subs_t WHERE account_id = %d LIMIT 1",
            $account_id
        ), ARRAY_A );

        if ( ! $sub ) {
            // No subscription — leave accounts.max_users alone.
            return false;
        }

        $plan     = $sub['plan_type'];
        $cycle    = $sub['billing_cycle'] ?: 'monthly';
        $included = $plan ? CMMS_Plans::included_seats( $plan, $cycle ) : null;

        if ( $included === null ) {
            // Enterprise / custom — leave max_users as whatever Super
            // Admin set it to. Don't auto-zero unlimited accounts.
            return true;
        }

        $new_max = $included + (int) $sub['seats_purchased'];
        $wpdb->update(
            $accounts_t,
            array( 'max_users' => $new_max ),
            array( 'id' => $account_id ),
            array( '%d' ),
            array( '%d' )
        );

        return true;
    }

    /**
     * Used by display code when the account is too legacy/edge to
     * have meaningful seat data.
     */
    private static function empty_snapshot() {
        return array(
            'included'            => null,
            'purchased'           => 0,
            'pending_change'      => 0,
            'total_allowed'       => null,
            'currently_used'      => 0,
            'available'           => null,
            'hard_limit'          => null,
            'plan_type'           => null,
            'billing_cycle'       => null,
            'seat_price'          => null,
            'upgrade_recommended' => false,
            'at_hard_limit'       => false,
            'is_managed'          => false,
        );
    }
}
