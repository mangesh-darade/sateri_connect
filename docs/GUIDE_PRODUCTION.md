# Production Guide — Live server setup

**Who this is for:** Putting the app online for real customers  
**What you will do:** Deploy on a real server with HTTPS, Cheerio API key, live phone number, and workers  
**Time:** Half day to a few days (depends on hosting and Cheerio / WABA review)  

> **Messaging provider:** **Cheerio Direct APIs only**. See [CHEERIO_FLOW.md](CHEERIO_FLOW.md) and [CHEERIO_CONFIGURATION.md](CHEERIO_CONFIGURATION.md).

> **Tip:** Finish the **Local** guide first so you understand the app.  
> Then follow this Production guide step by step.

**Example live URL:** `https://yevle.elintpos.in/` (or a subdomain like `https://wa.yevle.elintpos.in/`)  
(Document root must point to the `public/` folder.)

> **Your domain checklist:** see [DEPLOY_YEVLE.md](DEPLOY_YEVLE.md) — right now `yevle.elintpos.in` still shows another app (`login.php`), so WhatsApp must be deployed first (subdomain recommended).

---

## Big picture

| Step | Do this | You are done when… |
|-----:|---------|---------------------|
| 1 | Prepare hosting | PHP 8.2+, MySQL, HTTPS ready |
| 2 | Upload code + `.env` | Site opens on your domain |
| 3 | Install / migrate / seed perms | Login works on HTTPS |
| 4 | Harden security | `CI_ENVIRONMENT=production` |
| 5 | Cheerio live setup | Live number + permanent token |
| 6 | Webhook on HTTPS | Cheerio webhook verified |
| 7 | Sync templates | Approved templates show |
| 8 | Cron / workers | Queue + delays + sequences send |
| 9 | Smoke test | Real send + receive + inbox statuses work |

---

## Local vs Production (simple)

| Topic | Local | Production |
|-------|-------|------------|
| URL | `http://localhost/...` | `https://your-domain.com/` |
| Webhook | Cloudflare tunnel / ngrok | Real HTTPS domain |
| Token | Cheerio API key OK | Permanent Cheerio API key |
| Phone | Cheerio test number | Live WhatsApp Business number |
| Recipients | Only verified test phones | Real customers (after WhatsApp / Cheerio rules) |
| Workers | Run by hand | Cron / Supervisor every minute |
| Debug | Development OK | Must be production (no toolbar) |

---

## What you need

| Item | Why |
|------|-----|
| VPS or shared hosting | Runs PHP + MySQL |
| Domain name | Public URL for users and webhooks |
| SSL certificate | HTTPS (Let’s Encrypt is fine) |
| Cheerio Dashboard + live WhatsApp number | Real messaging |
| SSH or cPanel access | Upload files, run commands |
| Backup plan | Protect data |

---

# PART A — Server

## Step 1 — Server requirements

- PHP **8.2+** with: `intl`, `mbstring`, `json`, `curl`, `openssl`, `mysqli`  
- MySQL 8+ (or MariaDB) with **InnoDB**  
- Apache or Nginx  
- Composer (on server or build on PC then upload `vendor/`)  
- Ability to set DocumentRoot to **`public/`** only  

### Checklist

- [ ] PHP version is 8.2 or higher  
- [ ] Required extensions are enabled  
- [ ] MySQL database created (example name: `apiwa`)  

---

## Step 2 — Upload the project

1. Upload the project files to the server (Git, SFTP, or zip).  
2. Point the web root to:

```text
/path/to/sateri_connect/public
```

3. Make sure these are **not** public on the web:
   - `.env`
   - `app/`
   - `writable/` (except through the app)
   - `vendor/`

### Permissions (Linux example)

```bash
chown -R www-data:www-data writable
chmod -R ug+rwX writable
```

### Checklist

- [ ] Opening `https://your-domain.com/` loads the app (or installer)  
- [ ] `.env` is not downloadable from the browser  

---

## Step 3 — Configure `.env`

Set at least:

```ini
CI_ENVIRONMENT = production

app.baseURL = 'https://your-domain.com/'
app.forceGlobalSecureRequests = true

database.default.hostname = localhost
database.default.database = apiwa
database.default.username = YOUR_DB_USER
database.default.password = YOUR_DB_PASSWORD
database.default.DBDriver = MySQLi
```

