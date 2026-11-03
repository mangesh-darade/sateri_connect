# Authorization Rules

## Principles
- Authenticate first, then authorize every action. Deny by default.
- Authorization lives on the server in a central mechanism (filters, middleware, policies). Do not scatter ad-hoc role string comparisons.
- Hiding a button is not authorization. Every UI action must have a matching server check.

## Layers to check (all that apply)
1. **Identity**: who is calling (session/token), still active, not suspended.
2. **Boundary**: which tenant/account/company/user scope they belong to.
3. **Capability**: do they hold the permission for this action.
4. **Object**: do they own or may access this specific record.
5. **State**: is the action valid now (status, plan limits, consent, balance).

## Rules
- New action ⇒ new or reused permission identifier ⇒ registered in the permission catalog/defaults ⇒ assigned to roles deliberately. Never default a new permission to everyone.
- Privilege escalation guards: users cannot grant permissions they do not hold, edit their own role, or modify higher-privileged users.
- Admin/platform-level functions are separated from tenant-level functions and use a distinct auth path.
- Token scopes can only narrow, never widen, a user's permissions.
- Bulk actions authorize every item, not just the first.
- Authorization failures: 403 (or 404 to avoid enumeration), logged with actor and target, no detail leaked.
- Tests: for each protected endpoint include at least one allowed and one denied (wrong role and wrong tenant) case.

## Review triggers
Changes to filters/middleware, permission catalogs, role editors, token issuance, impersonation, or tenant routing require a full `SECURITY_REVIEW_CHECKLIST.md` pass.
