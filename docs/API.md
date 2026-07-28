# REST API Reference

Base URL (local XAMPP example):

```
http://localhost/APIWA/public/api
```

All responses are JSON:

```json
{
  "success": true,
  "message": "Optional human-readable message",
  "data": {},
  "errors": null
}
```

On validation failure, `success` is `false` and `errors` may contain field messages. HTTP status codes: `200`/`201` success, `400`/`422` validation, `401` unauthorized, `404` not found, `429` rate limited.

Rate limiting is applied to all `/api/*` routes (default ~60 requests/minute per IP+path).

CSRF is **not** required for API routes. Use JWT Bearer authentication instead.

---

## Authentication

### POST `/api/auth/login`

Obtain a JWT. **No** Bearer token required.

**Request**

```http
POST /api/auth/login HTTP/1.1
Content-Type: application/json

{
  "email": "admin@example.com",
  "password": "your-password"
}
```

**Response `200`**

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "token_type": "Bearer",
    "expires_in": 86400,
    "user": {
      "id": 1,
      "name": "Admin",
      "email": "admin@example.com",
      "role_id": 1
    }
  }
}
```

**Response `401`**

```json
{
  "success": false,
  "message": "Invalid email or password."
}
```

### GET `/api/auth/me`

Current authenticated user.

**Headers**

```http
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
```

**Response `200`**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Admin",
    "email": "admin@example.com",
    "role_id": 1,
    "status": "active"
  }
}
```

---

## Contacts

### GET `/api/contacts`

List contacts (supports query params such as `page`, `per_page`, `q`, `status`).

```http
GET /api/contacts?page=1&per_page=25&q=alice HTTP/1.1
Authorization: Bearer <token>
```

**Response**

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 12,
        "name": "Alice",
        "mobile": "15551234567",
        "email": "alice@example.com",
        "status": "active",
        "tags": ["lead"]
      }
    ],
    "pagination": {
      "page": 1,
      "per_page": 25,
      "total": 1
    }
  }
}
```

### POST `/api/contacts`

```json
{
  "name": "Bob",
  "mobile": "15559876543",
  "email": "bob@example.com",
  "country": "US",
  "status": "active",
  "notes": "VIP"
}
```

**Response `201`**

```json
{
  "success": true,
  "message": "Contact created",
  "data": { "id": 13 }
}
```

### GET `/api/contacts/{id}`

```json
{
  "success": true,
  "data": {
    "id": 13,
    "name": "Bob",
    "mobile": "15559876543",
    "status": "active"
  }
}
```

### PUT `/api/contacts/{id}`

```json
{
  "name": "Bob Updated",
  "status": "inactive"
}
```

### DELETE `/api/contacts/{id}`

```json
{
  "success": true,
  "message": "Contact deleted"
}
```

---

## Customer Groups

Audience lists for campaigns (stored as tags). Requires `contacts.*` permissions.

### GET `/api/customer-groups`

```http
GET /api/customer-groups?q=expo HTTP/1.1
Authorization: Bearer <token>
```

**Response**

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "items": [
      {
        "id": 3,
        "name": "Expo_Thane_1007",
        "color": "#6B7280",
        "contact_count": 269,
        "created_at": "2026-07-13 18:54:18",
        "updated_at": "2026-07-13 18:54:18"
      }
    ],
    "meta": { "total": 1 }
  },
  "errors": null
}
```

### POST `/api/customer-groups`

Create an empty group.

```json
{
  "name": "SCGT July 26",
  "color": "#25D366"
}
```

**Validation errors `422`**

```json
{
  "success": false,
  "message": "Validation failed.",
  "data": null,
  "errors": {
    "name": "Group name is required."
  }
}
```

### GET `/api/customer-groups/{id}`

Returns the group plus its contacts.

### PUT `/api/customer-groups/{id}`

```json
{
  "name": "SCGT July 26 Updated",
  "color": "#667085"
}
```

### DELETE `/api/customer-groups/{id}`

Deletes the group (memberships removed; contacts remain).

