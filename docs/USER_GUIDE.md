# User Guide (Deep)

Complete step-by-step guide for the WhatsApp Automation Platform after installation.

**Local base:** `http://localhost/sateri_connect/public/`

Related docs: [SETTINGS.md](SETTINGS.md) · [CHEERIO_CONFIGURATION.md](CHEERIO_CONFIGURATION.md) · [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md) · [CRON_SETUP.md](CRON_SETUP.md) · [API.md](API.md) · [GUIDE_LOCAL.md](GUIDE_LOCAL.md) · [CHANGELOG_2026-07-29.md](CHANGELOG_2026-07-29.md)

---

## Table of contents

1. [First login](#1-first-login)
2. [Recommended setup order](#2-recommended-setup-order)
3. [Dashboard](#3-dashboard)
4. [Settings](#4-settings)
5. [Templates](#5-templates)
6. [Contacts](#6-contacts)
7. [Campaigns](#7-campaigns)
8. [Team Inbox](#8-team-inbox)
9. [Keywords (chatbot)](#9-keywords-chatbot)
10. [Automations / Workflows](#10-automations--workflows)
11. [Sequences](#11-sequences)
12. [Message queue](#12-message-queue)
13. [Reports / Analytics](#13-reports--analytics)
14. [Users & roles](#14-users--roles)
15. [Background workers (required)](#15-background-workers-required)
16. [24-hour messaging window](#16-24-hour-messaging-window)
17. [Troubleshooting](#17-troubleshooting)

---

## 1. First login

1. Open http://localhost/sateri_connect/public/login  
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

## 8. Team Inbox

**URL:** `/chat`  
**Permissions:** `chat.view`, `chat.send`, `chat.assign`, `chat.close`

Live agent inbox for 1:1 conversations (Team Inbox 2.0).

### Statuses

`open` · `pending` · `intervened` · `chatbot` · `resolved` (legacy `closed` → resolved)

### Flow

1. Customer messages → webhook → conversation appears.  
2. Agent opens **Team Inbox**, selects conversation, replies.  
3. Use status dropdown or **Resolve** / **Reopen** (`chat.close`).  
4. Filters: Active, Expired, CTWA, FRT exceeded, status chips.  
5. Inside the **24-hour session window**, free-form text/media is allowed.  
6. Outside the window, use an approved **template**.  
7. Internal notes: agent-only (not sent to WhatsApp).  

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

## 10. Automations / Workflows

**URL:** `/automations`  
**Builder:** `/automations/{id}/builder` (fullscreen)  
**Permissions:** `automations.view|create|edit|delete`

Visual rule engine: trigger → conditions → actions.

### Steps

1. **Automations → Create** — name + enable.  
2. Open **Builder** (fullscreen canvas).  
3. Drag triggers / conditions / actions; connect ports; **Save**.  
4. Useful nodes: Delay (resumes via worker), send_email, assign_bot, update_chat_status, attribute_condition, campaign_sent.  
5. Toggle Active from the list.  
6. Ensure `php spark automations:process` runs every minute (delays + birthday).

Catalog help: `/guide/automations`.

---

## 11. Sequences

**URL:** `/sequences`  
**Permissions:** `sequences.view|create|edit|delete`

Multi-step WhatsApp drips (text or template) with delay between steps and optional **exit on reply**.

1. Create sequence → add steps → save.  
2. Enroll contact by ID on the edit form.  
3. Worker `automations:process` sends due steps.  
4. Inbound reply can exit active enrollments.

---

## 12. Message queue

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

## 13. Reports / Analytics

**URL:** `/reports`, `/analytics`  
**Permission:** `reports.view` (export: `reports.export`)

- Overview / analytics  
- Campaign reports  
- Delivery reports  
- Export PDF / Excel (where enabled)

Use after campaigns have run and status webhooks have updated delivery states.

---

## 14. Users & roles

### Users — `/users`

Create team members: name, email, password, role, active/inactive.

### Roles — `/roles`

Permission matrix by module:

- Dashboard, Contacts, Campaigns, Templates, Chat  
- Automations, **Sequences**, Keywords, Reports, Settings  
- Users, Roles, Queue, **Guide** (`guide.view`)

**Super Admin** is locked to full access.  
Edit checkboxes for Admin / Manager / Agent → **Save Permissions**.

Default roles from seeder: `super-admin`, `admin`, `manager`, `agent`.  
`php spark db:seed PermissionSeeder` re-syncs system roles only (custom roles kept).

---

## 15. Background workers (required)

From project root (`c:\wamp64\www\sateri_connect`):

```bash
php spark queue:process
php spark campaigns:process
php spark automations:process
php spark queue:retry
php spark templates:sync
php spark logs:cleanup
```

`automations:process` also processes delayed workflow jobs and sequence due steps.

Verify:

```bash
php spark functional:smoke
```
For production, schedule these (Task Scheduler on Windows / cron on Linux). Full examples: [CRON_SETUP.md](CRON_SETUP.md).

**Windows Task Scheduler tip:** run `php.exe` with working directory = project root, trigger every 1 minute for queue + campaigns.

---

## 16. 24-hour messaging window

WhatsApp policy (simplified):

- When a **user messages you**, a ~24h customer care window opens.  
- Inside window: session messages (text, etc.) allowed.  
- Outside window: only **template** messages (until user writes again).

The inbox UI is aware of this window; campaigns almost always use templates.

---

## 17. Troubleshooting

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
http://localhost/sateri_connect/public/login
http://localhost/sateri_connect/public/settings
http://localhost/sateri_connect/public/templates
http://localhost/sateri_connect/public/contacts
http://localhost/sateri_connect/public/campaigns
http://localhost/sateri_connect/public/chat
http://localhost/sateri_connect/public/queue
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
