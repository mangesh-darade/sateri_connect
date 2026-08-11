# Sateri Connect — REST API Guide

Copy-paste ready reference for connecting ERP / website / other systems to Sateri Connect.

WhatsApp is sent **through Sateri** (Meta or Cheerio provider already configured in Settings). External systems must **not** call Meta Graph directly with your Cloud token — use this API + JWT.

---

## One subdomain per customer (tenant)

Each client gets their **own subdomain**. Data, Settings, WhatsApp credentials, and JWT users are **isolated per subdomain**.

| Piece | Pattern | Example |
|-------|---------|---------|
| App / panel | `https://{subdomain}.elintpos.in` | `https://demoelintommetaapi.elintpos.in` |
| REST API | `https://{subdomain}.elintpos.in/api` | `https://demoelintommetaapi.elintpos.in/api` |
| Meta webhook | `https://{subdomain}.elintpos.in/webhooks` | `https://demoelintommetaapi.elintpos.in/webhooks` |

Other tenants look like:

```text
https://androidtestings.elintpos.in/api
https://yourclient.elintpos.in/api
https://acme.elintpos.in/api
```

**Always use that client’s subdomain** — never call another tenant’s host. Login, contacts, and messages belong only to the host you hit.

In examples below, `{BASE}` means:

```text
https://{YOUR_SUBDOMAIN}.elintpos.in/api
```

Swap `{YOUR_SUBDOMAIN}` (or set `BASE`) to the real client subdomain before copy-paste.

---

## Base URLs

| Environment | Base URL |
|-------------|----------|
| **Production (per tenant)** | `https://{subdomain}.elintpos.in/api` |
| Example tenant | `https://demoelintommetaapi.elintpos.in/api` |
| Local WAMP | `http://localhost/sateri_connect/public/api` |

**Inbound WhatsApp webhook** (Meta → that tenant only):

```text
https://{subdomain}.elintpos.in/webhooks
```

---

## Quick start (copy-paste)

Set **that client’s** base + login once:

```bash
# Change subdomain per customer
BASE="https://YOUR_SUBDOMAIN.elintpos.in/api"
# Example: BASE="https://demoelintommetaapi.elintpos.in/api"

EMAIL="admin@example.com"
PASS="your-password"
```

### 1) Login → JWT

```bash
curl -s -X POST "$BASE/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}"
```

Save the token:

```bash
TOKEN=$(curl -s -X POST "$BASE/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" \
  | php -r 'echo json_decode(stream_get_contents(STDIN))->data->token ?? "";')

echo "$TOKEN"
```

### 2) Who am I

```bash
curl -s "$BASE/auth/me" \
  -H "Authorization: Bearer $TOKEN"
```

### 3) Send WhatsApp **template** (works outside 24h window)

```bash
curl -s -X POST "$BASE/messages/template" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "919876543210",
    "name": "Customer",
    "template_name": "hello_world",
    "language": "en_US",
    "components": []
  }'
```

### 4) Send WhatsApp **text** (only inside 24h customer-care window)

```bash
curl -s -X POST "$BASE/messages/text" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "919876543210",
    "text": "Hello from ERP"
  }'
```

Messages sent via this API are saved in the DB and **appear in Live Chat**.

---

## Rules

| Rule | Detail |
|------|--------|
| Auth | `Authorization: Bearer <JWT>` on all routes except login |
| CSRF | **Not** required for `/api/*` |
| Body | JSON (`Content-Type: application/json`) |
| Phone | Digits with country code, no `+` / spaces — e.g. `919876543210` |
| 24h window | Free text only if customer replied in last 24h; otherwise use **template** |
| Token TTL | 24 hours (`86400` seconds) — login again when expired |
| Rate limit | Login ~10/min; other API routes ~60/min per IP+path |
| Permissions | API user must be **active** and have the permission listed below |

`.env` must have a strong `JWT_SECRET`.

---

## Response envelope

Every response:

