# Deploy to yevle.elintpos.in (WhatsApp app)

Your domain **already runs another site** (`login.php` ? ElintPOS style app).  
This WhatsApp project is **not** online there yet (`/webhooks` = 404).

Follow this order.

---

## Current status (checked)

| Check | Result |
|-------|--------|
| `https://yevle.elintpos.in/` | Opens other app ? `login.php` |
| `https://yevle.elintpos.in/webhooks` | **404** (WhatsApp app missing) |
| `https://yevle.elintpos.in/sateri_connect/public/` | **404** |

So Cheerio **cannot** verify webhook until this CodeIgniter app is uploaded and reachable.

---

## Option A — Subdomain (recommended)

Create DNS:

```text
wa.yevle.elintpos.in   ? same server IP
```

Point that vhost DocumentRoot to:

```text
/path/to/sateri_connect/public
```

Then:

| Use | URL |
|-----|-----|
| App | `https://wa.yevle.elintpos.in/` |
| Webhook | `https://wa.yevle.elintpos.in/webhooks` |

Keeps existing POS on `yevle.elintpos.in` untouched.

---

## Option B — Subfolder on same domain

Upload project to e.g.:

```text
/home/.../yevle.elintpos.in/sateri_connect/
```

DocumentRoot stays the POS site. App URL:

```text
https://yevle.elintpos.in/sateri_connect/public/
```

Webhook:

```text
https://yevle.elintpos.in/sateri_connect/public/webhooks
```

`.env`:

```ini
app.baseURL = 'https://yevle.elintpos.in/sateri_connect/public/'
```

---

## Option C — Replace main site (dangerous)

Only if you want to remove the current POS login site.  
Not recommended without backup.

---

## After files are online — do this

### 1) Server `.env`

Use template from project:

`docs/deploy/env.production.yevle.example`

Copy to server as `.env`, fill DB user/password, run:

```bash
php spark key:generate
```

Set:

```ini
CI_ENVIRONMENT = production
app.baseURL = 'https://YOUR-CHOSEN-URL/'
app.forceGlobalSecureRequests = true
```

### 2) Database

```bash
# create DB sateri_connect, then:
php spark migrate
# install wizard in browser OR seed admin
```

### 3) SSL

HTTPS must work (Let’s Encrypt). Cheerio / WABA rejects plain HTTP.

### 4) Cheerio webhook

| Field | Value (Option A example) |
|-------|---------------------------|
| Callback URL | `https://wa.yevle.elintpos.in/webhooks` |
| Verify token | Same as Settings ? Cheerio API |

Subscribe: **messages**  
Remove old Cloudflare tunnel URL.

### 5) Publish app

Ensure Cheerio WABA is live for production webhooks.

### 6) Cron

```cron
* * * * * cd /path/to/sateri_connect && php spark queue:process
* * * * * cd /path/to/sateri_connect && php spark campaigns:process
* * * * * cd /path/to/sateri_connect && php spark automations:process
```

### 7) Test

```bash
php spark templates:sync
```

Send template ? reply on phone ? check Live Chat.

---

## What I cannot do from your PC alone

- Upload files to `65.2.59.101` (no server SSH/FTP in this session)  
- Point DNS / create subdomain without hosting panel access  
- Make Cheerio webhook verify succeed while `/webhooks` is still 404  

## What is ready in this project

- [`.env.production.yevle`](../.env.production.yevle) — production env template  
- In-app **Guide ? Production**  
- Local Cheerio settings already work on WAMP  

---

## Next action for you

1. Hosting panel ? create **`wa.yevle.elintpos.in`** (Option A) **or** upload to `/sateri_connect/public` (Option B).  
2. Tell me which option + when `https://…/login` of **this** WhatsApp app opens.  
3. Then I can give the exact Cheerio Callback string and we can re-run webhook verify steps.
