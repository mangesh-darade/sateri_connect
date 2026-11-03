# Performance Rules

Performance work must not trade away security or correctness.

## Database
- Paginate and bound every list query. Avoid N+1; batch loads and writes.
- Add indexes for new filter/sort/join columns (via migration) and verify with `EXPLAIN` on hot paths. Do not over-index write-heavy tables.
- Keep transactions short. Avoid locking whole tables in request paths.
- Move heavy aggregates to cached/precomputed values refreshed by a scheduled job, with a clear staleness bound.

## Requests
- Anything slow, bulk, or externally dependent (sends, imports, exports, syncs, reports) runs in a queue/worker with progress and retry, not inline.
- Stream or chunk large exports/imports; cap memory.
- Set timeouts on all outbound calls.

## Caching
- Cache keys must include the isolation key (tenant/account/user) to prevent cross-tenant leaks.
- Never cache authorization decisions or secrets beyond a request unless invalidation is defined.
- Define TTL and invalidation for each cache.

## Frontend
Load assets only where needed, minimize payloads, debounce search inputs, and avoid repeated polling at tight intervals.

## Measurement
State the measured or expected effect for performance changes. Avoid speculative micro-optimizations that reduce readability.

## Resource safety
Cap upload sizes, page sizes, loop iterations, regex complexity (ReDoS), and recursion depth on user-influenced input.