```json
{
  "success": true,
  "message": "OK",
  "data": {},
  "errors": null
}
```

Failure:

```json
{
  "success": false,
  "message": "Validation failed.",
  "data": null,
  "errors": {
    "mobile": "Mobile is required."
  }
}
```

| HTTP | Meaning |
|------|---------|
| 200 / 201 | OK / created |
| 401 | Missing / invalid / expired JWT |
| 403 | Inactive user or no permission |
| 404 | Not found |
| 409 | Conflict (e.g. duplicate contact) |
| 422 | Validation / outside 24h window |
| 429 | Rate limited (`Retry-After`) |
| 500 | Provider / server error |

---

## Endpoint map

| Method | Path | Permission | Purpose |
|--------|------|------------|---------|
| `POST` | `/api/auth/login` | — | Get JWT |
| `GET` | `/api/auth/me` | JWT | Current user |
| `GET` | `/api/contacts` | `contacts.view` | List contacts |
| `POST` | `/api/contacts` | `contacts.create` | Create contact |
| `GET` | `/api/contacts/{id}` | `contacts.view` | Get contact |
| `PUT` | `/api/contacts/{id}` | `contacts.edit` | Update contact |
| `DELETE` | `/api/contacts/{id}` | `contacts.delete` | Delete contact |
| `GET` | `/api/customer-groups` | `contacts.view` | List groups |
| `POST` | `/api/customer-groups` | `contacts.create` | Create group |
| `GET` | `/api/customer-groups/{id}` | `contacts.view` | Group + members |
| `PUT` | `/api/customer-groups/{id}` | `contacts.edit` | Update group |
| `DELETE` | `/api/customer-groups/{id}` | `contacts.delete` | Delete group |
| `POST` | `/api/customer-groups/{id}/contacts` | `contacts.create` | Add member |
| `DELETE` | `/api/customer-groups/{id}/contacts/{contactId}` | `contacts.edit` | Remove member |
| `GET` | `/api/messages` | `chat.view` | List messages |
| `POST` | `/api/messages/send` | `chat.send` | Send text or template |
| `POST` | `/api/messages/text` | `chat.send` | Send text |
| `POST` | `/api/messages/template` | `chat.send` | Send template |
| `GET` | `/api/templates` | `templates.view` | List local templates |
| `POST` | `/api/templates/sync` | `templates.sync` | Sync from provider |
| `GET` | `/api/campaigns` | `campaigns.view` | List campaigns |
| `POST` | `/api/campaigns` | `campaigns.create` | Create campaign |
| `GET` | `/api/campaigns/{id}` | `campaigns.view` | Campaign detail |
| `POST` | `/api/campaigns/{id}/pause` | `campaigns.edit` | Pause |
| `POST` | `/api/campaigns/{id}/resume` | `campaigns.edit` | Resume |
| `GET` | `/api/reports/stats` | `reports.view` | Message stats |

---

## Authentication

### `POST /api/auth/login`

Full URL (per tenant):

```text
https://{subdomain}.elintpos.in/api/auth/login
```

```bash
curl -s -X POST "$BASE/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@example.com",
    "password": "your-password"
  }'
```

Example success:

```json
{
  "success": true,
  "message": "Authenticated.",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "token_type": "Bearer",
    "expires_in": 86400,
    "user": {
      "id": 1,
      "name": "Admin",
      "email": "admin@example.com",
      "role_id": 1,
      "role_slug": "super-admin",
      "permissions": ["*"]
    }
  },
  "errors": null
}
```

### `GET /api/auth/me`

```bash
curl -s "$BASE/auth/me" \
  -H "Authorization: Bearer YOUR_JWT_HERE"
```

---

## Messages (remote WhatsApp send)

### `POST /api/messages/template`

Full URL (per tenant):

```text
https://{subdomain}.elintpos.in/api/messages/template
```

By phone (creates contact if missing):

