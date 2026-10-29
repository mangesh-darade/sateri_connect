# Project Context (project-specific — the only file that may name project facts)

Generated from repository inspection on 2026-10-29. Re-verify against the code before relying on any item; update this file when facts change.

## Source of truth
- `CLAUDE.md` (architecture, commands, conventions), `PROJECT_MAP.md` (screens/routes/permissions), `docs/` (setup, tenancy, wallet, providers, `PRODUCTION_SECURITY.md`).
- Repo-native rules, which remain in force and are never weakened by this directory: `.agents/rules/*.md` and `.cursor/rules/*.mdc` (error presenter, filter/auth pattern, JS `APP` namespace, Meta WhatsApp, schema↔model sync, service-layer contract, tenancy context, third-party API pattern, UI consistency, English-only UI, prompt/token optimizer).

## Stack
- PHP ^8.2, CodeIgniter 4.7, MySQL (InnoDB), Bootstrap 5 + jQuery, PHPUnit 10, `firebase/php-jwt`, `phpoffice/phpspreadsheet`. Local dev on WAMP.
- Multi-tenant: one DB per client plus a routing master DB. Tenant resolution via login index, session key, JWT claim, webhook phone/page ID, or host route. Details in `CLAUDE.md` and `docs/MULTI_CLIENT_TENANCY.md`.
- Channels: WhatsApp (Meta Cloud API or Cheerio), Facebook/Instagram, Email (SES, SMTP, SendGrid). Payments: Razorpay, Mswipe. Also ElintOm integration and an AI service.
- Background work via spark commands on cron (queue, campaigns, automations, payment reconcile, stats refresh, template sync, log cleanup, wallet settle).
- Layering: Controllers → `app/Libraries/*Service` → `app/Models`. Filters: `auth`, `permission`, `csrf`, `rateLimit`, `api-auth`, platform-auth, install.

## Project-specific hard rules (from existing repo rules)
- Services return `['ok','status','message','data'|'errors']` and never throw to controllers or call `redirect()/view()`.
- Never open a DB connection inside `TenantResolver::boot()`. Never read `$_SERVER['HTTP_HOST']` for tenant choice; use `TenantResolver::requestHost()`. Never hardcode tenant DB names. Never run `php spark migrate -g master`.
- Rebuild tenant-bound services/models after any tenant switch; use `capture()` + `restoreIfChanged()`.
- WhatsApp provider and credentials come only from `SettingsService`. Use `WhatsAppTemplateSendGuard`, `WhatsAppTemplatePayload`, `WhatsAppSendWindow`, `WhatsAppConsentService`. Map Meta errors with `MetaApiErrorMapper`; no extra retry loops over `MetaCloudAPI`.
- Every state change calls `log_activity(...)`. Web errors via `render_app_error()`; AJAX returns `{status, message}`.
- Frontend: jQuery `$.ajax` with CSRF, `APP.toast()`, page JS in `scripts` section or `public/assets/js/`. All UI text in English.
- Schema change = migration in `app/Database/Migrations` + `app/Database/Schema/tables.php` + Model.
- `/api/v1` permissions are enforced inside `ApiAuthFilter` (`resolveRequiredPermission`); scoped keys are denied on unmapped endpoints.
- Do not commit unless asked; ask first.
- `.agents/rules/tenancy-context-rule.md` mentions `TenantConnection::get()`, which does not exist; use `TenantConnection::apply()` and `TenantContext::get()`.

## Commands
`vendor/bin/phpunit tests/<File>.php` (tests live flat in `tests/`; `phpunit.dist.xml` points at other dirs). `php tests/WalletPaymentsUnitTest.php` for wallet logic. `tests/TenantDatabaseGuardTest.php` fails on its own bootstrap (`FCPATH already defined`, pre-existing). No CI config, linter or static analyzer found.

## Security-sensitive modules
`TenantResolver`, `TenantConnection`, `MasterTenantRepository`, `TenantProvisionService`, `ApiAuthFilter`, `AuthFilter`, `PermissionFilter`, `CsrfFilter`, `RateLimitFilter`, `LoginThrottle`, `JwtService`, `EncryptionService`, `WebhookValidator` + `Webhooks`/`RazorpayWebhook`/`MswipeWebhook`/`SesWebhook`, `WalletService`/`WalletPaymentSettler`, `Install` controller, `Media` and `Chat` uploads, `EmailAttachmentHandler`, `ContactImportService`, `ContactErasureService`, `AiService`, `Settings`.

## Inspection findings (verify, then triage; none were fixed by this task)
1. **TLS verification fallback to off — FIXED 2026-10-29.** `AiService.php` now keeps verification on in production when no CA bundle is found (off only in non-production). `MetaCloudAPI` defaults to verify-on (off only if `WhatsApp::$sslVerify` is explicitly `false`); `WhatsAppIdentityService` already gated on production.
2. **Tracked data/artifacts (Medium).** Tracked in git: `apiwa_database.sql` and two `.xlsx` inventories at the repo root; `public.zip`, `sateri_connect_database.sql` and `sateri_connect_clean_install.sql` exist at root (check tracking and contents). Confirm they contain no real data/secrets and that the web root cannot serve them (`docs/PRODUCTION_SECURITY.md` says dumps must not be deployed).
3. **Installer routes public (Medium).** `/install/*` is publicly routed; confirm the `InstallFilter` + lock fail closed in every deployment and are covered by tests.
4. **Rate limiting is opt-in per route (Medium).** `RateLimitFilter` is keyed by IP+method+path and applied on selected routes (signup, forgot-password, etc.). Review login, API, webhook, export and AI endpoints for coverage; consider trusted-proxy correctness for the IP key.
5. **Raw SQL/`query(` usage (Review).** ~190 hits for raw-query/exec patterns across 61 files (most are migrations/commands). Audit controllers/libraries (`ContactListFilter`, `DashboardStats`, `PlatformStatsService`, `MasterTenantRepository`, `Chat`, `Media`) for bound parameters and allowlisted identifiers.
6. **Upload handling (Review).** `Chat`, `Media`, `Settings` (branding), `Templates`, `Campaigns`, `EmailAttachmentHandler` move user files; verify extension+MIME allowlist, server-side names, non-executable storage and tenant scoping for each (`uploads/` has a deny `.htaccess`? — confirm).
7. **Test gaps.** No automated tests found for cross-tenant IDOR, permission denial matrix, webhook signature rejection, or CSRF; existing tests are mostly functional/smoke. No CI, static analysis or dependency/secret scanning configured.
8. **Docs drift.** `CLAUDE.md` notes one rule file references a nonexistent method; `docs/PRODUCTION_SECURITY.md` mentions a `_unused/` folder not present in the tree.

## Suggested next steps
Add CI (phpunit, `composer audit`, secret scan, `php -l`), a PHPStan/Psalm baseline, tenant-isolation and permission tests, and a pre-commit secret check. Raise these with the team before adding tooling.
