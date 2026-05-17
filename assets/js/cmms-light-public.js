/**
 * CMMS - public JS. Vanilla, no dependencies.
 */

/* ============================================================
   Self-heal (1.14.26)

   Goal: detect a stale-asset state automatically and recover without
   the user having to "Clear site data". This is the front-line defense
   against the blank-page-after-update bug.

   How we detect a stale state:
     1. Version mismatch: the HTML carries <meta name="cmms-version">
        from the server, the JS knows its own version via window.CMMSLight.
        If they differ, the JS is stale.
     2. JS load timeout: if the DOM is parsed but core scripts didn't
        attach the expected globals within ~6s, something is broken.

   How we recover:
     1. Unregister all CMMS service workers.
     2. Clear all CMMS-named caches.
     3. Reload once, with a query-string cache-buster.

   We use a sessionStorage gate to avoid an infinite reload loop:
   once we've attempted recovery this session, we don't try again.
   The user gets exactly one auto-recovery attempt.
   ============================================================ */
(function () {
    'use strict';

    var RELOAD_FLAG = 'cmms_recovery_attempted';
    var GLOBAL_VAR = 'CMMSLight';

    function getServerVersion() {
        var m = document.querySelector('meta[name="cmms-version"]');
        return m ? m.getAttribute('content') : null;
    }
    function getClientVersion() {
        return (window[GLOBAL_VAR] && window[GLOBAL_VAR].pluginVersion) || null;
    }
    function alreadyTried() {
        try { return sessionStorage.getItem(RELOAD_FLAG) === '1'; } catch (e) { return false; }
    }
    function markTried() {
        try { sessionStorage.setItem(RELOAD_FLAG, '1'); } catch (e) {}
    }
    function clearTried() {
        try { sessionStorage.removeItem(RELOAD_FLAG); } catch (e) {}
    }

    function recover(reason) {
        if (alreadyTried()) {
            // We already tried this session; don't loop. Log silently.
            try { console.warn('CMMS: stale assets detected (' + reason + ') but recovery already attempted'); } catch (e) {}
            return;
        }
        markTried();
        try { console.warn('CMMS: recovering from stale assets — reason: ' + reason); } catch (e) {}

        var promises = [];

        // 1. Unregister every service worker registered on this origin.
        //    We only registered one (CMMS's), but be defensive.
        if ('serviceWorker' in navigator && navigator.serviceWorker.getRegistrations) {
            promises.push(
                navigator.serviceWorker.getRegistrations()
                    .then(function (regs) {
                        return Promise.all(regs.map(function (r) {
                            try { return r.unregister(); } catch (e) { return null; }
                        }));
                    })
                    .catch(function () {})
            );
        }

        // 2. Delete every cache named in CMMS's namespace. We use a prefix
        //    match so legacy caches from old versions get nuked too.
        if (window.caches && caches.keys) {
            promises.push(
                caches.keys()
                    .then(function (keys) {
                        return Promise.all(
                            keys
                                .filter(function (k) { return k.indexOf('cmms') === 0 || k.indexOf('CMMS') === 0; })
                                .map(function (k) {
                                    try { return caches.delete(k); } catch (e) { return null; }
                                })
                        );
                    })
                    .catch(function () {})
            );
        }

        // 3. Once both are done, reload with a cache-buster. The buster
        //    ensures even an aggressively-cached HTML response from a
        //    CDN gets bypassed for this one request.
        Promise.all(promises).then(function () {
            var url = new URL(window.location.href);
            url.searchParams.set('_cmms_nocache', Date.now().toString(36));
            // Use replace() so the buster URL doesn't pollute history.
            window.location.replace(url.toString());
        });
    }

    // === DETECTION 1: Version mismatch on load ===
    // Run after DOMContentLoaded so the meta tag and CMMSLight global
    // are both present. If they exist and don't match, recover.
    function checkVersionMismatch() {
        var serverVer = getServerVersion();
        var clientVer = getClientVersion();
        // If either is missing we can't compare — bail. Don't false-positive.
        if (!serverVer || !clientVer) return;
        if (serverVer !== clientVer.split('.').slice(0, 3).join('.')) {
            // Compare the major.minor.patch part only; the client version
            // includes a filemtime suffix that the server meta doesn't.
            recover('version_mismatch:' + serverVer + '!=' + clientVer);
        } else {
            // Versions match — clear the recovery flag so a future
            // legitimate problem can attempt recovery again.
            clearTried();
        }
    }

    // === DETECTION 2: JS didn't attach globals (script load failure) ===
    // If after 6 seconds the CMMSLight global isn't there but a CMMS page
    // marker IS, our script never executed — probably blocked or 404'd.
    // Recover.
    function checkScriptFailure() {
        if (window[GLOBAL_VAR]) return; // we loaded fine
        var marker = document.querySelector('meta[name="cmms-version"]');
        if (!marker) return; // not a CMMS page
        recover('script_load_failure');
    }

    // Wire up. DOMContentLoaded for version check, timeout for script check.
    if (document.readyState !== 'loading') {
        checkVersionMismatch();
    } else {
        document.addEventListener('DOMContentLoaded', checkVersionMismatch);
    }
    setTimeout(checkScriptFailure, 6000);
})();

