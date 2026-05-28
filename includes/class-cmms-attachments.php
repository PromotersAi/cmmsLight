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

    /**
     * 1.15.6: Attach a file that already exists on disk (NOT an HTTP
     * upload). Used for email-to-task attachments, which the email
     * inbox writes to a temp path via file_put_contents — these are
     * not $_FILES entries, so wp_handle_upload() (which calls
     * move_uploaded_file internally) would reject them.
     *
     * This mirrors the validation in handle_upload (extension check,
     * mime check, random stored name) but moves the file with a plain
     * rename/copy instead of move_uploaded_file.
     *
     * @param int    $account_id
     * @param string $object_type  'task'
     * @param int    $object_id
     * @param string $src_path     Absolute path to the source file on disk
     * @param string $original_name Original filename (for display + ext)
     * @param int|null $uploaded_by
     * @return int|WP_Error  attachment id or error
     */
    public static function handle_upload_from_path( $account_id, $object_type, $object_id, $src_path, $original_name, $uploaded_by = null ) {
        if ( ! file_exists( $src_path ) ) {
            return new WP_Error( 'no_file', __( 'Source file missing', 'cmms-light' ) );
        }

        $size = filesize( $src_path );
        if ( $size === false || $size > self::MAX_BYTES ) {
            return new WP_Error( 'too_large', __( 'File too large (max 10 MB).', 'cmms-light' ) );
        }

        // Sanitize and verify filename / extension.
        $original_name = sanitize_file_name( basename( $original_name ) );
        if ( $original_name === '' || strpos( $original_name, '..' ) !== false ) {
            return new WP_Error( 'bad_name', __( 'Invalid filename.', 'cmms-light' ) );
        }

        $ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
        if ( $ext === '' ) {
            return new WP_Error( 'no_ext', __( 'File has no extension.', 'cmms-light' ) );
        }

        // Block dangerous extensions (incl. hidden double-extensions).
        $name_only = pathinfo( $original_name, PATHINFO_FILENAME );
        $blocked = self::blocked_extensions();
        if ( in_array( $ext, $blocked, true ) ) {
            return new WP_Error( 'blocked_ext', __( 'File type not allowed.', 'cmms-light' ) );
        }
        foreach ( explode( '.', strtolower( $name_only ) ) as $part ) {
            if ( in_array( $part, $blocked, true ) ) {
                return new WP_Error( 'blocked_ext', __( 'File type not allowed.', 'cmms-light' ) );
            }
        }

        // Real-content mime check via WP core (reads the file bytes).
        if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $checked = wp_check_filetype_and_ext( $src_path, $original_name, self::allowed_mimes() );
        if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
            return new WP_Error( 'mime_mismatch', __( 'File type not allowed.', 'cmms-light' ) );
        }

        // Build destination dir (same custom dir used for uploads).
        $upload = wp_upload_dir();
        $dest_dir = $upload['basedir'] . '/cmms-light';
        if ( ! is_dir( $dest_dir ) ) {
            wp_mkdir_p( $dest_dir );
        }

        // Random stored name (never trust user-supplied name on disk).
        $safe_name = wp_generate_uuid4() . '.' . $checked['ext'];
        $dest_path = $dest_dir . '/' . $safe_name;
        $dest_url  = $upload['baseurl'] . '/cmms-light/' . $safe_name;

        // Move the file. rename() is atomic on the same filesystem;
        // fall back to copy+unlink across mounts.
        if ( ! @rename( $src_path, $dest_path ) ) {
            if ( ! @copy( $src_path, $dest_path ) ) {
                return new WP_Error( 'move_failed', __( 'Could not store file', 'cmms-light' ) );
            }
            @unlink( $src_path );
        }

        global $wpdb;
        $table = CMMS_DB::table( 'attachments' );
        $wpdb->insert( $table, array(
            'account_id'   => intval( $account_id ),
            'object_type'  => sanitize_text_field( $object_type ),
            'object_id'    => intval( $object_id ),
            'file_name'    => $safe_name,
            'original_name'=> $original_name,
            'file_url'     => esc_url_raw( $dest_url ),
            'file_path'    => $dest_path,
            'mime_type'    => sanitize_text_field( $checked['type'] ),
            'uploaded_by'  => $uploaded_by ? intval( $uploaded_by ) : null,
            'created_at'   => current_time( 'mysql' ),
        ), array( '%d','%s','%d','%s','%s','%s','%s','%s','%d','%s' ) );

        return $wpdb->insert_id;
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
     * 1.14.86: Save a file that we downloaded from a remote source
     * (e.g., Telegram photo). The bytes are already in memory or on
     * a temp path — this method handles the writing + DB row.
     *
     * Unlike handle_upload() (which works with $_FILES from the
     * browser), this one accepts:
     *   $bytes     — the file content as a string
     *   $extension — file extension (e.g. 'jpg', 'png')
     *   $original_name — what to show the user (e.g. 'IMG_telegram.jpg')
     *   $mime_type — content type
     *
     * Returns the new attachment id, or WP_Error.
     */
    public static function handle_remote_file( $account_id, $object_type, $object_id, $bytes, $extension, $original_name, $mime_type, $uploaded_by = null ) {
        if ( empty( $bytes ) ) {
            return new WP_Error( 'empty', __( 'No file data', 'cmms-light' ) );
        }
        if ( strlen( $bytes ) > self::MAX_BYTES ) {
            return new WP_Error( 'too_large', __( 'File too large (max 10 MB).', 'cmms-light' ) );
        }

        $extension = strtolower( ltrim( $extension, '.' ) );
        if ( in_array( $extension, self::blocked_extensions(), true ) ) {
            return new WP_Error( 'blocked_ext', __( 'File type not allowed.', 'cmms-light' ) );
        }

        // Map extension → allowed mime; reject if not in our list.
        $allowed = self::allowed_mimes();
        $mime_ok = false;
        foreach ( $allowed as $ext_group => $allowed_mime ) {
            if ( in_array( $extension, explode( '|', $ext_group ), true ) ) {
                $mime_ok = true;
                break;
            }
        }
        if ( ! $mime_ok ) {
            return new WP_Error( 'mime_not_allowed', __( 'File type not allowed.', 'cmms-light' ) );
        }

        // Build the destination path in our upload subdir.
        if ( ! function_exists( 'wp_upload_dir' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        add_filter( 'upload_dir', array( __CLASS__, 'custom_upload_dir' ) );
        $upload_dir = wp_upload_dir();
        remove_filter( 'upload_dir', array( __CLASS__, 'custom_upload_dir' ) );

        if ( ! empty( $upload_dir['error'] ) ) {
            return new WP_Error( 'upload_dir', $upload_dir['error'] );
        }

        // Ensure the directory exists.
        if ( ! wp_mkdir_p( $upload_dir['path'] ) ) {
            return new WP_Error( 'mkdir', __( 'Could not create upload directory.', 'cmms-light' ) );
        }

        // Randomize stored filename, like handle_upload does.
        $safe_name = wp_generate_uuid4() . '.' . $extension;
        $disk_path = trailingslashit( $upload_dir['path'] ) . $safe_name;
        $disk_url  = trailingslashit( $upload_dir['url'] )  . $safe_name;

        // Write bytes atomically (best effort).
        $written = file_put_contents( $disk_path, $bytes );
        if ( $written === false || $written === 0 ) {
            return new WP_Error( 'write_failed', __( 'Could not write file.', 'cmms-light' ) );
        }

        // Insert DB row.
        global $wpdb;
        $table = CMMS_DB::table( 'attachments' );
        $wpdb->insert( $table, array(
            'account_id'   => intval( $account_id ),
            'object_type'  => sanitize_text_field( $object_type ),
            'object_id'    => intval( $object_id ),
            'file_name'    => $safe_name,
            'original_name'=> sanitize_file_name( $original_name ),
            'file_url'     => esc_url_raw( $disk_url ),
            'file_path'    => $disk_path,
            'mime_type'    => sanitize_text_field( $mime_type ),
            'uploaded_by'  => $uploaded_by ? intval( $uploaded_by ) : null,
            'created_at'   => current_time( 'mysql' ),
        ), array( '%d','%s','%d','%s','%s','%s','%s','%s','%d','%s' ) );

        return $wpdb->insert_id;
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
