# Third-Party Integration Rules

## Before integrating
- Confirm the provider's API from its official documentation or the existing client in the repo. Never invent endpoints, parameters or response fields.
- Prefer extending the existing integration adapter over adding a parallel client.
- Get approval before adding a new vendor, SDK or data flow, because it changes the privacy and risk surface.

## Outbound calls
- Wrap every call in try/catch for all throwables; set connect and total timeouts; cap response size.
- TLS verification on. No `verify=false` fallbacks that can run in production.
- Do not stack your own retry loops on top of a client that already retries; use idempotency keys for non-idempotent operations.
- Use circuit-breaker/backoff behavior for repeated failures; respect provider rate limits.
- Credentials come from the settings/secret layer for the correct tenant/account, never from client input or the wrong scope.

## Errors
Map provider errors to internal safe messages and codes. Log provider status, request ID and masked parameters server-side. Never show raw provider responses to end users. Return a generic 502-style message on upstream failure.

## Data minimization
Send only the fields the provider needs. Mask or omit personal data in logs and error reports. Honor consent/opt-out state before sending messages or marketing content.

## Inbound webhooks / callbacks
Follow `API_SECURITY_RULES.md`: signature verification on the raw body, replay protection, idempotency, tenant routing only from verified fields, fast acknowledgement, queue heavy work.

## Payments
- Never trust client-reported amounts or status. Compute amounts server-side; confirm payment state via signed webhook and/or provider API.
- Credit balances only once, idempotently (unique payment/event ID), inside a transaction with a ledger entry.
- Never store card data. Log payment IDs, not instrument details.
- Reconcile periodically and handle refunds/chargebacks explicitly.

## Testing
Use provider sandboxes and mocks. Never test against production credentials or real customer data.

## Offboarding
Document how to rotate and revoke each integration's credentials.