(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') fn();
		else document.addEventListener('DOMContentLoaded', fn);
	}

	ready(function () {
		// Mobile sidebar toggle
		var toggle = document.getElementById('cmms-menu-toggle');
		var sidebar = document.getElementById('cmms-sidebar');
		var overlay = document.getElementById('cmms-sidebar-overlay');

		function openSidebar() {
			if (sidebar) sidebar.classList.add('cmms-open');
			if (overlay) overlay.classList.add('cmms-open');
		}
		function closeSidebar() {
			if (sidebar) sidebar.classList.remove('cmms-open');
			if (overlay) overlay.classList.remove('cmms-open');
		}
		if (toggle) {
			toggle.addEventListener('click', function (e) {
				e.preventDefault();
				if (sidebar && sidebar.classList.contains('cmms-open')) closeSidebar();
				else openSidebar();
			});
		}
		if (overlay) {
			overlay.addEventListener('click', closeSidebar);
		}
		// Close sidebar when clicking a nav link (mobile)
		if (sidebar) {
			var navLinks = sidebar.querySelectorAll('.cmms-sidebar-nav a');
			Array.prototype.forEach.call(navLinks, function (a) {
				a.addEventListener('click', function () {
					if (window.innerWidth < 900) closeSidebar();
				});
			});
		}

		// Confirm-delete forms
		var confirmEls = document.querySelectorAll('.cmms-confirm-delete');
		Array.prototype.forEach.call(confirmEls, function (el) {
			el.addEventListener('submit', function (e) {
				var msg = el.getAttribute('data-confirm') || 'Are you sure?';
				if (!window.confirm(msg)) e.preventDefault();
			});
		});

		// Auto-resize textareas (subtle nicety)
		var textareas = document.querySelectorAll('.cmms-textarea');
		Array.prototype.forEach.call(textareas, function (ta) {
			function resize() {
				ta.style.height = 'auto';
				ta.style.height = Math.min(ta.scrollHeight, 400) + 'px';
			}
			ta.addEventListener('input', resize);
		});

		// Show/hide recurrence-until field based on recurrence type
		var recTypeSelects = document.querySelectorAll('[data-cmms-recurrence-type]');
		Array.prototype.forEach.call(recTypeSelects, function (sel) {
			function syncRecUntil() {
				var form = sel.closest('form');
				if (!form) return;
				var isRecurring = sel.value && sel.value !== 'one_time';

				// Toggle "until" field
				var wrap = form.querySelector('[data-cmms-rec-until-wrap]');
				if (wrap) {
					var input = wrap.querySelector('input');
					if (isRecurring) {
						wrap.style.display = '';
						if (input) input.required = true;
					} else {
						wrap.style.display = 'none';
						if (input) input.required = false;
					}
				}

				// Toggle due-date helper text + required attribute
				var dueField = form.querySelector('[data-cmms-due-field]');
				if (dueField) {
					var help = dueField.querySelector('[data-cmms-due-help-recurring]');
					var dueInput = dueField.querySelector('input[name="due_date"]');
					if (isRecurring) {
						if (help) help.style.display = '';
						if (dueInput) dueInput.required = true;
					} else {
						if (help) help.style.display = 'none';
						if (dueInput) dueInput.required = false;
					}
				}
			}
			sel.addEventListener('change', syncRecUntil);
			syncRecUntil();
		});

		// Time range picker toggle
		var ranges = document.querySelectorAll('[data-cmms-range]');
		Array.prototype.forEach.call(ranges, function (rp) {
			var trigger = rp.querySelector('.cmms-range-trigger');
			if (!trigger) return;
			trigger.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				Array.prototype.forEach.call(ranges, function (other) {
					if (other !== rp) other.classList.remove('is-open');
				});
				rp.classList.toggle('is-open');
				trigger.setAttribute('aria-expanded', rp.classList.contains('is-open') ? 'true' : 'false');
			});
		});
		document.addEventListener('click', function (e) {
			Array.prototype.forEach.call(ranges, function (rp) {
				if (!rp.contains(e.target)) {
					rp.classList.remove('is-open');
					var t = rp.querySelector('.cmms-range-trigger');
					if (t) t.setAttribute('aria-expanded', 'false');
				}
			});
		});

		// PWA install banner + dialog
		(function () {
			var banner = document.querySelector('[data-cmms-install-banner]');
			var dialog = document.querySelector('[data-cmms-install-dialog]');
			if (!banner || !dialog) return;

			// Detect if already installed (standalone display mode)
			var isStandalone = window.matchMedia('(display-mode: standalone)').matches
				|| window.navigator.standalone === true;
			if (isStandalone) return; // Stay hidden

			// Detect platform
			var ua = navigator.userAgent;
			var isIOS = /iPhone|iPad|iPod/.test(ua) && !window.MSStream;
			var isAndroid = /Android/.test(ua);
			var detectedPlatform = isIOS ? 'ios' : (isAndroid ? 'android' : 'desktop');

			// Check dismissal flag (localStorage may throw in private mode — guard it)
			try {
				if (window.localStorage && localStorage.getItem('cmms_install_dismissed') === '1') {
					return;
				}
			} catch (e) { /* ignore */ }

			// Capture beforeinstallprompt for Android Chrome / Edge.
			// The early head-script may have already captured it before our
			// JS ran — pick it up from window if so. Otherwise listen now.
			var deferredPrompt = window.cmmsDeferredInstallPrompt || null;

			function revealNativeTriggers() {
				var triggers = dialog.querySelectorAll('[data-cmms-install-trigger]');
				Array.prototype.forEach.call(triggers, function (btn) { btn.hidden = false; });
			}

			if (deferredPrompt) revealNativeTriggers();

			// If the early script hasn't fired yet, listen for both the native
			// event AND our custom event from the head script.
			window.addEventListener('beforeinstallprompt', function (e) {
				e.preventDefault();
				deferredPrompt = e;
				window.cmmsDeferredInstallPrompt = e;
				revealNativeTriggers();
			});
			document.addEventListener('cmms-install-available', function () {
				deferredPrompt = window.cmmsDeferredInstallPrompt;
				revealNativeTriggers();
			});

			// Show banner now
			banner.hidden = false;

			// Set initial active panel based on detected platform
			function showPlatform(platform) {
				var tabs = dialog.querySelectorAll('[data-cmms-install-tab]');
				var panels = dialog.querySelectorAll('[data-cmms-install-panel]');
				Array.prototype.forEach.call(tabs, function (t) {
					t.classList.toggle('active', t.getAttribute('data-cmms-install-tab') === platform);
				});
				Array.prototype.forEach.call(panels, function (p) {
					var match = p.getAttribute('data-cmms-install-panel') === platform;
					p.hidden = !match;
					if (match) p.classList.add('active');
					else p.classList.remove('active');
				});
			}

			function openDialog() {
				showPlatform(detectedPlatform);
				dialog.hidden = false;
				document.body.style.overflow = 'hidden';
			}
			function closeDialog() {
				dialog.hidden = true;
				document.body.style.overflow = '';
			}

			// Dismiss banner
			var dismissBtn = banner.querySelector('[data-cmms-install-dismiss]');
			if (dismissBtn) {
				dismissBtn.addEventListener('click', function () {
					banner.hidden = true;
					try { if (window.localStorage) localStorage.setItem('cmms_install_dismissed', '1'); } catch (e) {}
				});
			}

			// Open dialog
			var openBtn = banner.querySelector('[data-cmms-install-open]');
			if (openBtn) openBtn.addEventListener('click', openDialog);

			// Close handlers (backdrop, X button, Escape)
			var closeEls = dialog.querySelectorAll('[data-cmms-install-close]');
			Array.prototype.forEach.call(closeEls, function (el) {
				el.addEventListener('click', closeDialog);
			});
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && !dialog.hidden) closeDialog();
			});

			// Tab switching
			var tabs = dialog.querySelectorAll('[data-cmms-install-tab]');
			Array.prototype.forEach.call(tabs, function (t) {
				t.addEventListener('click', function () {
					showPlatform(t.getAttribute('data-cmms-install-tab'));
				});
			});

			// Native install trigger (Android Chrome / Edge / Desktop)
			var triggers = dialog.querySelectorAll('[data-cmms-install-trigger]');
			Array.prototype.forEach.call(triggers, function (btn) {
				btn.addEventListener('click', function () {
					// Re-read in case it became available after page load
					if (!deferredPrompt) deferredPrompt = window.cmmsDeferredInstallPrompt;

					if (!deferredPrompt) {
						// Browser hasn't offered the prompt — show inline notice instead of native alert.
						var noticeMsg = (window.CMMSLight && window.CMMSLight.installFollowSteps)
							|| 'Please follow the steps below to install. Your browser does not currently offer a one-click install.';
						var existing = dialog.querySelector('.cmms-install-inline-notice');
						if (!existing) {
							var notice = document.createElement('div');
							notice.className = 'cmms-install-inline-notice';
							notice.textContent = noticeMsg;
							btn.parentNode.insertBefore(notice, btn.nextSibling);
							btn.style.display = 'none'; // hide the dead button
						}
						return;
					}
					deferredPrompt.prompt();
					deferredPrompt.userChoice.then(function (choice) {
						if (choice.outcome === 'accepted') {
							banner.hidden = true;
							closeDialog();
						}
						deferredPrompt = null;
						window.cmmsDeferredInstallPrompt = null;
					}).catch(function (err) {
						console.error('install prompt failed', err);
					});
				});
			});

			// Hide banner & dialog when app is actually installed
			document.addEventListener('cmms-install-completed', function () {
				banner.hidden = true;
				closeDialog();
			});
			window.addEventListener('appinstalled', function () {
				banner.hidden = true;
				closeDialog();
			});
		})();

		// Notification bell dropdown(s) - multiple bells (mobile + desktop) on same page
		var bells = document.querySelectorAll('[data-cmms-bell]');
		Array.prototype.forEach.call(bells, function (bell) {
			var btn = bell.querySelector('.cmms-bell-btn');
			if (!btn) return;
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				// Close any other open bells on the page
				Array.prototype.forEach.call(bells, function (other) {
					if (other !== bell) other.classList.remove('is-open');
				});
				bell.classList.toggle('is-open');
				btn.setAttribute('aria-expanded', bell.classList.contains('is-open') ? 'true' : 'false');
			});
		});
		// Click outside any bell closes them all
		document.addEventListener('click', function (e) {
			Array.prototype.forEach.call(bells, function (bell) {
				if (!bell.contains(e.target)) {
					bell.classList.remove('is-open');
					var b = bell.querySelector('.cmms-bell-btn');
					if (b) b.setAttribute('aria-expanded', 'false');
				}
			});
		});
		// Escape key closes all bells
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				Array.prototype.forEach.call(bells, function (bell) {
					bell.classList.remove('is-open');
					var b = bell.querySelector('.cmms-bell-btn');
					if (b) b.setAttribute('aria-expanded', 'false');
				});
			}
		});

		// Service worker registration is handled by CMMS_PWA::manifest_link()
		// which injects an inline script in <head>. We deliberately do NOT
		// register a service worker here, because:
		//   1. The legacy /cmms-sw.js path does not exist on this hosting setup
		//   2. Two registrations on the same page would race each other
		//   3. The PWA class is the single source of truth — it serves the SW
		//      from /cmms-dashboard/?cmms_pwa=sw and registers it correctly
		//      with scope:'/' via Service-Worker-Allowed.

		// =====================================================================
		// Web Push subscription UI (Settings → Notifications tab)
		// =====================================================================
		(function () {
			var panel = document.querySelector('[data-cmms-push-panel]');
			if (!panel) return;

			var statusEl  = panel.querySelector('[data-cmms-push-status]');
			var descEl    = panel.querySelector('[data-cmms-push-desc]');
			var enableBtn = panel.querySelector('[data-cmms-push-enable]');
			var disableBtn= panel.querySelector('[data-cmms-push-disable]');
			var testBtn   = panel.querySelector('[data-cmms-push-test]');

			// Browser feature check
			var supported = ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);
			if (!supported) {
				statusEl.textContent = '✕ ' + (window.CMMSLight && window.CMMSLight.pushNotSupported || 'Not supported');
				descEl.textContent = window.CMMSLight && window.CMMSLight.pushNotSupportedHelp || 'Your browser does not support push notifications.';
				return;
			}

			function urlB64ToUint8Array(base64String) {
				var padding = '='.repeat((4 - base64String.length % 4) % 4);
				var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
				var rawData = atob(base64);
				var outputArray = new Uint8Array(rawData.length);
				for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
				return outputArray;
			}

			function setState(state, message) {
				// state: 'denied' | 'subscribed' | 'available' | 'permission_default'
				if (state === 'denied') {
					statusEl.textContent = '🚫';
					descEl.textContent = window.CMMSLight && window.CMMSLight.pushDenied
						|| 'Notifications are blocked in your browser settings. Please enable them for this site to receive alerts.';
					enableBtn.hidden = true; disableBtn.hidden = true; testBtn.hidden = true;
				} else if (state === 'subscribed') {
					statusEl.textContent = '✓';
					descEl.textContent = window.CMMSLight && window.CMMSLight.pushOn
						|| 'Notifications are on. You will be alerted on this device when tasks need your attention.';
					enableBtn.hidden = true; disableBtn.hidden = false; testBtn.hidden = false;
				} else {
					statusEl.textContent = '';
					descEl.textContent = window.CMMSLight && window.CMMSLight.pushDescDefault
						|| 'Get alerted on this device when tasks are assigned, due, or commented on.';
					enableBtn.hidden = false; disableBtn.hidden = true; testBtn.hidden = true;
				}
			}

			async function getSubscription() {
				try {
					var reg = await navigator.serviceWorker.ready;
					return await reg.pushManager.getSubscription();
				} catch (e) { return null; }
			}

			async function refresh() {
				if (Notification.permission === 'denied') { setState('denied'); return; }
				var sub = await getSubscription();
				setState(sub ? 'subscribed' : 'available');
			}

			async function subscribe() {
				if (!window.CMMSLight || !window.CMMSLight.vapidPublicKey) {
					alert('Server is missing VAPID key. Please refresh the page.');
					return;
				}
				try {
					// Request permission first (user-initiated context)
					var perm = await Notification.requestPermission();
					if (perm !== 'granted') { setState(perm === 'denied' ? 'denied' : 'available'); return; }

					var reg = await navigator.serviceWorker.ready;
					var sub = await reg.pushManager.subscribe({
						userVisibleOnly: true,
						applicationServerKey: urlB64ToUint8Array(window.CMMSLight.vapidPublicKey),
					});

					// Send to server
					var keys = sub.toJSON().keys;
					var resp = await fetch(window.CMMSLight.adminPost + '?action=cmms_push_subscribe', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({
							endpoint: sub.endpoint,
							keys: keys,
							nonce: window.CMMSLight.pushSubNonce,
						}),
					});
					if (!resp.ok) {
						await sub.unsubscribe();
						alert('Could not register notifications. Please try again.');
						return;
					}
					setState('subscribed');
				} catch (e) {
					console.error('subscribe failed', e);
					alert('Could not enable notifications: ' + (e && e.message || e));
				}
			}

			async function unsubscribe() {
				try {
					var sub = await getSubscription();
					if (!sub) { setState('available'); return; }
					await fetch(window.CMMSLight.adminPost + '?action=cmms_push_unsubscribe', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ endpoint: sub.endpoint, nonce: window.CMMSLight.pushUnsubNonce }),
					});
					await sub.unsubscribe();
					setState('available');
				} catch (e) { console.error(e); }
			}

			async function sendTest() {
				try {
					var resp = await fetch(window.CMMSLight.adminPost + '?action=cmms_push_test', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ nonce: window.CMMSLight.pushSubNonce }),
					});
					if (!resp.ok) alert('Test failed');
				} catch (e) { console.error(e); }
			}

			enableBtn.addEventListener('click', subscribe);
			disableBtn.addEventListener('click', unsubscribe);
			testBtn.addEventListener('click', sendTest);
			refresh();
		})();
	});
})();

