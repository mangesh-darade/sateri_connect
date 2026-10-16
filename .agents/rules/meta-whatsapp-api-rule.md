# Meta / WhatsApp Cloud API Rule

## Available Meta Libraries (Use these — never create new HTTP clients)
| Class | Purpose |
|---|---|
| `MetaCloudAPI` | WhatsApp Cloud API — send messages, templates, media (graph.facebook.com) |
| `CheerioDirectAPI` | Cheerio WhatsApp API — same public interface as MetaCloudAPI |
| `MetaEmbeddedSignup` | Meta Embedded Signup onboarding (WABA + phone setup) |
| `MetaPageMessagingAPI` | Facebook Messenger / Instagram messaging |
| `MetaApiErrorMapper` | Convert raw Meta Graph API errors → user-friendly messages |
| `MetaGraphLogger` | Graph API call logging |
| `MetaDataDeletionService` | GDPR Meta data deletion callback handler |
| `WebhookValidator` | HMAC SHA-256 signature + challenge verification (Meta + Cheerio + master) |
| `WhatsAppTemplateSendGuard` | Template WABA ownership + APPROVED status check before send |
| `WhatsAppTemplateVariables` | Template variable extraction and substitution |
| `WhatsAppTemplatePayload` | Build Meta-shaped template payload for API |
| `WhatsAppDeliveryStatus` | Parse Meta webhook delivery status updates |
| `WhatsAppSendWindow` | 24-hour session window validation |
| `WhatsAppConsentService` | Customer WhatsApp opt-in/out management |
| `WhatsAppIdentityService` | Phone number identity verification |
| `WhatsAppTemplateSendGuard` | Template APPROVED status guard + WABA ownership |

---

## Rules

### Credentials & Config
- **Always** load Meta credentials via `$this->settings->getMetaConfig()` — never hardcode or read directly from `$_ENV`.
- `access_token`, `phone_number_id`, `waba_id`, `app_secret`, `app_id`, `api_version` → all come from `SettingsService::getMetaConfig()`.
- Active provider (`meta` or `cheerio`) → `$this->settings->getWhatsAppProvider()`. Never assume one provider.
- API version default: `v25.0`. Always read from settings/config, never hardcode a version string.

### Sending Messages / Templates
- Always use `WhatsAppTemplateSendGuard::resolveApprovedTemplate()` before sending a template. Never send to Meta without checking `status = APPROVED`.
- Template credentials/WABA always from tenant settings — never from client input.
- Use `WhatsAppTemplatePayload` to build the API payload — never hand-build template JSON in a controller.
- Use `WhatsAppTemplateVariables` for variable substitution — never string-replace in controller or model.
- Check `WhatsAppSendWindow` before sending a free-form message (24-hour session window).

### Error Handling
- All Meta Graph API errors → parse with `MetaApiErrorMapper::parseDecoded($decoded)` then humanize with `MetaApiErrorMapper::humanize($message, $httpStatus, $code)`.
- Never return raw Meta error JSON (contains internal API codes) to the user.
- 401/403 from Meta → credential issue (token expired/invalid). Log + return generic "WhatsApp access token invalid. Reconnect in Settings."
- 429 from Meta → rate limit. Return 429 with user message, do not retry immediately.
- Meta API retry: built into `MetaCloudAPI` (`config->maxRetries`). Never add extra retry loops on top.

### Webhooks
- Always validate signature using `WebhookValidator::validateSignature($rawBody, $signatureHeader)` before processing any payload.
- Read raw body **before** CI4 parses input: `$rawBody = file_get_contents('php://input')`.
- Webhook controller must return HTTP 200 within 5 seconds — offload heavy processing to queue.
- Signature mismatch → log warning + return 401. Never process unverified payload.
- Challenge verification: `WebhookValidator::verifyChallenge($mode, $token, $challenge)`.
- Both Cheerio and Meta use same `hub.*` webhook shape — `WebhookValidator` handles both.

### Templates
- Sync templates from Meta → use `TemplateSyncService` — never raw API call in controller.
- Templates must have `status = APPROVED` and `waba_id` matching tenant WABA before send.
- Never submit or delete templates directly from controller — use `TemplateSyncService` / `StandardTemplateOnboarding`.

### Embedded Signup / WABA Onboarding
- Use `MetaEmbeddedSignup` for the FB.login → code exchange → register phone → subscribe webhooks flow.
- Credentials resolve order: tenant settings → platform_settings → env. Never bypass this.
- `managed: true` means credentials come from platform master (not tenant) — don't overwrite with tenant settings.

### Logging
- Never log `access_token`, `app_secret`, or raw Meta tokens. Log `phone_number_id`, `waba_id`, `template_name`.
- Use `MetaGraphLogger` for Graph API call auditing — don't add manual `log_message` for routine API calls already covered by it.
