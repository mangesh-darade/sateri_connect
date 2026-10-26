# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Sateri Connect: multi-tenant WhatsApp / Email / Messenger / Instagram automation platform on **PHP 8.2+ / CodeIgniter 4.7**, MySQL (InnoDB), Bootstrap 5 + jQuery. Local dev is WAMP at `http://localhost/sateri_connect/public/`. `PROJECT_MAP.md` lists every screen, route and permission; `docs/` has setup and provider guides (`docs/MULTI_CLIENT_TENANCY.md` is the tenancy reference).

## Commands

```bash
composer install && cp .env.example .env && php spark key:generate   # first-time setup; then open /install wizard
php spark tenant:ensure-master --create-db [--admin-email=.. --admin-password=..]  # create/sync sateri_master
php spark tenant:provision -key acme -name "Acme" -database sateri_acme -admin-email a@b.c -admin-password '...'
php spark migrate            # tenant schema only
vendor/bin/phpunit tests/SomeTest.php            # run one test file (tests live flat in tests/)
vendor/bin/phpunit --filter testName tests/SomeTest.php
```

- Never run `php spark migrate -g master`: it would apply all app migrations onto the master DB.
- `phpunit.dist.xml` points at `tests/unit`, `tests/database`, `tests/session`, but most tests sit directly in `tests/`, so pass the file path. At least `tests/TenantDatabaseGuardTest.php` fails on its own bootstrap (`Constant FCPATH already defined`).
- Background workers (cron, every minute): `queue:process`, `campaigns:process`, `automations:process`, `queue:retry`; every 10 minutes: `payments:reconcile` (credits paid Mswipe links); every 5 minutes: `stats:refresh` (recomputes the cached client numbers behind the All clients page); daily: `templates:sync`, `logs:cleanup [days]`, `wallet:settle` (refunds wallet charges for messages never delivered within 30 days; loops all active clients). CLI workers need a tenant: set `database.tenant = <key>` in `.env` or run once per tenant.
- Other `app/Commands/*` are diagnostics (`schema:*`, `meta:webhook-diag`, `permissions:audit`, `*:test`); check `php spark list`.

## Architecture

### Multi-tenancy (read before touching DB code)
One codebase, one MySQL database per client, plus a routing DB `sateri_master`.

- `Config\Tenancy`: portal hosts (`localhost`, `127.0.0.1`, `tenancy.portalHosts` in `.env`) and master DB credentials (`database.master.*`).
- `Config\Database::__construct` calls `TenantResolver::boot()`. **boot() must never open a DB connection**, because that re-enters Config construction and hangs. Tenant DBs are applied later, on demand.
- `TenantResolver::ensureFromSession/ensureFromJwtClaim/ensureFromPublicKey` → `TenantConnection::apply($key, $source)` → reads `tenants` row from master (`MasterTenantRepository`), decrypts the DB password, repoints `$config->default`, reconnects. `TenantContext` holds the current key; `TenantConnection::$boundKey` tracks which tenant the live connection is really bound to (they can differ at boot).
- Master tables: `tenants`, `tenant_login_index` (email → tenant), `tenant_phone_routes` (Meta `phone_number_id` → tenant), `tenant_host_routes`, `platform_admins`, `platform_settings`, `platform_admin_meta`.
- Resolution paths: login email → login index; session `tenant_key`; JWT `tenant` claim (API); Meta webhook → phone/page id or host route (`TenantConnection::applyFromPhoneNumberId/applyFromRequestHost`); legacy Host subdomain via `Config\Database::applyBySubdomain()`.
- `MasterTenantRepository` routing writes (`upsertLoginIndex/PhoneRoute/HostRoute`) refuse to reassign a row owned by another active tenant. Unknown tenant keys are rejected unless they are the request's own legacy host slug. Keep both invariants.
- Every default-connection switch (`TenantConnection::reconnectDefault`) resets shared models and tenant-bound shared services (`settingsService`, `whatsAppConsent`, ...). Code that switches tenants mid-request must build services/models *after* the switch and use `capture()` + `restoreIfChanged()` to go back.
- `Webhooks::receive` splits a POST into one payload per `entry`; each entry is routed (phone id → master route, page id → `FacebookLeadService::useTenantForPage`, else host), verified with that tenant's secrets, processed, then the previous tenant is restored. On a shared portal/tunnel host an entry that routes nowhere is dropped. Unsigned payloads are accepted only when `webhook.allowUnsigned` is true (default: only `CI_ENVIRONMENT=development`).
- `/api/v1` role permissions are enforced inside `ApiAuthFilter` (`resolveRequiredPermission`), not by route filters: scoped API keys have their permissions narrowed to abilities, so `permission:` route filters would deny them. Scoped keys are denied on unmapped v1 endpoints.
- Logins (web and API) throttle through `LoginThrottle` (per email+IP, plus a higher per-account cap). Provisioning accepts only `[A-Za-z0-9_]` database names and never the master/system schemas; non-primary platform admins cannot choose the MySQL host/user/port.
- Do not read `$_SERVER['HTTP_HOST']` to pick a tenant (use `TenantResolver::requestHost()`), and do not hardcode tenant DB names. No platform admin password is seeded; `tenant:ensure-master` generates one.
- Note: `.agents/rules/tenancy-context-rule.md` mentions `TenantConnection::get()`; that method does not exist. Use `TenantConnection::apply()` and `TenantContext::get()`.

