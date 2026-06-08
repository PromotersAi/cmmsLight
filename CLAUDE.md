# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Git Conventions
- Always commit directly to main — do NOT create new branches unless explicitly asked
- Never open a PR unless I specifically request it
- Push directly to main after committing

## Project Overview

**cmmsLight** is a self-contained WordPress plugin (v1.15.11) implementing a lightweight CMMS (Computerized Maintenance Management System) as a multi-tenant SaaS.

- Language: PHP 7.4+ (server) + Vanilla JavaScript (no frameworks, no jQuery)
- No build system, no composer, no npm — activate directly in WordPress admin
- No automated tests — manual QA only

## Architecture

### Entry Points
- `cmms-light.php` — Plugin header; loads all 47 class files via `require_once`; registers activation/deactivation hooks
- `includes/class-cmms-plugin.php` — Orchestrator; registers all services, hooks, and AJAX handlers

### Key Files by Weight
| File | Size | Role |
|------|------|------|
| `includes/class-cmms-shortcodes.php` | 14,852 lines | All 4 page UIs rendered as PHP/HTML/inline-JS |
| `includes/class-cmms-ajax.php` | 174KB | 50+ AJAX action handlers |
| `includes/class-cmms-activator.php` | 60KB | Plugin activation, 22-table schema, demo data |
| `includes/class-cmms-i18n.php` | 120KB | Localization (Hebrew + English built-in) |
| `assets/css/cmms-light-public.css` | 3,341 lines | Industrial design system (navy + safety orange) |
| `assets/js/cmms-light-public.js` | 2,279 lines | Vanilla JS; includes self-heal / cache-bust logic |

### Database
22 custom tables, all prefixed `wp_cmms_*`. Every query is filtered by `account_id` for tenant isolation. Key tables: `wp_cmms_accounts`, `wp_cmms_users`, `wp_cmms_tasks`, `wp_cmms_assets`, `wp_cmms_forms`, `wp_cmms_form_submissions`, `wp_cmms_task_logs`.

### Frontend
All UI is rendered inside 4 WordPress shortcodes in `class-cmms-shortcodes.php`:
- `[cmms_signup]` — Organization registration
- `[cmms_login]` — User login
- `[cmms_dashboard]` — Main app; routes via `?view=...`
- `[cmms_public_form]` — External form submission (no login required)

`templates/cmms-app-template.php` strips theme chrome (header/footer/admin bar) for a standalone SPA feel.

### External Integrations
- **REST webhook** (`class-cmms-webhook.php`) — Bearer token; external systems POST tasks
- **Email-to-task** (`class-cmms-email-inbox.php`) — Cloudflare Email Worker forwards emails as HMAC-signed JSON
- **Telegram bot** (`class-cmms-telegram.php` + `class-cmms-telegram-webhook.php`) — Task status via chat
- **Web push** (`class-cmms-push.php`)

### Multi-tenancy Pattern
1. Org signs up → `wp_cmms_accounts` row created
2. Owner linked via `wp_cmms_users` (role = Owner)
3. All queries server-side filtered by `account_id` — never trust client-supplied account ID

### Role Hierarchy
Owner → Manager → Technician → Reporter (enforced server-side in `class-cmms-auth.php`)

### Self-heal Logic (JS)
`cmms-light-public.js` detects version mismatches between the page `<meta name="cmms-version">` and the loaded JS, unregisters stale service workers, clears caches, and reloads. Do not remove or weaken this logic.