### POST `/api/customer-groups/{id}/contacts`

Add by mobile (creates contact if needed) or by existing `contact_id`.

```json
{
  "name": "Mangesh",
  "mobile": "919876543210",
  "email": "user@example.com"
}
```

or

```json
{
  "contact_id": 42
}
```

**Validation errors `422`**

```json
{
  "success": false,
  "message": "Validation failed.",
  "data": null,
  "errors": {
    "mobile": "Enter a valid mobile number (10–15 digits, with country code)."
  }
}
```

### DELETE `/api/customer-groups/{id}/contacts/{contactId}`

Remove a contact from the group (contact itself is not deleted).

---

## Campaigns

### GET `/api/campaigns`

```http
GET /api/campaigns?status=running HTTP/1.1
Authorization: Bearer <token>
```

### POST `/api/campaigns`

```json
{
  "name": "March Promo",
  "template_id": 5,
  "contact_ids": [12, 13, 14],
  "variables": {
    "1": "Alice"
  },
  "scheduled_at": null
}
```

**Response**

```json
{
  "success": true,
  "message": "Campaign created",
  "data": { "id": 7, "status": "draft" }
}
```

### GET `/api/campaigns/{id}`

Returns campaign detail plus counters (`sent_count`, `delivered_count`, etc.).

### POST `/api/campaigns/{id}/pause`

```json
{ "success": true, "message": "Campaign paused", "data": { "status": "paused" } }
```

### POST `/api/campaigns/{id}/resume`

```json
{ "success": true, "message": "Campaign resumed", "data": { "status": "running" } }
```

---

## Messages

### GET `/api/messages`

Query: `contact_id`, `page`, `per_page`, `direction`.

```http
GET /api/messages?contact_id=12&per_page=50 HTTP/1.1
Authorization: Bearer <token>
```

### POST `/api/messages`

Send a free-form text message (within the 24-hour customer care window when required by WhatsApp policy).

```json
{
  "contact_id": 12,
  "type": "text",
  "text": "Hello from the API"
}
```

**Response**

```json
{
  "success": true,
  "message": "Message queued/sent",
  "data": {
    "message_id": 901,
    "wa_message_id": "wamid.HBgLMTU1NTE...",
    "status": "sent"
  }
}
```

### POST `/api/messages/template`

```json
{
  "contact_id": 12,
  "template_name": "hello_world",
  "language": "en_US",
  "components": []
}
```

---

## Templates

### GET `/api/templates`

Lists locally stored Cheerio message templates.

### POST `/api/templates/sync`

Fetches templates from Cheerio Direct API and upserts the local `templates` table.

```json
{
  "success": true,
  "message": "Templates synced",
  "data": { "synced": 14 }
}
```

---

## Reports

### GET `/api/reports`

High-level dashboard stats (contacts, campaigns, message status totals).

### GET `/api/reports/stats`

Optional query filters: `from`, `to`, `campaign_id`.

```json
{
  "success": true,
  "data": {
    "sent": 1200,
    "delivered": 1100,
    "read": 800,
    "failed": 40,
    "replies": 95
  }
}
```

---

## cURL examples

```bash
# Login
TOKEN=$(curl -s -X POST "http://localhost/APIWA/public/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"secret"}' \
  | php -r 'echo json_decode(stream_get_contents(STDIN))->data->token;')

# Me
curl -s "http://localhost/APIWA/public/api/auth/me" \
  -H "Authorization: Bearer $TOKEN"

# Create contact
curl -s -X POST "http://localhost/APIWA/public/api/contacts" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Test","mobile":"15550001111"}'
```

---

## Errors

| Status | Meaning |
|--------|---------|
| 401 | Missing/invalid/expired Bearer token |
| 404 | Resource not found |
| 422 | Validation failed |
| 429 | Rate limit exceeded (`Retry-After` header) |

Set `JWT_SECRET` in `.env` (see `.env.example`). Token TTL defaults to 24 hours (`Config\Jwt::$ttl`).
