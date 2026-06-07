<?php
/**
 * CMMS Plans / Packages catalog (1.14.34).
 *
 * Plans now live in the cmms_packages DB table, one row per
 * (plan_type, billing_cycle) combination. This class is a thin
 * read-layer over that table that the rest of the codebase calls.
 *
 * Architecture rationale:
 *   - Each plan tier (Starter/Business/Enterprise) maps to multiple
 *     packages — one per billing cycle. Pricing, max users, and the
 *     iCredit payment page id are per-package, so a Super Admin can
 *     wire a different iCredit page to each.
 *   - The public API of this class still talks "plans" because that's
 *     the user-facing concept on /start. all() returns 3 tier-shaped
 *     summaries; get_package() returns one specific (plan, cycle) row.
 *   - PHP fallback exists only for the edge case where the seed never
 *     ran or someone deleted all rows. Normal operation reads DB.
 *
 * Backward compatibility:
 *   - all(), get(), default_id(), default_cycle(), valid_ids(),
 *     valid_cycles(), yearly_savings_pct(), yearly_per_month() keep
 *     the same signatures so existing call sites (CMMS_Ajax,
 *     onboarding view) don't need to change.
 *   - Internally each call resolves through the new package store.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Plans {

    /** Plan tier ids. Used to group packages on the pricing page. */
    const STARTER    = 'starter';
    const BUSINESS   = 'business';
    const ENTERPRISE = 'enterprise';

    /** In-request cache so we hit the DB once per page load. */
    private static $packages_cache = null;

    /**
     * Reload the in-request cache. Call after writes (1.14.35 admin UI
     * will use this); not needed during normal page rendering.
     */
    public static function flush_cache() {
        self::$packages_cache = null;
    }

    /**
     * All packages as rows (assoc arrays), sorted by sort_order. Reads
     * from cmms_packages. On DB error or empty result, falls back to
     * the hardcoded PHP defaults so the pricing page never breaks.
     */
    public static function all_packages() {
        if ( self::$packages_cache !== null ) return self::$packages_cache;

        global $wpdb;
        $t = CMMS_DB::table( 'packages' );
        $rows = null;
        if ( $t ) {
            // Defensive: hide errors in case the table doesn't exist
            // yet (very first activation, before migrations ran).
            $prev = $wpdb->hide_errors();
            $rows = $wpdb->get_results( "SELECT * FROM $t ORDER BY sort_order ASC, id ASC", ARRAY_A );
            if ( $prev ) $wpdb->show_errors();
        }

        if ( empty( $rows ) ) {
            // DB empty or unavailable → fall back to hardcoded defaults.
            self::$packages_cache = self::fallback_packages();
            return self::$packages_cache;
        }

        // Normalize types — DB returns everything as strings.
        $out = array();
        foreach ( $rows as $row ) {
            $row['id']               = (int) $row['id'];
            $row['price']            = (float) $row['price'];
            $row['max_users']        = ( $row['max_users'] === null || $row['max_users'] === '' ) ? null : (int) $row['max_users'];

            // 1.14.71: seat fields. Stored as NULL for Enterprise/custom
            // packages, integers/decimals for Starter/Business. Cast
            // explicitly so downstream code can rely on the types.
            if ( array_key_exists( 'included_seats', $row ) ) {
                $row['included_seats'] = ( $row['included_seats'] === null || $row['included_seats'] === '' )
                    ? null : (int) $row['included_seats'];
            } else {
                $row['included_seats'] = null;
            }
            if ( array_key_exists( 'seat_addon_price', $row ) ) {
                $row['seat_addon_price'] = ( $row['seat_addon_price'] === null || $row['seat_addon_price'] === '' )
                    ? null : (float) $row['seat_addon_price'];
            } else {
                $row['seat_addon_price'] = null;
            }
            if ( array_key_exists( 'hard_user_limit', $row ) ) {
                $row['hard_user_limit'] = ( $row['hard_user_limit'] === null || $row['hard_user_limit'] === '' )
                    ? null : (int) $row['hard_user_limit'];
            } else {
                $row['hard_user_limit'] = null;
            }
            if ( array_key_exists( 'upgrade_recommended_at', $row ) ) {
                $row['upgrade_recommended_at'] = ( $row['upgrade_recommended_at'] === null || $row['upgrade_recommended_at'] === '' )
                    ? null : (int) $row['upgrade_recommended_at'];
            } else {
                $row['upgrade_recommended_at'] = null;
            }

            $row['is_active']        = (int) $row['is_active'];
            $row['show_on_pricing']  = (int) $row['show_on_pricing'];
            $row['manual_only']      = (int) $row['manual_only'];
            $row['recommended']      = (int) $row['recommended'];
            $row['custom_price']     = (int) $row['custom_price'];
            $row['sort_order']       = (int) $row['sort_order'];
            $row['features'] = self::decode_features( $row['features'] );
            $out[] = $row;
        }
        self::$packages_cache = $out;
        return self::$packages_cache;
    }

    /** Get one package by plan_type + billing_cycle, or null if missing. */
    public static function get_package( $plan_type, $billing_cycle ) {
        foreach ( self::all_packages() as $p ) {
            if ( $p['plan_type'] === $plan_type && $p['billing_cycle'] === $billing_cycle ) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Get all packages that should appear on /start, grouped by plan_type.
     * Each group keyed by billing_cycle.
     */
    public static function packages_grouped_for_pricing() {
        $out = array();
        foreach ( self::all_packages() as $p ) {
            if ( ! $p['is_active'] ) continue;
            if ( ! $p['show_on_pricing'] ) continue;
            // Enterprise stays visible even though manual_only=1, because
            // its CTA is "Contact us" — exactly the right experience.
            if ( $p['manual_only'] && $p['plan_type'] !== self::ENTERPRISE ) continue;
            $pt = $p['plan_type'];
            if ( ! isset( $out[ $pt ] ) ) $out[ $pt ] = array();
            $out[ $pt ][ $p['billing_cycle'] ] = $p;
        }
        return $out;
    }

    /* ============================================================
       Legacy API surface — preserved for existing callers.
       all() returns 3 tier-shaped entries (one per plan_type).
       Used by /start render, AJAX validation, summary card.
    ============================================================ */

    public static function all() {
        $grouped = self::packages_grouped_for_pricing();
        $out = array();
        foreach ( $grouped as $plan_type => $cycles ) {
            $base = isset( $cycles['monthly'] ) ? $cycles['monthly']
                  : ( isset( $cycles['yearly'] ) ? $cycles['yearly'] : null );
            if ( ! $base ) continue;
            $out[ $plan_type ] = array(
                'id'           => $plan_type,
                'name'         => $base['display_name'],
                'tagline'      => $base['tagline'] ?? '',
                'price_monthly'=> isset( $cycles['monthly'] ) ? $cycles['monthly']['price'] : 0,
                'price_yearly' => isset( $cycles['yearly'] )  ? $cycles['yearly']['price']  : 0,
                'max_users'    => $base['max_users'],
                'recommended'  => (bool) $base['recommended'],
                'custom_price' => (bool) $base['custom_price'],
                'features'     => is_array( $base['features'] ) ? $base['features'] : array(),
            );
        }
        return $out;
    }

    public static function get( $plan_id ) {
        $all = self::all();
        return isset( $all[ $plan_id ] ) ? $all[ $plan_id ] : null;
    }

    public static function default_id()    { return self::BUSINESS; }
    public static function default_cycle() { return 'monthly'; }

    public static function valid_ids() {
        $ids = array();
        foreach ( self::all() as $plan_type => $plan ) $ids[] = $plan_type;
        // Always include the three standard tiers as a safety net.
        return array_values( array_unique( array_merge(
            array( self::STARTER, self::BUSINESS, self::ENTERPRISE ),
            $ids
        ) ) );
    }
    public static function valid_cycles() { return array( 'monthly', 'yearly' ); }

    public static function yearly_savings_pct( $plan_id ) {
        $p = self::get( $plan_id );
        if ( ! $p || $p['custom_price'] ) return 0;
        $monthly_total = $p['price_monthly'] * 12;
        if ( $monthly_total <= 0 ) return 0;
        $savings = $monthly_total - $p['price_yearly'];
        return (int) round( ( $savings / $monthly_total ) * 100 );
    }

    public static function yearly_per_month( $plan_id ) {
        $p = self::get( $plan_id );
        if ( ! $p ) return 0;
        return (int) round( $p['price_yearly'] / 12 );
    }

    /* ============================================================
       1.14.71 — Seat helpers

       All methods take ($plan_type, $billing_cycle) and read directly
       from the package row. Returns NULL when the package has no seat
       configuration (Enterprise / custom packages).
    ============================================================ */

    /**
     * How many users are included in the base package price.
     *
     * Returns:
     *   int  — Starter=3, Business=10 by default
     *   null — Enterprise (custom / unlimited)
     */
    public static function included_seats( $plan_type, $billing_cycle = 'monthly' ) {
        $pkg = self::get_package( $plan_type, $billing_cycle );
        if ( ! $pkg ) return null;
        return ( $pkg['included_seats'] !== null && $pkg['included_seats'] !== '' )
            ? (int) $pkg['included_seats']
            : null;
    }

    /**
     * Price per additional seat for this package & cycle.
     *
     * Monthly returns the monthly price (e.g. 50 → ₪50/month per seat).
     * Yearly  returns the yearly price (i.e. seat_addon_price as stored,
     *                                    which is configured as the
     *                                    yearly-equivalent — Super Admin
     *                                    sets this explicitly per row).
     *
     * Returns null if seats aren't supported (Enterprise / custom).
     */
    public static function seat_addon_price( $plan_type, $billing_cycle = 'monthly' ) {
        $pkg = self::get_package( $plan_type, $billing_cycle );
        if ( ! $pkg ) return null;
        return ( $pkg['seat_addon_price'] !== null && $pkg['seat_addon_price'] !== '' )
            ? (float) $pkg['seat_addon_price']
            : null;
    }

    /**
     * Hard ceiling for this package — above this, customer must upgrade.
     *
     * Returns:
     *   int  — Starter=10, Business=25 by default
     *   null — Enterprise (no ceiling)
     */
    public static function hard_user_limit( $plan_type, $billing_cycle = 'monthly' ) {
        $pkg = self::get_package( $plan_type, $billing_cycle );
        if ( ! $pkg ) return null;
        return ( $pkg['hard_user_limit'] !== null && $pkg['hard_user_limit'] !== '' )
            ? (int) $pkg['hard_user_limit']
            : null;
    }

    /**
     * Percent of hard_user_limit at which to show "upgrade recommended".
     * E.g. 80 → suggest upgrade once user count reaches 80% of the ceiling.
     *
     * Returns null when not configured (Enterprise / disabled).
     */
    public static function upgrade_recommended_at( $plan_type, $billing_cycle = 'monthly' ) {
        $pkg = self::get_package( $plan_type, $billing_cycle );
        if ( ! $pkg ) return null;
        return ( $pkg['upgrade_recommended_at'] !== null && $pkg['upgrade_recommended_at'] !== '' )
            ? (int) $pkg['upgrade_recommended_at']
            : null;
    }

    /**
     * Total price for $total_users on a given plan.
     *
     *   total_users <= included_seats   → just the base price
     *   total_users  > included_seats   → base + (extras × seat price)
     *
     * If the package doesn't support seats (Enterprise / custom price),
     * returns the base price unchanged.
     */
    public static function calculate_total_price( $plan_type, $billing_cycle, $total_users ) {
        $pkg = self::get_package( $plan_type, $billing_cycle );
        if ( ! $pkg ) return 0.0;
        $base       = (float) $pkg['price'];
        $included   = ( $pkg['included_seats'] !== null )   ? (int) $pkg['included_seats']     : null;
        $seat_price = ( $pkg['seat_addon_price'] !== null ) ? (float) $pkg['seat_addon_price'] : null;

        if ( $included === null || $seat_price === null ) {
            return $base;
        }

        $extras = max( 0, (int) $total_users - $included );
        return $base + ( $extras * $seat_price );
    }

    /**
     * Whether $seats_to_add can be added to an account currently using
     * $current_users on $plan_type. Returns boolean.
     *
     * Logic: current + seats_to_add must be ≤ hard_user_limit.
     * NULL hard_user_limit (Enterprise) means unlimited → always true.
     */
    public static function is_seats_addable( $plan_type, $current_users, $seats_to_add, $billing_cycle = 'monthly' ) {
        $limit = self::hard_user_limit( $plan_type, $billing_cycle );
        if ( $limit === null ) return true;
        return ( (int) $current_users + (int) $seats_to_add ) <= (int) $limit;
    }

    /* ============================================================
       Internals
    ============================================================ */

    /**
     * Tolerant decoder for the features column. Supports JSON
     * (preferred), serialized arrays (legacy), and newline/comma
     * separated strings (manual edits via phpMyAdmin).
     */
    private static function decode_features( $raw ) {
        if ( empty( $raw ) ) return array();
        if ( is_array( $raw ) ) return array_values( array_filter( array_map( 'strval', $raw ) ) );
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) return array_values( array_filter( array_map( 'strval', $decoded ) ) );
        if ( is_string( $raw ) && strpos( $raw, 'a:' ) === 0 ) {
            $u = @unserialize( $raw );
            if ( is_array( $u ) ) return array_values( array_filter( array_map( 'strval', $u ) ) );
        }
        $parts = preg_split( '/[\r\n,]+/', (string) $raw );
        return array_values( array_filter( array_map( 'trim', $parts ) ) );
    }

    /**
     * Hardcoded PHP fallback for the rare case where the packages
     * table is missing or empty. Mirrors the seed in the activator.
     *
     * 1.14.71: includes seat fields (included_seats, seat_addon_price,
     * hard_user_limit, upgrade_recommended_at).
     */
    private static function fallback_packages() {
        $now = current_time( 'mysql' );
        $defaults = array(
            array( 'plan_type' => 'starter',    'billing_cycle' => 'monthly', 'price' => 290,   'max_users' => 3,    'included' => 3,    'seat_price' => 50,   'hard_limit' => 10,   'rec_at' => 80,   'recommended' => 0, 'custom_price' => 0, 'sort_order' => 10 ),
            array( 'plan_type' => 'starter',    'billing_cycle' => 'yearly',  'price' => 2990,  'max_users' => 3,    'included' => 3,    'seat_price' => 50,   'hard_limit' => 10,   'rec_at' => 80,   'recommended' => 0, 'custom_price' => 0, 'sort_order' => 11 ),
            array( 'plan_type' => 'business',   'billing_cycle' => 'monthly', 'price' => 690,   'max_users' => 10,   'included' => 10,   'seat_price' => 50,   'hard_limit' => 25,   'rec_at' => 80,   'recommended' => 1, 'custom_price' => 0, 'sort_order' => 20 ),
            array( 'plan_type' => 'business',   'billing_cycle' => 'yearly',  'price' => 6900,  'max_users' => 10,   'included' => 10,   'seat_price' => 50,   'hard_limit' => 25,   'rec_at' => 80,   'recommended' => 1, 'custom_price' => 0, 'sort_order' => 21 ),
            array( 'plan_type' => 'enterprise', 'billing_cycle' => 'monthly', 'price' => 1490,  'max_users' => null, 'included' => null, 'seat_price' => null, 'hard_limit' => null, 'rec_at' => null, 'recommended' => 0, 'custom_price' => 1, 'sort_order' => 30 ),
            array( 'plan_type' => 'enterprise', 'billing_cycle' => 'yearly',  'price' => 14900, 'max_users' => null, 'included' => null, 'seat_price' => null, 'hard_limit' => null, 'rec_at' => null, 'recommended' => 0, 'custom_price' => 1, 'sort_order' => 31 ),
        );
        $taglines = array(
            'starter'    => 'מתאים לעסקים קטנים שמתחילים עם CMMS',
            'business'   => 'הבחירה הנפוצה — לעסקים שצריכים אחזקה מסודרת',
            'enterprise' => 'לארגונים גדולים, מספר אתרים, ודרישות מותאמות',
        );
        $display = array(
            'starter'    => 'Starter',
            'business'   => 'Business',
            'enterprise' => 'Enterprise',
        );
        $features = array(
            'starter'    => array( 'עד 3 משתמשים במערכת', 'משימות וקריאות שירות', 'דיווח תקלות דרך QR', 'טפסים ציבוריים', 'אפליקציית מובייל', 'התראות בסיסיות' ),
            'business'   => array( 'עד 10 משתמשים במערכת', 'אחזקה מונעת ומתוכננת', 'דוחות KPI ומעקב ביצועים', 'ניהול נכסים ומכונות', 'הרשאות לפי תפקיד', 'מרכז הדרכה', 'דוחות מתקדמים', 'התראות מתקדמות' ),
            'enterprise' => array( 'משתמשים ללא הגבלה', 'מספר אתרים וסניפים', 'SLA והגדרות מתקדמות', 'הרשאות מורכבות', 'API ואינטגרציות (בקרוב)', 'הטמעה מלווה', 'תמיכה מועדפת' ),
        );
        $out = array();
        $id = 0;
        foreach ( $defaults as $d ) {
            $pt = $d['plan_type'];
            $out[] = array(
                'id'                   => ++$id,
                'internal_name'        => $pt . '_' . $d['billing_cycle'],
                'display_name'         => $display[ $pt ],
                'plan_type'            => $pt,
                'billing_cycle'        => $d['billing_cycle'],
                'price'                => (float) $d['price'],
                'currency'             => 'ILS',
                'max_users'            => $d['max_users'],
                'included_seats'       => $d['included'],
                'seat_addon_price'     => $d['seat_price'],
                'hard_user_limit'      => $d['hard_limit'],
                'upgrade_recommended_at' => $d['rec_at'],
                'billing_mode'         => $pt === 'enterprise' ? 'manual' : 'recurring',
                'icredit_page_id'      => null,
                'external_payment_reference' => null,
                'success_redirect_url' => null,
                'failure_redirect_url' => null,
                'cancel_redirect_url'  => null,
                'is_active'            => 1,
                'show_on_pricing'      => 1,
                'manual_only'          => $pt === 'enterprise' ? 1 : 0,
                'recommended'          => $d['recommended'],
                'custom_price'         => $d['custom_price'],
                'sort_order'           => $d['sort_order'],
                'tagline'              => $taglines[ $pt ],
                'features'             => $features[ $pt ],
                'created_at'           => $now,
                'updated_at'           => $now,
            );
        }
        return $out;
    }
}