Also set:

- Strong `encryption.key` → run `php spark key:generate`  
- Strong `JWT_SECRET` (long random string)  

Use a **normal MySQL user**, not `root`, in production.

### Checklist

- [ ] `baseURL` uses `https://` and ends with `/`  
- [ ] `CI_ENVIRONMENT = production`  
- [ ] Database login works  

---

## Step 4 — Install / migrate

If first deploy:

1. Open `https://your-domain.com/install` and finish the wizard  
   **or**  
2. Run:

```bash
cd /path/to/sateri_connect
php spark migrate
php spark db:seed
```

Create the admin user during install (or seed).

Login at:

```text
https://your-domain.com/login
```

### Checklist

- [ ] Login works on HTTPS  
- [ ] Dashboard opens  
- [ ] Debug toolbar is **not** visible  

---

## Step 5 — Security checklist

- [ ] `CI_ENVIRONMENT = production`  
- [ ] HTTPS works on all pages  
- [ ] Strong admin password  
- [ ] `.env` not in git / not public  
- [ ] CSRF works on forms  
- [ ] Session cookies Secure + HttpOnly (production defaults)  
- [ ] Uploads served safely (not open folder listing)  
- [ ] Daily database backup enabled  

More detail: [DEPLOYMENT.md](DEPLOYMENT.md)

---

# PART B — Cheerio for live WhatsApp

## Step 6 — Live WhatsApp number + permanent token

1. Open https://app.cheerio.in/ → your app.  
2. Connect a **live** WhatsApp Business phone number (not only the test number).  
3. Create a **System User** token with WhatsApp permissions (permanent / long-lived).  
4. Copy:
   - Access Token (System User)
   - \(Cheerio live number\) (live)
   - WhatsApp Business Account ID
   - Webhook Secret  
5. Make a strong **Webhook Verify Token** and save it.

### App Review / access

- Sandbox / development: only test phones.  
- Production messaging to customers may need **Business verification** and **App Review**.  
- Follow Cheerio Dashboard go-live steps for your WABA.

### Checklist

- [ ] Live \(Cheerio live number\) ready  
- [ ] Permanent (System User) token ready  
- [ ] Webhook Secret ready  
- [ ] Business verification status understood  

---

## Step 7 — Save Cheerio settings in the app

1. Login → **Settings → Cheerio API**.  
2. Paste token, \(Cheerio live number\), WABA \(in Cheerio\), Webhook Secret, Verify Token.  
3. API base: Cheerio Direct APIs (configured in app).  
4. **Save Settings**.

Also set **Settings → Application**:

- App name  
- Timezone (example: `Asia/Kolkata`)  
- Correct public app URL  

### Checklist

- [ ] Settings saved  
- [ ] No temporary “test-only” token left in production  

---

## Step 8 — Webhook (real HTTPS — no tunnel)

Callback URL:

```text
https://your-domain.com/webhooks
```

If the app is in a subfolder and DocumentRoot is **not** `public/`:

```text
https://your-domain.com/sateri_connect/public/webhooks
```

In Cheerio / WABA webhook settings:

1. Callback URL = your HTTPS `/webhooks` URL  
2. Verify token = same as Settings  
3. Verify  
4. Subscribe: **`messages`**

### Checklist

- [ ] Cheerio shows webhook verified  
- [ ] `messages` subscribed  
- [ ] No ngrok/cloudflared needed in production  

---

## Step 9 — Sync templates

On the server:

```bash
cd /path/to/sateri_connect
php spark templates:sync
```

Or use **Templates → Sync** in the panel.

Only **APPROVED** templates can be used for broadcasts outside the 24-hour window.

### Checklist

- [ ] Approved templates appear in the app  
- [ ] You know which template to use for first contact  

---

# PART C — Workers (required)

## Step 10 — Cron / Supervisor

Messages stay pending until workers run.

Run every minute (Linux crontab example):

```cron
* * * * * cd /path/to/sateri_connect && php spark queue:process >> writable/logs/queue.log 2>&1
* * * * * cd /path/to/sateri_connect && php spark campaigns:process >> writable/logs/campaigns.log 2>&1
* * * * * cd /path/to/sateri_connect && php spark automations:process >> writable/logs/automations.log 2>&1
```

