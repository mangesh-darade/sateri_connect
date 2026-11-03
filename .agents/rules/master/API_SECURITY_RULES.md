# API Security Rules

## Authentication and authorization
- Every endpoint declares its auth requirement. "Public" must be a deliberate, documented decision.
- Enforce permission checks server-side per endpoint and per object. Scoped tokens must be denied on endpoints that are not explicitly mapped to an ability.
- Never trust identity, role, tenant or account IDs sent in the body, query or headers; derive them from the authenticated context.

## Object-level access (IDOR/BOLA)
For any `/{id}` route, load the record through a query scoped by the caller's boundary and return 404 when not found in that scope. Never fetch by ID alone and check ownership afterwards in the client.

## Input and output
- Validate type, length, format, range and enum on every field (`INPUT_VALIDATION_RULES.md`). Reject unknown fields where practical.
- Return only fields the caller is allowed to see (explicit response mapping, not whole DB rows). Never return hashes, tokens, internal IDs of other tenants, or raw provider errors.
- Consistent status codes and error envelope; generic messages for 5xx.

## Abuse protection
Rate-limit by token/user and IP; stricter on login, OTP, export, search and expensive endpoints. Cap page size, payload size and request time. Paginate all list endpoints.

## Transport and headers
HTTPS only. Restrictive CORS: explicit origins, no wildcard with credentials. Appropriate `Cache-Control: no-store` on sensitive responses.

## Webhooks (inbound)
- Verify an HMAC/signature over the raw body with a constant-time compare before any processing or tenant resolution.
- Reject unsigned payloads in production. Enforce timestamp/replay windows where the provider supports it.
- Make handlers idempotent; return quickly and move heavy work to a queue.
- Resolve tenant/account only from verified data.

## Versioning and compatibility
Do not change existing response shapes or status codes; add versioned or additive changes. Keep API docs/collections updated.

## Logging
Log request ID, caller, route, status and latency. Never log tokens, passwords, full payment data or full personal data.
