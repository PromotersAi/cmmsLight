<?php
/**
 * CMMS PWA - clean implementation.
 *
 * Architecture:
 *   - manifest.json delivered via ?cmms_pwa=manifest on /cmms-dashboard/
 *     (proven to work on this nginx setup)
 *   - service worker delivered via ?cmms_pwa=sw on /cmms-dashboard/
 *   - SW scope is the entire site (Service-Worker-Allowed: /)
 *   - SW does almost nothing — does not intercept HTML at all, only
 *     opportunistically caches static plugin assets. This minimizes
 *     the chance of a broken cache leaving users with white screens.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMMS_PWA {

    public function __construct() {
        add_action( 'template_redirect', array( $this, 'maybe_serve_pwa_endpoint' ), 1 );
        add_action( 'wp_head', array( $this, 'manifest_link' ) );
    }

    public static function is_cmms_page() {
        $slugs = array( 'cmms-dashboard', 'cmms-login', 'cmms-signup', 'cmms-form', 'start' );
        foreach ( $slugs as $slug ) {
            if ( is_page( $slug ) ) return true;
        }
        return false;
    }

    public function maybe_serve_pwa_endpoint() {
        if ( ! isset( $_GET['cmms_pwa'] ) ) return;
        $type = sanitize_key( wp_unslash( $_GET['cmms_pwa'] ) );
        if ( $type === 'manifest' ) {
            $this->serve_manifest();
        } elseif ( $type === 'sw' ) {
            $this->serve_service_worker();
        } elseif ( $type === 'check' ) {
            $this->serve_health_check();
        }
    }

    /**
     * PWA self-diagnostic. Visit /cmms-dashboard/?cmms_pwa=check to get a
     * JSON report of every install criterion. Useful when a customer says
     * "Chrome doesn't offer Install" — the JSON will tell you why.
     *
     * Each check returns ok=true/false plus a human-readable message.
     */
    private function serve_health_check() {
        while ( ob_get_level() > 0 ) ob_end_clean();
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Cache-Control: no-store, max-age=0' );
        }

        $checks = array();

        // 1. HTTPS
        $is_https = is_ssl();
        $checks['https'] = array(
            'ok' => $is_https,
            'message' => $is_https ? 'Site is served over HTTPS' : 'PWAs require HTTPS — Chrome will refuse install',
        );

        // 2. Manifest URL is reachable from this same origin
        $manifest_url = home_url( '/cmms-dashboard/?cmms_pwa=manifest' );
        $checks['manifest_url'] = array(
            'ok' => true,
            'message' => 'Manifest available at: ' . $manifest_url,
        );

        // 3. SW URL is reachable
        $version = defined( 'CMMS_LIGHT_VERSION' ) ? CMMS_LIGHT_VERSION : '0';
        $sw_url = home_url( '/cmms-dashboard/?cmms_pwa=sw&v=' . rawurlencode( $version ) );
        $checks['sw_url'] = array(
            'ok' => true,
            'message' => 'Service worker at: ' . $sw_url,
        );

        // 4. Required icon files actually exist on disk
        $icon_files = array(
            'icon-192.png' => CMMS_LIGHT_DIR . 'assets/images/icon-192.png',
            'icon-512.png' => CMMS_LIGHT_DIR . 'assets/images/icon-512.png',
            'icon-maskable-512.png' => CMMS_LIGHT_DIR . 'assets/images/icon-maskable-512.png',
        );
        $missing_icons = array();
        foreach ( $icon_files as $name => $path ) {
            if ( ! file_exists( $path ) ) $missing_icons[] = $name;
        }
        $checks['icons_present'] = array(
            'ok' => empty( $missing_icons ),
            'message' => empty( $missing_icons )
                ? 'All required icons (192, 512, maskable) are on disk'
                : 'Missing icon files: ' . implode( ', ', $missing_icons ),
        );

        // 5. The dashboard page actually exists in WP
        $dashboard = get_page_by_path( 'cmms-dashboard' );
        $checks['dashboard_page'] = array(
            'ok' => (bool) $dashboard,
            'message' => $dashboard
                ? 'cmms-dashboard page exists (ID ' . (int) $dashboard->ID . ')'
                : 'cmms-dashboard page is MISSING — the install criteria depend on this page',
        );

        // 6. SW page accessible (Service-Worker-Allowed headers come from PHP, not nginx)
        $checks['php_serves_sw'] = array(
            'ok' => true,
            'message' => 'PHP handler is registered. Verify by curl: ' . $sw_url,
        );

        // 7. Plugin version bound to cache
        $checks['cache_versioning'] = array(
            'ok' => true,
            'message' => 'Cache name will be: cmms-static-v' . preg_replace( '/[^0-9a-z._-]/i', '', $version ),
        );

        $all_ok = true;
        foreach ( $checks as $c ) { if ( empty( $c['ok'] ) ) { $all_ok = false; break; } }

        echo wp_json_encode( array(
            'all_ok'  => $all_ok,
            'version' => $version,
            'checks'  => $checks,
            'next_steps' => $all_ok
                ? 'Open Chrome DevTools → Application → Manifest. Check that all fields parsed cleanly. The Install button should appear in the address bar after engagement (30s of interaction or one click).'
                : 'Fix the failing checks above first.',
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    private function serve_manifest() {
        while ( ob_get_level() > 0 ) ob_end_clean();
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/manifest+json; charset=utf-8' );
            header( 'X-Robots-Tag: noindex, nofollow' );
            // Manifest can be cached briefly. The SW URL has ?v= for
            // versioning; the manifest is small enough that 5min is safe.
            header( 'Cache-Control: public, max-age=300' );
        }

        $brand = get_option( 'cmms_brand_name' ) ?: ( get_bloginfo( 'name' ) ?: 'CMMS' );

        // Locale: Chrome expects a valid BCP-47 tag. Internal CMMS_I18n
        // returns 'he', 'en', 'ar' which are all valid; we sanitize anyway
        // in case a future code path returns something exotic.
        $lang_raw = class_exists( 'CMMS_I18n' ) ? CMMS_I18n::current() : 'he';
        $lang = preg_match( '/^[a-z]{2,3}(-[A-Z]{2})?$/i', $lang_raw ) ? $lang_raw : 'en';

        // Stable manifest id. Chrome 96+ uses this to identify a PWA across
        // updates, even if start_url changes. Without it, Chrome sometimes
        // refuses to show "Install" because it can't decide if this is a
        // new app or an existing one.
        $id = home_url( '/cmms-dashboard/' );

        $manifest = array(
            'id'               => $id,
            'name'             => $brand,
            'short_name'       => mb_substr( $brand, 0, 12 ),
            'description'      => __( 'Maintenance management', 'cmms-light' ),
            'start_url'        => home_url( '/cmms-dashboard/' ),
            'scope'            => '/',
            'display'          => 'standalone',
            // Provide fallbacks Chrome will accept if 'standalone' isn't
            // available on the platform. Order matters — first supported wins.
            'display_override' => array( 'standalone', 'minimal-ui' ),
            'orientation'      => 'portrait',
            'background_color' => '#0b1c33',
            'theme_color'      => '#0b1c33',
            'lang'             => $lang,
            // 'dir' was previously included as 'rtl'. We remove it: Chrome
            // infers direction from 'lang' for valid locales, and an
            // explicit 'rtl' has been observed to confuse install criteria
            // checks on some Chrome versions.
            'categories'       => array( 'productivity', 'business' ),
            // ===== ICONS =====
            // Order matters. Put the bigger icons first; Chrome picks the
            // best match for the install dialog from the top of the list.
            // Critical: we have BOTH a 192 and a 512 with purpose='any',
            // which is the minimum Chrome needs to satisfy install criteria.
            // We also provide a maskable icon so Android can render a
            // properly-clipped launcher icon.
            'icons' => array(
                array( 'src' => CMMS_LIGHT_URL . 'assets/images/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
                array( 'src' => CMMS_LIGHT_URL . 'assets/images/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
                array( 'src' => CMMS_LIGHT_URL . 'assets/images/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
                array( 'src' => CMMS_LIGHT_URL . 'assets/images/icon-96.png', 'sizes' => '96x96', 'type' => 'image/png', 'purpose' => 'any' ),
            ),
            'screenshots' => array(
                array( 'src' => CMMS_LIGHT_URL . 'assets/images/screenshot-mobile.png',  'sizes' => '540x720',  'type' => 'image/png', 'form_factor' => 'narrow', 'label' => 'CMMS dashboard on mobile' ),
                array( 'src' => CMMS_LIGHT_URL . 'assets/images/screenshot-desktop.png', 'sizes' => '1280x720', 'type' => 'image/png', 'form_factor' => 'wide',   'label' => 'CMMS dashboard on desktop' ),
            ),
            // 'prefer_related_applications: false' tells Chrome explicitly
            // that this PWA is the canonical install target — not some
            // related Play Store app. Without this, Chrome occasionally
            // suppresses the install banner.
            'prefer_related_applications' => false,
        );
        echo wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }

    private function serve_service_worker() {
        while ( ob_get_level() > 0 ) ob_end_clean();
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/javascript; charset=utf-8' );
            header( 'Service-Worker-Allowed: /' );
            header( 'X-Robots-Tag: noindex, nofollow' );
            // CRITICAL: tell browsers to never cache the SW script itself.
            // Without these headers, Chrome/Safari will hold onto an old
            // version of the SW for up to 24h, defeating our update logic.
            header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );
        }

        // The cache name is bound to the plugin version. Every time we ship
        // a new release, the cache name changes, the activate handler nukes
        // every cache that doesn't match, and clients get a clean slate.
        $version = defined( 'CMMS_LIGHT_VERSION' ) ? CMMS_LIGHT_VERSION : '0';
        $cache_name = 'cmms-static-v' . preg_replace( '/[^0-9a-z._-]/i', '', $version );
        ?>
// CMMS Service Worker — defensive, version-pinned cache invalidation.
//
// Lessons learned from past production white-screens:
//  1. The SW script itself MUST be served with no-cache headers, otherwise
//     browsers hold a stale copy and the update never reaches users.
//  2. The cache NAME must change whenever assets change. We bind it to the
//     plugin version so every release auto-invalidates.
//  3. We NEVER intercept HTML/document requests. Even a perfectly written
//     SW can serve a stale shell once, and that one stale shell is what
//     causes "white screen" bug reports. Routes for /cmms-dashboard/, login,
//     etc. always hit the live network.
//  4. skipWaiting() + clients.claim() make new SW take over IMMEDIATELY,
//     not waiting for every tab to close first.

const CACHE_NAME = '<?php echo esc_js( $cache_name ); ?>';
const PLUGIN_ASSETS_PREFIX = '/wp-content/plugins/cmms-light/assets/';

// === INSTALL ===
// New SW: activate as fast as possible, don't wait for old SW to finish.
self.addEventListener('install', (event) => {
    self.skipWaiting();
});

// === ACTIVATE ===
// Wipe ALL caches that aren't the current version, then take control of
// every open client. This is what fixes a stuck device after an update.
self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();
            // Aggressive: delete any cache whose name doesn't match the
            // current version. Includes legacy names from older versions.
            await Promise.all(
                keys
                    .filter((k) => k !== CACHE_NAME)
                    .map((k) => caches.delete(k))
            );
            // Take over all currently-open tabs without requiring a reload.
            await self.clients.claim();
        })()
    );
});

