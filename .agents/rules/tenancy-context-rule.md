# Tenancy Context Rule

This project is multi-tenant. Every request resolves a tenant via `TenantResolver` → `TenantContext`.

## Key Classes
- `App\Libraries\TenantContext` — holds current tenant's `db_name`, `tenant_id`, `subdomain`.
- `App\Libraries\TenantConnection` — returns tenant-scoped DB connection.
- `App\Libraries\TenantResolver` — resolves tenant from subdomain or session (portal hosts).
- `App\Config\Tenancy` — portal hosts + master DB config (from `.env`).
- `App\Libraries\MasterTenantRepository` — queries `sateri_master` DB only.

## Rules
- **Never** query tenant data using the default `db()` connection without confirming the tenant DB is already switched. Use `TenantConnection::get()` when in doubt.
- **Never** hardcode `elintomreach_db` or any tenant DB name in code. Always read from `TenantContext`.
- `MasterTenantRepository` is for master DB only (tenant provisioning, routing). Never mix with tenant queries.
- New controller/service that needs settings → inject `SettingsService` (not direct `settings` table query) — it already reads from the correct tenant DB.
- Webhooks and API endpoints resolve tenant via `ApiAuthFilter` before the controller runs — do not re-resolve manually.
- Portal hosts (localhost, 127.0.0.1) resolve tenant from session, not subdomain — do not assume subdomain always exists.

## Do NOT
- `$db = db_connect('default')` in a new service without verifying which DB `default` points to.
- Store tenant DB credentials anywhere other than master DB / `.env`.
- Access `$_SERVER['HTTP_HOST']` directly to determine tenant — use `TenantResolver`.
