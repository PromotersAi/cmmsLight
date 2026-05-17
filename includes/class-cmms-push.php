<?php
/**
 * Web Push notifications.
 *
 * Implementation note: We don't depend on composer's web-push-php library
 * because shipping a 30-file vendor tree inside a WordPress plugin is fragile.
 * Instead we implement the parts of RFC 8030 (Web Push), RFC 8291 (Message
 * Encryption), and RFC 8292 (VAPID) that we actually need:
 *
 *   1. VAPID key generation (P-256 ECDSA)
 *   2. JWT signing for the Authorization header
 *   3. Payload encryption with aes128gcm (RFC 8188)
 *   4. POST to the endpoint
 *
 * Push works on Android (Chrome/Firefox/Edge), Desktop browsers, and on iOS
 * 16.4+ when the user has installed the PWA to their home screen.
 *
 * Security: VAPID private key is stored in wp_options, never exposed to the
 * client. The public key is exposed (as it must be for the browser to
 * subscribe).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Push {

    const OPTION_VAPID_PUB  = 'cmms_vapid_public_key';
    const OPTION_VAPID_PRIV = 'cmms_vapid_private_key';
    const OPTION_VAPID_SUB  = 'cmms_vapid_subject';

    /* ============================================================
       VAPID key management
    ============================================================ */

    /**
     * Returns the public VAPID key in URL-safe base64 (uncompressed point form).
     * Generates one on first call.
     */
    public static function public_key() {
        $pub = get_option( self::OPTION_VAPID_PUB );
        if ( $pub ) return $pub;
        self::generate_keys();
        return get_option( self::OPTION_VAPID_PUB );
    }

    public static function private_key_pem() {
        $priv = get_option( self::OPTION_VAPID_PRIV );
        if ( $priv ) return $priv;
        self::generate_keys();
        return get_option( self::OPTION_VAPID_PRIV );
    }

    public static function vapid_subject() {
        $sub = get_option( self::OPTION_VAPID_SUB );
        if ( $sub ) return $sub;
        $email = get_option( 'admin_email' );
        $sub = $email ? 'mailto:' . $email : 'https://' . wp_parse_url( home_url(), PHP_URL_HOST );
        update_option( self::OPTION_VAPID_SUB, $sub, false );
        return $sub;
    }

    private static function generate_keys() {
        $pkey = openssl_pkey_new( array(
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ) );
        if ( ! $pkey ) {
            return new WP_Error( 'no_ec', __( 'OpenSSL EC support missing on this server.', 'cmms-light' ) );
        }
        $priv_pem = '';
        openssl_pkey_export( $pkey, $priv_pem );
        $details = openssl_pkey_get_details( $pkey );
        // Public key as uncompressed point: 0x04 || X(32) || Y(32) = 65 bytes
        $pub_bytes = "\x04" . str_pad( $details['ec']['x'], 32, "\0", STR_PAD_LEFT )
                            . str_pad( $details['ec']['y'], 32, "\0", STR_PAD_LEFT );
        $pub_b64 = self::b64url_encode( $pub_bytes );

        update_option( self::OPTION_VAPID_PRIV, $priv_pem, false );
        update_option( self::OPTION_VAPID_PUB, $pub_b64, false );
        return true;
    }

    /* ============================================================
       Subscription registration (called from AJAX endpoint)
    ============================================================ */

    public static function save_subscription( $cmms_user_id, $account_id, $endpoint, $p256dh, $auth_key, $user_agent = '' ) {
        global $wpdb;
        $table = CMMS_DB::table( 'push_subs' );

        // If endpoint already exists, update user binding (e.g. user logged out and another logged in on same device).
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM $table WHERE endpoint = %s LIMIT 1",
            $endpoint
        ) );
        if ( $existing ) {
            $wpdb->update( $table,
                array(
                    'user_id'       => (int) $cmms_user_id,
                    'account_id'    => (int) $account_id,
                    'p256dh'        => $p256dh,
                    'auth_key'      => $auth_key,
                    'user_agent'    => substr( (string) $user_agent, 0, 255 ),
                    'last_used_at'  => current_time( 'mysql' ),
                    'failure_count' => 0,
                ),
                array( 'id' => (int) $existing->id ),
                array( '%d', '%d', '%s', '%s', '%s', '%s', '%d' ),
                array( '%d' )
            );
            return (int) $existing->id;
        }

        $wpdb->insert( $table, array(
            'account_id'    => (int) $account_id,
            'user_id'       => (int) $cmms_user_id,
            'endpoint'      => $endpoint,
            'p256dh'        => $p256dh,
            'auth_key'      => $auth_key,
            'user_agent'    => substr( (string) $user_agent, 0, 255 ),
            'created_at'    => current_time( 'mysql' ),
            'last_used_at'  => current_time( 'mysql' ),
        ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
        return (int) $wpdb->insert_id;
    }

    public static function delete_subscription_by_endpoint( $endpoint ) {
        global $wpdb;
        $wpdb->delete( CMMS_DB::table( 'push_subs' ), array( 'endpoint' => $endpoint ), array( '%s' ) );
    }

    /* ============================================================
       Sending a notification
    ============================================================ */

    /**
     * Send a push notification to all of a CMMS user's subscriptions.
     * Silently no-ops if user has no subs or VAPID isn't set up.
     *
     * @param int    $cmms_user_id
     * @param string $title
     * @param string $body
     * @param string $url   Optional click target.
     * @return int Number of sends attempted.
     */
    public static function send_to_user( $cmms_user_id, $title, $body, $url = '' ) {
        global $wpdb;
        $subs = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . CMMS_DB::table( 'push_subs' ) . " WHERE user_id = %d",
            (int) $cmms_user_id
        ) );
        if ( ! $subs ) return 0;

        $payload = wp_json_encode( array(
            'title' => wp_strip_all_tags( $title ),
            'body'  => wp_strip_all_tags( $body ),
            'url'   => $url ?: home_url( '/cmms-dashboard/' ),
            'icon'  => CMMS_LIGHT_URL . 'assets/images/icon-192.png',
            'badge' => CMMS_LIGHT_URL . 'assets/images/icon-192.png',
        ) );

        $count = 0;
        foreach ( $subs as $sub ) {
            $ok = self::send_one( $sub, $payload );
            if ( $ok ) {
                $count++;
                $wpdb->update(
                    CMMS_DB::table( 'push_subs' ),
                    array( 'last_used_at' => current_time( 'mysql' ), 'failure_count' => 0 ),
                    array( 'id' => (int) $sub->id ),
                    array( '%s', '%d' ), array( '%d' )
                );
            } else {
                // Increment failure count; delete after 3 failures.
                $new_fail = (int) $sub->failure_count + 1;
                if ( $new_fail >= 3 ) {
                    $wpdb->delete( CMMS_DB::table( 'push_subs' ), array( 'id' => (int) $sub->id ), array( '%d' ) );
                } else {
                    $wpdb->update(
                        CMMS_DB::table( 'push_subs' ),
                        array( 'failure_count' => $new_fail ),
                        array( 'id' => (int) $sub->id ),
                        array( '%d' ), array( '%d' )
                    );
                }
            }
        }
        return $count;
    }

    /**
     * Send to a single subscription. Returns true on 2xx.
     */
    private static function send_one( $sub, $payload ) {
        $endpoint = $sub->endpoint;
        $p256dh   = self::b64url_decode( $sub->p256dh );
        $auth_key = self::b64url_decode( $sub->auth_key );
        if ( ! $p256dh || ! $auth_key ) return false;

        // Encrypt payload.
        $enc = self::encrypt_aes128gcm( $payload, $p256dh, $auth_key );
        if ( is_wp_error( $enc ) ) return false;

        // Build VAPID JWT.
        $audience = self::audience_for_endpoint( $endpoint );
        $jwt = self::vapid_jwt( $audience );
        if ( is_wp_error( $jwt ) ) return false;

        $headers = array(
            'Authorization'    => 'vapid t=' . $jwt . ', k=' . self::public_key(),
            'TTL'              => '86400',
            'Content-Type'     => 'application/octet-stream',
            'Content-Encoding' => 'aes128gcm',
            'Urgency'          => 'normal',
        );

        $resp = wp_remote_post( $endpoint, array(
            'headers' => $headers,
            'body'    => $enc,
            'timeout' => 10,
        ) );
        if ( is_wp_error( $resp ) ) return false;
        $code = (int) wp_remote_retrieve_response_code( $resp );
        // 410 Gone = subscription dead, delete it.
        if ( $code === 410 || $code === 404 ) {
            global $wpdb;
            $wpdb->delete( CMMS_DB::table( 'push_subs' ), array( 'id' => (int) $sub->id ), array( '%d' ) );
            return false;
        }
        return $code >= 200 && $code < 300;
    }

    private static function audience_for_endpoint( $endpoint ) {
        $parts = wp_parse_url( $endpoint );
        return $parts['scheme'] . '://' . $parts['host'];
    }

    /* ============================================================
       VAPID JWT signing
    ============================================================ */

    private static function vapid_jwt( $audience ) {
        $header = array( 'typ' => 'JWT', 'alg' => 'ES256' );
        $payload = array(
            'aud' => $audience,
            'exp' => time() + 12 * HOUR_IN_SECONDS,
            'sub' => self::vapid_subject(),
        );
        $h_b64 = self::b64url_encode( wp_json_encode( $header ) );
        $p_b64 = self::b64url_encode( wp_json_encode( $payload ) );
        $signing_input = $h_b64 . '.' . $p_b64;

        $priv_pem = self::private_key_pem();
        $pkey = openssl_pkey_get_private( $priv_pem );
        if ( ! $pkey ) return new WP_Error( 'vapid', 'Bad key' );

        // openssl_sign with SHA256 produces DER-encoded ECDSA signature.
        // JWT ES256 requires raw 64-byte (R||S) form. Convert.
        $signature_der = '';
        if ( ! openssl_sign( $signing_input, $signature_der, $pkey, OPENSSL_ALGO_SHA256 ) ) {
            return new WP_Error( 'vapid', 'Sign failed' );
        }
        $raw = self::der_to_raw_signature( $signature_der );
        if ( ! $raw ) return new WP_Error( 'vapid', 'Sig convert failed' );

        return $signing_input . '.' . self::b64url_encode( $raw );
    }

    /**
     * Convert DER-encoded ECDSA signature -> 64-byte raw (R||S) for JWT ES256.
     */
    private static function der_to_raw_signature( $der ) {
        // SEQUENCE { INTEGER r, INTEGER s }
        $offset = 0;
        if ( ord( $der[ $offset++ ] ) !== 0x30 ) return false;
        // skip total length
        $len = ord( $der[ $offset++ ] );
        if ( $len & 0x80 ) {
            $offset += $len & 0x7f;
        }
        if ( ord( $der[ $offset++ ] ) !== 0x02 ) return false;
        $r_len = ord( $der[ $offset++ ] );
        $r = substr( $der, $offset, $r_len );
        $offset += $r_len;
        if ( ord( $der[ $offset++ ] ) !== 0x02 ) return false;
        $s_len = ord( $der[ $offset++ ] );
        $s = substr( $der, $offset, $s_len );

        // Strip leading zeros, then pad to 32 bytes.
        $r = ltrim( $r, "\x00" );
        $s = ltrim( $s, "\x00" );
        if ( strlen( $r ) > 32 || strlen( $s ) > 32 ) return false;
        $r = str_pad( $r, 32, "\x00", STR_PAD_LEFT );
        $s = str_pad( $s, 32, "\x00", STR_PAD_LEFT );
        return $r . $s;
    }

    /* ============================================================
       Payload encryption (RFC 8188 / aes128gcm)
    ============================================================ */

    private static function encrypt_aes128gcm( $plaintext, $client_pub_raw, $auth_secret ) {
        // 1. Generate ephemeral ECDH keypair on P-256.
        $eph = openssl_pkey_new( array(
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ) );
        if ( ! $eph ) return new WP_Error( 'enc', 'No EC' );

        $eph_details = openssl_pkey_get_details( $eph );
        $eph_pub_raw = "\x04" . str_pad( $eph_details['ec']['x'], 32, "\0", STR_PAD_LEFT )
                              . str_pad( $eph_details['ec']['y'], 32, "\0", STR_PAD_LEFT );

        // 2. Compute ECDH shared secret with client's public key.
        $shared = self::ecdh_shared_secret( $eph, $client_pub_raw );
        if ( is_wp_error( $shared ) ) return $shared;

        // 3. HKDF chain per RFC 8291.
        $salt = random_bytes( 16 );
        $prk_key = self::hkdf( $auth_secret, $shared, "WebPush: info\x00" . $client_pub_raw . $eph_pub_raw, 32 );
        $cek = self::hkdf( $salt, $prk_key, "Content-Encoding: aes128gcm\x00", 16 );
        $nonce = self::hkdf( $salt, $prk_key, "Content-Encoding: nonce\x00", 12 );

        // 4. AES-128-GCM encryption. Plaintext is padded with 0x02 || 0x00 padding.
        $padded = $plaintext . "\x02";
        $ciphertext = openssl_encrypt( $padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
        if ( $ciphertext === false ) return new WP_Error( 'enc', 'AES failed' );
        $ciphertext .= $tag;

        // 5. Build aes128gcm content-coding header per RFC 8188:
        //    salt(16) || rs(4 big-endian) || idlen(1) || keyid(idlen) || ciphertext
        $idlen = strlen( $eph_pub_raw );
        $rs = pack( 'N', 4096 ); // record size
        $body = $salt . $rs . chr( $idlen ) . $eph_pub_raw . $ciphertext;
        return $body;
    }

    private static function ecdh_shared_secret( $local_pkey, $peer_pub_raw ) {
        // Convert peer's raw uncompressed point to PEM SubjectPublicKeyInfo.
        $peer_pem = self::raw_pub_to_pem( $peer_pub_raw );
        if ( ! $peer_pem ) return new WP_Error( 'ecdh', 'Bad peer key' );

        // openssl_pkey_derive (PHP 7.3+) — preferred path.
        if ( function_exists( 'openssl_pkey_derive' ) ) {
            $secret = openssl_pkey_derive( $peer_pem, $local_pkey, 32 );
            if ( $secret === false ) return new WP_Error( 'ecdh', 'Derive failed' );
            return $secret;
        }

        return new WP_Error( 'ecdh', 'openssl_pkey_derive not available — please upgrade PHP to 7.3+' );
    }

    /**
     * Wrap a raw 65-byte uncompressed P-256 public point in a SubjectPublicKeyInfo PEM.
     */
    private static function raw_pub_to_pem( $raw_65 ) {
        if ( strlen( $raw_65 ) !== 65 || $raw_65[0] !== "\x04" ) return false;
        // ASN.1 prefix for SPKI(EC P-256):
        // SEQUENCE { SEQUENCE { OID ecPublicKey, OID prime256v1 }, BIT STRING { 00 || raw } }
        $prefix = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' );
        $der = $prefix . $raw_65;
        $b64 = chunk_split( base64_encode( $der ), 64 );
        return "-----BEGIN PUBLIC KEY-----\n" . $b64 . "-----END PUBLIC KEY-----\n";
    }

    /**
     * RFC 5869 HKDF using HMAC-SHA-256.
     */
    private static function hkdf( $salt, $ikm, $info, $length ) {
        if ( function_exists( 'hash_hkdf' ) ) {
            return hash_hkdf( 'sha256', $ikm, $length, $info, $salt );
        }
        // Manual HKDF for older PHP.
        $prk = hash_hmac( 'sha256', $ikm, $salt, true );
        $t = '';
        $okm = '';
        $i = 1;
        while ( strlen( $okm ) < $length ) {
            $t = hash_hmac( 'sha256', $t . $info . chr( $i ), $prk, true );
            $okm .= $t;
            $i++;
        }
        return substr( $okm, 0, $length );
    }

    /* ============================================================
       Helpers
    ============================================================ */

    public static function b64url_encode( $bin ) {
        return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
    }
    public static function b64url_decode( $str ) {
        $str = strtr( $str, '-_', '+/' );
        $pad = strlen( $str ) % 4;
        if ( $pad ) $str .= str_repeat( '=', 4 - $pad );
        return base64_decode( $str );
    }
}
