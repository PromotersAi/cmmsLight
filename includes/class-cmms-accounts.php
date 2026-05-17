<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Accounts {

    public static function create_account( $name, $owner_user_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'accounts' );
        $wpdb->insert( $table, array(
            'name'          => sanitize_text_field( $name ),
            'owner_user_id' => intval( $owner_user_id ),
            'status'        => 'active',
            'created_at'    => current_time( 'mysql' ),
        ), array( '%s', '%d', '%s', '%s' ) );
        return $wpdb->insert_id;
    }

    public static function get( $id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'accounts' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
    }

    public static function all() {
        global $wpdb;
        $table = CMMS_DB::table( 'accounts' );
        return $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC" );
    }

    public static function set_status( $id, $status ) {
        global $wpdb;
        $table = CMMS_DB::table( 'accounts' );
        return $wpdb->update( $table, array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
    }

    public static function update_name( $id, $name ) {
        global $wpdb;
        $table = CMMS_DB::table( 'accounts' );
        return $wpdb->update( $table, array( 'name' => sanitize_text_field( $name ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
    }

    public static function delete_account( $id ) {
        global $wpdb;
        $tables = CMMS_DB::tables();
        $id = intval( $id );
        $wpdb->delete( $tables['form_submissions'], array( 'account_id' => $id ), array( '%d' ) );
        $wpdb->query( $wpdb->prepare( "DELETE ff FROM {$tables['form_fields']} ff INNER JOIN {$tables['forms']} f ON f.id = ff.form_id WHERE f.account_id = %d", $id ) );
        $wpdb->delete( $tables['forms'], array( 'account_id' => $id ), array( '%d' ) );
        $wpdb->delete( $tables['attachments'], array( 'account_id' => $id ), array( '%d' ) );
        $wpdb->delete( $tables['task_logs'], array( 'account_id' => $id ), array( '%d' ) );
        $wpdb->delete( $tables['tasks'], array( 'account_id' => $id ), array( '%d' ) );
        $wpdb->delete( $tables['assets'], array( 'account_id' => $id ), array( '%d' ) );
        $wpdb->delete( $tables['categories'], array( 'account_id' => $id ), array( '%d' ) );
        $wpdb->delete( $tables['users'], array( 'account_id' => $id ), array( '%d' ) );
        $wpdb->delete( $tables['accounts'], array( 'id' => $id ), array( '%d' ) );
    }

    /**
     * Public signup: creates WP user + cmms_user (owner) + account + default categories.
     *
     * @param array $args
     * @param bool  $auto_login Whether to log the new owner in (default true).
     *                          The demo generator passes false so the global admin's
     *                          session is preserved.
     */
    public static function signup( $args, $auto_login = true ) {
        $errors = array();

        $email    = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
        $password = isset( $args['password'] ) ? $args['password'] : '';
        $name     = isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '';
        $org      = isset( $args['organization'] ) ? sanitize_text_field( $args['organization'] ) : '';
        $phone    = isset( $args['phone'] ) ? sanitize_text_field( $args['phone'] ) : '';

        if ( empty( $email ) || ! is_email( $email ) ) $errors[] = __( 'Valid email is required', 'cmms-light' );
        if ( strlen( $password ) < 6 ) $errors[] = __( 'Password must be at least 6 characters', 'cmms-light' );
        if ( empty( $name ) ) $errors[] = __( 'Name is required', 'cmms-light' );
        if ( empty( $org ) ) $errors[] = __( 'Organization name is required', 'cmms-light' );
        if ( email_exists( $email ) ) $errors[] = __( 'Email already registered', 'cmms-light' );

        if ( ! empty( $errors ) ) {
            return new WP_Error( 'signup_failed', implode( '; ', $errors ) );
        }

        $username = self::generate_username( $email );
        $wp_user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $wp_user_id ) ) return $wp_user_id;

        wp_update_user( array(
            'ID'           => $wp_user_id,
            'display_name' => $name,
            'first_name'   => $name,
        ) );

        $u = new WP_User( $wp_user_id );
        $u->set_role( 'subscriber' );

        $account_id = self::create_account( $org, $wp_user_id );
        CMMS_Users::create_cmms_user( $wp_user_id, $account_id, CMMS_Auth::ROLE_OWNER, $name, $phone );
        CMMS_Categories::create_defaults( $account_id );

        if ( $auto_login ) {
            wp_set_current_user( $wp_user_id );
            wp_set_auth_cookie( $wp_user_id, true );
        }

        return $account_id;
    }

    private static function generate_username( $email ) {
        $base = sanitize_user( current( explode( '@', $email ) ), true );
        if ( empty( $base ) ) $base = 'user';
        $username = $base;
        $i = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $i;
            $i++;
        }
        return $username;
    }
}