// ============================================================================
// IMPORT (Excel) — drag & drop, file name preview, submit-button enable
// ============================================================================
(function () {
	document.addEventListener('DOMContentLoaded', function () {
		var dropZone   = document.querySelector('[data-cmms-import-drop]');
		if (!dropZone) return;
		var input      = dropZone.querySelector('[data-cmms-import-input]');
		var dropTitle  = dropZone.querySelector('[data-cmms-import-droptitle]');
		var submitBtn  = document.querySelector('[data-cmms-import-submit]');
		var form       = document.querySelector('[data-cmms-import-form]');

		function updateUI() {
			if (input.files && input.files[0]) {
				var name = input.files[0].name;
				if (dropTitle) dropTitle.textContent = name;
				dropZone.classList.add('has-file');
				if (submitBtn) submitBtn.disabled = false;
			} else {
				dropZone.classList.remove('has-file');
				if (submitBtn) submitBtn.disabled = true;
			}
		}

		input.addEventListener('change', updateUI);

		// Drag & drop
		['dragenter', 'dragover'].forEach(function (ev) {
			dropZone.addEventListener(ev, function (e) {
				e.preventDefault();
				e.stopPropagation();
				dropZone.classList.add('dragging');
			});
		});
		['dragleave', 'drop'].forEach(function (ev) {
			dropZone.addEventListener(ev, function (e) {
				e.preventDefault();
				e.stopPropagation();
				dropZone.classList.remove('dragging');
			});
		});
		dropZone.addEventListener('drop', function (e) {
			if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
				input.files = e.dataTransfer.files;
				updateUI();
			}
		});

		// While the form is uploading, disable the button + change label so
		// users don't double-submit a 5MB file.
		if (form) {
			form.addEventListener('submit', function () {
				if (submitBtn) {
					submitBtn.disabled = true;
					submitBtn.dataset.originalText = submitBtn.textContent;
					submitBtn.textContent = '...';
				}
			});
		}
	});
})();

