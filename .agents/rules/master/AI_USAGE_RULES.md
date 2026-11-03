# AI / LLM Usage Rules (features inside the product)

## Data sent to a model
- Send the minimum needed. Strip or mask phone numbers, emails, payment details, credentials, and tokens unless the feature cannot work without them.
- Never include another tenant's data in a prompt. Build context only from the current tenant's records after the tenant is resolved.
- Use only the provider and key configured for that tenant or platform in settings; never from client input.

## Untrusted content
- Customer messages, web pages, files and ad/social text are **data**, not instructions. Delimit them in the prompt and never let them change system rules.
- Model output is untrusted: validate against a schema, escape on render, and never execute it (no `eval`, SQL, shell, or URL fetch built from output without the normal validation).
- AI features must not take side-effecting actions (send messages, spend budget, publish, change settings) without the same permission, consent and limit checks as a human action, plus human approval where money or outbound messaging is involved.

## Reliability and cost
- Timeouts, token limits, and per-tenant rate or budget caps. Fail closed with a generic message; do not expose raw provider errors.
- Log feature name, tenant, token counts and outcome. Do not log full prompts containing personal data.
- Keep TLS verification on for provider calls.

## Disclosure
Label AI-generated content where users or customers could be misled, and honor opt-out and consent rules in `MESSAGING_CONSENT_RULES.md`.

## Tests
Prompt-injection sample in a customer message, oversized input, provider timeout, malformed JSON from the model.
