<?php
/**
 * Time range picker helper. Used across Home / Tasks / Reports to scope data
 * to a specific time window. Stored in URL query (?range=month or ?range=custom&from=&to=).
 *
 * Returns SQL-friendly bounds and a human-readable label.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_TimeRange {

    /**
     * Available range presets. Each preset has a label key and a function that
     * returns [from_datetime, to_datetime] in MySQL format.
     */
    public static function presets() {
        return array( 'today', 'week', 'month', 'quarter', 'year', 'all', 'next_month', 'next_quarter', 'custom' );
    }

    /**
     * Parse $_GET parameters and return a normalized range array:
     *   array( 'key' => 'month', 'from' => 'Y-m-d 00:00:00' or null, 'to' => '...' or null, 'label' => 'This month' )
     *
     * 'all' returns null bounds (= no filter).
     */
    public static function from_request() {
        $key = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'month';
        if ( ! in_array( $key, self::presets(), true ) ) $key = 'month';

        $tz_offset = (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
        $now_local = current_time( 'timestamp' ); // already shifted by gmt_offset
        $today_start = strtotime( wp_date( 'Y-m-d 00:00:00', $now_local ) );

        $from = null; $to = null;
        switch ( $key ) {
            case 'today':
                $from = $today_start;
                $to   = $today_start + DAY_IN_SECONDS - 1;
                break;
            case 'week':
                $from = $today_start - 7 * DAY_IN_SECONDS;
                $to   = $today_start + DAY_IN_SECONDS - 1;
                break;
            case 'month':
                $from = $today_start - 30 * DAY_IN_SECONDS;
                $to   = $today_start + DAY_IN_SECONDS - 1;
                break;
            case 'quarter':
                $from = $today_start - 90 * DAY_IN_SECONDS;
                $to   = $today_start + DAY_IN_SECONDS - 1;
                break;
            case 'year':
                $from = $today_start - 365 * DAY_IN_SECONDS;
                $to   = $today_start + DAY_IN_SECONDS - 1;
                break;
            case 'next_month':
                $from = $today_start;
                $to   = $today_start + 30 * DAY_IN_SECONDS;
                break;
            case 'next_quarter':
                $from = $today_start;
                $to   = $today_start + 90 * DAY_IN_SECONDS;
                break;
            case 'all':
                $from = null; $to = null;
                break;
            case 'custom':
                $f_raw = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
                $t_raw = isset( $_GET['to'] )   ? sanitize_text_field( wp_unslash( $_GET['to'] ) )   : '';
                $f_ts = $f_raw ? strtotime( $f_raw ) : false;
                $t_ts = $t_raw ? strtotime( $t_raw ) : false;
                if ( $f_ts ) $from = $f_ts;
                if ( $t_ts ) $to = $t_ts + DAY_IN_SECONDS - 1;
                break;
        }

        return array(
            'key'   => $key,
            'from'  => $from ? gmdate( 'Y-m-d H:i:s', $from - $tz_offset ) : null,
            'to'    => $to   ? gmdate( 'Y-m-d H:i:s', $to   - $tz_offset ) : null,
            'label' => self::label_for( $key ),
            'from_date' => $from ? wp_date( 'Y-m-d', $from ) : '',
            'to_date'   => $to   ? wp_date( 'Y-m-d', $to   ) : '',
        );
    }

    public static function label_for( $key ) {
        $map = array(
            'today'        => 'range.today',
            'week'         => 'range.week',
            'month'        => 'range.month',
            'quarter'      => 'range.quarter',
            'year'         => 'range.year',
            'all'          => 'range.all',
            'next_month'   => 'range.next_month',
            'next_quarter' => 'range.next_quarter',
            'custom'       => 'range.custom',
        );
        $key_for = isset( $map[ $key ] ) ? $map[ $key ] : 'range.month';
        return CMMS_I18n::t( $key_for );
    }

    /**
     * Return SQL "WHERE ... AND" fragment + args for a column, applied to a $range from from_request().
     * If range is "all" returns array( '', array() ) so the caller can skip.
     */
    public static function sql_clause( $column, $range ) {
        if ( empty( $range['from'] ) && empty( $range['to'] ) ) {
            return array( '', array() );
        }
        $clauses = array();
        $args = array();
        if ( ! empty( $range['from'] ) ) {
            $clauses[] = "$column >= %s";
            $args[] = $range['from'];
        }
        if ( ! empty( $range['to'] ) ) {
            $clauses[] = "$column <= %s";
            $args[] = $range['to'];
        }
        return array( ' AND ' . implode( ' AND ', $clauses ), $args );
    }
}
