# User Guide (Deep)

Complete step-by-step guide for the WhatsApp Automation Platform after installation.

**Local base:** `http://localhost/whstapp/public/`

Related docs: [SETTINGS.md](SETTINGS.md) · [CHEERIO_CONFIGURATION.md](CHEERIO_CONFIGURATION.md) · [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md) · [CRON_SETUP.md](CRON_SETUP.md) · [API.md](API.md)

---

## Table of contents

1. [First login](#1-first-login)
2. [Recommended setup order](#2-recommended-setup-order)
3. [Dashboard](#3-dashboard)
4. [Settings](#4-settings)
5. [Templates](#5-templates)
6. [Contacts](#6-contacts)
7. [Campaigns](#7-campaigns)
8. [Chat inbox](#8-chat-inbox)
9. [Keywords (chatbot)](#9-keywords-chatbot)
10. [Automations](#10-automations)
11. [Message queue](#11-message-queue)
12. [Reports](#12-reports)
13. [Users & roles](#13-users--roles)
14. [Background workers (required)](#14-background-workers-required)
15. [24-hour messaging window](#15-24-hour-messaging-window)
16. [Troubleshooting](#16-troubleshooting)

---

## 1. First login

1. Open http://localhost/whstapp/public/login  
2. Sign in with the admin account created in the installer.  
3. You land on **Dashboard**.

If `/install` still opens, the app is not marked installed — finish the installer **Finish** step (or set `app_installed = 1` in `settings`).

---

## 2. Recommended setup order

Do this **once** before sending real messages:

| Step | Where | Why |
|-----:|-------|-----|
| 1 | Settings → Cheerio API | Access token, \(Cheerio live number\), WABA \(in Cheerio\), Webhook Secret |
| 2 | Settings → Webhooks | Copy callback URL; configure Cheerio + verify token |
| 3 | Settings → Application | Timezone e.g. `Asia/Kolkata`, app name |
| 4 | Settings → SMTP (optional) | Password-reset emails |
| 5 | Templates → Sync | Pull approved Cheerio templates |
| 6 | Contacts | Add or import phone numbers (E.164) |
| 7 | Cron / workers | Queue must run or messages stay pending |
| 8 | Keywords / Chat / Campaigns | Use the product |

Without Cheerio credentials + workers, campaigns and chat send will fail or stall.

---

## 3. Dashboard

**URL:** `/dashboard`

Shows high-level counts (contacts, campaigns, queue, recent activity). Use it as a health check after workers run.

---

## 4. Settings

**URL:** `/settings`  
**Deep guide:** [SETTINGS.md](SETTINGS.md)

Four tabs:

- **Cheerio API** — Cheerio API key (stored encrypted)
- **Application** — name, timezone, email, URL
- **SMTP** — outbound email + Test SMTP
- **Webhooks** — callback URL to paste in Cheerio / WABA

Click **Save Settings** after edits. Leave masked secrets unchanged to keep existing values.

---

## 5. Templates

**URL:** `/templates`

WhatsApp / Cheerio requires **approved message templates** for outbound messages outside the customer care (24h) window — especially marketing broadcasts.

### Steps

1. Create templates in **Cheerio Dashboard → WhatsApp Manager → Message templates**.  
2. Wait until status is **APPROVED**.  
3. In this app: **Templates → Sync** (or CLI: `php spark templates:sync`).  
4. Open a template to preview variables (`{{1}}`, `{{2}}`, …).

Campaigns pick from synced, approved templates.

---

## 6. Contacts

**URL:** `/contacts`

### Add one contact

1. **Contacts → Create**  
2. Enter **phone** in international format without `+` spaces preferred as digits only, e.g. `919876543210` (India).  
3. Optional: name, email, tags.  
4. Save.

### Import CSV

1. **Contacts → Import**  
2. Upload CSV with at least a phone column.  
3. Map columns if prompted.  
4. Review duplicates (**Contacts → Duplicates**).

### Bulk actions

- Bulk delete  
- Bulk assign tags  
- Export CSV  

Use tags later to target campaign audiences.

---

## 7. Campaigns

**URL:** `/campaigns`

Broadcast template messages to many contacts.

### Create & send (happy path)

1. **Campaigns → Create**  
2. Name the campaign.  
3. Select an **approved** template.  
4. Choose audience (all contacts, tags, or selected list — depending on form options).  
5. Map template variables (name, custom fields, static text).  
6. **Preview** recipients / sample payload.  
7. Either:
   - **Send now**, or  
   - **Schedule** a datetime (timezone from Settings).  
8. Watch status: draft → scheduled/running → completed.  
9. Use **Pause / Resume / Cancel** as needed.  
10. Open campaign → **Analytics** / queue status.

### Important

- Campaigns enqueue rows into **message_queue**.  
- Nothing leaves the server until `php spark queue:process` (and `campaigns:process`) run.  
- See [CRON_SETUP.md](CRON_SETUP.md).

---

## 8. Chat inbox

**URL:** `/chat`

Live agent inbox for 1:1 conversations.

### Flow

1. Customer messages your WhatsApp Business number → Cheerio webhook → app stores message → conversation appears.  
2. Agent opens **Chat**, selects conversation, replies.  
3. Inside the **24-hour session window**, free-form text/media is allowed.  
4. Outside the window, use an approved **template** (or wait for customer to message again).  
5. Internal notes: agent-only notes on the contact (not sent to WhatsApp).  
6. Mark read / assign (permissions permitting).

Webhook must be verified and subscribed to `messages` or the inbox stays empty.

---

## 9. Keywords (chatbot)

**URL:** `/keywords`

Auto-replies when an inbound message matches a keyword.

### Concepts

| Field | Meaning |
|-------|---------|
| Keyword | Text to match (e.g. `Hi`, `1`, `price`) |
| Match type | Exact / contains (as implemented in UI) |
| Response type | Text, interactive list, etc. |
| Parent | Nested menu replies (e.g. Hi → options 1 / 2) |
| Active | Only active keywords fire |
| Order | Priority / menu order |

### Sample seeded bot

After install seeders, a simple menu may exist:

- Customer: `Hi` → interactive menu  
- Customer: `1` → products text  
- Customer: `2` → support text  

### Steps to customize

1. **Keywords → Create**  
2. Set keyword + response.  
3. For menus, create parent then child keywords with `parent_id`.  
4. Reorder if needed.  
5. Test by messaging the WhatsApp number (webhook + workers must work).

---

## 10. Automations

**URL:** `/automations`

Rule engine for events / time-based triggers (e.g. birthday, delayed follow-ups — depending on configured rules).

### Steps

1. **Automations → Create** — name + enable.  
2. Open **Builder** for that automation.  
3. Define trigger → conditions → actions (send template / tag / etc.).  
4. Toggle on/off from the list.  
5. Ensure `php spark automations:process` runs on a schedule.

---

## 11. Message queue

**URL:** `/queue`

Every outbound Cloud API send is queued for reliability (retry, rate control).

| Status | Meaning |
|--------|---------|
| pending | Waiting for worker |
| processing | Currently sending |
| sent | Accepted by Cheerio |
| failed | Error — inspect reason; **Retry** or fix credentials |
| cancelled | Manually cancelled |

### Operator actions

- **Retry** failed item  
- **Cancel** pending item  
- Watch **Queue stats**  

If the queue never drains → workers/cron are not running.

---

## 12. Reports

**URL:** `/reports`

- Overview stats  
- Campaign reports  
- Delivery reports  
- Export PDF / Excel (where enabled)

Use after campaigns have run and status webhooks have updated delivery states.

---

## 13. Users & roles

### Users — `/users`

Create team members: name, email, password, role, active/inactive.

### Roles — `/roles`

Permission matrix by module:

- Dashboard, Contacts, Campaigns, Templates, Chat  
- Automations, Keywords, Reports, Settings  
- Users, Roles, Queue  

**Super Admin** is locked to full access.  
Edit checkboxes for Admin / Manager / Agent → **Save Permissions**.

Default roles from seeder: `super-admin`, `admin`, `manager`, `agent`.

---

## 14. Background workers (required)

From project root (`c:\wamp64\www\whstapp`):

```bash
php spark queue:process
php spark campaigns:process
php spark automations:process
php spark queue:retry
php spark templates:sync
php spark logs:cleanup
```

For production, schedule these (Task Scheduler on Windows / cron on Linux). Full examples: [CRON_SETUP.md](CRON_SETUP.md).

**Windows Task Scheduler tip:** run `php.exe` with working directory = project root, trigger every 1 minute for queue + campaigns.

---

## 15. 24-hour messaging window

WhatsApp policy (simplified):

- When a **user messages you**, a ~24h customer care window opens.  
- Inside window: session messages (text, etc.) allowed.  
- Outside window: only **template** messages (until user writes again).

The inbox UI is aware of this window; campaigns almost always use templates.

---

## 16. Troubleshooting

| Symptom | Check |
|---------|--------|
| Install loops / always redirects to install | `settings.app_installed` must be `1` |
| Settings save but Cheerio fields empty | Re-open Settings after save; ensure encryption key in `.env` |
| Chat empty | Webhook URL, verify token, Cheerio webhook subscription, HTTPS/tunnel |
| Campaign stuck pending | Run `queue:process` + `campaigns:process` |
| Send fails 401/190 | Access token expired — update Settings → Cheerio API |
| Template sync empty | Wrong WABA \(in Cheerio\) / token permissions / no approved templates |
| SMTP test fails | Host/port/TLS, app password, from-address allowed by provider |
| `/roles` error | Use latest `app/Views/roles/index.php` (grouped permissions fix) |
| MySQL “key too long” on migrate | Set `default_storage_engine=InnoDB` (WAMP often defaults MyISAM) |

### Logs

```
writable/logs/log-YYYY-MM-DD.log
```

### Useful local URLs

```
http://localhost/whstapp/public/login
http://localhost/whstapp/public/settings
http://localhost/whstapp/public/templates
http://localhost/whstapp/public/contacts
http://localhost/whstapp/public/campaigns
http://localhost/whstapp/public/chat
http://localhost/whstapp/public/queue
```

---

## Permission cheat sheet

| Permission slug (examples) | Allows |
|----------------------------|--------|
| `settings.view` / `settings.edit` | Open / save Settings |
| `contacts.*` | CRM contacts |
| `campaigns.*` | Broadcasts |
| `templates.sync` | Pull Cheerio templates |
| `chat.send` | Reply in inbox |
| `queue.manage` | Retry / cancel queue |
| `roles.edit` | Change permission matrix |

Super-admin effectively has `*` (all).
