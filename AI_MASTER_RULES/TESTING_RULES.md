# Testing Rules

## Principles
- A change is not done until relevant tests pass or you state exactly why none could be run.
- Run the narrowest relevant test first, then the broader suite when the change is cross-cutting. Use the repository's documented commands (`PROJECT_CONTEXT.md`).
- Never "fix" a failing test by weakening the assertion without understanding the cause. Report pre-existing failures separately from ones you introduced.
- Never test against production data or credentials. Use synthetic data and sandboxes; clean up what tests create.

## What to cover
- Happy path plus failure paths: validation errors, empty/oversized input, provider failure, timeouts.
- **Security cases**: unauthenticated, wrong role, wrong tenant/account (IDOR), CSRF missing, injection strings, signature invalid on webhooks, replayed webhook/payment, rate-limit.
- Pure logic (pricing, limits, parsing, state machines) gets unit tests.
- Bug fixes get a regression test that fails before and passes after.
- Migrations: verify up (and down where provided) on a scratch database.

## Quality
Deterministic (no reliance on wall clock, network, ordering). Isolated state. Clear names describing behavior. Mock external services at the adapter boundary.

## Reporting
State what was run, results, and what was not run. Do not claim coverage you did not measure.

## Static analysis
Run existing linters/static analyzers if configured. Recommend adding them if absent; do not add tooling unasked.
