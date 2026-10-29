# Architecture Rules

## Layering
Respect the repository's existing layers (presentation → application/service → data). Dependencies point inward only.
- Controllers/handlers: parse input, authorize, call a service, shape the response. No business logic, no raw SQL, no direct third-party calls.
- Services: business rules and external integrations. Return a consistent result structure; do not depend on HTTP/view concerns.
- Data layer: models/repositories own persistence. Queries live here, not in views or controllers.
- Views/templates: presentation only. No queries, no business decisions.

## Boundaries
- Keep tenant/account/user isolation logic in one place and call it, never re-implement it per feature.
- External systems are reached only through an adapter/service so they can be mocked, rate-limited, and error-mapped.
- Long or fallible work (sending, syncing, imports, reports) goes to a queue/job, not the request.
- Cron/worker code must establish its own tenant/user context explicitly.

## Idempotency and concurrency
Webhooks, jobs and payment callbacks must be idempotent (dedupe keys, unique constraints). Use transactions for multi-row invariants and locks/atomic updates for counters and balances.

## Change discipline
- Do not restructure directories, rename public routes, or change response shapes without approval; other clients may depend on them.
- Prefer additive changes (new column, new endpoint, new flag) with backward compatibility; deprecate before removing.
- Introduce a new library, framework, or pattern only with justification and approval.

## Documentation
Update the repository's map/docs (screens, routes, permissions, setup) in the same change when behavior changes.

## Risk register mindset
When touching authentication, billing, tenant routing, webhooks or file handling, state the blast radius and rollback plan in your summary.