```bash
curl -s -X POST "$BASE/messages/template" \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "919876543210",
    "name": "Mangesh",
    "template_name": "hello_world",
    "language": "en_US",
    "components": []
  }'
```

By existing `contact_id`:

```bash
curl -s -X POST "$BASE/messages/template" \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "contact_id": 12,
    "template_name": "hello_world",
    "language": "en_US",
    "components": []
  }'
```

Template with body variables (Meta-style components):

```bash
curl -s -X POST "$BASE/messages/template" \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "919876543210",
    "template_name": "order_update",
    "language": "en",
    "components": [
      {
        "type": "body",
        "parameters": [
          { "type": "text", "text": "ORD-1001" },
          { "type": "text", "text": "Shipped" }
        ]
      }
    ]
  }'
```

Accepted fields: `to` / `mobile`, `contact_id`, `name` (new contact), `template_name` / `name`, `language`, `components`.

### `POST /api/messages/text`

```text
https://{subdomain}.elintpos.in/api/messages/text
```

```bash
curl -s -X POST "$BASE/messages/text" \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "919876543210",
    "text": "Your order is ready for pickup."
  }'
```

Outside 24h → `422` with message: `Outside 24-hour window. Use a template message.`

### `POST /api/messages/send`

One endpoint for both types:

```bash
curl -s -X POST "$BASE/messages/send" \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "919876543210",
    "type": "template",
    "template_name": "hello_world",
    "language": "en_US",
    "components": []
  }'
```

```bash
curl -s -X POST "$BASE/messages/send" \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "919876543210",
    "type": "text",
    "text": "Hello"
  }'
```

### `GET /api/messages`

```bash
curl -s "$BASE/messages?contact_id=12&page=1&limit=50" \
  -H "Authorization: Bearer YOUR_JWT_HERE"
```

Query: `contact_id`, `page`, `limit` (max 100).

---

## Contacts

### List

```bash
curl -s "$BASE/contacts?page=1&limit=25&q=alice&status=active" \
  -H "Authorization: Bearer YOUR_JWT_HERE"
```

### Create

```bash
curl -s -X POST "$BASE/contacts" \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Bob",
    "mobile": "919876543210",
    "email": "bob@example.com",
    "country": "IN",
    "status": "active",
    "notes": "From ERP"
  }'
```

### Get / Update / Delete

```bash
curl -s "$BASE/contacts/12" \
  -H "Authorization: Bearer YOUR_JWT_HERE"

curl -s -X PUT "$BASE/contacts/12" \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{"name":"Bob Updated","status":"active"}'

curl -s -X DELETE "$BASE/contacts/12" \
  -H "Authorization: Bearer YOUR_JWT_HERE"
```

---

## Customer groups

```bash
# List
curl -s "$BASE/customer-groups?q=expo" \
  -H "Authorization: Bearer YOUR_JWT_HERE"

# Create
curl -s -X POST "$BASE/customer-groups" \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{"name":"ERP Leads","color":"#25D366"}'

# Show
curl -s "$BASE/customer-groups/3" \
  -H "Authorization: Bearer YOUR_JWT_HERE"

# Add member by mobile
curl -s -X POST "$BASE/customer-groups/3/contacts" \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{"name":"Mangesh","mobile":"919876543210"}'

# Add by contact_id
curl -s -X POST "$BASE/customer-groups/3/contacts" \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{"contact_id":42}'

# Remove member
curl -s -X DELETE "$BASE/customer-groups/3/contacts/42" \
  -H "Authorization: Bearer YOUR_JWT_HERE"

# Update / delete group
curl -s -X PUT "$BASE/customer-groups/3" \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{"name":"ERP Leads Updated","color":"#667085"}'

curl -s -X DELETE "$BASE/customer-groups/3" \
  -H "Authorization: Bearer YOUR_JWT_HERE"
```

---

## Templates