### Messaging providers
WhatsApp runs on either Meta Cloud API (`MetaCloudAPI`) or Cheerio (`CheerioDirectAPI`, same interface). The active provider and credentials always come from `SettingsService` (`getWhatsAppProvider()`, `getMetaConfig()`), never from `.env` or client input. Facebook/Instagram use `MetaPageMessagingAPI`; Facebook Lead Ads go through `FacebookLeadService`. Email: SES (primary), SMTP, SendGrid. Shared Meta webhooks hit `Webhooks` and are verified with `WebhookValidator` (HMAC over the raw body, taken before CI4 parses input) before tenant resolution.

### Layering
Controllers (thin) → `app/Libraries/*Service` (business logic, external APIs) → `app/Models`. Settings live in the tenant DB behind `SettingsService`. Permissions use the `can('slug')` helper with `PermissionFilter`; protected routes sit in `auth` filter groups, API routes under `/api/v1` with `api-auth`.

## Conventions enforced by `.agents/rules` and `.cursor/rules`

- **Services return** `['ok' => bool, 'status' => int, 'message' => string, 'data'?|'errors'?]`; they never throw to controllers, never call `redirect()/view()/render_app_error()`. Controllers must check `$result['ok']`.
- **3rd-party calls**: try/catch `Throwable`, log class/method/masked params, return generic 502 message. Never expose raw provider errors (map Meta errors with `MetaApiErrorMapper`). Don't add retry loops on top of `MetaCloudAPI`'s.
- Web controllers render failures with `render_app_error()`; AJAX endpoints return JSON `{status:'success'|'error', message}`.
- **Meta sending**: check `WhatsAppTemplateSendGuard::resolveApprovedTemplate()` before templates, build payloads with `WhatsAppTemplatePayload`/`WhatsAppTemplateVariables`, check `WhatsAppSendWindow` for free-form. Opt-in goes through `WhatsAppConsentService` (never overrides STOP).
- **Frontend**: jQuery `$.ajax` only (with `beforeSend`/`complete`/`error`), `APP.toast()` for feedback, CSRF token from the page, no business logic in inline `<script>`; page JS goes in the `scripts` section or `public/assets/js/`. All UI text must be **English** even though chat with the team may be Marathi/Hinglish.
- Every state-changing action must call `log_activity($action, $module, $description, $metadata)`.
- **Schema sync (always)**: whenever a table or column is created or changed, do all three in the same change: (1) a migration in `app/Database/Migrations`, (2) the schema snapshot `app/Database/Schema/tables.php`, (3) the matching Model (`$allowedFields`, casts, validation) or a new Model for a new table. See `.agents/rules/schema-model-sync.md`.
- Never commit unless the user explicitly asks, and ask before committing.

## WhatsApp wallet and online payments

`docs/WALLET_AND_PAYMENTS.md` covers the wallet (rates, markup, GST, spending limits), Razorpay recharge, invoices and the cron jobs. Pure-logic checks: `php tests/WalletPaymentsUnitTest.php`.