// ============================================================================
// BULK TASKS GRID — paste from Excel, inline validation, batch save
//
// Architecture: a JS-driven model of the grid (array of row objects) is the
// source of truth. The DOM is rendered from that model. Edits and pastes
// update the model; the DOM updates in response. We avoid storing state in
// DOM attributes — that gets messy with sortable rows and dropdowns.
// ============================================================================
(function () {
	document.addEventListener('DOMContentLoaded', function () {
		var root = document.querySelector('[data-cmms-bulk-root]');
		if (!root) return;
		var cfg = window.cmmsBulkTasksConfig;
		if (!cfg) return;

		var tbody    = root.querySelector('[data-cmms-bulk-body]');
		var addBtn   = document.querySelector('[data-cmms-bulk-add]');
		var saveBtn  = document.querySelector('[data-cmms-bulk-save]');
		var resultEl = document.querySelector('[data-cmms-bulk-result]');

		// State: array of row objects. Each row is what we'll send to the server.
		var rows = [];
		var INITIAL_ROWS = 30;
		var ADD_BATCH    = 50;

		function blankRow() {
			return {
				title: '',
				description: '',
				asset_id: '',
				priority: 'normal',
				assigned_to: '',
				due_date: '',
				error: '', // last validation error for inline display
			};
		}

		function init() {
			for (var i = 0; i < INITIAL_ROWS; i++) rows.push(blankRow());
			render();
		}

		// Render the entire tbody. Cheap because we have at most ~1000 rows
		// and the user rarely interacts with them all simultaneously.
		function render() {
			var html = '';
			for (var i = 0; i < rows.length; i++) {
				html += renderRow(i, rows[i]);
			}
			tbody.innerHTML = html;
			attachRowHandlers();
		}

		function renderRow(idx, row) {
			var rowClass = 'cmms-bulk-row';
			if (row.error) rowClass += ' cmms-bulk-row-error';
			return '<tr class="' + rowClass + '" data-row="' + idx + '">' +
				'<td class="cmms-bulk-td-num">' + (idx + 1) + '</td>' +
				'<td>' + inputCell(idx, 'title', row.title, 'text') + '</td>' +
				'<td>' + inputCell(idx, 'description', row.description, 'text') + '</td>' +
				'<td>' + assetSelect(idx, row.asset_id) + '</td>' +
				'<td>' + prioritySelect(idx, row.priority) + '</td>' +
				'<td>' + assigneeSelect(idx, row.assigned_to) + '</td>' +
				'<td>' + inputCell(idx, 'due_date', row.due_date, 'date') + '</td>' +
				'</tr>';
		}

		function inputCell(idx, field, value, type) {
			var safe = (value == null ? '' : String(value)).replace(/"/g, '&quot;');
			return '<input type="' + type + '" class="cmms-bulk-input" ' +
				'data-row="' + idx + '" data-field="' + field + '" ' +
				'value="' + safe + '">';
		}

		function assetSelect(idx, val) {
			var opts = '<option value="">' + esc(cfg.i18n.asset_none) + '</option>';
			for (var i = 0; i < cfg.assets.length; i++) {
				var a = cfg.assets[i];
				var sel = (String(a.id) === String(val)) ? ' selected' : '';
				opts += '<option value="' + a.id + '"' + sel + '>' + esc(a.name) + '</option>';
			}
			return '<select class="cmms-bulk-input" data-row="' + idx + '" data-field="asset_id">' + opts + '</select>';
		}

		function prioritySelect(idx, val) {
			var opts = '';
			for (var i = 0; i < cfg.priorities.length; i++) {
				var p = cfg.priorities[i];
				var sel = (p.value === val) ? ' selected' : '';
				opts += '<option value="' + p.value + '"' + sel + '>' + esc(p.label) + '</option>';
			}
			return '<select class="cmms-bulk-input" data-row="' + idx + '" data-field="priority">' + opts + '</select>';
		}

		function assigneeSelect(idx, val) {
			var opts = '<option value="">' + esc(cfg.i18n.assignee_none) + '</option>';
			for (var i = 0; i < cfg.users.length; i++) {
				var u = cfg.users[i];
				var sel = (String(u.id) === String(val)) ? ' selected' : '';
				opts += '<option value="' + u.id + '"' + sel + '>' + esc(u.name) + '</option>';
			}
			return '<select class="cmms-bulk-input" data-row="' + idx + '" data-field="assigned_to">' + opts + '</select>';
		}

		function esc(s) {
			return String(s == null ? '' : s)
				.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
		}

		function attachRowHandlers() {
			// Single delegated listener for change/input events on the whole tbody.
			// Cheaper than wiring 30 * 6 = 180 handlers up front.
			tbody.addEventListener('input', onCellChange);
			tbody.addEventListener('change', onCellChange);
			tbody.addEventListener('paste', onPaste);
			tbody.addEventListener('keydown', onKey);
		}

		function onCellChange(e) {
			var t = e.target;
			if (!t || !t.dataset || t.dataset.row === undefined) return;
			var rowIdx = parseInt(t.dataset.row, 10);
			var field = t.dataset.field;
			if (isNaN(rowIdx) || !rows[rowIdx]) return;
			rows[rowIdx][field] = t.value;
			// Clear inline error when user edits
			if (rows[rowIdx].error) {
				rows[rowIdx].error = '';
				var tr = t.closest('tr');
				if (tr) tr.classList.remove('cmms-bulk-row-error');
			}
		}

		function onKey(e) {
			// Tab from last cell of last row -> add a row and focus.
			if (e.key !== 'Tab' || e.shiftKey) return;
			var t = e.target;
			if (!t || !t.dataset) return;
			var rowIdx = parseInt(t.dataset.row, 10);
			var isLastInput = t.dataset.field === 'due_date';
			if (rowIdx === rows.length - 1 && isLastInput) {
				rows.push(blankRow());
				render();
				e.preventDefault();
				focusCell(rowIdx + 1, 'title');
			}
		}

		// PASTE FROM EXCEL — the important part.
		function onPaste(e) {
			var t = e.target;
			if (!t || !t.dataset || t.dataset.row === undefined) return;
			var clip = e.clipboardData || window.clipboardData;
			if (!clip) return;
			var text = clip.getData('text/plain');
			if (!text) return;

			// Detect: is this a multi-cell paste from a spreadsheet?
			// A spreadsheet paste contains tab characters (column separators)
			// and/or newline characters (row separators). If neither, this is
			// a normal single-value paste — let the browser handle it.
			var hasGrid = text.indexOf('\t') !== -1 || /[\r\n]/.test(text);
			if (!hasGrid) return;

			e.preventDefault();
			var startRow = parseInt(t.dataset.row, 10);
			var startCol = colIndexOf(t.dataset.field);
			if (isNaN(startRow) || startCol === -1) return;

			// Normalize line endings, drop a trailing empty row if any
			var lines = text.replace(/\r\n?/g, '\n').replace(/\n+$/, '').split('\n');
			var fields = colOrder();

			for (var i = 0; i < lines.length; i++) {
				var targetRow = startRow + i;
				while (rows.length <= targetRow) rows.push(blankRow());
				var cells = lines[i].split('\t');
				for (var j = 0; j < cells.length; j++) {
					var targetCol = startCol + j;
					if (targetCol >= fields.length) break;
					var field = fields[targetCol];
					var value = cells[j];
					rows[targetRow][field] = transformPasteValue(field, value);
				}
				rows[targetRow].error = '';
			}
			render();
			focusCell(startRow, t.dataset.field);
		}

		// Column order matches the visible grid: title, description, asset, priority, assignee, due
		function colOrder() {
			return ['title', 'description', 'asset_id', 'priority', 'assigned_to', 'due_date'];
		}
		function colIndexOf(field) {
			return colOrder().indexOf(field);
		}

		// Some pasted values need translation (e.g. asset NAME -> asset ID).
		function transformPasteValue(field, raw) {
			raw = (raw || '').trim();
			if (field === 'asset_id') {
				if (!raw) return '';
				// Try numeric ID first
				var asNum = parseInt(raw, 10);
				if (!isNaN(asNum)) {
					for (var i = 0; i < cfg.assets.length; i++) {
						if (cfg.assets[i].id === asNum) return String(asNum);
					}
				}
				// Try name match (case-insensitive)
				var lower = raw.toLowerCase();
				for (var k = 0; k < cfg.assets.length; k++) {
					if (cfg.assets[k].name.toLowerCase() === lower) return String(cfg.assets[k].id);
				}
				return ''; // no match — leave blank (visible to user)
			}
			if (field === 'assigned_to') {
				if (!raw) return '';
				var n = parseInt(raw, 10);
				if (!isNaN(n)) {
					for (var i2 = 0; i2 < cfg.users.length; i2++) {
						if (cfg.users[i2].id === n) return String(n);
					}
				}
				var ln = raw.toLowerCase();
				for (var m = 0; m < cfg.users.length; m++) {
					if (cfg.users[m].name.toLowerCase() === ln) return String(cfg.users[m].id);
				}
				return '';
			}
			if (field === 'priority') {
				var lp = raw.toLowerCase();
				if (['low', 'normal', 'high', 'urgent'].indexOf(lp) !== -1) return lp;
				return 'normal';
			}
			if (field === 'due_date') {
				// Try common date shapes; HTML <input type=date> wants YYYY-MM-DD.
				if (!raw) return '';
				// Already ISO?
				if (/^\d{4}-\d{2}-\d{2}/.test(raw)) return raw.substring(0, 10);
				// DD/MM/YYYY
				var m1 = raw.match(/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{2,4})$/);
				if (m1) {
					var y = parseInt(m1[3], 10); if (y < 100) y += 2000;
					return y + '-' + pad2(m1[2]) + '-' + pad2(m1[1]);
				}
				return raw; // server will try harder
			}
			return raw;
		}
		function pad2(n) { var s = String(n); return s.length === 1 ? '0' + s : s; }

		function focusCell(rowIdx, field) {
			var sel = '[data-row="' + rowIdx + '"][data-field="' + field + '"]';
			var el = tbody.querySelector(sel);
			if (el) el.focus();
		}

		// Action: add 50 more rows
		addBtn.addEventListener('click', function () {
			for (var i = 0; i < ADD_BATCH; i++) rows.push(blankRow());
			render();
		});

		// Action: save
		saveBtn.addEventListener('click', function () {
			save();
		});

		function isRowEmpty(row) {
			return !row.title && !row.description && !row.asset_id &&
			       !row.assigned_to && !row.due_date &&
			       (row.priority === 'normal' || !row.priority);
		}

		function save() {
			// Build payload of NON-empty rows. Track original index so we can
			// map server failures back to the exact row in our local array.
			var payload = [];
			var indexMap = []; // payload index -> rows array index
			for (var i = 0; i < rows.length; i++) {
				if (isRowEmpty(rows[i])) continue;
				payload.push({
					title: rows[i].title,
					description: rows[i].description,
					asset_id: rows[i].asset_id || '',
					priority: rows[i].priority || 'normal',
					assigned_to: rows[i].assigned_to || '',
					due_date: rows[i].due_date || '',
				});
				indexMap.push(i);
			}
			if (payload.length === 0) return;

			saveBtn.disabled = true;
			saveBtn.textContent = cfg.i18n.saving;
			resultEl.hidden = true;
			resultEl.className = '';

			fetch(cfg.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ nonce: cfg.nonce, rows: payload }),
			}).then(function (r) {
				return r.json();
			}).then(function (data) {
				saveBtn.disabled = false;
				saveBtn.textContent = cfg.i18n.save;
				if (!data || !data.success) {
					showResult('error', cfg.i18n.err_network);
					return;
				}
				var saved  = data.data && data.data.saved  ? data.data.saved  : 0;
				var failed = data.data && data.data.failed ? data.data.failed : [];

				// Mark failed rows with their reason and remove the saved ones.
				// Strategy: rows in payload that didn't fail are "saved" — drop them
				// from local state. Failed rows stay, with a visible error.
				var failedSet = {};
				for (var i = 0; i < failed.length; i++) {
					var origIdx = indexMap[failed[i].row_index];
					if (rows[origIdx]) {
						rows[origIdx].error = failed[i].reason || 'error';
					}
					failedSet[origIdx] = true;
				}
				// Remove successfully-saved rows.
				var newRows = [];
				for (var k = 0; k < rows.length; k++) {
					var wasInPayload = indexMap.indexOf(k) !== -1;
					if (wasInPayload && !failedSet[k]) {
						continue; // saved, drop it
					}
					newRows.push(rows[k]);
				}
				// Always keep at least a few empty rows ready
				while (newRows.length < 5) newRows.push(blankRow());
				rows = newRows;
				render();

				var msg = cfg.i18n.saved_summary.replace('%d', saved);
				if (failed.length > 0) {
					msg += ' ' + cfg.i18n.failed_some.replace('%d', failed.length);
					showResult('warn', msg);
				} else {
					showResult('success', msg);
				}
			}).catch(function () {
				saveBtn.disabled = false;
				saveBtn.textContent = cfg.i18n.save;
				showResult('error', cfg.i18n.err_network);
			});
		}

		function showResult(kind, msg) {
			resultEl.className = 'cmms-bulk-result cmms-bulk-result-' + kind;
			var html = esc(msg);
			// If success, offer a "view tasks" link
			if (kind === 'success' || kind === 'warn') {
				html += ' <a href="' + cfg.home + '" class="cmms-bulk-result-link">' + esc(cfg.i18n.view_tasks) + ' →</a>';
			}
			resultEl.innerHTML = html;
			resultEl.hidden = false;
		}

		init();
	});
})();

