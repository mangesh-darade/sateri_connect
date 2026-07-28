# Settings (Deep Guide)

**URL:** `http://localhost/whstapp/public/settings`  
**Permissions:** `settings.view` (open), `settings.edit` (save / SMTP test)

Settings control how the platform talks to **Cheerio Direct APIs**, how the app identifies itself, how email is sent, and what webhook URL should receive inbound events.

---

## Page layout

```
┌──────────────┬──────────┬────────┬────────┬───────────┐
│ Cheerio API  │ Go Live  │  App   │  SMTP  │ Webhooks  │
└──────────────┴──────────┴────────┴────────┴───────────┘
                         [ Save Settings ]
```

One form posts to `POST /settings/save` with `section=all` (saves every tab together).

Secrets (API key, webhook secret, SMTP password) are:

- Stored **encrypted** in the `settings` table when flagged  
- Shown **masked** in the UI  
- **Not overwritten** if you leave the field blank or submit the masked value  

---

## Tab 1 — Cheerio API

These values are required for sending/receiving WhatsApp messages.

| Field (UI) | DB key | Encrypted? | Purpose |
|------------|--------|------------|---------|
| Cheerio API Key | `cheerio_api_key` | Yes | `x-api-key` header for Direct APIs |
| Webhook Verify Token | `cheerio_webhook_verify_token` | No* | Must match webhook “Verify token” |
| Webhook Secret | `cheerio_webhook_secret` | Yes | Validate `X-Hub-Signature-256` on POST |
| Base URL | config only | — | `https://newprod.api.cheerio.in/direct-apis` |

\*Verify token is a shared secret you invent; treat it as sensitive even if not encrypted.

### Step-by-step

1. Open [Cheerio API Key settings](https://app.cheerio.in/settings/apikey) and generate a key.  
2. Paste into **Cheerio API Key** and Save.  
3. Invent a long random **Verify Token**; use the same in your webhook console.  
4. Set **Webhook Secret** if signature validation is enabled.  
5. Click **Test Cheerio Connection**.  

Full walkthrough: [CHEERIO_CONFIGURATION.md](CHEERIO_CONFIGURATION.md).

---

## Tab 2 — Application

| Field | DB key | Example | Purpose |
|-------|--------|---------|---------|
| Application Name | `app_name` | `WhatsApp Automation Platform` | Branding in UI / emails |
| Timezone | `app_timezone` (+ legacy `timezone`) | `Asia/Kolkata` | Scheduling / display |
| Support / App Email | `app_email` | `ops@company.com` | Contact / fallback from |
| App URL | `app_url` | `http://localhost/whstapp/public/` | Canonical link |

### Steps

1. Set timezone to your market (`Asia/Kolkata` for India).  
2. Set a clear app name.  
3. Save.

Use [IANA timezone names](https://en.wikipedia.org/wiki/List_of_tz_database_time_zones).

---

## Tab 3 — SMTP

Used for password-reset and system emails (not for WhatsApp).

| Field | DB key | Notes |
|-------|--------|-------|
| SMTP Host | `smtp_host` | e.g. `smtp.gmail.com`, `smtp.office365.com` |
| Port | `smtp_port` | `587` (TLS) or `465` (SSL) |
| Encryption | `smtp_encryption` | `tls` / `ssl` / empty |
| Username | `smtp_user` | Usually full email |
| Password | `smtp_password` (+ legacy `smtp_pass`) | App password recommended |
| From Email | `smtp_from_email` | Must be allowed by provider |
| From Name | `smtp_from_name` | Display name |

### Steps

1. Fill host/port/encryption.  
2. Enter username + app password.  
3. Set From email/name.  
4. **Save Settings** first.  
5. Click **Test SMTP** → enter a recipient → confirm inbox.

### Gmail example

1. Enable 2FA on Google account.  
2. Create an **App Password**.  
3. Host `smtp.gmail.com`, port `587`, encryption `tls`.  
4. Username = Gmail address, password = app password.

If Test SMTP fails, read `writable/logs/` and the JSON error toast (auth, TLS, relay denied).

---

## Tab 4 — Webhooks

Read-only helpers for Cheerio webhook configuration.

| Item | Value (WAMP local) |
|------|--------------------|
| Callback / Webhook URL | `http://localhost/whstapp/public/webhooks` |
| Verify Token | Same as Cheerio API tab |

Also accepted by the app: `/webhook` (alias). Prefer **`/webhooks`**.

### Steps

1. Copy webhook URL (copy button).  
2. For **local** Cheerio webhook verification you need a public HTTPS tunnel, e.g. ngrok:

   ```bash
   ngrok http 80
   ```

   Then callback becomes:

   ```
   https://<subdomain>.ngrok-free.app/whstapp/public/webhooks
   ```

3. In Cheerio / WABA webhook settings:
   - Callback URL = tunneled URL  
   - Verify token = Settings value  
4. Verify (provider sends GET with `hub.challenge`).  
5. Subscribe fields:
   - `messages`  
   - message status / delivery related fields as offered  
6. Attach webhook to your WABA.

Deep webhook doc: [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md).

### Why localhost alone is not enough

Cheerio / WABA servers must **HTTP GET/POST** your URL. `localhost` is only on your PC — use ngrok, Cloudflare Tunnel, or deploy to HTTPS hosting.

---

## Save behaviour (technical)

- Route: `POST /settings/save` (CSRF required)  
- Controller: `App\Controllers\Settings::save`  
- Service: `App\Libraries\SettingsService`  
- Activity log entry: `update` / `settings`  

Masked secret detection: any value containing `•` is ignored so you don’t overwrite with the mask string.

---

## After Settings — checklist

- [ ] Cheerio API key + \(Cheerio live number\) saved  
- [ ] Webhook verified in Cheerio (green / subscribed)  
- [ ] `php spark templates:sync` succeeds  
- [ ] Send a test template or receive an inbound message in **Chat**  
- [ ] Queue worker running  

---

## Security notes

1. Restrict `settings.edit` to trusted roles only.  
2. Keep `encryption.key` in `.env` stable — changing it makes old encrypted settings unreadable.  
3. Rotate Cheerio API keys and update Settings immediately.  
4. Production: HTTPS only; never expose `/settings` without auth (auth filter already required).  

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Fields empty after save | Confirm flash “Settings saved”; reload page; check `writable/logs` |
| Encrypted values garbage after server move | Same `encryption.key` required |
| Webhook verify fails | Token mismatch; wrong URL path; tunnel down; CSRF not excluded (should be excluded for `webhooks`) |
| Cheerio API errors in queue | Bad/expired token; wrong \(Cheerio live number\); template not approved |
| SMTP test 422 | Provide a valid `to` email in the prompt |
