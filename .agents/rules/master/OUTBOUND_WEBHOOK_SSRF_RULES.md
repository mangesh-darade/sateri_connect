# Outbound Webhook and SSRF Rules

Applies to any feature that calls a URL supplied by a user, tenant, or third party (customer webhooks, media/image fetch, link previews, import-by-URL).

## Validate at save time and again at send time
- Scheme `https` only; standard port only; max URL length; no userinfo (`user:pass@`).
- Resolve the host and reject private, loopback, link-local, reserved, and cloud-metadata addresses for **every** resolved IP (IPv4 and IPv6). DNS can change between save and send, so re-check before each call.
- Do not follow redirects, or re-validate every hop. Prefer disabling redirects.
- Pin the connection to the validated IP where the HTTP client allows it (avoids DNS rebinding).

## Request hygiene
- Connect and total timeouts, response size cap, no response body returned to the end user.
- Sign each payload (HMAC with a per-endpoint secret), include a timestamp and a unique delivery ID so receivers can reject replays.
- Store the endpoint secret encrypted; show it once.
- Limit endpoints per tenant, retry with backoff and a maximum attempt count, auto-disable endpoints that keep failing.
- Payloads carry only the fields the event needs; no secrets, tokens, or internal IDs that aid enumeration.
- Deliveries are queued and processed by a worker, never inline in the user request.

## Tests required
Private IP, loopback, metadata IP, `http://`, non-443 port, redirect to internal host, hostname resolving to a private IP.

## Reference in this repo
`app/Libraries/OutgoingWebhooks.php` is the pattern to follow; extend it instead of writing a second client.
