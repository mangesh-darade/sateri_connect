# Cheerio WhatsApp — End-to-End Flow

How to use this platform with **Cheerio Direct APIs**.

Local base (WAMP): `http://localhost/whstapp/public/`

---

## Architecture

```
You (panel) ──► Queue / Chat / Keywords ──► WhatsAppCloudAPI ──► newprod.api.cheerio.in/direct-apis
Cheerio / WABA webhooks ──► POST /webhooks ──► Webhooks controller ──► Chat + KeywordBot + Automations
Workers: php spark queue:process | campaigns:process | automations:process
```

Auth on every outbound call: header `x-api-key` (from Settings).

---

## One-time setup

1. **WAMP green** (Apache + MySQL), app installed, login works.  
2. Generate API key: [https://app.cheerio.in/settings/apikey](https://app.cheerio.in/settings/apikey)  
3. **Settings → Cheerio API** — paste API key, verify token, webhook secret.  
4. **Settings → Webhooks** — copy callback URL (use **ngrok** locally).  
5. Point Cheerio / WABA webhook at that URL; verify subscribe.  
6. **Templates → Sync** (approved templates only).  
7. Start workers (every minute in production):

```powershell
cd c:\wamp64\www\whstapp
php spark queue:process
php spark campaigns:process
php spark automations:process
```

---

## Daily flows

### A) Broadcast campaign

1. Contacts → phones as digits with country code (`9198…`).  
2. Templates → Sync → pick **APPROVED** template.  
3. Campaigns → Create → map variables.  
4. Send now or Schedule → queue drains via `queue:process`.  
5. Status webhooks update delivered/read.

### B) Live chat

1. Customer messages you → webhook → **Chat**.  
2. Inside **24h window** → free text / media via `/v1/whatsapp/direct/send`.  
3. Outside 24h → **template only** via `/v1/whatsapp/template/send`.  

### C) Keyword bot

1. Keywords → trigger + replies / buttons.  
2. Inbound webhook → KeywordBot → direct or template send.

### D) Automations

1. Automations → Workflow Builder.  
2. Triggers: inbound WhatsApp, contact created, keyword, birthday cron, tags.  
3. Queued **text** only inside 24h; use **template** for cold outreach.

---

## API mapping (this app → Cheerio)

| App method | Cheerio endpoint |
|------------|------------------|
| `sendTemplate` | `POST /v1/whatsapp/template/send` |
| `sendText` / media / interactive | `POST /v1/whatsapp/direct/send` |
| `uploadMedia` | `POST /v1/whatsapp/media-id` |
| `downloadMedia` / `getMediaUrl` | `POST /v1/whatsapp/media` |
| `getTemplates` | `GET /v1/getAllTemplates` |
| `createTemplate` | `POST /v1/whatsapp/create-template` |
| `testConnection` | `GET /v1/getAllTemplates?limit=1` |
| `getMessageStatus` | `GET /v1/whatsapp-status/:wamid` |

`markAsRead` is a no-op (not exposed by Cheerio Direct APIs).  
`deleteTemplate` is not available via Direct API — use Cheerio Dashboard.

---

## Common errors

| Symptom | Meaning | Fix |
|---------|---------|-----|
| 401 / unauthorized | Bad or missing `x-api-key` | Regenerate key; save in Settings |
| Outside 24h / policy | Session closed | Send approved template |
| Template param mismatch | Wrong component count/order | Fix variable map |
| Invalid phone | Bad `to` | Digits with country code, no `+` |
| Webhook verify fail | Token mismatch | Same verify token in Settings + console |

---

## Related docs

- [CHEERIO_CONFIGURATION.md](CHEERIO_CONFIGURATION.md)  
- [SETTINGS.md](SETTINGS.md)  
- [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md)  
- [CRON_SETUP.md](CRON_SETUP.md)  
