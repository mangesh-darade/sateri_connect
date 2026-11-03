# Security Rules

## Authentication
- Passwords: hash with a modern adaptive algorithm (`password_hash` / argon2 / bcrypt). Never store, log or email plaintext. Never invent your own scheme.
- Throttle login, signup, password reset, OTP and verification endpoints per account and per IP. Return identical responses for "unknown user" and "wrong password".
- Regenerate the session ID on login and privilege change. Destroy it fully on logout.
- Tokens (JWT, API keys): short expiry, signed with a server-held secret, algorithm pinned on verification, scoped to the minimum abilities. Store API keys hashed where feasible; show them once.
- Password reset/verification tokens: random, single use, expiring, stored hashed.

## Sessions and cookies
`HttpOnly`, `Secure` (in production), `SameSite` set. No sensitive data in cookies beyond the session ID. Absolute and idle timeouts.

## Authorization
See `AUTHORIZATION_RULES.md`. Summary: every protected action is checked server-side, by object, not only by role.

## CSRF, XSS, injection
- State-changing requests that use cookie auth require a CSRF token. Never use GET for state changes.
- Escape all output for its context (HTML, attribute, JS, URL, CSS). Never render user content as raw HTML unless sanitized by an allowlist sanitizer.
- Queries use parameter binding or the framework query builder. No string-concatenated SQL, shell commands, LDAP, XPath, or template expressions from user input.
- No `eval`, `unserialize` on untrusted data, dynamic `include` from user input, or `shell_exec`/`exec` with user-controlled arguments.

## SSRF and outbound requests
Any URL fetched from user- or tenant-supplied input must be validated: allow `https` only, resolve and reject private/loopback/link-local/metadata ranges, limit redirects, set timeouts and size caps.

## TLS
Certificate and host verification stay **on** for every outbound call. Disabling verification is allowed only behind an explicit local-development check, never as a fallback that can run in production, and never silently.

## Security headers
CSP (as strict as the app allows), `X-Content-Type-Options: nosniff`, frame protection, `Referrer-Policy`, HSTS on HTTPS. Do not weaken existing headers.

## Data protection
Encrypt sensitive stored values (tokens, credentials) with a managed key. Minimize personal data collected and returned. Support deletion/export duties the product has promised.

## Errors
Never expose stack traces, SQL, file paths, provider raw errors or secrets to clients in production. Log them server-side with masking.

## Prohibited
Default/seeded credentials, debug endpoints left enabled, wildcard CORS with credentials, security checks gated only on client-side code, "temporary" auth bypasses, disabled validation to "make it work".

## Handling findings
Classify (Critical/High/Medium/Low), report with location and exploit scenario, propose a fix, and do not publish exploit details outside the repo's private channels.