`automations:process` is required for:
- Workflow **Delay** resume (`automation_delayed_jobs`)
- **Sequence** drip steps
- Birthday / scheduled automation triggers

Also useful after deploy:

```bash
cd /path/to/sateri_connect
php spark migrate
php spark db:seed PermissionSeeder
```

Also useful daily:

```cron
5 3 * * * cd /path/to/sateri_connect && php spark templates:sync
15 3 * * * cd /path/to/sateri_connect && php spark logs:cleanup
```

Windows server: use Task Scheduler (see [CRON_SETUP.md](CRON_SETUP.md)).

### Checklist

- [ ] Queue worker runs every minute  
- [ ] Campaigns / automations workers scheduled  
- [ ] Logs are rotating or cleaned  

---

# PART D — Go-live test

## Step 11 — Smoke test

1. Add a real contact (or your own phone if allowed).  
2. Send an **approved template**.  
3. Confirm the phone receives it.  
4. Reply from the phone.  
5. Confirm **Team Inbox** (`/chat`) shows the inbound message and status filters work.  
6. Reply from Live Chat (inside 24h window).  
7. Optional: create a **Sequence**, enroll a contact, confirm `automations:process` sends the next step.  
8. Check **Queue** is empty / sent (not stuck pending).  
9. Check delivery status updates (if webhooks work).

### Checklist

- [ ] Outbound template works  
- [ ] Inbound webhook works  
- [ ] Agent reply works  
- [ ] Inbox statuses / Resolve work  
- [ ] Queue is healthy  
- [ ] `automations:process` is on cron  

---

## Step 12 — Team and roles

1. **Roles** — only give needed permissions. New modules:
   - `sequences.view|create|edit|delete`
   - `guide.view` (Setup Workspace guides)
2. **Users** — create agent accounts (not shared admin).  
3. Re-seed system role matrix after upgrades (custom roles are preserved):

```bash
php spark db:seed PermissionSeeder
```

4. Train agents on:
   - 24-hour window  
   - Templates outside the window  
   - Team Inbox statuses (open / pending / intervened / chatbot / resolved)  
   - Keywords + Sequences (view) + Workflows (if allowed)  

### Checklist

- [ ] Admin account is not shared  
- [ ] Agents have only the rights they need  
- [ ] Sequences / Guide permissions appear on Roles page  

---

# Problems and fixes (production)

| Problem | Fix |
|---------|-----|
| Webhook fails | HTTPS only; correct `/webhooks` path; same verify token |
| Queue never sends | Cron/Supervisor not running |
| Delay / sequence never continue | Ensure `automations:process` cron every minute + `php spark migrate` |
| Missing Sequences menu | Role needs `sequences.view`; run PermissionSeeder |
| SSL / curl errors | Server CA certs OK; PHP curl openssl enabled |
| Token errors | Use System User token; check permissions |
| 404 on all pages | DocumentRoot must be `public/`; enable rewrite |
| Mixed HTTP/HTTPS | Set `app.baseURL` to https; force secure requests |
| Debug bar online | Set `CI_ENVIRONMENT = production` |

Logs:

```text
writable/logs/
```

---

# Final production checklist

- [ ] HTTPS site opens  
- [ ] Production `.env` set  
- [ ] Login works  
- [ ] Live Cheerio number + API key saved  
- [ ] Webhook verified + `messages`  
- [ ] Templates synced  
- [ ] Cron workers running (`queue`, `campaigns`, `automations`)  
- [ ] Test send + receive OK  
- [ ] Team Inbox statuses OK  
- [ ] Sequences permissions reviewed  
- [ ] Backups enabled  
- [ ] Strong passwords / roles reviewed  

When all boxes are checked, you are ready for live use (within WhatsApp / Cheerio policy and access levels).

---

## More docs

| Doc | Topic |
|-----|--------|
| [GUIDE_LOCAL.md](GUIDE_LOCAL.md) | Local WAMP testing |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Full hardening checklist |
| [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md) | Webhook details |
| [CHEERIO_CONFIGURATION.md](CHEERIO_CONFIGURATION.md) | Cheerio API key & live setup |
| [CRON_SETUP.md](CRON_SETUP.md) | Workers |
| [SETTINGS.md](SETTINGS.md) | Settings fields |

---

**End of Production guide.**  
Do Local first if you are new. Then complete this guide before real customers.