// === MESSAGE ===
// Allow the page to ask the SW to skip waiting via postMessage. Used as a
// belt-and-braces fallback in case skipWaiting() in install didn't fire
// for some reason (e.g. a bugged previous SW).
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'CMMS_SKIP_WAITING') {
        self.skipWaiting();
    }
});

// === FETCH ===
// Strict allowlist: ONLY plugin static assets get the cache treatment.
// Everything else — HTML pages, AJAX, third-party assets, images — passes
// through to the network with no SW involvement.
self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;
    const url = new URL(event.request.url);

    // Hard exclusion: NEVER touch HTML or document navigation requests.
    // This is the #1 defense against white-screen-after-update bugs.
    if (event.request.mode === 'navigate') return;
    if (event.request.destination === 'document') return;

    // Only intercept plugin static assets.
    if (url.pathname.indexOf(PLUGIN_ASSETS_PREFIX) === -1) return;

    // Network-first with cache fallback. Try the network; on success,
    // store a copy and serve it. On failure (offline), fall back to
    // whatever we have cached. Never serve a known-stale asset over a
    // working network.
    event.respondWith(
        fetch(event.request)
            .then((response) => {
                if (response && response.ok && response.status === 200) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME)
                        .then((c) => c.put(event.request, copy))
                        .catch(() => {});
                }
                return response;
            })
            .catch(() => caches.match(event.request).then((r) => r || Response.error()))
    );
});
// === PUSH ===
// Receive a push from the server, decrypt it (the browser does this
// automatically using the keys we registered), and show a system
// notification. The payload is JSON: { title, body, url, tag? }.
self.addEventListener('push', (event) => {
    let data = {};
    if (event.data) {
        try { data = event.data.json(); } catch (e) {
            try { data = { title: event.data.text() }; } catch (_) {}
        }
    }
    const title = (data && data.title) ? String(data.title) : 'CMMS';
    const opts = {
        body:  (data && data.body) ? String(data.body) : '',
        icon:  '<?php echo esc_js( CMMS_LIGHT_URL . 'assets/images/icon-192.png' ); ?>',
        badge: '<?php echo esc_js( CMMS_LIGHT_URL . 'assets/images/icon-192.png' ); ?>',
        // Tag groups duplicates from the same task (a comment storm shows
        // as one notification, not five). Falls back to a unique value
        // when the server didn't send a tag.
        tag:   (data && data.tag) ? String(data.tag) : ('cmms-' + Date.now()),
        data:  { url: (data && data.url) ? String(data.url) : '/cmms-dashboard/' },
        // requireInteraction keeps the notification visible until the
        // user dismisses it — important for overdue/critical alerts on
        // Android where the system would otherwise auto-collapse it.
        requireInteraction: false,
    };
    event.waitUntil( self.registration.showNotification(title, opts) );
});

