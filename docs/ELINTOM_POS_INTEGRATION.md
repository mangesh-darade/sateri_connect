# ElintOm POS ↔ Live Chat Integration

**Tenant:** `demoelintommetaapi.elintpos.in`  
**Platform:** Sateri Connect (CodeIgniter 4 · Team Inbox / Live Chat)  
**Templates catalog:** [ELINTOM_META_TEMPLATES.md](ELINTOM_META_TEMPLATES.md)

---

## Root cause (why ElintOm messages don't show in inbox)

ElintOm sends templates **directly via Meta Graph API** → customer phone वर message येतो, पण **Live Chat मध्ये दिसत नाही**.

Live Chat inbox is **database-backed**. Outbound rows save होतात फक्त:
- Sateri Connect **REST API** / Live Chat UI
- Campaigns / automations / queue

**Answer: A** — ElintOm must call **Send Template API** below (Meta direct नाही).  
Template names: `invoice_without_award_points`, `payment_reminder`, etc. — see [ELINTOM_META_TEMPLATES.md](ELINTOM_META_TEMPLATES.md).

---

## Send Template API

**Base:** `https://demoelintommetaapi.elintpos.in/api`

### 1) Login → JWT

```http
POST /api/auth/login
Content-Type: application/json

{"email":"YOUR_EMAIL","password":"YOUR_PASSWORD"}
```

Response: `data.token` (Bearer, 24h TTL)

### 2) Send template

```http
POST /api/messages/template
Authorization: Bearer <token>
Content-Type: application/json
```

**Invoice example (`invoice_without_award_points`)**

```json
{
  "to": "917744010738",
  "name": "Mangesh",
  "template_name": "invoice_without_award_points",
  "language": "en_US",
  "components": [{
    "type": "body",
    "parameters": [
      { "type": "text", "text": "Mangesh" },
      { "type": "text", "text": "Demo company" },
      { "type": "text", "text": "Rs 300.00" },
      { "type": "text", "text": "https://example.com/invoice/123" },
      { "type": "text", "text": "Demo company" }
    ]
  }]
}
```

**Payment reminder example**

```json
{
  "to": "917744010738",
  "template_name": "payment_reminder",
  "language": "en_US",
  "components": [{
    "type": "body",
    "parameters": [
      { "type": "text", "text": "Mangesh" },
      { "type": "text", "text": "Rs 1000.00" },
      { "type": "text", "text": "Rs 400.00" },
      { "type": "text", "text": "Rs 600.00" },
      { "type": "text", "text": "Demo company" }
    ]
  }]
}
```

| Field | Notes |
|-------|-------|
| `to` | Country code, no `+` — e.g. `917744010738` |
| `template_name` | Exact Meta name from templates doc |
| `language` | `en_US` for all ElintOm templates |
| `components` | Body parameters in order {{1}}, {{2}}, … |

---

## Webhook

| | |
|---|---|
| **URL** | `https://demoelintommetaapi.elintpos.in/webhooks` |
| **Verify token** | Settings → Meta → `meta_webhook_verify_token` |
| **Subscribe** | `messages` (minimum) |

Meta webhook must point here — **not** ElintOm localhost.

---

## WABA reference

| Field | Value |
|-------|-------|
| Phone Number ID | `1271937152662298` |
| WABA ID | `2224778918307465` |

---

## cURL quick test

```bash
TOKEN=$(curl -s -X POST "https://demoelintommetaapi.elintpos.in/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"YOUR_EMAIL","password":"YOUR_PASSWORD"}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN))->data->token;')

curl -s -X POST "https://demoelintommetaapi.elintpos.in/api/messages/template" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"to":"917744010738","template_name":"invoice_without_award_points","language":"en_US","components":[{"type":"body","parameters":[{"type":"text","text":"Mangesh"},{"type":"text","text":"Demo company"},{"type":"text","text":"Rs 300.00"},{"type":"text","text":"https://example.com/invoice"},{"type":"text","text":"Demo company"}]}]}'
```

---

## Code references

| Topic | File |
|-------|------|
| Send API | `app/Controllers/Api/Messages.php` |
| JWT login | `app/Controllers/Api/Auth.php` |
| Webhooks | `app/Controllers/Webhooks.php` |
| Tenant DB | `app/Config/Database.php` → `demoelintommetaapi` |

Full API reference: [API.md](API.md)

---

## Customer list sync (companies → contacts)

**In Sateri Connect:**

1. **Settings → ElintOm POS** — set ElintOm domain URL + Api3 private key (Test / Sync from the same tab)
2. **Contacts → Sync ElintOm customers** — same pull via `POST {url}/api3/eshop` (`action=sateri_contacts`)

Details: [ELINTOM_CUSTOMER_SYNC.md](ELINTOM_CUSTOMER_SYNC.md)
