<?php
/**
 * Admin Packages page (1.14.35).
 *
 * Super Admin UI for managing the cmms_packages catalog. Two screens:
 *   - List: table of all packages with quick toggle for is_active
 *   - Edit: full form for one package (all 16 fields)
 *
 * The class only renders and handles form POSTs — it does NOT register
 * the submenu page. That registration lives in CMMS_Admin::menu() so
 * the menu wiring stays in one place.
 *
 * Architecture:
 *   - All reads go through CMMS_Plans for cache consistency.
 *   - Writes go directly to $wpdb->update() / ->insert(), then
 *     CMMS_Plans::flush_cache() to invalidate the per-request cache.
 *   - On a successful save we redirect back to the list with a
 *     "saved=1" query param so the form isn't re-POSTable on refresh
 *     (Post-Redirect-Get pattern).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_Admin_Packages {

    /** Capability required to view/edit. matches the main admin menu. */
    const CAP = 'manage_options';

    /** Slug used in admin.php?page= and in the menu registration. */
    const SLUG = 'cmms-light-packages';

    /**
     * Handle form POSTs early on admin_init, BEFORE the page renders.
     * This is the standard WP pattern for admin forms — early POST
     * handling lets us redirect-after-save without "headers already
     * sent" errors.
     */
    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_handle_save' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_handle_toggle' ) );
    }

    /**
     * Entry point called by the WP admin menu callback. Routes between
     * list and edit views based on the `action` query param. Defaults
     * to list view.
     */
    public static function render() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'cmms-light' ) );
        }
        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
        if ( $action === 'edit' ) {
            self::render_edit();
            return;
        }
        self::render_list();
    }

    /* ============================================================
       LIST VIEW
    ============================================================ */
    private static function render_list() {
        $packages = CMMS_Plans::all_packages();
        $saved = ! empty( $_GET['saved'] );
        $toggled = ! empty( $_GET['toggled'] );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Packages', 'cmms-light' ); ?></h1>
            <hr class="wp-header-end">

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Package saved successfully.', 'cmms-light' ); ?></p>
                </div>
            <?php endif; ?>
            <?php if ( $toggled ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Package status updated.', 'cmms-light' ); ?></p>
                </div>
            <?php endif; ?>

            <p style="max-width:720px;color:#50575e;">
                <?php esc_html_e(
                    'Each package is one plan tier × one billing cycle. Editing a package changes how it appears on /start and how new accounts are billed. The iCredit Page ID field will be used by the payment integration in a later release.',
                    'cmms-light'
                ); ?>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:60px"><?php esc_html_e( 'Order', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Internal Name', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'Display Name', 'cmms-light' ); ?></th>
                        <th style="width:90px"><?php esc_html_e( 'Plan', 'cmms-light' ); ?></th>
                        <th style="width:90px"><?php esc_html_e( 'Cycle', 'cmms-light' ); ?></th>
                        <th style="width:100px"><?php esc_html_e( 'Price', 'cmms-light' ); ?></th>
                        <th style="width:80px"><?php esc_html_e( 'Max Users', 'cmms-light' ); ?></th>
                        <th style="width:110px"><?php esc_html_e( 'Billing', 'cmms-light' ); ?></th>
                        <th><?php esc_html_e( 'iCredit Page ID', 'cmms-light' ); ?></th>
                        <th style="width:90px"><?php esc_html_e( 'Active', 'cmms-light' ); ?></th>
                        <th style="width:120px"><?php esc_html_e( 'Actions', 'cmms-light' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $packages ) ) : ?>
                    <tr><td colspan="11"><?php esc_html_e( 'No packages found.', 'cmms-light' ); ?></td></tr>
                <?php else : foreach ( $packages as $p ) :
                    $edit_url = add_query_arg( array(
                        'page'   => self::SLUG,
                        'action' => 'edit',
                        'id'     => $p['id'],
                    ), admin_url( 'admin.php' ) );
                    $toggle_url = wp_nonce_url(
                        add_query_arg( array(
                            'page'      => self::SLUG,
                            'cmms_pkg_toggle' => $p['id'],
                        ), admin_url( 'admin.php' ) ),
                        'cmms_pkg_toggle_' . $p['id']
                    );
                ?>
                    <tr>
                        <td><?php echo (int) $p['sort_order']; ?></td>
                        <td><code><?php echo esc_html( $p['internal_name'] ); ?></code></td>
                        <td><strong><?php echo esc_html( $p['display_name'] ); ?></strong></td>
                        <td><?php echo esc_html( $p['plan_type'] ?? '—' ); ?></td>
                        <td><?php echo esc_html( $p['billing_cycle'] ); ?></td>
                        <td>
                            <?php if ( $p['custom_price'] ) : ?>
                                <span style="color:#888">Custom</span>
                            <?php else : ?>
                                ₪<?php echo esc_html( number_format( (float) $p['price'] ) ); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $p['max_users'] === null ? '∞' : (int) $p['max_users']; ?>
                        </td>
                        <td>
                            <?php
                            $modes = array(
                                'recurring' => 'Recurring',
                                'one_time'  => 'One-time',
                                'manual'    => 'Manual',
                            );
                            echo esc_html( $modes[ $p['billing_mode'] ] ?? $p['billing_mode'] );
                            ?>
                        </td>
                        <td>
                            <?php if ( ! empty( $p['icredit_page_id'] ) ) : ?>
                                <code style="font-size:11px"><?php echo esc_html( $p['icredit_page_id'] ); ?></code>
                            <?php else : ?>
                                <span style="color:#a00">— not set —</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $p['is_active'] ) : ?>
                                <span style="color:#46b450; font-weight:600;">✓ Active</span>
                            <?php else : ?>
                                <span style="color:#a00">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
                                <?php esc_html_e( 'Edit', 'cmms-light' ); ?>
                            </a>
                            <a href="<?php echo esc_url( $toggle_url ); ?>"
                               class="button button-small"
                               onclick="return confirm('<?php echo esc_js( __( 'Toggle active status?', 'cmms-light' ) ); ?>');">
                                <?php echo $p['is_active']
                                    ? esc_html__( 'Disable', 'cmms-light' )
                                    : esc_html__( 'Enable', 'cmms-light' ); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ============================================================
       EDIT VIEW
    ============================================================ */
    private static function render_edit() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        if ( ! $id ) {
            self::render_list();
            return;
        }

        // Fetch the row directly from DB so we always see the latest
        // (cache-free) values. We're about to edit, after all.
        global $wpdb;
        $t = CMMS_DB::table( 'packages' );
        $p = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
        if ( ! $p ) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Package not found.', 'cmms-light' ); ?></h1>
                <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>">
                    <?php esc_html_e( '← Back to packages', 'cmms-light' ); ?>
                </a></p>
            </div>
            <?php
            return;
        }

        // Decode features for display in textarea (one per line — easier
        // to edit than JSON).
        $features = CMMS_Plans::all_packages(); // ensures cache populated
        $features_text = '';
        $features_raw = $p['features'] ?? '';
        $decoded = json_decode( $features_raw, true );
        if ( is_array( $decoded ) ) {
            $features_text = implode( "\n", $decoded );
        } else {
            $features_text = (string) $features_raw;
        }

        $back_url = admin_url( 'admin.php?page=' . self::SLUG );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php esc_html_e( 'Edit Package:', 'cmms-light' ); ?>
                <?php echo esc_html( $p['display_name'] . ' ' . $p['billing_cycle'] ); ?>
            </h1>
            <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
                <?php esc_html_e( '← Back to list', 'cmms-light' ); ?>
            </a>
            <hr class="wp-header-end">

            <form method="post" action="">
                <?php wp_nonce_field( 'cmms_pkg_save_' . $id, 'cmms_pkg_save_nonce' ); ?>
                <input type="hidden" name="cmms_pkg_save" value="1">
                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">

                <table class="form-table" role="presentation">
                    <tbody>

                    <tr>
                        <th scope="row"><label for="internal_name"><?php esc_html_e( 'Internal Name', 'cmms-light' ); ?></label></th>
                        <td>
                            <input name="internal_name" id="internal_name" type="text" class="regular-text"
                                   value="<?php echo esc_attr( $p['internal_name'] ); ?>" required maxlength="64">
                            <p class="description"><?php esc_html_e( 'System identifier. No spaces. Used internally — do not change unless you know what you are doing.', 'cmms-light' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="display_name"><?php esc_html_e( 'Display Name', 'cmms-light' ); ?></label></th>
                        <td>
                            <input name="display_name" id="display_name" type="text" class="regular-text"
                                   value="<?php echo esc_attr( $p['display_name'] ); ?>" required maxlength="190">
                            <p class="description"><?php esc_html_e( 'Shown to users on the pricing page (e.g. "Starter", "Business").', 'cmms-light' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="plan_type"><?php esc_html_e( 'Plan Type', 'cmms-light' ); ?></label></th>
                        <td>
                            <select name="plan_type" id="plan_type">
                                <option value="">— none —</option>
                                <?php foreach ( array( 'starter', 'business', 'enterprise' ) as $opt ) : ?>
                                    <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $p['plan_type'], $opt ); ?>><?php echo esc_html( ucfirst( $opt ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Plan tier. Packages with the same plan_type appear together as one column on /start.', 'cmms-light' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="billing_cycle"><?php esc_html_e( 'Billing Cycle', 'cmms-light' ); ?></label></th>
                        <td>
                            <select name="billing_cycle" id="billing_cycle">
                                <option value="monthly" <?php selected( $p['billing_cycle'], 'monthly' ); ?>>Monthly</option>
                                <option value="yearly"  <?php selected( $p['billing_cycle'], 'yearly' ); ?>>Yearly</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="price"><?php esc_html_e( 'Price (₪)', 'cmms-light' ); ?></label></th>
                        <td>
                            <input name="price" id="price" type="number" step="0.01" min="0" class="small-text"
                                   value="<?php echo esc_attr( $p['price'] ); ?>" required>
                            <p class="description"><?php esc_html_e( 'For yearly packages, this is the total annual price (already discounted).', 'cmms-light' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="max_users"><?php esc_html_e( 'Max Users', 'cmms-light' ); ?></label></th>
                        <td>
                            <input name="max_users" id="max_users" type="number" min="0" class="small-text"
                                   value="<?php echo $p['max_users'] === null ? '' : esc_attr( $p['max_users'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Leave empty for unlimited (Enterprise).', 'cmms-light' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="billing_mode"><?php esc_html_e( 'Billing Mode', 'cmms-light' ); ?></label></th>
                        <td>
                            <select name="billing_mode" id="billing_mode">
                                <option value="recurring" <?php selected( $p['billing_mode'], 'recurring' ); ?>>Recurring (auto-billed)</option>
                                <option value="one_time"  <?php selected( $p['billing_mode'], 'one_time' ); ?>>One-time</option>
                                <option value="manual"    <?php selected( $p['billing_mode'], 'manual' ); ?>>Manual (admin handles billing)</option>
                            </select>
                            <p class="description"><?php esc_html_e( 'How this package is charged. iCredit integration will respect this setting.', 'cmms-light' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="icredit_page_id"><?php esc_html_e( 'iCredit Page ID / Product ID', 'cmms-light' ); ?></label></th>
                        <td>
                            <input name="icredit_page_id" id="icredit_page_id" type="text" class="regular-text"
                                   value="<?php echo esc_attr( $p['icredit_page_id'] ?? '' ); ?>" maxlength="190">
                            <p class="description"><?php esc_html_e( 'External reference for the iCredit payment page used by this package. Optional — leave empty to use ad-hoc GetUrl flow.', 'cmms-light' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="external_payment_reference"><?php esc_html_e( 'External Payment Reference', 'cmms-light' ); ?></label></th>
                        <td>
                            <input name="external_payment_reference" id="external_payment_reference" type="text" class="regular-text"
                                   value="<?php echo esc_attr( $p['external_payment_reference'] ?? '' ); ?>" maxlength="190">
                            <p class="description"><?php esc_html_e( 'Optional additional reference (product code, internal ID) for cross-system reconciliation.', 'cmms-light' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="success_redirect_url"><?php esc_html_e( 'Success Redirect URL', 'cmms-light' ); ?></label></th>
                        <td>
                            <input name="success_redirect_url" id="success_redirect_url" type="url" class="large-text"
                                   value="<?php echo esc_attr( $p['success_redirect_url'] ?? '' ); ?>">
                            <p class="description"><?php esc_html_e( 'Where to send the user after successful payment. Leave empty to use site default.', 'cmms-light' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="failure_redirect_url"><?php esc_html_e( 'Failure Redirect URL', 'cmms-light' ); ?></label></th>
                        <td>
                            <input name="failure_redirect_url" id="failure_redirect_url" type="url" class="large-text"
                                   value="<?php echo esc_attr( $p['failure_redirect_url'] ?? '' ); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="cancel_redirect_url"><?php esc_html_e( 'Cancel Redirect URL', 'cmms-light' ); ?></label></th>
                        <td>
                            <input name="cancel_redirect_url" id="cancel_redirect_url" type="url" class="large-text"
                                   value="<?php echo esc_attr( $p['cancel_redirect_url'] ?? '' ); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="sort_order"><?php esc_html_e( 'Sort Order', 'cmms-light' ); ?></label></th>
                        <td>
                            <input name="sort_order" id="sort_order" type="number" class="small-text"
                                   value="<?php echo (int) $p['sort_order']; ?>">
                            <p class="description"><?php esc_html_e( 'Lower numbers appear first on /start.', 'cmms-light' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="tagline"><?php esc_html_e( 'Tagline', 'cmms-light' ); ?></label></th>
                        <td>
                            <input name="tagline" id="tagline" type="text" class="large-text"
                                   value="<?php echo esc_attr( $p['tagline'] ?? '' ); ?>" maxlength="255">
                            <p class="description"><?php esc_html_e( 'One-line positioning shown under the plan name on /start.', 'cmms-light' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="features"><?php esc_html_e( 'Features', 'cmms-light' ); ?></label></th>
                        <td>
                            <textarea name="features" id="features" rows="8" class="large-text code"><?php echo esc_textarea( $features_text ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'One feature per line. These appear as checkmarked bullets on the pricing card.', 'cmms-light' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Flags', 'cmms-light' ); ?></th>
                        <td>
                            <label>
                                <input name="is_active" type="checkbox" value="1" <?php checked( $p['is_active'], 1 ); ?>>
                                <?php esc_html_e( 'Active (package exists in system)', 'cmms-light' ); ?>
                            </label><br>
                            <label>
                                <input name="show_on_pricing" type="checkbox" value="1" <?php checked( $p['show_on_pricing'], 1 ); ?>>
                                <?php esc_html_e( 'Show on /start pricing page', 'cmms-light' ); ?>
                            </label><br>
                            <label>
                                <input name="manual_only" type="checkbox" value="1" <?php checked( $p['manual_only'], 1 ); ?>>
                                <?php esc_html_e( 'Manual only — available only for admin-created accounts', 'cmms-light' ); ?>
                            </label><br>
                            <label>
                                <input name="recommended" type="checkbox" value="1" <?php checked( $p['recommended'], 1 ); ?>>
                                <?php esc_html_e( 'Recommended — highlight on /start ("Most Popular" badge)', 'cmms-light' ); ?>
                            </label><br>
                            <label>
                                <input name="custom_price" type="checkbox" value="1" <?php checked( $p['custom_price'], 1 ); ?>>
                                <?php esc_html_e( 'Custom price — show "From X" prefix instead of fixed price', 'cmms-light' ); ?>
                            </label>
                        </td>
                    </tr>

                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Save Package', 'cmms-light' ); ?>
                    </button>
                    <a href="<?php echo esc_url( $back_url ); ?>" class="button">
                        <?php esc_html_e( 'Cancel', 'cmms-light' ); ?>
                    </a>
                </p>
            </form>
        </div>
        <?php
    }

    /* ============================================================
       SAVE HANDLER
    ============================================================ */
    public static function maybe_handle_save() {
        if ( empty( $_POST['cmms_pkg_save'] ) ) return;
        if ( ! current_user_can( self::CAP ) ) return;

        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        if ( ! $id ) return;

        // Nonce is keyed to the specific row id so a stale form for
        // package A can't write into package B.
        check_admin_referer( 'cmms_pkg_save_' . $id, 'cmms_pkg_save_nonce' );

        global $wpdb;
        $t = CMMS_DB::table( 'packages' );

        // Sanitize and validate. We accept invalid plan_type as null
        // (custom package), and treat empty max_users as null.
        $data = array(
            'internal_name'         => isset( $_POST['internal_name'] ) ? sanitize_key( wp_unslash( $_POST['internal_name'] ) ) : '',
            'display_name'          => isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
            'plan_type'             => isset( $_POST['plan_type'] ) ? sanitize_key( wp_unslash( $_POST['plan_type'] ) ) : null,
            'billing_cycle'         => isset( $_POST['billing_cycle'] ) ? sanitize_key( wp_unslash( $_POST['billing_cycle'] ) ) : 'monthly',
            'price'                 => isset( $_POST['price'] ) ? (float) $_POST['price'] : 0,
            'max_users'             => ( isset( $_POST['max_users'] ) && $_POST['max_users'] !== '' ) ? (int) $_POST['max_users'] : null,
            'billing_mode'          => isset( $_POST['billing_mode'] ) ? sanitize_key( wp_unslash( $_POST['billing_mode'] ) ) : 'recurring',
            'icredit_page_id'       => isset( $_POST['icredit_page_id'] ) ? sanitize_text_field( wp_unslash( $_POST['icredit_page_id'] ) ) : '',
            'external_payment_reference' => isset( $_POST['external_payment_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['external_payment_reference'] ) ) : '',
            'success_redirect_url'  => isset( $_POST['success_redirect_url'] ) ? esc_url_raw( wp_unslash( $_POST['success_redirect_url'] ) ) : '',
            'failure_redirect_url'  => isset( $_POST['failure_redirect_url'] ) ? esc_url_raw( wp_unslash( $_POST['failure_redirect_url'] ) ) : '',
            'cancel_redirect_url'   => isset( $_POST['cancel_redirect_url'] ) ? esc_url_raw( wp_unslash( $_POST['cancel_redirect_url'] ) ) : '',
            'sort_order'            => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
            'tagline'               => isset( $_POST['tagline'] ) ? sanitize_text_field( wp_unslash( $_POST['tagline'] ) ) : '',
            'is_active'             => ! empty( $_POST['is_active'] ) ? 1 : 0,
            'show_on_pricing'       => ! empty( $_POST['show_on_pricing'] ) ? 1 : 0,
            'manual_only'           => ! empty( $_POST['manual_only'] ) ? 1 : 0,
            'recommended'           => ! empty( $_POST['recommended'] ) ? 1 : 0,
            'custom_price'          => ! empty( $_POST['custom_price'] ) ? 1 : 0,
            'updated_at'            => current_time( 'mysql' ),
        );

        // Features: split textarea on newlines, trim, drop empties,
        // encode as JSON for storage. This is the inverse of the
        // decoding in CMMS_Plans::decode_features().
        $raw_features = isset( $_POST['features'] ) ? (string) wp_unslash( $_POST['features'] ) : '';
        $lines = preg_split( '/\r\n|\r|\n/', $raw_features );
        $lines = array_values( array_filter( array_map( 'trim', $lines ), function( $l ) { return $l !== ''; } ) );
        // Sanitize each line — we allow plain text only, no HTML.
        $lines = array_map( 'sanitize_text_field', $lines );
        $data['features'] = wp_json_encode( $lines );

        // Validate the critical fields. If invalid, fall through to
        // the redirect with an error param — the list view will show it.
        $error = null;
        if ( $data['internal_name'] === '' )   $error = 'internal_name_required';
        elseif ( $data['display_name'] === '' ) $error = 'display_name_required';
        elseif ( ! in_array( $data['billing_cycle'], array( 'monthly', 'yearly' ), true ) ) $error = 'invalid_cycle';
        elseif ( ! in_array( $data['billing_mode'], array( 'recurring', 'one_time', 'manual' ), true ) ) $error = 'invalid_mode';
        elseif ( $data['price'] < 0 ) $error = 'invalid_price';

        // plan_type can be empty (custom rows). If non-empty, restrict
        // to the standard tiers to avoid typos.
        if ( ! $error && $data['plan_type'] !== '' && $data['plan_type'] !== null ) {
            if ( ! in_array( $data['plan_type'], array( 'starter', 'business', 'enterprise' ), true ) ) {
                $error = 'invalid_plan_type';
            }
        }
        // Normalize empty plan_type to null so the UNIQUE key works.
        if ( $data['plan_type'] === '' ) $data['plan_type'] = null;

        if ( $error ) {
            wp_safe_redirect( add_query_arg( array(
                'page'   => self::SLUG,
                'action' => 'edit',
                'id'     => $id,
                'error'  => $error,
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        // Build format array matching $data order. nullable fields use
        // %s with explicit null; wpdb handles that correctly.
        // Order: internal_name, display_name, plan_type, billing_cycle,
        //   price, max_users, billing_mode, icredit_page_id,
        //   external_payment_reference (1.14.40), success/failure/cancel
        //   redirects, sort_order, tagline, is_active, show_on_pricing,
        //   manual_only, recommended, custom_price, updated_at, features
        $format = array(
            '%s','%s','%s','%s','%f',
            $data['max_users'] === null ? null : '%d',
            '%s','%s','%s','%s','%s','%s','%d','%s',
            '%d','%d','%d','%d','%d','%s','%s',
        );

        $wpdb->update( $t, $data, array( 'id' => $id ), $format, array( '%d' ) );
        CMMS_Plans::flush_cache();

        // Post-Redirect-Get to prevent re-submission on browser refresh.
        wp_safe_redirect( add_query_arg( array(
            'page'  => self::SLUG,
            'saved' => 1,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ============================================================
       QUICK TOGGLE (Enable/Disable from list view)
    ============================================================ */
    public static function maybe_handle_toggle() {
        if ( ! isset( $_GET['cmms_pkg_toggle'] ) ) return;
        if ( ! current_user_can( self::CAP ) ) return;

        $id = (int) $_GET['cmms_pkg_toggle'];
        if ( ! $id ) return;

        check_admin_referer( 'cmms_pkg_toggle_' . $id );

        global $wpdb;
        $t = CMMS_DB::table( 'packages' );
        // Atomic flip — uses bitwise XOR so two simultaneous clicks
        // can't leave the state in an unexpected place.
        $wpdb->query( $wpdb->prepare(
            "UPDATE $t SET is_active = 1 - is_active, updated_at = %s WHERE id = %d",
            current_time( 'mysql' ),
            $id
        ) );
        CMMS_Plans::flush_cache();

        wp_safe_redirect( add_query_arg( array(
            'page'    => self::SLUG,
            'toggled' => 1,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }
}

// Bootstrap the form handlers on every admin load. Render is invoked
// by WP when the submenu page is visited.
CMMS_Admin_Packages::init();