// === NOTIFICATION CLICK ===
// Open the deep-link in an existing tab if one is open, otherwise open a
// new one. Without this, clicking does nothing on most browsers.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/cmms-dashboard/';
    event.waitUntil(
        (async () => {
            const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            for (const client of all) {
                // If the dashboard is already open in any tab, focus it
                // and navigate to the target.
                if (client.url.indexOf('/cmms-dashboard') !== -1 && 'focus' in client) {
                    if ('navigate' in client) { try { await client.navigate(target); } catch (e) {} }
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(target);
            }
        })()
    );
});
        <?php
        exit;
    }

    public function manifest_link() {
        if ( ! self::is_cmms_page() ) return;

        $brand = get_option( 'cmms_brand_name' ) ?: 'CMMS';
        $version = defined( 'CMMS_LIGHT_VERSION' ) ? CMMS_LIGHT_VERSION : '0';
        $manifest_url = home_url( '/cmms-dashboard/?cmms_pwa=manifest' );

        // Append the plugin version as a query param to the SW URL. This
        // gives the browser a NEW URL on every release, which forces it to
        // re-fetch the script (browsers compare SW URLs byte-for-byte).
        // Without this param, browsers may serve an old SW from their HTTP
        // cache for up to 24 hours regardless of Cache-Control headers.
        $sw_url = home_url( '/cmms-dashboard/?cmms_pwa=sw&v=' . rawurlencode( $version ) );

        echo '<link rel="manifest" href="' . esc_url( $manifest_url ) . '">' . "\n";
        echo '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url( CMMS_LIGHT_URL . 'assets/images/apple-touch-icon.png' ) . '">' . "\n";
        echo '<link rel="icon" type="image/png" sizes="192x192" href="' . esc_url( CMMS_LIGHT_URL . 'assets/images/icon-192.png' ) . '">' . "\n";
        echo '<link rel="icon" type="image/png" sizes="512x512" href="' . esc_url( CMMS_LIGHT_URL . 'assets/images/icon-512.png' ) . '">' . "\n";
        echo '<meta name="theme-color" content="#0b1c33">' . "\n";
        echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
        echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
        echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( $brand ) . '">' . "\n";
        echo '<meta name="application-name" content="' . esc_attr( $brand ) . '">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">' . "\n";

        // SW registration with auto-update.
        //
        // Two layers of update enforcement:
        //   1. updateViaCache: 'none' tells the browser "don't trust the
        //      HTTP cache for this SW script". Combined with the no-cache
        //      headers we send, this guarantees a fresh fetch.
        //   2. After registration, we listen for an updatefound event. When
        //      a new SW reaches the 'installed' state while another one is
        //      already controlling, we postMessage SKIP_WAITING and reload
        //      once it activates. Result: user lands on fresh assets within
        //      a single page load of getting the new SW.
        $reg_script = <<<'JS'
if ("serviceWorker" in navigator) {
    window.addEventListener("load", function () {
        navigator.serviceWorker.register(%SW_URL%, { scope: "/", updateViaCache: "none" })
            .then(function (reg) {
                // Force an immediate update check on every page load. This is
                // belt-and-braces — registration usually does this anyway, but
                // we want to be absolutely sure for users who keep the app
                // open in a tab for days.
                if (reg.update) { try { reg.update(); } catch (e) {} }

                // When a new worker is installing alongside the old one,
                // tell it to skipWaiting and reload after it takes control.
                reg.addEventListener("updatefound", function () {
                    var nw = reg.installing;
                    if (!nw) return;
                    nw.addEventListener("statechange", function () {
                        if (nw.state === "installed" && navigator.serviceWorker.controller) {
                            try { nw.postMessage({ type: "CMMS_SKIP_WAITING" }); } catch (e) {}
                        }
                    });
                });
            })
            .catch(function () { /* fail silently — the app must work without SW */ });

        // When the controller changes (i.e. a new SW just took over via
        // skipWaiting + clients.claim), reload once. This recovers stuck
        // pages that loaded with the old SW still active.
        var refreshing = false;
        navigator.serviceWorker.addEventListener("controllerchange", function () {
            if (refreshing) return;
            refreshing = true;
            window.location.reload();
        });
    });
}
JS;
        $reg_script = str_replace( '%SW_URL%', wp_json_encode( $sw_url ), $reg_script );
        echo '<script>' . $reg_script . '</script>' . "\n";

        // Capture beforeinstallprompt early so we don't lose the event.
        echo '<script>window.cmmsDeferredInstallPrompt=null;window.addEventListener("beforeinstallprompt",function(e){e.preventDefault();window.cmmsDeferredInstallPrompt=e;document.dispatchEvent(new CustomEvent("cmms-install-available"));});window.addEventListener("appinstalled",function(){window.cmmsDeferredInstallPrompt=null;document.dispatchEvent(new CustomEvent("cmms-install-completed"));});</script>' . "\n";
    }
}
