# Messaging Consent and Compliance Rules

Applies to WhatsApp, email, SMS, Messenger, Instagram and any outbound channel.

## Consent
- Send marketing or template messages only to contacts with recorded opt-in for that channel. Record source, time and wording of consent.
- Opt-out (STOP, unsubscribe, block) always wins. No code path, import, automation, API call or admin action may override or re-enable it silently.
- Re-check consent at send time, not only when a campaign is created.

## Channel policy
- Follow the provider's current policy for templates, session windows, quality rating and messaging limits. Use the repo's existing guards (template-approval check, send-window check, consent service) before sending.
- Free-form messages only inside the allowed reply window; otherwise an approved template.
- Respect quiet hours, frequency caps, and per-tenant sending limits where configured.

## Data subject rights
- Support export and erasure of a contact across messages, tags, imports and logs. Erasure must also stop future sends.
- Imports require a consent flag or confirmation from the uploader; log who imported what.

## Money and limits
- Charge, reserve or check balance before sending; refund only through the ledger, once, idempotently.
- Block sends when the wallet, plan or spend limit is exhausted.

## Tracking
Open/click tracking and bounce/complaint handling must honor unsubscribes and suppress hard bounces and complaints automatically.

## Tests
Opted-out contact in a campaign, STOP received mid-campaign, expired window, unapproved template, zero balance.
