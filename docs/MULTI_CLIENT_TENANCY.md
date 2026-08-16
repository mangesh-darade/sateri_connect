# Multi-client tenancy (single domain + separate DBs)

Sateri Connect can host many clients on **one hostname** without per-client DNS subdomains. Each client keeps a **separate MySQL database**. A small **master** database routes login and Meta webhooks.

## Architecture

| Layer | Role |
|-------|------|
| Portal host | e.g. `connect.example.com` or `localhost` (see `tenancy.portalHosts`) |
| `sateri_master` | `tenants`, `tenant_login_index`, `tenant_phone_routes` |
| `sateri_{client}` | Full app schema (users, contacts, chat, settings, …) |

**Legacy subdomain tenants** (e.g. `demoelintommetaapi.elintpos.in`) still work via [`app/Config/Database.php`](../app/Config/Database.php) `applyBySubdomain()`.

```text
Login email → master.tenant_login_index → tenant DB → verify password → session.tenant_key
Webhook phone_number_id → master.tenant_phone_routes → tenant DB → inbox
API JWT claim "tenant" → tenant DB
```

## Setup master DB

1. Create MySQL database:

```sql
CREATE DATABASE sateri_master CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

2. Optional `.env` overrides (defaults: localhost / root / empty / `sateri_master`):

```ini
database.master.hostname = localhost
database.master.username = root
database.master.password =
database.master.database = sateri_master
database.master.port = 3306

# Comma-separated hosts that use session/JWT tenancy (not Host slug alone)
tenancy.portalHosts = localhost,127.0.0.1,connect.example.com
```

3. Ensure master schema:

```bash
php spark tenant:ensure-master --create-db
```

(Do **not** run `php spark migrate -g master` — that would apply all app migrations onto the master database.)

## Provision a client

```bash
php spark tenant:provision -key acme -name "Acme Co" -database sateri_acme -admin-email admin@acme.test -admin-password ChangeMe123!
```

This will:

1. `CREATE DATABASE` (unless `--skip-create-db`)
2. Insert/update `tenants` in master (DB password encrypted)
3. Migrate + seed the tenant DB
4. Create admin user + `tenant_login_index` row

## Platform super admin

Default (after `php spark tenant:ensure-master`):

- Email: `platform@sateri.local`
- Password: `Platform@123`

Login → **Platform dashboard** (`/platform/clients`):

- KPI strip: clients, contacts, sent, failed, open chats, Meta-ready count
- Client table: health, users, contacts, sent/failed, chats, Meta status
- **Deep view** per client: performance (14-day bars), campaigns, Meta + login forms
- **Open** enters that client workspace as admin
- Create client (DB + admin login)

## Login

`/login` is the normal email/password form. Multi-client routing uses the email → tenant index (or optional `?tenant={key}`).

Platform super admin: `/login?tenant=_platform` (link also on the login page).

## Day-to-day

1. User opens **one** portal URL and logs in with their email.
2. In tenant **Settings → Meta**, save phone number id + app secret + verify token.
3. Saving Meta upserts `tenant_phone_routes` so shared webhook works:

`https://connect.example.com/webhooks`

4. REST API login returns JWT with `tenant` claim; all later API calls use that DB.

## Migrating an existing subdomain tenant into master

```sql
-- Example: register demoelintommetaapi credentials into master
-- Prefer: php spark tenant:provision --key=demoelintommetaapi --skip-create-db --skip-migrate ...
-- then upsertLoginIndex for each admin email.
```

Or use `tenant:provision` with `--skip-create-db --skip-migrate` and matching `--database` / credentials, then:

```sql
INSERT INTO sateri_master.tenant_login_index (email, tenant_key, created_at, updated_at)
VALUES ('admin@example.com', 'demoelintommetaapi', NOW(), NOW());
```

## CLI workers

Queue/campaign workers must target a tenant:

```ini
database.tenant = acme
```

Or run once per tenant key.

## Security notes

- Tenant DB passwords in master are stored encrypted (`enc:…`) when written via provision.
- Emails are globally unique in `tenant_login_index`.
- Do not put tenant credentials in `.env` `database.default.*` (still ignored for default group).
