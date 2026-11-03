# Security Review Checklist

Use before declaring any change done. Mark N/A with a reason; do not skip silently.

## Identity and access
- [ ] Endpoint/action requires authentication (or public by documented decision)
- [ ] Server-side permission check exists and the permission is registered
- [ ] Object-level check: record fetched within caller's tenant/account/user scope
- [ ] No client-supplied identity, role, tenant or price is trusted
- [ ] Denied cases return safe responses and are logged

## Input and output
- [ ] All inputs validated (type, length, range, enum) server-side
- [ ] No mass assignment; models use field allowlists
- [ ] Queries parameterized; dynamic identifiers from allowlist
- [ ] Output escaped for context; no unsafe `innerHTML`/raw output
- [ ] Redirect targets and URLs fetched by the server are validated (open redirect, SSRF)

## Sessions and CSRF
- [ ] State-changing requests use POST/PUT/DELETE with CSRF protection (unless token-authenticated API)
- [ ] No sensitive data in URLs, cookies or browser storage

## Secrets and data
- [ ] No secrets, tokens, real data or debug output added to code, logs, fixtures or responses
- [ ] Sensitive values encrypted/masked; responses return only needed fields
- [ ] Personal data minimized; consent/opt-out respected

## Files
- [ ] Upload allowlist, size limit, server-generated names, non-executable storage
- [ ] Downloads authorized, path-confined, `nosniff`

## Integrations
- [ ] Timeouts, try/catch, TLS verification on, errors mapped to safe messages
- [ ] Webhooks: signature over raw body, replay/idempotency, tenant from verified data
- [ ] Payments: server-side amounts, idempotent credit, ledger entry

## Data layer
- [ ] Migration + schema snapshot/docs + model updated together
- [ ] Transactions/atomic updates for balances and counters
- [ ] New filter/sort columns indexed; list queries paginated

## Operations
- [ ] Audit log entry for each state change
- [ ] Rate limits on abuse-prone endpoints
- [ ] Production-only safeguards not bypassed by dev flags
- [ ] Cron/worker tenant context explicit and idempotent
- [ ] New dependency justified, pinned and audited

## Verification
- [ ] Relevant tests run (incl. denied/wrong-tenant/invalid-input cases); results reported
- [ ] Diff reviewed for unrelated changes
- [ ] Docs/route/permission map updated
- [ ] Residual risks and follow-ups listed in the summary