// ============================================================================
// PWA INSTALL — banner on dashboard + button in Settings
//
// Architecture:
//   - The HTML for both UIs is rendered server-side, hidden by default.
//   - On load, this JS:
//     1. Detects if app is already installed → hides install UI, shows
//        "installed" indicator in Settings.
//     2. If NOT installed and engine supports prompt API: captures the
//        beforeinstallprompt event, shows the banner/button.
//     3. If NOT installed and engine is iOS Safari: shows the banner with
//        platform-specific instructions modal on click.
//     4. If user dismisses the dashboard banner: stores preference in
//        localStorage so it doesn't pop up again.
//
// The deferred prompt was already captured early via inline script
// (see CMMS_PWA::manifest_link). We read it from window.cmmsDeferredInstallPrompt.
// ============================================================================
(function () {
	function isStandalone() {
		// True if the app is launched from the home screen (already installed)
		if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
		if (window.navigator && window.navigator.standalone === true) return true; // iOS Safari
		return false;
	}

	function isIOSSafari() {
		var ua = window.navigator.userAgent;
		var isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
		// Inside an iframe doesn't count, and Chrome on iOS reports Safari in UA
		// but doesn't support prompt. We treat all iOS as needing manual instructions.
		return isIOS;
	}

	function isAndroidChrome() {
		var ua = window.navigator.userAgent;
		return /Android/.test(ua) && /Chrome/.test(ua) && !/EdgA|Firefox|SamsungBrowser/.test(ua);
	}

	var DISMISS_KEY = 'cmms_pwa_banner_dismissed';

	function isBannerDismissed() {
		try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch (e) { return false; }
	}
	function dismissBanner() {
		try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) {}
	}
	// Reset the dismissal — used by Settings button so a user who dismissed
	// the banner can still install later.
	window.cmmsResetInstallDismiss = function () {
		try { localStorage.removeItem(DISMISS_KEY); } catch (e) {}
	};

	document.addEventListener('DOMContentLoaded', function () {
		// =====================================================================
		// PART A: Dashboard banner
		// =====================================================================
		var banner    = document.querySelector('[data-cmms-install-banner]');
		var bannerBtn = document.querySelector('[data-cmms-install-banner-btn]');
		var bannerClose = document.querySelector('[data-cmms-install-banner-close]');

		// =====================================================================
		// PART B: Settings tile/button
		// =====================================================================
		var settingsContainer = document.querySelector('[data-cmms-install-settings]');
		var settingsBtn      = document.querySelector('[data-cmms-install-settings-btn]');
		var settingsInstalled = document.querySelector('[data-cmms-install-settings-installed]');
		var settingsHelp     = document.querySelector('[data-cmms-install-settings-help]');

		var installed = isStandalone();
		var bannerDismissed = isBannerDismissed();

		// Update both UIs based on current state.
		function refreshUI() {
			if (banner) {
				if (installed || bannerDismissed) {
					banner.hidden = true;
				} else {
					banner.hidden = false;
				}
			}
			if (settingsContainer) {
				// Always show the settings UI (per spec). Toggle which sub-element shows.
				settingsContainer.hidden = false;
				if (installed) {
					if (settingsBtn) settingsBtn.hidden = true;
					if (settingsInstalled) settingsInstalled.hidden = false;
					if (settingsHelp) settingsHelp.hidden = true;
				} else {
					if (settingsBtn) settingsBtn.hidden = false;
					if (settingsInstalled) settingsInstalled.hidden = true;
					if (settingsHelp) settingsHelp.hidden = false;
				}
			}
		}
		refreshUI();

		// === Trigger install via captured prompt event ===
		// Returns true if a real prompt was shown. False means caller should
		// fall back to instructions modal.
		async function triggerNativeInstall() {
			var deferred = window.cmmsDeferredInstallPrompt;
			if (!deferred || typeof deferred.prompt !== 'function') return false;
			try {
				deferred.prompt();
				var result = await deferred.userChoice;
				// One-shot: the event can't be reused even if the user dismisses.
				window.cmmsDeferredInstallPrompt = null;
				if (result && result.outcome === 'accepted') {
					// Banner will hide itself once 'appinstalled' fires.
					return true;
				}
				return true; // we DID show a prompt, user just declined
			} catch (e) {
				return false;
			}
		}

		// === Show platform-specific install instructions ===
		function showInstallInstructions() {
			// Build a small, centered modal explaining how to install on the
			// current platform. We read i18n strings from the global cfg
			// stash so server-rendered text is honored.
			var i18n = (window.cmmsInstallI18n || {});
			var platform = isIOSSafari() ? 'ios' : (isAndroidChrome() ? 'android' : 'other');
			var title, steps;
			if (platform === 'ios') {
				title = i18n.ios_title || 'Install on iOS';
				steps = i18n.ios_steps || ['Tap the Share button at the bottom of Safari', 'Scroll and choose "Add to Home Screen"', 'Tap "Add" in the top-right'];
			} else if (platform === 'android') {
				title = i18n.android_title || 'Install on Android';
				steps = i18n.android_steps || ['Tap the menu (⋮) at the top-right of Chrome', 'Choose "Install app" or "Add to Home screen"', 'Tap "Install"'];
			} else {
				title = i18n.other_title || 'Install on this device';
				steps = i18n.other_steps || ['Open this site in Chrome (Android) or Safari (iOS) to install'];
			}

			// Build modal DOM
			var overlay = document.createElement('div');
			overlay.className = 'cmms-modal-overlay';
			overlay.setAttribute('role', 'dialog');
			overlay.setAttribute('aria-modal', 'true');
			overlay.innerHTML =
				'<div class="cmms-modal">' +
					'<button type="button" class="cmms-modal-close" aria-label="Close">×</button>' +
					'<h3 class="cmms-modal-title"></h3>' +
					'<ol class="cmms-modal-steps"></ol>' +
					'<button type="button" class="cmms-btn cmms-btn-primary cmms-btn-block cmms-modal-ok">' + (i18n.got_it || 'Got it') + '</button>' +
				'</div>';
			overlay.querySelector('.cmms-modal-title').textContent = title;
			var ol = overlay.querySelector('.cmms-modal-steps');
			steps.forEach(function (s) {
				var li = document.createElement('li');
				li.textContent = s;
				ol.appendChild(li);
			});
			document.body.appendChild(overlay);
			document.body.style.overflow = 'hidden';

			function close() {
				document.body.style.overflow = '';
				if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
			}
			overlay.querySelector('.cmms-modal-close').addEventListener('click', close);
			overlay.querySelector('.cmms-modal-ok').addEventListener('click', close);
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) close();
			});
		}

		// === Click handlers ===
		async function handleInstallClick() {
			var ok = await triggerNativeInstall();
			if (!ok) showInstallInstructions();
		}

		if (bannerBtn) bannerBtn.addEventListener('click', handleInstallClick);
		if (settingsBtn) settingsBtn.addEventListener('click', function () {
			// In Settings, we ALWAYS allow trying again, even if the user
			// dismissed the banner. Reset that flag so the dashboard banner
			// reappears next time too if install doesn't complete.
			window.cmmsResetInstallDismiss();
			handleInstallClick();
		});
		if (bannerClose) bannerClose.addEventListener('click', function () {
			dismissBanner();
			if (banner) banner.hidden = true;
		});

		// === Listen for late-arriving install events ===
		// beforeinstallprompt may fire AFTER our DOMContentLoaded if Chrome
		// is still evaluating engagement. Update UI when it arrives.
		window.addEventListener('beforeinstallprompt', function (e) {
			e.preventDefault();
			window.cmmsDeferredInstallPrompt = e;
			// On a fresh install opportunity, also reset the dismissal so the
			// banner can come back. (The user might want to install now even
			// if they dismissed yesterday.)
			// Actually, no — respect the dismissal. They explicitly closed it.
		});

		// When the OS reports the app installed, hide all install UI.
		window.addEventListener('appinstalled', function () {
			window.cmmsDeferredInstallPrompt = null;
			installed = true;
			refreshUI();
		});

		// React to display-mode changes (rare but possible: user installs in
		// another tab and switches back).
		if (window.matchMedia) {
			var mq = window.matchMedia('(display-mode: standalone)');
			if (mq.addEventListener) {
				mq.addEventListener('change', function (e) {
					installed = e.matches;
					refreshUI();
				});
			}
		}
	});
})();

