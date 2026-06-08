# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

**cmmsLight** is a multi-tenant SaaS CMMS (Computerized Maintenance Management System) built entirely as a WordPress plugin. It has no external build tooling — PHP files and pre-bundled JS/CSS are committed directly to git and deployed via WordPress plugin upload.

## Git Conventions

- Always commit directly to `main` — do NOT create new branches unless explicitly asked
- Never open a PR unless specifically requested
- Push directly to `main` after committing

## No Build/Test Pipeline

There is no `package.json`, `composer.json` (other than a vendored xlsx parser), Makefile, or test framework. There are no automated tests. JS/CSS assets (`assets/js/cmms-light-public.js`, `assets/css/cmms-light-public.css`) are pre-bundled and checked in directly. Testing is manual against a live WordPress installation.

## Architecture Overview

### Multi-Tenancy Pattern

Every feature is scoped by `account_id`. Every database query must include `WHERE account_id = %d` — this is the primary isolation mechanism. Roles within an account: Owner, Manager, Technician, Reporter.

### Request Flow

1. WordPress page with a shortcode (e.g. `[cmms_dashboard]`) renders HTML via `CMMS_Shortcodes`
2. The standalone page template (`templates/cmms-app-template.php`) strips all WordPress theme chrome via `template_redirect`
3. Browser JS (`assets/js/cmms-light-public.js`) handles in-page navigation via `?view=` query params
4. User actions POST to `admin-post.php` with `action=cmms_*` — handled by `CMMS_AJAX`
5. AJAX handlers validate nonces, check permissions, call feature classes, return JSON
6. Feature classes query 20+ custom `wp_cmms_*` tables directly via `$wpdb->prepare()`

### Key Files

| File | Role |
|------|------|
| `cmms-light.php` | Plugin entry point — includes all classes |
| `includes/class-cmms-plugin.php` | Orchestrator — instantiates everything on `plugins_loaded` |
| `includes/class-cmms-db.php` | All 20+ custom table schemas via `dbDelta` |
| `includes/class-cmms-activator.php` | Install/upgrade/migration lifecycle |
| `includes/class-cmms-shortcodes.php` | All frontend UI (~817 KB, renders every view) |
| `includes/class-cmms-ajax.php` | All form/AJAX handlers (~174 KB, 60+ endpoints) |
| `includes/class-cmms-auth.php` | JWT token auth + session management |
| `includes/class-cmms-i18n.php` | Hebrew/English translations (~120 KB) |
| `templates/cmms-app-template.php` | Standalone HTML shell (no WP theme) |
| `public/class-cmms-public.php` | Asset enqueuer + theme isolation |
| `admin/class-cmms-admin.php` | WordPress super-admin panel |

### Feature Classes Pattern

Each domain area has a dedicated class in `includes/`:
- `CMMS_Tasks` — work orders, status, recurrence, attachments, comments
- `CMMS_Assets` — equipment/location registry, QR codes, custom fields
- `CMMS_Forms` — public + internal forms, custom fields, submission→task routing
- `CMMS_Users` / `CMMS_Accounts` — user and tenant management
- `CMMS_Notifications` / `CMMS_Push` / `CMMS_Telegram` — notification channels
- `CMMS_Webhook` — REST endpoint for external task creation (Bearer token auth)
- `CMMS_Email_Inbox` — email-to-task routing (HMAC auth)
- `CMMS_Cron` — scheduled jobs for recurring tasks and notification sweeps
- `CMMS_Icredit` — Israeli payment gateway integration
- `CMMS_Subscriptions` / `CMMS_Plans` / `CMMS_Seats` — billing lifecycle

### Database

All tables are prefixed `wp_cmms_*`. Core tables: `accounts`, `users`, `tasks`, `task_logs`, `assets`, `asset_field_defs`, `categories`, `forms`, `form_fields`, `form_submissions`, `attachments`, `notifications`, `reminders`, `push_subs`, `subscriptions`, `payments`, `packages`, `telegram_users`, `webhook_keys`, `email_routes`, `task_signatures`.

### Frontend

Pure HTML + CSS + vanilla JS (no React/Vue). The single JS bundle handles all in-page interactions. Views are switched via `?view=` query params parsed by JS. The UI uses CSS custom properties with a deep navy + safety orange palette. Hebrew is the primary language; English secondary.

### Security Conventions

- WordPress nonces on all form actions (CSRF protection)
- `$wpdb->prepare()` for all queries (SQL injection prevention)
- `sanitize_text_field`, `absint`, `wp_kses_post` on all inputs
- `account_id` enforced on every query (tenant isolation)
- File uploads: MIME type validated, stored in `/wp-content/uploads/cmms-light/`
