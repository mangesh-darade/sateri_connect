# Cheerio Direct API Configuration

This platform sends and receives WhatsApp messages through **Cheerio Direct APIs** (`https://newprod.api.cheerio.in/direct-apis/`). Meta Graph API (`graph.facebook.com`) is **not** used as the messaging transport.

Official collection: [Cheerio Direct API Documentation](https://documenter.getpostman.com/view/13841235/2s9Y5Zvh9y#intro)

API key: [app.cheerio.in → Settings → API Key](https://app.cheerio.in/settings/apikey)

---

## 1. Prerequisites

1. Cheerio account with a **live WhatsApp Business Account** (WABA).
2. **API key** generated in Cheerio → Manage Profile / Settings → API Key.
3. Templates you will send must already exist / be approved in the **Cheerio Dashboard** (or created via API).
4. Cheerio premium plan (as required by Cheerio for Direct APIs).

Contact for WABA onboarding: ritul@cheerio.in

---

## 2. Base URL & authentication

| Item | Value |
|------|--------|
| **BASE_API** | `https://newprod.api.cheerio.in/direct-apis/` |
| **Auth header** | `x-api-key: YOUR_API_KEY` |
| **Content-Type** | `application/json` (except media upload = `multipart/form-data`) |

Every WhatsApp Direct API call from this app sends `x-api-key` from **Settings → Cheerio API**.

---

## 3. WhatsApp endpoints used by this app

| Action | Method | Path |
|--------|--------|------|
| Upload media | `POST` | `/v1/whatsapp/media-id` (form field `file`) |
| Resolve media | `POST` | `/v1/whatsapp/media` body `{ "mediaId": "..." }` |
| Send template | `POST` | `/v1/whatsapp/template/send` |
| Send session / interactive message | `POST` | `/v1/whatsapp/direct/send` |
| Create template | `POST` | `/v1/whatsapp/create-template` |
| List templates | `GET` | `/v1/getAllTemplates?limit=500` |
| Get template by name/id | `GET` | `/v1/whatsapp/template/:name_or_id` |
| Message status (poll) | `GET` | `/v1/whatsapp-status/:wamid` |

Optional / not required for core inbox:

- Bulk send: `POST /v1/whatsapp/multiple`
- Bulk create templates: `POST /v1/whatsapp/create-bulk-template`
- Announcements, contacts, SMS, email, workflows (Cheerio dashboard features)

---

## 4. Send template payload

```http
POST /v1/whatsapp/template/send
x-api-key: YOUR_KEY
Content-Type: application/json
```

```json
{
  "to": "919779003936",
  "data": {
    "name": "incoming_lead_website",
    "language": { "code": "en" },
    "components": [
      {
        "type": "body",
        "parameters": [
          { "type": "text", "text": "Priam Jain" }
        ]
      }
    ]
  }
}
```

Template body shape follows WhatsApp’s template object (header / body / button components) as Cheerio documents it.  
See Cheerio collection: [https://documenter.getpostman.com/view/13841235/2s9Y5Zvh9y](https://documenter.getpostman.com/view/13841235/2s9Y5Zvh9y)

**Typical 200 response:**

```json
{
  "messaging_product": "whatsapp",
  "contacts": [{ "input": "918198776153", "wa_id": "918198776153" }],
  "messages": [{ "id": "wamid.HBg...." }]
}
```

---

## 5. Send direct (session) message

Inside the 24-hour customer care window (user messaged you first, or after a template opened the conversation):

```http
POST /v1/whatsapp/direct/send
```

```json
{
  "recipient_type": "individual",
  "to": "919779003936",
  "type": "text",
  "text": { "body": "Hello" }
}
```

Interactive buttons / lists use the same WhatsApp-style `interactive` object.  
You **cannot** cold-open with a free-form direct message — use a template first.

---

## 6. Media

1. `POST /v1/whatsapp/media-id` with multipart field `file` → receive a media ID.
2. Use that ID (or a public `link`) in template header components or direct image/document/video payloads.
3. `POST /v1/whatsapp/media` with `{ "mediaId": "..." }` to resolve inbound media for download.

---

## 7. Templates sync & create

- **Sync in this app:** Templates → Sync → calls `GET /v1/getAllTemplates`.
- **Contacts → Sync from Cheerio** → `GET /v1/contact/getAll`
- **Automations → Sync from Cheerio** → `GET /v1/workflows` (imported Off)
- CLI: `php spark cheerio:sync`
- **Create:** Templates → Create → `POST /v1/whatsapp/create-template` (WhatsApp component format via Cheerio).
- In Cheerio Dashboard you can also create/sync templates under Template Library → WhatsApp.

Delete-by-API is not exposed in Cheerio Direct APIs; remove templates from the Cheerio dashboard when needed.

**Not auto-synced:** Chat, Queue, Campaigns, Keywords (create here / webhooks for chat).

---

## 8. Webhooks (inbound + status)

Cheerio documents webhook bodies using the **standard WhatsApp Cloud API webhook shape** (`hub.*`, `entry`, `messages`):

Configure your public callback in Cheerio / WABA webhook settings:

| Setting | Where |
|---------|--------|
| Callback URL | `https://YOUR-DOMAIN/.../webhooks` (Settings → Webhooks) |
| Verify token | Same value in Settings and the provider console |
| Webhook secret | Used for `X-Hub-Signature-256` when enabled |

Local development: use **ngrok** (or similar) so Cheerio can reach your machine.  
Full steps: [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md)

Delivery statuses also arrive via webhooks; optionally poll `GET /v1/whatsapp-status/:wamid` (rate limit ~15 req/sec).

---

## 9. Platform settings checklist

In **Settings → Cheerio API** (or installer step):

- [ ] Cheerio API key  
- [ ] Webhook verify token  
- [ ] Webhook secret (signature validation)  
- [ ] Templates synced (`APPROVED`)  
- [ ] Public HTTPS webhook URL verified  

---

## 10. Smoke test

1. Settings → **Test Cheerio Connection** (lists templates with your API key).  
2. Templates → Sync — statuses show `APPROVED`.  
3. Send a template from Chat or Campaigns to a real number.  
4. Reply from WhatsApp — inbound appears in Live Chat.  
5. Confirm delivery / read statuses update (`webhook_logs`).

---

## Related docs

- [CHEERIO_FLOW.md](CHEERIO_FLOW.md) — end-to-end product flows  
- [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md)  
- [SETTINGS.md](SETTINGS.md)  
- [CRON_SETUP.md](CRON_SETUP.md)  
