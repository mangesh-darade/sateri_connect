# Database Rules

## Schema changes
- Every schema change is a versioned migration with a working rollback where feasible. Never edit an applied migration; add a new one.
- Keep all schema artifacts in sync in the same change: migration, schema snapshot/docs (if the repo keeps one), and the model (allowed fields, casts, validation).
- Verify the real column names/types in the migration or schema before writing a query. Never assume a column exists.
- Migrations must be safe on large tables: avoid long locks, add indexes deliberately, backfill in batches, and be idempotent when the repo's migrations are.
- Never run destructive or multi-database migration commands against the wrong target; confirm which database a command affects.

## Queries
- Always bind parameters or use the query builder. Identifiers that must be dynamic (sort column, table) come from an allowlist.
- Scope every query by the isolation key (tenant/account/user) unless the connection itself is the isolation boundary and you have confirmed it.
- Select needed columns, paginate, and cap result sizes. No unbounded `SELECT *` on large tables inside request paths.
- Avoid N+1; batch or join. Add indexes for new filters/sorts and check with `EXPLAIN` on hot paths.
- Use transactions for multi-statement invariants; keep them short; handle deadlock retries at the boundary.
- Money, balances and counters: use integer minor units or exact decimals, atomic updates, and a ledger/audit row.

## Mass assignment
Models expose an explicit allowlist of writable fields. Never pass raw request bodies to insert/update. Sensitive columns (role, owner, tenant, balance, status flags) are never client-writable.

## Data lifecycle
- Soft vs hard delete follows existing convention; honor erasure obligations.
- Store secrets encrypted; store passwords/tokens hashed.
- Do not store more personal data than needed; set retention/cleanup jobs for logs and temporary data.

## Access
Application DB users get the minimum privileges (no DDL in runtime unless migrations run as that user). Never use root/admin credentials in application config outside local dev. Never commit dumps containing real data.

## Backups and safety
Before any bulk update/delete, state the affected scope, test with a `SELECT` first, and require human confirmation for production data.
