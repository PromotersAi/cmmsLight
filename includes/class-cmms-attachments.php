<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Attachments {

    /** Maximum upload size in bytes (10 MB). */
    const MAX_BYTES = 10485760;

    /** Hard blocklist of dangerous extensions - rejected even if mime "looks" ok. */
    public static function blocked_extensions() {
        return array(
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps',
            'cgi', 'pl', 'py', 'rb', 'jsp', 'asp', 'aspx', 'sh', 'bash', 'zsh',
            'exe', 'bat', 'cmd', 'com', 'msi', 'ps1', 'vbs', 'vbe', 'js', 'jse',
            'wsf', 'wsh', 'jar', 'dll', 'so', 'app', 'scr', 'reg', 'lnk',
            'htaccess', 'htpasswd', 'svg', 'html', 'htm', 'xhtml',
        );
    }

    public static function allowed_mimes() {
        return array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
            'pdf'          => 'application/pdf',
            'doc'          => 'application/msword',
            'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'          => 'application/vnd.ms-excel',
            'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt'          => 'text/plain',
            'csv'          => 'text/csv',
        );
    }

    public static function handle_upload( $account_id, $object_type, $object_id, $file, $uploaded_by = null ) {
        if ( empty( $file ) || empty( $file['name'] ) ) {
            return new WP_Error( 'empty', __( 'No file', 'cmms-light' ) );
        }
        if ( ! empty( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'upload_error', __( 'Upload error', 'cmms-light' ) );
        }

        // Size cap.
        if ( isset( $file['size'] ) && $file['size'] > self::MAX_BYTES ) {
            return new WP_Error( 'too_large', __( 'File too large (max 10 MB).', 'cmms-light' ) );
        }

        // Sanitize and verify filename / extension.
        $original_name = sanitize_file_name( basename( $file['name'] ) );
        if ( $original_name === '' || strpos( $original_name, '..' ) !== false ) {
            return new WP_Error( 'bad_name', __( 'Invalid filename.', 'cmms-light' ) );
        }

        $ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
        if ( $ext === '' ) {
            return new WP_Error( 'no_ext', __( 'File has no extension.', 'cmms-light' ) );
        }

        // Reject double extensions (e.g. "evil.php.jpg").
        $name_only = pathinfo( $original_name, PATHINFO_FILENAME );
        $blocked = self::blocked_extensions();
        if ( in_array( $ext, $blocked, true ) ) {
            return new WP_Error( 'blocked_ext', __( 'File type not allowed.', 'cmms-light' ) );
        }
        // Catch hidden bad extensions like "report.php.pdf" - check every dot-segment.
        foreach ( explode( '.', strtolower( $name_only ) ) as $part ) {
            if ( in_array( $part, $blocked, true ) ) {
                return new WP_Error( 'blocked_ext', __( 'File type not allowed.', 'cmms-light' ) );
            }
        }

        // Real-content mime check via WP core.
        if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $checked = wp_check_filetype_and_ext( $file['tmp_name'], $original_name, self::allowed_mimes() );
        if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
            return new WP_Error( 'mime_mismatch', __( 'File type not allowed.', 'cmms-light' ) );
        }

        // Randomize the stored filename so attackers can't predict / target paths,
        // and never preserve a user-supplied name verbatim on disk.
        $safe_name = wp_generate_uuid4() . '.' . $checked['ext'];
        $file['name'] = $safe_name;

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $overrides = array(
            'test_form' => false,
            'mimes'     => self::allowed_mimes(),
            'unique_filename_callback' => array( __CLASS__, 'force_safe_filename' ),
        );

        add_filter( 'upload_dir', array( __CLASS__, 'custom_upload_dir' ) );
        $movefile = wp_handle_upload( $file, $overrides );
        remove_filter( 'upload_dir', array( __CLASS__, 'custom_upload_dir' ) );

        if ( isset( $movefile['error'] ) ) {
            return new WP_Error( 'upload_failed', $movefile['error'] );
        }

        global $wpdb;
        $table = CMMS_DB::table( 'attachments' );
        $wpdb->insert( $table, array(
            'account_id'   => intval( $account_id ),
            'object_type'  => sanitize_text_field( $object_type ),
            'object_id'    => intval( $object_id ),
            'file_name'    => sanitize_file_name( basename( $movefile['file'] ) ),
            'original_name'=> $original_name,
            'file_url'     => esc_url_raw( $movefile['url'] ),
            'file_path'    => $movefile['file'],
            'mime_type'    => sanitize_text_field( $movefile['type'] ),
            'uploaded_by'  => $uploaded_by ? intval( $uploaded_by ) : null,
            'created_at'   => current_time( 'mysql' ),
        ), array( '%d','%s','%d','%s','%s','%s','%s','%s','%d','%s' ) );

        return $wpdb->insert_id;
    }

    public static function force_safe_filename( $dir, $name, $ext ) {
        // Ensure final name on disk is the random uuid we set, even if WP tries to dedupe.
        return $name;
    }

    public static function custom_upload_dir( $dirs ) {
        $dirs['subdir'] = '/cmms-light';
        $dirs['path']   = $dirs['basedir'] . '/cmms-light';
        $dirs['url']    = $dirs['baseurl'] . '/cmms-light';
        return $dirs;
    }

    public static function for_object( $object_type, $object_id ) {
        global $wpdb;
        $table = CMMS_DB::table( 'attachments' );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE object_type = %s AND object_id = %d ORDER BY created_at ASC",
            $object_type, $object_id
        ) );
    }

    public static function delete( $id, $account_id = null ) {
        global $wpdb;
        $table = CMMS_DB::table( 'attachments' );
        $att = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $att ) return false;
        if ( $account_id && (int) $att->account_id !== (int) $account_id ) return false;
        if ( $att->file_path && file_exists( $att->file_path ) ) {
            @unlink( $att->file_path );
        }
        $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
        return true;
    }

    public static function is_image( $att ) {
        return $att && strpos( (string) $att->mime_type, 'image/' ) === 0;
    }

    /**
     * Display name for an attachment - shows the user's original name, but
     * everything stored on disk uses the random uuid.
     */
    public static function display_name( $att ) {
        if ( ! $att ) return '';
        if ( ! empty( $att->original_name ) ) return $att->original_name;
        return $att->file_name;
    }
}
