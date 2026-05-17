<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Users {

    public static function create_cmms_user( $wp_user_id, $account_id, $role, $display_name = '', $phone = '' ) {
        global $wpdb;
        $table = CMMS_DB::table( 'users' );
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE wp_user_id = %d", $wp_user_id ) );
        if ( $existing ) return $existing->id;

        $wpdb->insert( $table, array(
            'wp_user_id'   => intval( $wp_user_id ),
            'account_id'   => intval( $account_id ),
            'role'         => $role,
            'display_name' => $display_name,
            'phone'        => $phone,
            'status'       => 'active',
            'created_at'   => current_time( 'mysql' ),
        ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );
        return $wpdb->insert_id;
    }

    public static function add_user_to_account( $account_id, $email, $name, $password, $role, $phone = '' ) {
        $email = sanitize_email( $email );
        $name = sanitize_text_field( $name );
        $phone = sanitize_text_field( $phone );

        if ( ! is_email( $email ) ) return new WP_Error( 'invalid_email', __( 'Invalid email', 'cmms-light' ) );
        if ( strlen( $password ) < 6 ) return new WP_Error( 'weak_pass', __( 'Password must be at least 6 chars', 'cmms-light' ) );

        $allowed_roles = array( CMMS_Auth::ROLE_MANAGER, CMMS_Auth::ROLE_TECHNICIAN, CMMS_Auth::ROLE_REPORTER );
        if ( ! in_array( $role, $allowed_roles, true ) ) return new WP_Error( 'invalid_role', __( 'Invalid role', 'cmms-light' ) );

        $wp_user_id = email_exists( $email );
        if ( $wp_user_id ) {
            global $wpdb;
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . CMMS_DB::table( 'users' ) . " WHERE wp_user_id = %d", $wp_user_id ) );
            if ( $existing ) {
                return new WP_Error( 'exists', __( 'User already exists in a CMMS account', 'cmms-light' ) );
            }
        } else {
            $base = sanitize_user( current( explode( '@', $email ) ), true );
            if ( empty( $base ) ) $base = 'user';
            $username = $base;
            $i = 1;
            while ( username_exists( $username ) ) {
                $username = $base . $i;
                $i++;
            }
            $wp_user_id = wp_create_user( $username, $password, $email );
            if ( is_wp_error( $wp_user_id ) ) return $wp_user_id;
            wp_update_user( array( 'ID' => $wp_user_id, 'display_name' => $name, 'first_name' => $name ) );
            $u = new WP_User( $wp_user_id );
            $u->set_role( 'subscriber' );
        }

        $cmms_user_id = self::create_cmms_user( $wp_user_id, $account_id, $role, $name, $phone );
        if ( ! is_wp_error( $cmms_user_id ) && $cmms_user_id ) {
            /**
             * Fires when a new user has been added to an account. The temp
             * password is passed so the notification system can include it
             * in the welcome email. Listener should NOT log the password.
             */
            do_action( 'cmms_user_invited', (int) $cmms_user_id, $password, get_current_user_id() );
        }
        return $cmms_user_id;
    }

    public static function list_by_account( $account_id, $role = '' ) {
        global $wpdb;
        $table = CMMS_DB::table( 'users' );
        if ( $role ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE account_id = %d AND role = %s ORDER BY display_name ASC", $account_id, $role ) );
        }
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE account_id = %d ORDER BY display_name ASC", $account_id ) );
    }

    public static function get( $id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'users' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
    }

    public static function get_by_wp_id( $wp_user_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'users' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE wp_user_id = %d", $wp_user_id ) );
    }

    public static function update( $id, $data ) {
        global $wpdb;
        $table = CMMS_DB::table( 'users' );
        $u = self::get( $id );
        if ( ! $u ) return new WP_Error( 'not_found', __( 'User not found', 'cmms-light' ) );

        $update = array();
        $format = array();
        if ( isset( $data['display_name'] ) ) { $update['display_name'] = sanitize_text_field( $data['display_name'] ); $format[] = '%s'; }
        if ( isset( $data['phone'] ) ) { $update['phone'] = sanitize_text_field( $data['phone'] ); $format[] = '%s'; }
        if ( isset( $data['role'] ) ) {
            $allowed = array( CMMS_Auth::ROLE_OWNER, CMMS_Auth::ROLE_MANAGER, CMMS_Auth::ROLE_TECHNICIAN, CMMS_Auth::ROLE_REPORTER );
            if ( in_array( $data['role'], $allowed, true ) ) {
                $update['role'] = $data['role'];
                $format[] = '%s';
            }
        }
        if ( isset( $data['status'] ) ) {
            $status = sanitize_text_field( $data['status'] );
            if ( in_array( $status, array( 'active', 'inactive', 'suspended' ), true ) ) {
                // Don't let the last active owner be deactivated/suspended.
                if ( $u->role === CMMS_Auth::ROLE_OWNER && $u->status === 'active' && $status !== 'active' ) {
                    if ( self::count_active_owners( $u->account_id ) <= 1 ) {
                        return new WP_Error( 'last_owner', __( 'Cannot deactivate the last active owner.', 'cmms-light' ) );
                    }
                }
                $update['status'] = $status;
                $format[] = '%s';
            }
        }

        // Update email + display name on the underlying WP user (separate table).
        if ( isset( $data['email'] ) && $data['email'] ) {
            $email = sanitize_email( $data['email'] );
            if ( ! is_email( $email ) ) return new WP_Error( 'invalid_email', __( 'Invalid email address.', 'cmms-light' ) );
            $existing_id = email_exists( $email );
            if ( $existing_id && (int) $existing_id !== (int) $u->wp_user_id ) {
                return new WP_Error( 'email_taken', __( 'Another account already uses this email.', 'cmms-light' ) );
            }
            wp_update_user( array( 'ID' => $u->wp_user_id, 'user_email' => $email ) );
        }
        if ( isset( $data['display_name'] ) && $data['display_name'] ) {
            wp_update_user( array( 'ID' => $u->wp_user_id, 'display_name' => $data['display_name'] ) );
        }

        if ( empty( $update ) ) return true; // email-only or display-name-only update is OK
        $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );
        return true;
    }

    /**
     * Count active owners on an account. Used to prevent demoting / removing
     * the last owner, which would orphan the account.
     */
    public static function count_active_owners( $account_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'users' );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE account_id = %d AND role = %s AND status = 'active'",
            $account_id, CMMS_Auth::ROLE_OWNER
        ) );
    }

    public static function delete( $id ) {
        global $wpdb;
        $u = self::get( $id );
        if ( ! $u ) return false;
        $wpdb->delete( CMMS_DB::table( 'users' ), array( 'id' => $id ), array( '%d' ) );
        return true;
    }

    public static function display_name( $cmms_user_id ) {
        if ( ! $cmms_user_id ) return '';
        $u = self::get( $cmms_user_id );
        if ( ! $u ) return '';
        return $u->display_name ? $u->display_name : '#' . $u->id;
    }
}
