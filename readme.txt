=== CMMS Light ===
Contributors: cmmslight
Tags: cmms, maintenance, tasks, assets, work orders, preventive maintenance
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later 
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, multi-tenant CMMS (Computerized Maintenance Management System) for WordPress. Manage tasks, assets, technicians, and external report forms - mobile first.

== Description ==

CMMS Light turns any WordPress site into a fully functional Computerized Maintenance Management System (CMMS). It is designed for small and medium operations that need to track maintenance work without the overhead of a heavy enterprise platform.

**Key features**

* Multi-tenant accounts - each organization has its own users, tasks, assets and forms.
* Roles: Owner, Manager, Technician (executor), and Reporter (creates tasks only).
* Task types: Breakdown, Preventive, Planned, and General Tasks (defaults auto-created per account).
* Asset registry: machines, equipment, locations, vehicles.
* Tasks with status (Open / In Progress / Waiting / Completed / Closed), priority, due date, recurrence (one-time, weekly, monthly, every X days), file attachments, comments and full activity log.
* Mobile-first interface with large action tiles for technicians in the field.
* Public report forms (like Google Forms) with shareable URL and auto-generated QR code - external users can submit issues without logging in. Submissions become tasks automatically.
* Basic dashboard reports: open / completed / overdue counts, by technician, by category.
* Progressive Web App support: Add to Home Screen, offline-friendly shell.
* Global Admin (WordPress administrator) panel to view all accounts, all users, and activate / deactivate accounts.
* No external plugin dependencies. Custom database tables - does not pollute `wp_posts` or `wp_postmeta`.

== Installation ==

1. Download the plugin ZIP file.
2. In WordPress Admin, go to **Plugins -> Add New -> Upload Plugin**.
3. Choose `cmms-light.zip` and click **Install Now**.
4. Click **Activate Plugin**.
5. The plugin automatically creates four pages: `/cmms-signup/`, `/cmms-login/`, `/cmms-dashboard/`, and `/cmms-form/`.
6. Visit `/cmms-signup/` to create your first organization (account owner). You will be auto-logged in and redirected to the dashboard.
7. (Optional) Visit **CMMS Light** in the WordPress admin sidebar to manage accounts globally.

== Frequently Asked Questions ==

= Does this require any other plugin? =

No. CMMS Light is fully self-contained. It does not depend on WooCommerce, ACF, Elementor, or any other plugin.

= Where is the data stored? =

In ten custom database tables prefixed `wp_cmms_*` (the prefix matches your WordPress install). Removing the plugin via **Delete** in the Plugins screen drops all CMMS tables and uploaded files.

= How do public forms work? =

A manager creates a form, picks the default category / priority / status / assignee, and shares the public URL or QR code. Anyone with the link can submit - no login required. Each submission creates a task in the right account, with all answers and uploaded files attached, marked with source "External Form".

= Is it secure? =

All admin actions are nonce-protected. Permissions are enforced server-side per role. Account isolation is enforced via `account_id` checks on every query. File uploads are restricted by mime type and stored outside the plugin directory in `/wp-content/uploads/cmms-light/` with directory listing disabled.

= Can I use it on mobile? =

Yes - the entire dashboard is mobile-first. There is also PWA support so users can "Add to Home Screen" and use it like a native app.

== Screenshots ==

1. Mobile dashboard with large action tiles.
2. Task list with priority indicators and overdue badges.
3. Public report form (no login required).
4. QR code for sharing the public form.
5. Manager reports dashboard.
6. Global admin: all accounts overview.

== Changelog ==

= 1.0.0 =
* Initial release.
* Accounts, users, roles (Owner / Manager / Technician / Reporter).
* Tasks with status, priority, recurrence, attachments, comments, activity log.
* Assets module.
* Categories (Preventive / Planned / Breakdown / General) auto-created per account.
* Public forms with QR codes; submissions create tasks automatically.
* Mobile-first UI with PWA manifest and service worker.
* Global admin dashboard for cross-account management.
* Basic reports (counts, by technician, by category).

== Upgrade Notice ==

= 1.0.0 =
First public release.