// ============================================================================
// GLOBAL SEARCH — debounced AJAX, dropdown (desktop) + overlay (mobile)
//
// Design notes:
//   - Single source of truth: the cmms_search AJAX endpoint. Both the
//     desktop input and the mobile overlay call the same endpoint and
//     render the same response shape.
//   - Debounce 300ms — feels responsive without hammering the server when
//     users are typing fast.
//   - Race protection: every request stamps an ever-incrementing token;
//     responses that come back out-of-order are discarded so we never
//     show stale results.
//   - The dropdown closes on:
//       (a) click outside the search container
//       (b) Esc key
//       (c) navigation (clicking a result link)
// ============================================================================
(function () {
	document.addEventListener('DOMContentLoaded', function () {
		var cfg = window.cmmsSearchConfig;
		if (!cfg) return;

		// === DOM lookup ===
		var deskContainer = document.querySelector('[data-cmms-search-desktop]');
		var deskInput     = document.querySelector('[data-cmms-search-input]');
		var deskDropdown  = document.querySelector('[data-cmms-search-dropdown]');
		// Multiple triggers: there's one in the mobile topbar (hidden on
		// desktop) and one in the desktop main-header (hidden on mobile).
		// Same handler, just different CSS visibility.
		var mobileTriggers = document.querySelectorAll('[data-cmms-search-mobile-open]');
		// There can be multiple close targets — the X button and the
		// desktop backdrop both carry [data-cmms-search-mobile-close].
		// Use querySelectorAll so the click handler is attached to both,
		// otherwise tapping the backdrop or the X (whichever isn't first
		// in the DOM) silently does nothing.
		var mobileCloseEls = document.querySelectorAll('[data-cmms-search-mobile-close]');
		var mobileOverlay = document.querySelector('[data-cmms-search-overlay]');
		var mobileInput   = document.querySelector('[data-cmms-search-mobile-input]');
		var mobileResults = document.querySelector('[data-cmms-search-mobile-results]');

		// === Search state ===
		// requestSeq increments on every send. When a response comes back we
		// compare its seq to lastApplied — if older, we drop it. This avoids
		// the classic "user typed 'pum' then 'pump', the slow 'pum' response
		// arrives second and overwrites pump's results" bug.
		var requestSeq = 0;
		var lastApplied = 0;
		var debounceTimer = null;

		function escHtml(s) {
			return String(s == null ? '' : s)
				.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
		}

		// Wrap matched substring in <mark>. Case-insensitive. Used to
		// highlight where the query appeared in result fields.
		function highlight(text, q) {
			if (!q || !text) return escHtml(text);
			var lowerText = text.toLowerCase();
			var lowerQ = q.toLowerCase();
			var idx = lowerText.indexOf(lowerQ);
			if (idx === -1) return escHtml(text);
			var before = text.substring(0, idx);
			var match  = text.substring(idx, idx + q.length);
			var after  = text.substring(idx + q.length);
			return escHtml(before) + '<mark>' + escHtml(match) + '</mark>' + escHtml(after);
		}

		// Build the HTML for one search result group (Tasks / Assets / Users)
		function renderTaskRow(t, q) {
			var meta = [];
			if (t.status)   meta.push(cfg.status_labels[t.status]   || t.status);
			if (t.priority) meta.push(cfg.priority_labels[t.priority] || t.priority);
			if (t.asset_name) meta.push(escHtml(t.asset_name));
			return '<a class="cmms-search-result" href="' + escHtml(t.url) + '" data-cmms-search-result>' +
				'<div class="cmms-search-result-title">' + highlight(t.title, q) + '</div>' +
				'<div class="cmms-search-result-meta">' + meta.join(' · ') + '</div>' +
			'</a>';
		}
		function renderAssetRow(a, q) {
			var meta = [];
			if (a.asset_type) meta.push(escHtml(a.asset_type));
			if (a.location)   meta.push(escHtml(a.location));
			return '<a class="cmms-search-result" href="' + escHtml(a.url) + '" data-cmms-search-result>' +
				'<div class="cmms-search-result-title">' + highlight(a.name, q) + '</div>' +
				'<div class="cmms-search-result-meta">' + meta.join(' · ') + '</div>' +
			'</a>';
		}
		function renderUserRow(u, q) {
			var meta = [];
			if (u.role)  meta.push(cfg.role_labels[u.role] || u.role);
			if (u.email) meta.push(escHtml(u.email));
			return '<a class="cmms-search-result" href="' + escHtml(u.url) + '" data-cmms-search-result>' +
				'<div class="cmms-search-result-title">' + highlight(u.name, q) + '</div>' +
				'<div class="cmms-search-result-meta">' + meta.join(' · ') + '</div>' +
			'</a>';
		}

		function renderResults(data, q) {
			var tasks = (data && data.tasks)  || [];
			var assets = (data && data.assets) || [];
			var users  = (data && data.users)  || [];
			var total = tasks.length + assets.length + users.length;
			if (total === 0) {
				return '<div class="cmms-search-empty">' + escHtml(cfg.i18n.no_results) + '</div>';
			}
			var html = '';
			if (tasks.length) {
				html += '<div class="cmms-search-group">' +
					'<div class="cmms-search-group-title">' + escHtml(cfg.i18n.tasks) + '</div>' +
					tasks.map(function (t) { return renderTaskRow(t, q); }).join('') +
				'</div>';
			}
			if (assets.length) {
				html += '<div class="cmms-search-group">' +
					'<div class="cmms-search-group-title">' + escHtml(cfg.i18n.assets) + '</div>' +
					assets.map(function (a) { return renderAssetRow(a, q); }).join('') +
				'</div>';
			}
			if (users.length) {
				html += '<div class="cmms-search-group">' +
					'<div class="cmms-search-group-title">' + escHtml(cfg.i18n.users) + '</div>' +
					users.map(function (u) { return renderUserRow(u, q); }).join('') +
				'</div>';
			}
			return html;
		}

		// === Core: run a search ===
		function runSearch(q, intoElement) {
			if (!q || q.length < 2) {
				intoElement.innerHTML = '<div class="cmms-search-empty">' + escHtml(cfg.i18n.start_typing) + '</div>';
				return;
			}
			var mySeq = ++requestSeq;
			intoElement.innerHTML = '<div class="cmms-search-loading">' +
				'<div class="cmms-search-spinner"></div></div>';
			var url = cfg.endpoint + '&q=' + encodeURIComponent(q);
			fetch(url, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					// Drop out-of-order responses
					if (mySeq < lastApplied) return;
					lastApplied = mySeq;
					if (!data || !data.success) {
						intoElement.innerHTML = '<div class="cmms-search-empty">' + escHtml(cfg.i18n.err_network) + '</div>';
						return;
					}
					intoElement.innerHTML = renderResults(data.data, q);
				})
				.catch(function () {
					if (mySeq < lastApplied) return;
					lastApplied = mySeq;
					intoElement.innerHTML = '<div class="cmms-search-empty">' + escHtml(cfg.i18n.err_network) + '</div>';
				});
		}

		// Debounce wrapper used by both inputs.
		function debounceSearch(q, intoElement) {
			if (debounceTimer) clearTimeout(debounceTimer);
			debounceTimer = setTimeout(function () {
				runSearch(q, intoElement);
			}, 300);
		}

		// === DESKTOP behavior ===
		if (deskInput && deskDropdown) {
			deskInput.addEventListener('input', function () {
				var q = deskInput.value.trim();
				if (q.length < 2) {
					deskDropdown.hidden = true;
					deskDropdown.innerHTML = '';
					return;
				}
				deskDropdown.hidden = false;
				debounceSearch(q, deskDropdown);
			});

			deskInput.addEventListener('focus', function () {
				if (deskInput.value.trim().length >= 2) {
					deskDropdown.hidden = false;
				}
			});

			// Close on Esc
			deskInput.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') {
					deskInput.blur();
					deskDropdown.hidden = true;
				}
			});

			// Close on outside click
			document.addEventListener('click', function (e) {
				if (!deskContainer) return;
				if (deskContainer.contains(e.target)) return;
				deskDropdown.hidden = true;
			});

			// When a result link is clicked, close dropdown so that on
			// SPA-like navigation (same-tab) the user doesn't briefly see
			// stale results flicker before the page reloads.
			deskDropdown.addEventListener('click', function (e) {
				var link = e.target.closest('[data-cmms-search-result]');
				if (link) {
					deskDropdown.hidden = true;
				}
			});
		}

		// === MOBILE behavior ===
		function openMobile() {
			if (!mobileOverlay) return;
			mobileOverlay.hidden = false;
			document.body.style.overflow = 'hidden';
			// Defer focus so iOS Safari doesn't fight with the trigger button.
			setTimeout(function () { if (mobileInput) mobileInput.focus(); }, 30);
		}
		function closeMobile() {
			if (!mobileOverlay) return;
			mobileOverlay.hidden = true;
			document.body.style.overflow = '';
			if (mobileInput) mobileInput.value = '';
			if (mobileResults) {
				mobileResults.innerHTML = '<div class="cmms-search-empty">' + escHtml(cfg.i18n.start_typing) + '</div>';
			}
		}

		// Attach to every trigger button (mobile topbar + desktop header)
		for (var i = 0; i < mobileTriggers.length; i++) {
			mobileTriggers[i].addEventListener('click', openMobile);
		}
		// Attach close handler to every close target (X + backdrop).
		for (var ci = 0; ci < mobileCloseEls.length; ci++) {
			mobileCloseEls[ci].addEventListener('click', closeMobile);
		}

		if (mobileInput && mobileResults) {
			mobileInput.addEventListener('input', function () {
				var q = mobileInput.value.trim();
				if (q.length < 2) {
					mobileResults.innerHTML = '<div class="cmms-search-empty">' + escHtml(cfg.i18n.start_typing) + '</div>';
					return;
				}
				debounceSearch(q, mobileResults);
			});
			mobileInput.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') closeMobile();
			});
			// When a result link is clicked, close the overlay so the user
			// returns to a clean state on the destination page.
			mobileResults.addEventListener('click', function (e) {
				var link = e.target.closest('[data-cmms-search-result]');
				if (link) closeMobile();
			});
		}
	});
})();

