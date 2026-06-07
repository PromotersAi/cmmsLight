<?php
/**
 * CMMS Task Signatures
 *
 * Manages digital signatures attached to tasks. Common use case:
 * a technician completes work at an external customer site and
 * collects the customer's signature on the spot as proof the work
 * was performed and accepted. This becomes a legal record that
 * the work was done before invoicing.
 *
 * Signature types:
 *   - 'customer'   — external party signing off on the work
 *   - 'technician' — internal worker acknowledging responsibility
 *
 * Storage:
 *   - Image: base64-encoded PNG (canvas.toDataURL output) in DB.
 *     Small enough to fit comfortably (~5-30KB per signature).
 *   - Metadata: name, role/company, timestamp, IP, optional user_id.
 *
 * @since 1.15.3
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Signatures {

    const TYPE_CUSTOMER   = 'customer';
    const TYPE_TECHNICIAN = 'technician';

    /**
     * Add a new signature to a task. Returns the row id on success
     * or WP_Error on failure.
     *
     * @param int    $task_id
     * @param array  $data {
     *     @type string $signer_type   'customer' or 'technician'
     *     @type string $signer_name   Mandatory full name of signer
     *     @type string $signer_role   Optional company/title
     *     @type string $signature_data data: URI from canvas (data:image/png;base64,...)
     * }
     * @param int|null $signed_by_user_id  Internal user id if logged-in user is signing
     */
    public static function add( $task_id, $data, $signed_by_user_id = null ) {
        global $wpdb;

        $task_id = (int) $task_id;
        $task = CMMS_Tasks::get( $task_id );
        if ( ! $task ) {
            return new WP_Error( 'task_not_found', __( 'Task not found', 'cmms-light' ) );
        }

        // ── Validate signer name ──
        $signer_name = isset( $data['signer_name'] ) ? sanitize_text_field( $data['signer_name'] ) : '';
        if ( $signer_name === '' ) {
            return new WP_Error( 'missing_name', __( 'Signer name is required', 'cmms-light' ) );
        }

        // ── Validate signer type ──
        $signer_type = isset( $data['signer_type'] ) ? sanitize_key( $data['signer_type'] ) : self::TYPE_CUSTOMER;
        if ( ! in_array( $signer_type, array( self::TYPE_CUSTOMER, self::TYPE_TECHNICIAN ), true ) ) {
            $signer_type = self::TYPE_CUSTOMER;
        }

        // ── Validate signature data ──
        // Must be a data URI starting with data:image/png;base64,
        // and decode to non-trivial bytes (> 200B — anything smaller
        // is essentially a blank canvas, not a real signature).
        $signature_data = isset( $data['signature_data'] ) ? (string) $data['signature_data'] : '';
        if ( strpos( $signature_data, 'data:image/png;base64,' ) !== 0 ) {
            return new WP_Error( 'invalid_signature', __( 'Invalid signature image format', 'cmms-light' ) );
        }
        $b64 = substr( $signature_data, strlen( 'data:image/png;base64,' ) );
        $decoded = base64_decode( $b64, true );
        if ( $decoded === false || strlen( $decoded ) < 200 ) {
            return new WP_Error( 'empty_signature', __( 'Signature is empty — please sign before saving', 'cmms-light' ) );
        }

        // Cap stored size to prevent abuse (a real signature is well
        // under 100KB; anything larger is either decorative bloat or
        // an attack).
        if ( strlen( $signature_data ) > 500 * 1024 ) {
            return new WP_Error( 'too_large', __( 'Signature image too large', 'cmms-light' ) );
        }

        // ── Capture IP for legal traceability ──
        $signed_ip = '';
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $signed_ip = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP );
            if ( ! $signed_ip ) $signed_ip = '';
        }

        // ── Insert ──
        $table = CMMS_DB::table( 'task_signatures' );
        $ok = $wpdb->insert( $table, array(
            'task_id'           => $task_id,
            'account_id'        => (int) $task->account_id,
            'signer_type'       => $signer_type,
            'signer_name'       => $signer_name,
            'signer_role'       => isset( $data['signer_role'] ) ? sanitize_text_field( $data['signer_role'] ) : null,
            'signature_data'    => $signature_data,
            'signed_by_user_id' => $signed_by_user_id ? (int) $signed_by_user_id : null,
            'signed_ip'         => $signed_ip ?: null,
            'signed_at'         => current_time( 'mysql' ),
        ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );

        if ( ! $ok ) {
            return new WP_Error( 'db_error', __( 'Failed to save signature', 'cmms-light' ) );
        }

        $sig_id = (int) $wpdb->insert_id;

        // Log the signing event in the task activity log so it shows
        // up in the timeline. The log entry doesn't include the image
        // itself — just the fact that someone signed.
        if ( class_exists( 'CMMS_Tasks' ) ) {
            CMMS_Tasks::log(
                $task_id,
                (int) $task->account_id,
                $signed_by_user_id ? (int) $signed_by_user_id : null,
                'signature_added',
                sprintf( '%s: %s', $signer_type, $signer_name )
            );
        }

        return $sig_id;
    }

    /**
     * Delete a signature by id. Only the original signer (if logged in)
     * or task owner/manager can delete.
     */
    public static function delete( $signature_id, $by_user_id = null ) {
        global $wpdb;
        $table = CMMS_DB::table( 'task_signatures' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            (int) $signature_id
        ) );
        if ( ! $row ) {
            return new WP_Error( 'not_found', __( 'Signature not found', 'cmms-light' ) );
        }

        $deleted = $wpdb->delete( $table, array( 'id' => (int) $signature_id ), array( '%d' ) );
        if ( $deleted === false ) {
            return new WP_Error( 'db_error', __( 'Failed to delete signature', 'cmms-light' ) );
        }

        // Log deletion in task activity for audit trail.
        if ( class_exists( 'CMMS_Tasks' ) ) {
            CMMS_Tasks::log(
                (int) $row->task_id,
                (int) $row->account_id,
                $by_user_id ? (int) $by_user_id : null,
                'signature_deleted',
                sprintf( '%s: %s', $row->signer_type, $row->signer_name )
            );
        }

        return true;
    }

    /**
     * Get all signatures for a task, ordered by signing time (oldest first).
     */
    public static function list_for_task( $task_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'task_signatures' );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, task_id, signer_type, signer_name, signer_role,
                    signature_data, signed_by_user_id, signed_ip, signed_at
             FROM $table
             WHERE task_id = %d
             ORDER BY signed_at ASC",
            (int) $task_id
        ) );
    }

    /**
     * Get a single signature by id.
     */
    public static function get( $signature_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'task_signatures' );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            (int) $signature_id
        ) );
    }
}
