<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Categories {

    public static function defaults() {
        return array(
            array( 'name' => 'Preventive Maintenance', 'slug' => 'preventive' ),
            array( 'name' => 'Planned Maintenance',    'slug' => 'planned' ),
            array( 'name' => 'Breakdown Maintenance',  'slug' => 'breakdown' ),
            array( 'name' => 'General Tasks',          'slug' => 'general' ),
        );
    }

    public static function create_defaults( $account_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'categories' );
        foreach ( self::defaults() as $cat ) {
            $wpdb->insert( $table, array(
                'account_id' => $account_id,
                'name'       => $cat['name'],
                'slug'       => $cat['slug'],
                'enabled'    => 1,
                'is_default' => 1,
                'created_at' => current_time( 'mysql' ),
            ), array( '%d', '%s', '%s', '%d', '%d', '%s' ) );
        }
    }

    public static function list_by_account( $account_id, $only_enabled = false ) {
        global $wpdb;
        $table = CMMS_DB::table( 'categories' );
        $where = $wpdb->prepare( "account_id = %d", $account_id );
        if ( $only_enabled ) $where .= " AND enabled = 1";
        return $wpdb->get_results( "SELECT * FROM $table WHERE $where ORDER BY is_default DESC, name ASC" );
    }

    public static function get( $id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'categories' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
    }

    public static function create( $account_id, $name ) {
        global $wpdb;
        $table = CMMS_DB::table( 'categories' );
        $name = sanitize_text_field( $name );
        if ( empty( $name ) ) return false;
        $slug = sanitize_title( $name );
        $wpdb->insert( $table, array(
            'account_id' => $account_id,
            'name'       => $name,
            'slug'       => $slug,
            'enabled'    => 1,
            'is_default' => 0,
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%d', '%d', '%s' ) );
        return $wpdb->insert_id;
    }

    public static function set_enabled( $id, $enabled ) {
        global $wpdb;
        $table = CMMS_DB::table( 'categories' );
        return $wpdb->update( $table, array( 'enabled' => $enabled ? 1 : 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
    }

    public static function delete( $id ) {
        global $wpdb;
        $cat = self::get( $id );
        if ( ! $cat || $cat->is_default ) return false;
        $wpdb->delete( CMMS_DB::table( 'categories' ), array( 'id' => $id ), array( '%d' ) );
        return true;
    }
}
