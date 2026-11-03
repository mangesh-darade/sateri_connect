# Frontend Security Rules

## Output encoding
- Escape every dynamic value in templates using the framework's context-aware escaper. Raw output requires a documented reason and sanitized input.
- In JavaScript use `textContent`/`.text()` for untrusted strings. Avoid `innerHTML`/`.html()` with data; if unavoidable, sanitize with an allowlist library.
- Never build inline event handlers or `<script>` content from user data. Pass data via `data-*` attributes or JSON encoded safely.

## Requests
- Send the CSRF token on every state-changing request. Use the project's single AJAX pattern (see `PROJECT_CONTEXT.md`); do not hand-roll `fetch` variants.
- Never put secrets, tokens or API keys in client-side code, HTML, or URLs. Do not expose internal error details in the UI.
- Do not rely on hidden fields, disabled buttons or client checks for authorization or pricing.

## Third-party scripts and assets
Prefer self-hosted, version-pinned libraries. Use Subresource Integrity for CDN assets. No new third-party script without approval. Keep CSP working; do not add `unsafe-inline`/`unsafe-eval` to make something work.

## Storage
No sensitive data in `localStorage`/`sessionStorage`. Do not persist tokens there.

## Links and navigation
`rel="noopener noreferrer"` on `target="_blank"`. Validate redirect targets against an allowlist (no open redirects). Never `javascript:` URLs from data.

## Forms and files
Client file checks (type/size) are convenience; the server enforces. Disable autofill/caching for sensitive fields where appropriate.

## UX safety
Confirm destructive actions. Show generic error toasts. Follow the repository's UI consistency and language standards.

## Logic placement
Business logic stays on the server; page JS handles presentation and calls endpoints.