```bash
# List local copies
curl -s "$BASE/templates" \
  -H "Authorization: Bearer YOUR_JWT_HERE"

# Only approved
curl -s "$BASE/templates?status=APPROVED" \
  -H "Authorization: Bearer YOUR_JWT_HERE"

# Sync from Meta / Cheerio
curl -s -X POST "$BASE/templates/sync" \
  -H "Authorization: Bearer YOUR_JWT_HERE"
```

Use `name` + `language` from this list in `messages/template`.

---

## Campaigns

```bash
curl -s "$BASE/campaigns" \
  -H "Authorization: Bearer YOUR_JWT_HERE"

curl -s "$BASE/campaigns?status=running" \
  -H "Authorization: Bearer YOUR_JWT_HERE"

curl -s -X POST "$BASE/campaigns" \
  -H "Authorization: Bearer YOUR_JWT_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "March Promo",
    "template_id": 5,
    "message_type": "template",
    "variables": { "1": "Alice" }
  }'

curl -s "$BASE/campaigns/7" \
  -H "Authorization: Bearer YOUR_JWT_HERE"

curl -s -X POST "$BASE/campaigns/7/pause" \
  -H "Authorization: Bearer YOUR_JWT_HERE"

curl -s -X POST "$BASE/campaigns/7/resume" \
  -H "Authorization: Bearer YOUR_JWT_HERE"
```

---

## Reports

```bash
curl -s "$BASE/reports/stats?from=2026-08-01&to=2026-08-31" \
  -H "Authorization: Bearer YOUR_JWT_HERE"
```

---

## Postman / other system checklist

1. Environment variable `BASE` = `https://{THAT_CLIENT_SUBDOMAIN}.elintpos.in/api` (different per customer)
2. Collection login → save `token` → set header `Authorization: Bearer {{token}}`
3. Settings in Sateri **on that subdomain**: WhatsApp provider connected + templates synced
4. API user on **that same tenant** has `chat.send`
5. Prefer **template** for first outreach; **text** only after customer reply (24h)
6. Do **not** share Meta `EAAG…` token with the other system — only Sateri JWT
7. Never reuse one tenant’s JWT against another subdomain

---

## What Live Chat shows

| Action | Live Chat |
|--------|-----------|
| Send via `/api/messages/*` | Yes (outbound saved) |
| Customer replies (Meta → `/webhooks`) | Yes, if webhook is healthy |
| Send via Meta Graph / Business Suite outside Sateri | No |

---

## PHP example (other system)

```php
<?php
// Per customer — change subdomain
$base = 'https://YOUR_SUBDOMAIN.elintpos.in/api';
// e.g. https://demoelintommetaapi.elintpos.in/api

$login = json_decode(file_get_contents($base . '/auth/login', false, stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => json_encode([
            'email'    => 'admin@example.com',
            'password' => 'your-password',
        ]),
    ],
])), true);

$token = $login['data']['token'] ?? '';

$payload = json_encode([
    'to'            => '919876543210',
    'template_name' => 'hello_world',
    'language'      => 'en_US',
    'components'    => [],
]);

$ch = curl_init($base . '/messages/template');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
]);
echo curl_exec($ch);
```

---

## JavaScript / Node example

```javascript
// Per customer — change subdomain
const BASE = 'https://YOUR_SUBDOMAIN.elintpos.in/api';
// e.g. https://demoelintommetaapi.elintpos.in/api

async function sendTemplate() {
  const login = await fetch(`${BASE}/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: 'admin@example.com',
      password: 'your-password',
    }),
  }).then((r) => r.json());

  const token = login.data.token;

  const res = await fetch(`${BASE}/messages/template`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      to: '919876543210',
      template_name: 'hello_world',
      language: 'en_US',
      components: [],
    }),
  }).then((r) => r.json());

  console.log(res);
}

sendTemplate();
```

---

## Not available yet

- Long-lived API keys (only JWT login today)
- Outbound push webhooks to your ERP when a customer replies (poll `GET /api/messages` for now)

Need either of those built? Ask the Sateri team.
