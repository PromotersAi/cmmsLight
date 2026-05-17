<?php
/**
 * CMMS Assets — DAL for the assets table + custom-field machinery.
 *
 * An "asset" in this system is any business object the customer wants to
 * track maintenance against:
 *  - hotel room
 *  - factory machine
 *  - electrical cabinet
 *  - vehicle
 *  - branch office
 *
 * Customers therefore need to extend the asset record with their own
 * fields ("custom fields"). We support this with two pieces:
 *
 *  1. Field DEFINITIONS in `wp_cmms_asset_field_defs` — one row per
 *     (account, field). Defines label, type, validation rules.
 *
 *  2. Field VALUES in the asset's `custom_fields` JSON column. Each entry
 *     looks like:
 *         { "field_key": "floor", "label": "Floor", "type": "number", "value": 3 }
 *     The label/type are denormalized into the value for safety: if the
 *     admin renames a field later, old values keep their original label.
 *
 * Why JSON now and not a real values table:
 *  - Faster to ship.
 *  - Existing CRUD paths only need one INSERT/UPDATE per asset.
 *  - Public-view rendering is one DB hit.
 * Migration to a normalized values table is straightforward later because
 * the JSON has a stable, structured shape.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Assets {

    /* ===================================================================
     * Asset types — extensible, per-account override allowed in the future.
     * For now this is a hard-coded list of the most common business shapes.
     * ===================================================================*/
    public static function asset_types() {
        return array(
            'machine'   => __( 'Machine', 'cmms-light' ),
            'equipment' => __( 'Equipment', 'cmms-light' ),
            'room'      => __( 'Room', 'cmms-light' ),
            'location'  => __( 'Location', 'cmms-light' ),
            'vehicle'   => __( 'Vehicle', 'cmms-light' ),
            'branch'    => __( 'Branch', 'cmms-light' ),
            'cabinet'   => __( 'Cabinet', 'cmms-light' ),
            'other'     => __( 'Other', 'cmms-light' ),
        );
    }

    /* ===================================================================
     * Allowed public actions when QR is scanned by a non-logged-in user.
     * Admin can flip these per asset (or per account default).
     * ===================================================================*/
    public static function public_actions_catalog() {
        return array(
            'report_breakdown' => __( 'Report a breakdown', 'cmms-light' ),
            'upload_photo'     => __( 'Upload a photo', 'cmms-light' ),
            'submit_form'      => __( 'Submit a public form', 'cmms-light' ),
        );
    }

    public static function default_public_actions() {
        return array( 'report_breakdown', 'upload_photo' );
    }

    /* ===================================================================
     * Field definitions (per-account)
     * ===================================================================*/
    public static function field_def_types() {
        return array(
            'text'     => __( 'Text', 'cmms-light' ),
            'textarea' => __( 'Long text', 'cmms-light' ),
            'number'   => __( 'Number', 'cmms-light' ),
            'date'     => __( 'Date', 'cmms-light' ),
            'select'   => __( 'Dropdown', 'cmms-light' ),
            'checkbox' => __( 'Checkbox', 'cmms-light' ),
        );
    }

    /**
     * Return all field definitions for an account.
     *
     * Note: Earlier (1.11.0) we supported scoping fields to an asset_type.
     * Per spec, scoping was removed in 1.12.0 — all custom fields apply to
     * every asset regardless of type. The asset_type column on the def
     * table is kept for forward compatibility but ignored here.
     *
     * @param int    $account_id  Tenant scope.
     * @param string $asset_type  IGNORED. Kept in signature so callers don't break.
     * @param bool   $public_only If true, return only fields marked as is_public=1.
     */
    public static function list_field_defs( $account_id, $asset_type = '', $public_only = false ) {
        global $wpdb;
        $table = CMMS_DB::table( 'asset_field_defs' );
        $sql = "SELECT * FROM $table WHERE account_id = %d";
        $args = array( (int) $account_id );
        if ( $public_only ) {
            $sql .= " AND is_public = 1";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";
        return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
    }

    public static function add_field_def( $account_id, $data ) {
        global $wpdb;
        $table = CMMS_DB::table( 'asset_field_defs' );
        $label = isset( $data['label'] ) ? sanitize_text_field( $data['label'] ) : '';
        if ( $label === '' ) return new WP_Error( 'invalid', __( 'Label required', 'cmms-light' ) );

        // Field key generation strategy:
        //  1. If admin explicitly provided a key — sanitize and respect it.
        //     Collision in this case is a real error (admin asked for a
        //     specific key); we return WP_Error so the UI can warn.
        //  2. If admin did NOT provide a key — generate one from the label,
        //     then on collision auto-append _2, _3, ..., until unique.
        //     This path NEVER fails for collisions, because the admin
        //     never saw the key field anyway.
        $supplied_key = isset( $data['field_key'] ) ? sanitize_key( $data['field_key'] ) : '';

        if ( $supplied_key !== '' ) {
            $field_key = $supplied_key;
            // Check explicit collision — admin must rename
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table WHERE account_id = %d AND field_key = %s",
                (int) $account_id, $field_key
            ) );
            if ( $existing ) {
                return new WP_Error( 'dup', __( 'Field with this key already exists', 'cmms-light' ) );
            }
        } else {
            // Auto-derive a sensible base key from the label, then ensure
            // uniqueness by appending a numeric suffix.
            $field_key = self::generate_unique_field_key( (int) $account_id, $label );
        }

        $type = isset( $data['field_type'] ) ? sanitize_key( $data['field_type'] ) : 'text';
        if ( ! array_key_exists( $type, self::field_def_types() ) ) $type = 'text';

        $options = '';
        if ( $type === 'select' && ! empty( $data['options'] ) ) {
            // Comma- or newline-separated list of choices.
            $opts = is_array( $data['options'] ) ? $data['options'] : preg_split( '/[\r\n,]+/', (string) $data['options'] );
            $opts = array_filter( array_map( 'trim', (array) $opts ) );
            $options = wp_json_encode( array_values( $opts ) );
        }

        // Public visibility is opt-in only — admin must explicitly check it.
        // Default false ensures we never leak custom fields by accident.
        $is_public = ! empty( $data['is_public'] ) ? 1 : 0;

        $wpdb->insert( $table, array(
            'account_id' => (int) $account_id,
            'field_key'  => $field_key,
            'label'      => $label,
            'field_type' => $type,
            'options'    => $options,
            'required'   => ! empty( $data['required'] ) ? 1 : 0,
            'is_public'  => $is_public,
            'sort_order' => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
            'asset_type' => null, // unused; kept for schema compat
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ) );
        return (int) $wpdb->insert_id;
    }

    public static function delete_field_def( $account_id, $def_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'asset_field_defs' );
        return $wpdb->delete( $table, array(
            'id' => (int) $def_id,
            'account_id' => (int) $account_id,
        ), array( '%d', '%d' ) );
    }

    /**
     * Generate a unique, sensible field_key for a given label, scoped to one
     * account. Never fails. Strategy:
     *   1. Slugify the label. ASCII labels become things like "serial_number".
     *   2. If the result is empty (label was all-Hebrew/Arabic/Chinese, etc.
     *      and sanitize_key stripped everything), transliterate Hebrew or
     *      Arabic to Latin. If THAT's also empty, use a generic "field_N"
     *      counter.
     *   3. If the candidate already exists in this account, append _2, _3,
     *      ... until it's unique.
     *
     * The output is guaranteed to be:
     *   - non-empty
     *   - 1-64 chars (column limit)
     *   - safe per sanitize_key (lowercase, [a-z0-9_])
     *   - unique within the account
     */
    public static function generate_unique_field_key( $account_id, $label ) {
        $base = self::slug_from_label( $label );

        // If we got nothing usable, fall back to a numbered counter.
        // We count existing "field_N" rows and pick the next one.
        if ( $base === '' ) {
            $base = 'field';
        }
        // Cap base length to leave room for collision suffixes.
        if ( strlen( $base ) > 50 ) $base = substr( $base, 0, 50 );

        // Find a free key.
        global $wpdb;
        $table = CMMS_DB::table( 'asset_field_defs' );

        $candidate = $base;
        $i = 2;
        while ( true ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table WHERE account_id = %d AND field_key = %s",
                (int) $account_id, $candidate
            ) );
            if ( ! $exists ) return $candidate;
            $candidate = $base . '_' . $i;
            $i++;
            if ( $i > 9999 ) {
                // Pathological case — give up and use random. Should never
                // happen in practice, but it prevents an infinite loop.
                return 'field_' . wp_generate_password( 8, false, false );
            }
        }
    }

    /**
     * Convert a human label to a Latin-alphabet slug suitable as a field_key.
     * Handles Hebrew and Arabic by transliterating common letters.
     *
     * Strategy: always transliterate, because a label like "מתח (volt)"
     * contains BOTH non-Latin and Latin characters and we want the latter
     * to survive ("mtch_volt"). If the transliterated result is empty
     * (label was all emoji or punctuation), fall back to a plain ASCII
     * slugify of the original.
     *
     * Examples:
     *   "Serial number"        → "serial_number"
     *   "קומה"                 → "qvmh"
     *   "מספר חדר"             → "mspr_chdr"
     *   "מתח (volt)"           → "mtch_volt"
     *   "🚀🚀🚀"               → "" (caller falls back to "field")
     */
    public static function slug_from_label( $label ) {
        $label = (string) $label;
        $label = trim( $label );
        if ( $label === '' ) return '';

        // Hebrew transliteration map. Imperfect but produces readable
        // ASCII keys ("kvmh" for "קומה") that won't collide with other
        // Hebrew labels.
        $hebrew = array(
            'א'=>'a',  'ב'=>'b', 'ג'=>'g', 'ד'=>'d', 'ה'=>'h', 'ו'=>'v',
            'ז'=>'z',  'ח'=>'ch','ט'=>'t', 'י'=>'y', 'כ'=>'k', 'ך'=>'k',
            'ל'=>'l',  'מ'=>'m', 'ם'=>'m', 'נ'=>'n', 'ן'=>'n', 'ס'=>'s',
            'ע'=>'a',  'פ'=>'p', 'ף'=>'p', 'צ'=>'ts','ץ'=>'ts','ק'=>'q',
            'ר'=>'r',  'ש'=>'sh','ת'=>'t',
        );
        // Arabic transliteration map (basic letters only)
        $arabic = array(
            'ا'=>'a','ب'=>'b','ت'=>'t','ث'=>'th','ج'=>'j','ح'=>'h','خ'=>'kh',
            'د'=>'d','ذ'=>'dh','ر'=>'r','ز'=>'z','س'=>'s','ش'=>'sh',
            'ص'=>'s','ض'=>'d','ط'=>'t','ظ'=>'z','ع'=>'a','غ'=>'gh',
            'ف'=>'f','ق'=>'q','ك'=>'k','ل'=>'l','م'=>'m','ن'=>'n',
            'ه'=>'h','و'=>'w','ي'=>'y','ى'=>'a','ة'=>'h','ء'=>'a',
        );
        $map = array_merge( $hebrew, $arabic );

        $result = '';
        // mb_strlen falls back to strlen if mbstring isn't loaded; we
        // guard by checking once at runtime.
        $has_mb = function_exists( 'mb_strlen' );
        $len = $has_mb ? mb_strlen( $label ) : strlen( $label );
        for ( $i = 0; $i < $len; $i++ ) {
            $ch = $has_mb ? mb_substr( $label, $i, 1 ) : substr( $label, $i, 1 );
            if ( isset( $map[ $ch ] ) ) {
                $result .= $map[ $ch ];
            } elseif ( preg_match( '/[a-zA-Z0-9]/', $ch ) ) {
                $result .= strtolower( $ch );
            } elseif ( preg_match( '/\s/', $ch ) ) {
                $result .= '_';
            }
            // Anything else (emoji, punctuation, other scripts) is dropped.
        }

        // Collapse multiple underscores and trim.
        $result = preg_replace( '/_+/', '_', $result );
        $result = trim( $result, '_' );

        // If transliteration produced something, prefer it.
        if ( $result !== '' ) return $result;

        // Last resort: plain ASCII slugify.
        return sanitize_key( str_replace( ' ', '_', strtolower( $label ) ) );
    }

    /* ===================================================================
     * CRUD
     * ===================================================================*/
    public static function create( $account_id, $data ) {
        global $wpdb;
        $table = CMMS_DB::table( 'assets' );
        $name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
        if ( empty( $name ) ) return new WP_Error( 'invalid', __( 'Name required', 'cmms-light' ) );

        $type = isset( $data['asset_type'] ) ? sanitize_text_field( $data['asset_type'] ) : 'machine';
        $types = array_keys( self::asset_types() );
        if ( ! in_array( $type, $types, true ) ) $type = 'machine';

        // Custom field values — accept either array or already-encoded JSON.
        $custom_json = self::sanitize_custom_fields( isset( $data['custom_fields'] ) ? $data['custom_fields'] : array() );

        $public_actions = isset( $data['public_actions'] ) && is_array( $data['public_actions'] )
            ? array_values( array_intersect( array_keys( self::public_actions_catalog() ), $data['public_actions'] ) )
            : self::default_public_actions();

        $qr_token = self::generate_qr_token();

        $wpdb->insert( $table, array(
            'account_id'        => (int) $account_id,
            'name'              => $name,
            'asset_type'        => $type,
            'location'          => isset( $data['location'] ) ? sanitize_text_field( $data['location'] ) : '',
            'description'       => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
            'status'            => 'active',
            'custom_fields'     => $custom_json,
            'qr_token'          => $qr_token,
            'public_qr_enabled' => isset( $data['public_qr_enabled'] ) ? (int) (bool) $data['public_qr_enabled'] : 1,
            'public_actions'    => wp_json_encode( $public_actions ),
            'created_by'        => isset( $data['created_by'] ) ? (int) $data['created_by'] : null,
            'created_at'        => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ) );
        return $wpdb->insert_id;
    }

    public static function update( $id, $data ) {
        global $wpdb;
        $table = CMMS_DB::table( 'assets' );
        $update = array();
        $format = array();
        if ( isset( $data['name'] ) )        { $update['name'] = sanitize_text_field( $data['name'] ); $format[] = '%s'; }
        if ( isset( $data['asset_type'] ) )  {
            $type = sanitize_text_field( $data['asset_type'] );
            if ( array_key_exists( $type, self::asset_types() ) ) {
                $update['asset_type'] = $type; $format[] = '%s';
            }
        }
        if ( isset( $data['location'] ) )    { $update['location'] = sanitize_text_field( $data['location'] ); $format[] = '%s'; }
        if ( isset( $data['description'] ) ) { $update['description'] = wp_kses_post( $data['description'] ); $format[] = '%s'; }
        if ( isset( $data['status'] ) )      { $update['status'] = sanitize_text_field( $data['status'] ); $format[] = '%s'; }
        if ( array_key_exists( 'custom_fields', $data ) ) {
            $update['custom_fields'] = self::sanitize_custom_fields( $data['custom_fields'] );
            $format[] = '%s';
        }
        if ( isset( $data['public_qr_enabled'] ) ) {
            $update['public_qr_enabled'] = (int) (bool) $data['public_qr_enabled']; $format[] = '%d';
        }
        if ( isset( $data['public_actions'] ) && is_array( $data['public_actions'] ) ) {
            $allowed = array_intersect( array_keys( self::public_actions_catalog() ), $data['public_actions'] );
            $update['public_actions'] = wp_json_encode( array_values( $allowed ) );
            $format[] = '%s';
        }
        if ( empty( $update ) ) return false;
        return $wpdb->update( $table, $update, array( 'id' => (int) $id ), $format, array( '%d' ) );
    }

    public static function get( $id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'assets' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ) );
    }

    /**
     * Defensive lookup of an asset's display name by id. Used by task
     * rendering — must NEVER throw. If the asset is missing, deleted, or
     * the row is malformed, we return an empty string so the caller can
     * skip rendering the asset chip rather than crash the entire view.
     *
     * Returns empty string for: invalid id, missing row, null/empty name.
     * Returns plain string (unescaped — caller must escape on output).
     *
     * Cached per-request to avoid repeating the same SELECT for every
     * task card in a long list.
     */
    public static function asset_name( $id ) {
        static $cache = array();
        $id = (int) $id;
        if ( $id <= 0 ) return '';
        if ( array_key_exists( $id, $cache ) ) return $cache[ $id ];

        try {
            global $wpdb;
            $table = CMMS_DB::table( 'assets' );
            if ( ! $table ) { $cache[ $id ] = ''; return ''; }
            $name = $wpdb->get_var( $wpdb->prepare(
                "SELECT name FROM $table WHERE id = %d LIMIT 1",
                $id
            ) );
            $name = ( $name === null || $name === '' ) ? '' : (string) $name;
            $cache[ $id ] = $name;
            return $name;
        } catch ( \Throwable $e ) {
            // Never let a name lookup propagate an exception up into a list view.
            $cache[ $id ] = '';
            return '';
        }
    }

    /**
     * Look up an asset by its public QR token. Used by the public asset
     * record page; rate-limited at the controller level. We never accept
     * an asset by id in the public path — only by the unguessable token.
     */
    public static function get_by_qr_token( $token ) {
        global $wpdb;
        $table = CMMS_DB::table( 'assets' );
        $token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );
        if ( strlen( $token ) < 16 ) return null;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE qr_token = %s", $token ) );
    }

    public static function list_by_account( $account_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'assets' );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE account_id = %d AND status = 'active' ORDER BY name ASC",
            (int) $account_id
        ) );
    }

    public static function delete( $id, $account_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'assets' );
        return $wpdb->delete( $table, array(
            'id' => (int) $id,
            'account_id' => (int) $account_id,
        ), array( '%d', '%d' ) );
    }

    /* ===================================================================
     * Custom-fields helpers
     * ===================================================================*/

    /**
     * Sanitize an incoming custom_fields payload to a JSON string.
     * Accepts:
     *   - PHP array (preferred): each item with field_key/label/type/value
     *   - Already-encoded JSON string (passed through after re-encoding)
     * Always returns a JSON-encoded string of an array of normalized items.
     */
    public static function sanitize_custom_fields( $raw ) {
        $items = array();
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) $raw = $decoded;
        }
        if ( ! is_array( $raw ) ) return wp_json_encode( array() );

        $allowed_types = array_keys( self::field_def_types() );
        foreach ( $raw as $item ) {
            if ( ! is_array( $item ) ) continue;
            $key = isset( $item['field_key'] ) ? sanitize_key( $item['field_key'] ) : '';
            if ( $key === '' ) continue;
            $label = isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '';
            $type  = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'text';
            if ( ! in_array( $type, $allowed_types, true ) ) $type = 'text';

            // Sanitize value by type. Always store as string (or scalar) — JSON
            // encoding will preserve it correctly.
            $val = isset( $item['value'] ) ? $item['value'] : '';
            switch ( $type ) {
                case 'number':
                    $val = is_numeric( $val ) ? (float) $val : '';
                    break;
                case 'date':
                    $val = $val ? sanitize_text_field( (string) $val ) : '';
                    break;
                case 'checkbox':
                    $val = ! empty( $val ) ? 1 : 0;
                    break;
                case 'textarea':
                    $val = wp_kses_post( (string) $val );
                    break;
                default:
                    $val = sanitize_text_field( (string) $val );
            }

            $items[] = array(
                'field_key' => $key,
                'label'     => $label,
                'type'      => $type,
                'value'     => $val,
            );
        }
        return wp_json_encode( array_values( $items ), JSON_UNESCAPED_UNICODE );
    }

    /**
     * Decode the JSON column on an asset row. Returns array of items or [].
     */
    public static function decode_custom_fields( $asset ) {
        if ( ! $asset || empty( $asset->custom_fields ) ) return array();
        $arr = json_decode( $asset->custom_fields, true );
        return is_array( $arr ) ? $arr : array();
    }

    /**
     * Return the subset of stored custom field values that the admin has
     * explicitly marked as public (is_public=1 on the field definition).
     *
     * Used by the public asset record page (QR landing) to render only
     * non-sensitive fields. This is the security boundary — any field NOT
     * flagged public is invisible to anonymous visitors.
     *
     * Returns array of stored value items: [ {field_key, label, type, value}, ... ]
     */
    public static function decode_public_custom_fields( $asset ) {
        $stored = self::decode_custom_fields( $asset );
        if ( empty( $stored ) ) return array();

        // Build a set of public field_keys for this account.
        $public_defs = self::list_field_defs( (int) $asset->account_id, '', true );
        $public_keys = array();
        foreach ( (array) $public_defs as $def ) {
            $public_keys[ $def->field_key ] = true;
        }
        if ( empty( $public_keys ) ) return array();

        $out = array();
        foreach ( $stored as $item ) {
            if ( ! is_array( $item ) || empty( $item['field_key'] ) ) continue;
            if ( ! isset( $public_keys[ $item['field_key'] ] ) ) continue;
            // Skip empty values so the public page doesn't show blank rows.
            $val = isset( $item['value'] ) ? $item['value'] : '';
            if ( $val === '' || $val === null ) continue;
            $out[] = $item;
        }
        return $out;
    }

    public static function decode_public_actions( $asset ) {
        if ( ! $asset || empty( $asset->public_actions ) ) return self::default_public_actions();
        $arr = json_decode( $asset->public_actions, true );
        return is_array( $arr ) ? $arr : self::default_public_actions();
    }

    /* ===================================================================
     * QR tokens
     * ===================================================================*/

    /** Generate a 32-char unguessable token. Caller is responsible for uniqueness. */
    private static function generate_qr_token() {
        try {
            $bytes = random_bytes( 16 );
        } catch ( Exception $e ) {
            $bytes = wp_generate_password( 32, false, false );
        }
        return bin2hex( $bytes );
    }

    /** Public URL that the QR encodes. */
    public static function public_url( $asset ) {
        if ( ! $asset || empty( $asset->qr_token ) ) return '';
        return add_query_arg( array( 'asset' => $asset->qr_token ), home_url( '/cmms-asset/' ) );
    }

    /** Internal (logged-in) URL. */
    public static function internal_url( $asset ) {
        if ( ! $asset ) return '';
        return add_query_arg( array( 'view' => 'asset', 'id' => (int) $asset->id ), home_url( '/cmms-dashboard/' ) );
    }

    /** Builds a QR PNG URL (third-party generator). */
    public static function qr_image_url( $asset ) {
        $url = self::public_url( $asset );
        if ( ! $url ) return '';
        return 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&margin=10&data=' . urlencode( $url );
    }

    /**
     * Migrate existing assets that don't yet have a qr_token. Idempotent.
     * Called from the activator's run_migrations().
     */
    public static function backfill_qr_tokens() {
        global $wpdb;
        $table = CMMS_DB::table( 'assets' );
        $rows = $wpdb->get_results( "SELECT id FROM $table WHERE qr_token IS NULL OR qr_token = ''" );
        foreach ( (array) $rows as $row ) {
            $wpdb->update( $table,
                array( 'qr_token' => self::generate_qr_token() ),
                array( 'id' => (int) $row->id ),
                array( '%s' ),
                array( '%d' )
            );
        }
    }
}
