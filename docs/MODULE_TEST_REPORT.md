# Module deep test report (browser smoke + UI upgrade)

**Date:** 2026-08-02  
**Method:** Playwright MCP screen walk (waves A–G) + functional CRUD + shared visual upgrade  
**Base URL:** `http://localhost/sateri_connect/public/`  
**Login:** `admin@apiwa.local` (local reset for smoke)  
**Stack:** CodeIgniter 4 · WAMP · DB `apiwa` (localhost tenant)

---

## Overall verdict

### Browser smoke: **PASS** (34/34 screens HTTP 200, no exceptions)
### Visual upgrade (2C): **DONE** (same purple SaaS tokens)
### Functional deep: **PASS** with one list-sort fix applied
### Dummy-data CRUD (create / edit / delete): **PASS** (2026-08-02 evening)

| Area | Score |
|------|-------|
| Auth / login | **PASS** |
| Module page loads (A–G) | **PASS** (34 routes) |
| Contact create → detail | **PASS** (`/contacts/8082`) |
| Campaign wizard open | **PASS** |
| Roles header save + matrix | **PASS** |
| Notifications poll | **PASS** (`success: true`) |
| Contacts list newest-first | **FIXED** (was hiding NULL `last_message_at`) |
| Local DB tenant | **FIXED** (`Database.php` → `apiwa`) |
| Contacts / Keywords / Automations / Users CRUD | **PASS** |
| Sequences CRUD (incl. delete) | **PASS** — delete UI aligned to `data-confirm-delete` |
| Customer groups create + delete | **PASS** (via Add Contacts modal) |
| Campaigns WA wizard → draft save | **PASS** (label with phones + template) |
| Settings save | **PASS** |
| Templates create form load | **PASS** (Meta sync still required for real template create) |

---

## Pass 1 — Screen smoke scorecard

| Wave | Screen | Status | Notes |
|------|--------|--------|-------|
| A | `/login` | PASS | Form OK |
| A | `/dashboard` | PASS | KPIs + charts |
| A | `/guide`, `/guide/local` | PASS | Wrapped in `.page-list` |
| B | `/contacts` | PASS | Server-side DataTables |
| B | `/contacts/create` | PASS | `.page-stack` |
| B | `/contacts/import` | PASS | Duplicate H1 removed |
| B | `/contacts/duplicates` | PASS | Empty state partial |
| B | `/customer-groups` | PASS | |
| C | `/chat` (+ WA/Messenger/IG) | PASS | Channel pages load (IG/Messenger gated by Settings) |
| D | `/campaigns` | PASS | Wizard opens |
| D | `/email-manager` | PASS | |
| D | `/emails/send`, `/emails/bulk` | PASS | Duplicate in-page titles removed |
| D | `/templates`, `/templates/create` | PASS | Empty state upgraded |
| E | `/automations`, create | PASS | Empty state + `.page-stack` |
| E | `/sequences`, create | PASS | |
| E | `/keywords`, create | PASS | |
| E | `/queue` | PASS | |
| F | `/analytics` | PASS | |
| F | `/reports`, campaigns, delivery | PASS | |
| G | `/users`, create | PASS | |
| G | `/roles` | PASS | Save moved to `header_actions` |
| G | `/settings` | PASS | |

**Stubs (documented, not bugs):** SMS campaign “Coming soon”; no template edit route; Messenger/IG need Settings setup.

---

## UI changes (visual upgrade 2C)

Shared (brand tokens preserved — Onest/DM Sans, `--brand-*`):

- [`public/assets/css/app.css`](../public/assets/css/app.css) — stronger page header hierarchy, card hover, filter-bar polish, empty-state pattern, page-rise motion, table header density
- [`public/assets/css/sidebar.css`](../public/assets/css/sidebar.css) — brand strip gradient
- [`app/Views/partials/empty_state.php`](../app/Views/partials/empty_state.php) — reusable empty state
- Roles / guide / import / duplicates / forms / emails / empty lists aligned to `header_actions` + `.page-list` / `.page-stack`

---

## Pass 2 — Functional fixes this run

| # | Issue | Status |
|---|--------|--------|
| 1 | Localhost DB pointed at missing `sateri_connect` | **FIXED** → `apiwa` in `Database.php` |
| 2 | New contacts (NULL `last_message_at`) buried / invisible on default list | **FIXED** — default order by `c.id` DESC (`Contacts.php` + `contacts.js`) |
| 3 | Roles primary Save buried in sticky footer | **FIXED** — `header_actions` + `form="rolesMatrixForm"` |
| 4 | Duplicate page titles on Import / Email send-bulk | **FIXED** |
| 5 | Sequences list delete used native `confirm()` form (inconsistent UX) | **FIXED** — SweetAlert `data-confirm-delete` like other modules |

---

## Prior critical blockers (2026-07-24) — still closed

See history below; items 1–10 from the July audit remain **FIXED**.

---

## Remaining medium follow-ups

1. Keyword “contains” over-match / reply-loop guard  
2. Automation delay / SSRF hardening on webhook actions  
3. Guide permission gate  
4. Deploy on dedicated WhatsApp host (DocumentRoot = `public/`)  
5. Local inbound chat needs public HTTPS webhook (ngrok) — see [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md)  
6. Template edit route (product gap)  
7. SMS campaigns (explicit stub)

---

## Historical scorecard (2026-07-24 syntax/static audit)

| Module | Syntax | Function | Production ready | One-line reason |
|--------|--------|----------|------------------|-----------------|
| Dashboard | OK | OK | **YES** | Stats + perms OK |
| Settings (Cheerio/SMTP) | OK | OK | **PARTIAL** | Encrypt OK; verify token plaintext |
| Templates sync | OK | OK | **YES** | Sync works |
| Contacts | OK | OK | **YES** | CSV + tags; list sort fixed 2026-08-02 |
| Campaigns | OK | OK | **YES** | Wizard wired |
| Live Chat | OK | OK | **YES** | BS5 modal via APP |
| Webhooks | OK | OK | **YES** | Inbound media → `media/serve` |
| Queue | OK | OK | **YES** | Atomic claim |
| Keywords / Bot | OK | PARTIAL | **PARTIAL** | Contains over-match |
| Automations | OK | OK | **PARTIAL** | Birthday once/day |
| REST API | OK | OK | **YES** | JWT `api_*` only |
| Users / Roles | OK | OK | **YES** | Super-admin assign locked |
| Reports | OK | OK | **YES** | View/export perms OK |

### Critical blockers — status (retested Jul 2026)

| # | Issue | Status |
|---|--------|--------|
| 1 | Contacts CSV `file` vs `csv_file` | **FIXED** |
| 2 | Contacts tags `tag_ids` vs `tags` | **FIXED** |
| 3 | Campaigns schedule / send_now / audience on save | **FIXED** |
| 4 | Scheduled start queues all contacts | **FIXED** |
| 5 | Campaign custom variables not posted | **FIXED** |
| 6 | Birthday re-fires every cron | **FIXED** |
| 7 | ApiAuth writes web `user_id` | **FIXED** |
| 8 | Users can assign super-admin | **FIXED** |
| 9 | Queue no atomic claim / stuck processing | **FIXED** |
| 10 | Chat BS5 modal + media_url null | **FIXED** |

---

## Deploy reminder

See `docs/DEPLOY_YEVLE.md` — DocumentRoot must be `public/`, `CI_ENVIRONMENT=production`, webhook URL on the WhatsApp app host only.
