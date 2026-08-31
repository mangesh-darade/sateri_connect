# WhatsApp Webhook Setup (Cheerio)

Cheerio delivers inbound WhatsApp events using the **standard WhatsApp Cloud API webhook JSON shape**  
(`hub.verify_token`, `entry` → `changes` → `value` → `messages` / statuses).

You configure the **callback URL** on your Cheerio / WABA side to hit this app.

Related: [SETTINGS.md](SETTINGS.md) · [CHEERIO_CONFIGURATION.md](CHEERIO_CONFIGURATION.md) · [CHEERIO_FLOW.md](CHEERIO_FLOW.md)

Official Cheerio collection: [Cheerio Direct API docs](https://documenter.getpostman.com/view/13841235/2s9Y5Zvh9y)  
API key: [https://app.cheerio.in/settings/apikey](https://app.cheerio.in/settings/apikey)

---

## Callback URL (correct paths)

Routes (CSRF excluded):

| Path | Controller |
|------|------------|
| `GET\|POST /webhooks` | `Webhooks::index` |
| `GET\|POST /webhook` | `Webhooks::index` (alias) |

**Prefer `/webhooks`.**

### Production

```
https://your-domain.com/webhooks
```

If the app lives in a subfolder and DocumentRoot is **not** `public/`:

```
https://your-domain.com/sateri_connect/public/webhooks
```

### Local WAMP + ngrok

Providers cannot reach `localhost`. Tunnel Apache (port 80):

1. Install ngrok: [https://ngrok.com/download/windows](https://ngrok.com/download/windows)
2. Authtoken: [https://dashboard.ngrok.com/get-started/your-authtoken](https://dashboard.ngrok.com/get-started/your-authtoken)
3. Run:

```bash
ngrok http 80
```

Then use:

```
https://<your-subdomain>.ngrok-free.app/sateri_connect/public/webhooks
```

If a vhost DocumentRoot is already `...\sateri_connect\public`:

```
https://<your-subdomain>.ngrok-free.app/webhooks
```

### Local URL (browser only — not for Cheerio / WABA)

```
http://localhost/sateri_connect/public/webhooks
```

---

## Verify token

1. In **Settings → Cheerio API**, set **Webhook Verify Token** (long random string).  
   Stored as `cheerio_webhook_verify_token` (legacy `meta_webhook_verify_token` is still read if empty).
2. In Cheerio / WABA webhook configuration:
   - **Callback URL**: one of the HTTPS URLs above  
   - **Verify token**: must match Settings exactly  

Also shown read-only under **Settings → Webhooks**.

### Verification flow (GET)

Provider sends:

```
GET /webhooks?hub.mode=subscribe&hub.verify_token=YOUR_TOKEN&hub.challenge=1234567890
```

The app compares `hub.verify_token` to the stored token and responds with plain text `hub.challenge` on success.

---

## Webhook secret / signature

For POST payloads, providers may sign the body with `X-Hub-Signature-256` (HMAC SHA-256 of the raw body).

Store the secret in **Settings → Cheerio API → Webhook Secret** (encrypted as `cheerio_webhook_secret`).  
The webhook controller validates the signature before processing when a secret is configured.

---

## Subscribe to fields

Subscribe at least:

| Field | Purpose |
|-------|---------|
| `messages` | Inbound user messages (text, media, interactive, button replies) |
| Delivery / status updates | Usually included with the `messages` subscription payload |

Optional: `message_template_status_update`.

Ensure the webhook is attached to your **live WhatsApp Business Account (WABA)** in Cheerio.

---

## What the app does on POST

1. Validate `X-Hub-Signature-256` (when secret configured)  
2. Persist raw payload to `webhook_logs`  
3. Parse `entry` → `changes` → `value`  
4. **Messages**: upsert contact, store inbound `messages` row, update `conversations`, run keyword bot + automation triggers  
5. **Statuses**: update outbound message / campaign contact status counters  

Always return HTTP `200` quickly so the provider does not retry aggressively.

---

## Step-by-step checklist

1. Save verify token (+ optional webhook secret) in **Settings → Cheerio API**.  
2. Start ngrok (local) or deploy HTTPS (production).  
3. Paste callback URL + verify token in Cheerio / WABA webhook settings → Verify.  
4. Subscribe to `messages`.  
5. Send a WhatsApp message to your business number.  
6. Confirm conversation appears under **Live Chat**.  
7. Confirm a row in `webhook_logs` / `writable/logs` if debugging.

---

## Testing checklist

- [ ] GET verification succeeds  
- [ ] Inbound message → **Live Chat** / `messages` table  
- [ ] Campaign send → status transitions (`delivered` / `read`)  
- [ ] Bad signature → logged, not processed  
- [ ] CSRF not blocking POST (filter excludes `webhooks` / `webhook`)  

### Expected without verify params

Opening `/webhooks` in a browser without the challenge query string may return **403** — that is normal.

---

## Related

- [SETTINGS.md](SETTINGS.md)  
- [CHEERIO_CONFIGURATION.md](CHEERIO_CONFIGURATION.md)  
- [DEPLOYMENT.md](DEPLOYMENT.md) — HTTPS requirements  
- [USER_GUIDE.md](USER_GUIDE.md)  