// ============================================================================
// FORM FIELDS — inline edit toggle + drag-and-drop reordering
//
// Inline edit:
//   Each field renders a summary row + a hidden inline edit form. The edit
//   button toggles between them. Cancel re-hides the form (no AJAX).
//   Save submits to cmms_field_update (existing endpoint, full page reload).
//
// Drag-and-drop:
//   Plain HTML5 Drag and Drop API. No external library — supported in all
//   evergreen browsers including iOS Safari 13+ and Chrome on Android.
//   We attach drag handlers to the field card itself. The "menu" icon on
//   the left is the visible handle, but the entire card is draggable so
//   touch users have a bigger target.
//   On drop, we POST the new order as a JSON array of field IDs.
// ============================================================================
(function () {
	document.addEventListener('DOMContentLoaded', function () {
		// ============== INLINE EDIT ==============
		// Delegate clicks on the form-fields list. Both edit and cancel
		// buttons live inside .cmms-form-field-card; we identify them by
		// data attributes.
		var listEl = document.querySelector('[data-cmms-form-fields]');
		if (!listEl) return; // no form-edit page open

		listEl.addEventListener('click', function (e) {
			var editBtn = e.target.closest('[data-cmms-form-field-edit]');
			if (editBtn) {
				e.preventDefault();
				var card = editBtn.closest('[data-cmms-form-field]');
				if (!card) return;
				var summary = card.querySelector('[data-cmms-form-field-summary]');
				var form    = card.querySelector('[data-cmms-form-field-edit-form]');
				if (summary && form) {
					summary.hidden = true;
					form.hidden = false;
					// Disable dragging while user is typing — accidental drag
					// would kill the form contents on rerender.
					card.setAttribute('draggable', 'false');
					var firstInput = form.querySelector('input[name="label"]');
					if (firstInput) {
						firstInput.focus();
						firstInput.setSelectionRange(firstInput.value.length, firstInput.value.length);
					}
				}
				return;
			}
			var cancelBtn = e.target.closest('[data-cmms-form-field-cancel]');
			if (cancelBtn) {
				e.preventDefault();
				var card2 = cancelBtn.closest('[data-cmms-form-field]');
				if (!card2) return;
				var summary2 = card2.querySelector('[data-cmms-form-field-summary]');
				var form2    = card2.querySelector('[data-cmms-form-field-edit-form]');
				if (summary2 && form2) {
					form2.hidden = true;
					summary2.hidden = false;
					card2.setAttribute('draggable', 'true');
				}
			}
		});

		// ============== DRAG & DROP REORDER ==============
		var formId = parseInt(listEl.dataset.formId, 10);
		var statusEl = document.querySelector('[data-cmms-form-fields-reorder-status]');
		var cfg = window.cmmsFormFieldsConfig || {};
		var i18n = (cfg.i18n) || { saving: 'Saving...', saved: 'Saved', err_network: 'Save failed' };

		// Track the dragged element + drop indicator state
		var draggingEl = null;

		function setStatus(kind, text) {
			if (!statusEl) return;
			statusEl.hidden = false;
			statusEl.className = 'cmms-form-fields-reorder-status status-' + kind;
			statusEl.textContent = text;
			if (kind === 'saved') {
				setTimeout(function () { statusEl.hidden = true; }, 2000);
			}
		}

		function getCurrentOrder() {
			var cards = listEl.querySelectorAll('[data-cmms-form-field]');
			var ids = [];
			for (var i = 0; i < cards.length; i++) {
				var id = parseInt(cards[i].dataset.fieldId, 10);
				if (!isNaN(id)) ids.push(id);
			}
			return ids;
		}

		function persistOrder() {
			if (!cfg.reorder_endpoint) return;
			var order = getCurrentOrder();
			setStatus('saving', i18n.saving);
			fetch(cfg.reorder_endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					nonce:   cfg.reorder_nonce,
					form_id: formId,
					order:   order,
				}),
			}).then(function (r) { return r.json(); })
			  .then(function (data) {
				if (data && data.success) {
					setStatus('saved', i18n.saved);
				} else {
					setStatus('error', i18n.err_network);
				}
			}).catch(function () {
				setStatus('error', i18n.err_network);
			});
		}

		// Initialize each card as draggable
		var cards = listEl.querySelectorAll('[data-cmms-form-field]');
		for (var i = 0; i < cards.length; i++) {
			var card = cards[i];
			card.setAttribute('draggable', 'true');

			card.addEventListener('dragstart', function (e) {
				draggingEl = this;
				this.classList.add('cmms-dragging');
				// Setting effectAllowed to "move" tells the browser this is
				// a reorder, not a copy. Some browsers refuse to fire drop
				// events without effectAllowed being set.
				if (e.dataTransfer) {
					e.dataTransfer.effectAllowed = 'move';
					// Firefox requires SOME data to be set on dataTransfer or
					// it won't initiate the drag at all. Empty string is fine.
					try { e.dataTransfer.setData('text/plain', this.dataset.fieldId || ''); } catch (err) {}
				}
			});

			card.addEventListener('dragend', function () {
				this.classList.remove('cmms-dragging');
				draggingEl = null;
				// Clear any leftover drop indicators
				var others = listEl.querySelectorAll('.cmms-drag-over');
				for (var j = 0; j < others.length; j++) others[j].classList.remove('cmms-drag-over');
			});
		}

		// On drag-over of the LIST, find the card the user is hovering over,
		// and decide whether to insert above or below it. Move the dragging
		// element in the DOM in real time so the user sees what their drop
		// will look like.
		listEl.addEventListener('dragover', function (e) {
			if (!draggingEl) return;
			e.preventDefault(); // required to allow drop
			if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';

			var afterEl = getDragAfterElement(listEl, e.clientY);
			if (afterEl == null) {
				listEl.appendChild(draggingEl);
			} else if (afterEl !== draggingEl) {
				listEl.insertBefore(draggingEl, afterEl);
			}
		});

		listEl.addEventListener('drop', function (e) {
			if (!draggingEl) return;
			e.preventDefault();
			persistOrder();
		});

		// Helper: given the cursor Y position, find which sibling card the
		// dragging element should be inserted BEFORE. Returns null if it
		// should go at the end.
		function getDragAfterElement(container, y) {
			var siblings = container.querySelectorAll('[data-cmms-form-field]:not(.cmms-dragging)');
			var closest = { offset: Number.NEGATIVE_INFINITY, element: null };
			for (var i = 0; i < siblings.length; i++) {
				var s = siblings[i];
				var box = s.getBoundingClientRect();
				var offset = y - box.top - box.height / 2;
				if (offset < 0 && offset > closest.offset) {
					closest = { offset: offset, element: s };
				}
			}
			return closest.element;
		}
	});
})();

/* ============================================================
   Help Center (1.14.22)
   Floating "?" button -> modal with the current page's article(s).

   How it knows which page: each <view> wraps content in an element
   carrying [data-cmms-page="<id>"]. On click, we walk the DOM from
   the trigger upward (nope - the FAB lives outside the page wrap),
   so we instead query the document for any [data-cmms-page] element
   present and use its value. Multiple page markers per render are
   not expected; if found, we use the first one.
   ============================================================ */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') fn();
		else document.addEventListener('DOMContentLoaded', fn);
	}

	ready(function () {
		var fab = document.querySelector('[data-cmms-help-trigger]');
		var modal = document.querySelector('[data-cmms-help-modal]');
		if (!fab || !modal) return;

		var body = modal.querySelector('[data-cmms-help-modal-body]');
		var titleEl = modal.querySelector('#cmms-help-modal-title');

		// Determine the current page id once on load. If the user is on
		// the help center itself, hide the FAB — no help-on-help loop.
		var pageEl = document.querySelector('[data-cmms-page]');
		var pageId = pageEl ? pageEl.getAttribute('data-cmms-page') : '';
		if (pageId === 'help-center') {
			fab.hidden = true;
			return;
		}

		function openModal() {
			modal.hidden = false;
			document.body.style.overflow = 'hidden';
			loadArticles();
			// Move focus into the modal for accessibility.
			var closeBtn = modal.querySelector('.cmms-help-modal-close');
			if (closeBtn) closeBtn.focus();
		}

		function closeModal() {
			modal.hidden = true;
			document.body.style.overflow = '';
			// Reset the body so the next open shows the loading state, not
			// stale content from a previous page's articles.
			if (body) body.innerHTML = '<p>טוען&hellip;</p>';
			fab.focus();
		}

		// Asks the server for help articles tagged for the current page.
		// If no articles exist for this page, we still show something
		// useful — a pointer to the help center index — so the click
		// never feels broken.
		function loadArticles() {
			if (!pageId) {
				renderEmpty();
				return;
			}
			var url = (window.CMMSLight && window.CMMSLight.adminPost
				? window.CMMSLight.adminPost
				: '/wp-admin/admin-post.php')
				+ '?action=cmms_help_get&page=' + encodeURIComponent(pageId);
			fetch(url, { credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (data) {
					if (!data || !data.success || !data.data || !data.data.articles) {
						renderEmpty();
						return;
					}
					var articles = data.data.articles || [];
					if (!articles.length) { renderEmpty(); return; }
					// If only one article, render it directly. If multiple,
					// stack them with a small gap — they're all relevant to
					// this screen.
					if (articles.length === 1) {
						titleEl.textContent = articles[0].title || 'עזרה';
						body.innerHTML = articles[0].html || '';
					} else {
						titleEl.textContent = 'עזרה לעמוד זה';
						body.innerHTML = articles.map(function (a) {
							return a.html || '';
						}).join('<hr style="border:0;border-top:1px solid #e2e8f0;margin:24px 0;">');
					}
				})
				.catch(function () { renderEmpty(); });
		}

		function renderEmpty() {
			titleEl.textContent = 'עזרה';
			body.innerHTML = '<p>אין מדריך ייעודי לעמוד הזה. <br>היכנס ל"מרכז הדרכה" בתפריט הצד לרשימת מאמרים מלאה.</p>';
		}

		// Wire up triggers. Every [data-cmms-help-close] inside the modal
		// closes it (backdrop, X button, footer "close").
		fab.addEventListener('click', openModal);
		modal.querySelectorAll('[data-cmms-help-close]').forEach(function (el) {
			el.addEventListener('click', closeModal);
		});
		// Esc closes the modal — basic accessibility.
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !modal.hidden) closeModal();
		});
	});
})();
