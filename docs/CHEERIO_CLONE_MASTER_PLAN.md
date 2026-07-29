# Cheerio AI Clone — Deep Product Plan + Master Build Prompt

**Date:** 29 Jul 2026  
**Target product:** Feature-parity WhatsApp-first omnichannel marketing / CRM / automation platform inspired by [app.cheerio.in](https://app.cheerio.in)  
**Local base for this workspace:** Sateri Connect (`c:\wamp64\www\sateri_connect`)  
**Research method:** Cheerio marketing + help docs, live SPA static analysis (`app.cheerio.in` React bundle + CSS), comparison against existing Sateri Connect modules  

> **Important**
> - Do **not** copy Cheerio brand name, logo, fonts-as-trademark, or proprietary assets. Build your own brand (e.g. Sateri Connect / ElintOm).
> - Goal = **feature + UX parity**, not illegal IP theft.
> - Password shared in chat for research should be **rotated** after this work. Never commit credentials.

---

## 0. Executive verdict

Cheerio is a large **React SPA** (Create React App on S3/CloudFront) talking to `newprod.api.cheerio.in`. It is an **omnichannel conversational CRM**:

WhatsApp + Email + SMS + RCS + Instagram + Messenger + Voice + LinkedIn, with **Team Inbox**, **broadcast campaigns**, **visual workflows**, **AI assistants**, **e‑commerce integrations**, **catalog/payments**, and **billing**.

**Sateri Connect already covers ~55–65% of the core WhatsApp/Email CRM path** (contacts, templates, campaigns wizard, chat inbox, keywords, automations canvas, queue, analytics, RBAC, dual Cheerio/Meta provider).  

To become a “proper Cheerio-class clone”, you still need a phased build of inbox depth, workflow nodes, multi-channel, AI, commerce, and SaaS billing — see gap matrix below.

---

## 1. What Cheerio actually is (product map)

### 1.1 Product positioning

| Pillar | Meaning |
|--------|---------|
| WhatsApp-first | Broadcasts, templates, 24h session, CTWA ads, catalog, UPI payments |
| Omnichannel | Same CRM + inbox for IG / Messenger / Email / SMS / RCS / Voice |
| Automation | No-code workflow canvas (events → conditions → actions) |
| Team support | Shared inbox, assignment, FRT, chatbot intervene |
| Growth | Sequences/drips, smart forms, Meta ads, Shopify/Woo flows |
| Agentic AI | AI assistants, company brain, voice agents (newer surface) |
| SaaS | Plans, subscriptions, API keys, webhooks, multi-agent seats |

### 1.2 Tech / UI fingerprint (observed)

| Item | Observed |
|------|----------|
| Front-end | React SPA, React Bootstrap + **MUI** (DataGrid, Autocomplete, Charts, DateTimePicker, Drawer) |
| Hosting | Amazon S3 + CloudFront (`app.cheerio.in`) — client routes like `/login` 404 on raw S3 key unless SPA fallback |
| Theme color | `#A470FF` (meta theme), accents `#8e53f7`, `#9357ff`, `#9878ca`, deep purple `#4b3786` |
| Fonts | **DM Sans**, **Onest**, display **Dela Gothic One** |
| Layout | Left sidebar + top chrome; creator workspace under `/creator/*`; settings under `/settings/*` |
| Backend APIs | `newprod.api.cheerio.in`, tracking `redirect.api.cheerio.in`, help on `cheerioai.com` |
| WhatsApp embed | Meta Embedded Signup (FB SDK + WA_EMBEDDED_SIGNUP) |
| Analytics | GTM + GA + Microsoft Clarity |

### 1.3 Sidebar / module inventory (from live SPA routes)

Grouped the way a clone should present them:

#### A) Home / Agent

| Route | Module | Function |
|-------|--------|----------|
| `/creator/agentic-home` | Agentic home | AI-first landing / task hub |
| `/creator/business-agent` | Business agent | Business AI agent |
| `/creator/analytics`, `/creator/globalAnalytics`, `/creator/agentAnalytics` | Analytics | Channel + agent performance |
| `/creator/waPaymentAnalytics` | WA payments analytics | Catalog/payment funnel |

#### B) Team Inbox / Replies

| Route | Module | Function |
|-------|--------|----------|
| `/creator/whatsappreplies` | WhatsApp Team Inbox | Shared inbox, statuses, assignment |
| `/creator/messengerInbox`, `/creator/messengerReplies` | Messenger | FB Messenger inbox |
| `/creator/instagramReplies` | Instagram | IG DM inbox |
| Chat statuses (docs) | OPEN, PENDING, RESOLVED, CHATBOT, INTERVENED, ACTIVE, EXPIRED, FRT EXCEEDED, UNASSIGNED, CTWA | Inbox filters + lifecycle |

**Inbox lifecycle (must clone):**  
Incoming → time-trigger → keyword → default workflow → online agent → else unassigned OPEN.

#### C) Data — Contacts & audiences

| Route | Module | Function |
|-------|--------|----------|
| `/creator/contacts/contacts` | Contacts | CRM directory |
| `/creator/contacts/labels` | Labels | Segments / lists |
| `/creator/importcontacts`, `/creator/uploadContacts` | Import | CSV / bulk upload + attribute mapping |
| `/creator/excelLabel` | Excel label | Label from spreadsheet |
| `/creator/vendorsList` | Vendors | Vendor lists |
| `/settings/attributes` | Custom attributes | Contact field schema |

#### D) Marketing — Broadcasts / Templates / Sequences

| Route | Module | Function |
|-------|--------|----------|
| Campaign flows (SPA + docs) | Broadcast campaigns | WA/Email/SMS/RCS send + schedule + analytics |
| `/creator/templates/*` | Template library | WhatsApp / Email / RCS / drafts / my templates |
| `/creator/sequences/*` | Sequences | Multi-step drip (channel mix) + analytics |
| `/creator/timeTriggers` | Time triggers | Scheduled automation triggers |
| `/creator/announcements` | Announcements | Broadcast-style announcements / recurring |

#### E) Automation

| Route | Module | Function |
|-------|--------|----------|
| `/creator/workflow`, `/workflow` | Visual workflow builder | Drag-drop nodes + connectors |
| `/creator/keywords`, `/creator/chatbot` | Keywords / chatbot | Keyword menus + Dialogflow-style bot |
| `/creator/ai-assistants` | AI assistants | GPT/Llama assistants on WA/IG |
| `/creator/company-brain` | Company brain | Knowledge base for agents |
| Workflow nodes (docs) | Events + actions | See §1.4 |

#### F) Lead capture & commerce

| Route | Module | Function |
|-------|--------|----------|
| `/creator/smartForms` | Smart forms | Embed / popup / shareable WA forms |
| `/creator/catalog` | Catalog | WA commerce catalog |
| `/creator/codPrepaid` | COD / prepaid | Order payment preference flows |
| `/creator/qr-generation` | QR | WA chat QR / deep links |
| `/creator/metaAds` | Meta ads | CTWA / CTD ads + pixel |
| Shopify / Woo routes | E-com sync | Abandoned cart, order events, flows |
| `/creator/manageIntegrations/*` | Integrations | Shopify, Woo, Kylas, ChatGPT |

#### G) Email stack

| Route | Module | Function |
|-------|--------|----------|
| `/creator/templates/emailTemplates` | Email templates | Builder |
| `/creator/manageSender/*` | Senders + domains | Domain verify, sender identities |
| `/creator/emailVerifier` | Email verifier | List hygiene |
| Sequences email analytics | Drip analytics | Open/click |

#### H) Newer channels

| Route | Module |
|-------|--------|
| `/creator/rcstemplates/create` | RCS templates |
| `/creator/linkedinsequences/*` | LinkedIn sequences |
| `/creator/voice-agents`, `voice-campaigns`, `voice-numbers`, `voice-compliance` | Voice AI / dialer surface |

#### I) System / SaaS

| Route | Module |
|-------|--------|
| `/settings/apikey` | API keys |
| `/settings/webhooks` | Webhooks |
| `/settings/people` | Team / agents |
| `/settings/preferences`, `abondonedChat`, `mmlite` | Chat prefs / MM Lite |
| `/settings/planDetails`, `subscriptions`, `paymentDetails` | Billing |
| `/settings/domainDetails` | Domains |
| `/premiumplans`, `/premiumpricing` | Pricing walls |
| `/creator/setup-workspace`, `/creator/discoverPlatforms` | Onboarding |
| `/partner/dashboard` | Partner program |

### 1.4 Workflow nodes to implement (parity checklist)

**Event / trigger nodes**

- Incoming WhatsApp (optional keyword match)
- Campaign sent (drip after broadcast)
- Shopify event (order created/canceled, abandoned cart, …)
- Facebook lead / Meta ads lead
- Kylas event create / update
- Pabbly event
- Time trigger / schedule
- Contact created / attribute changed (recommended)

**Action / logic nodes**

- Response message (text/media + quick replies / list + save reply → variable)
- Send WhatsApp template (map attributes)
- Send Email (template or custom HTML)
- Set condition (sent/delivered/opened/clicked after delay)
- Attribute condition (AND/OR)
- Time delay (+ “skip user reply”)
- Assign chat to bot (Dialogflow / AI)
- Assign to agent (load-balanced)
- Update chat status
- Update attribute
- Webhook / HTTP request (recommended for clone)
- Humanize delay (seen in SPA strings)

---

## 2. UI / UX design system to clone (without copying brand)

Build a **purple-forward SaaS console**, not AdminLTE-green WhatsApp clone.

### 2.1 Visual tokens (own-brand equivalents)

```css
:root {
  --brand-50: #f6f1ff;
  --brand-100: #ebe0ff;
  --brand-400: #9878ca;
  --brand-500: #8e53f7;   /* primary CTA */
  --brand-600: #9357ff;
  --brand-800: #4b3786;   /* sidebar active / headings */
  --ink: #1a1a1a;
  --muted: #6b7280;
  --surface: #fafafa;
  --border: #e6e6e6;
  --danger: #dc3545;
  --success: #198754;
  --radius: 12px;
  --sidebar-w: 260px;
  --font: "DM Sans", "Onest", system-ui, sans-serif;
  --font-display: "Onest", "DM Sans", sans-serif;
}
```

### 2.2 Layout rules

1. **Left sidebar** — grouped sections (Home, Inbox, Data, Marketing, Automation, Commerce, Settings). Soft purple active state, icons + labels.
2. **Top bar** — workspace switcher, search, notifications, plan chip, avatar.
3. **Page chrome** — title left, primary actions right (matches your existing `header_actions` standard).
4. **Lists** — filter bar + dense table / MUI-like DataGrid feel (status chips, date ranges Last 7/30 days).
5. **Builders** — full-bleed canvas for Workflow, Template preview, Campaign wizard stepper.
6. **Inbox** — 3-pane: conversation list (filters) | thread | contact sidebar (attributes, labels, assign).
7. **Empty states** — illustration + one CTA (Create campaign / Connect WhatsApp).
8. **Motion** — subtle sidebar highlight, toast slide, stepper progress (2–3 intentional motions max).

### 2.3 Interaction patterns to match

- AJAX everywhere (no full reloads for send/assign/filter).
- Toast + inline validation.
- Draft → Preview → Schedule/Send wizards.
- Toggle enable/disable on workflows/keywords.
- Duplicate / edit / delete row actions.
- Real-time or near-real-time inbox polling.
- Provider onboarding via Meta Embedded Signup (optional) **or** API key paste (your current Cheerio Direct path).

---

## 3. Gap analysis: Cheerio vs Sateri Connect (today)

| Area | Cheerio | Sateri Connect | Gap |
|------|---------|----------------|-----|
| Auth / RBAC | Multi-agent seats, billing roles | Users + roles + perms | Medium (billing seats) |
| Contacts + labels + attributes | Rich attributes, excel labels | Contacts, tags, groups, CSV | Medium (custom attribute schema UI) |
| WA templates | Create + manage in-app | Sync + create + carousel checks | Low |
| Broadcast campaigns | Multi-channel | WA + Email wizard | Medium (SMS/RCS) |
| Sequences / drips | First-class | Email drips partial | High |
| Team Inbox depth | Status machine + FRT + CTWA | Basic omnichannel chat | **High** |
| Keywords / chatbot | + Dialogflow assign | Keywords CRUD | Medium |
| Visual workflows | Rich node library | Cheerio-style canvas + sync | **High** (node parity) |
| AI assistants / company brain | Yes | No | **High** |
| Smart forms | Embed/popup/link | Keywords only | High |
| Catalog + WA payments | Yes | No | High |
| Meta CTWA ads | Yes | No | High |
| Shopify / Woo deep flows | Yes | Integration stubs/docs only | High |
| Email verifier / domain sender | Yes | Email manager partial | Medium |
| RCS / LinkedIn / Voice | Yes | No | Later phase |
| Analytics | Global + agent + payment | Dashboard + reports | Medium |
| Billing / plans | SaaS | None | Later / optional |
| Multi-tenant | SaaS orgs | Subdomain DB switch | Different model (OK) |
| Provider | Cheerio-hosted WABA | Cheerio Direct **or** Meta Cloud | Advantage (keep both) |

**Bottom line:** Do **not** rebuild from zero. Evolve Sateri Connect into Cheerio-class product in phases.

---

## 4. Recommended build strategy

### Phase 0 — Product decisions (1–2 days)

1. Brand name, domain, purple design tokens.
2. Channels in MVP: **WhatsApp + Email + Team Inbox** only.
3. Provider strategy: keep **Meta Cloud + Cheerio Direct** dual mode.
4. Tenant model: keep subdomain DB (B2B white-label) **or** add org_id SaaS later.
5. Legal: own templates/docs; no Cheerio trademarks.

### Phase 1 — UX shell parity (1–2 weeks)

- Restyle sidebar/topbar to purple SaaS look (fonts DM Sans/Onest).
- Nav groups renamed to Cheerio-like IA (Inbox / Data / Marketing / Automation / Commerce).
- Standard page header everywhere (already started).
- Empty states + chips + denser tables.

### Phase 2 — Team Inbox 2.0 (2–3 weeks) — highest ROI

- Status model: OPEN / PENDING / RESOLVED / CHATBOT / INTERVENED / ACTIVE / EXPIRED / UNASSIGNED / FRT / CTWA.
- Assignment: online agents, load balance, unassigned queue.
- Filters dropdown + search.
- Contact right rail: attributes, labels, notes, 24h timer.
- Canned replies + media.
- Intervene-from-bot flow.

### Phase 3 — Workflow node parity (3–4 weeks)

- Implement missing nodes from §1.4 on existing canvas.
- Condition + delay engine hardening.
- Shopify/Meta lead triggers behind integration flags.
- Import Cheerio graphs where useful; own runtime always.

### Phase 4 — Campaigns & sequences (2–3 weeks)

- Multi-channel campaign type enum (WA/Email/SMS later).
- Sequences builder (steps, delays, exit on reply).
- Unified analytics: sent / delivered / read / replied / failed / clicked.

### Phase 5 — Lead capture & commerce (3–4 weeks)

- Smart Forms (link + embed + webhook → contact + workflow).
- Catalog browse messages + payment link / UPI status webhooks.
- CTWA referral tracking field on conversations.
- Shopify abandoned-cart → workflow trigger.

### Phase 6 — AI layer (2–4 weeks)

- Company Brain (docs upload → embeddings or simple RAG).
- AI Assistants assignable in inbox / workflow.
- Optional voice later.

### Phase 7 — SaaS ops (optional)

- Plans, usage metering, API keys UI polish, partner dashboard.

---

## 5. Suggested information architecture (clone IA)

```
Dashboard
Inbox
  ├─ WhatsApp
  ├─ Instagram
  └─ Messenger
Data
  ├─ Contacts
  ├─ Labels / Groups
  ├─ Attributes
  └─ Import
Marketing
  ├─ Campaigns (Broadcast)
  ├─ Sequences
  ├─ Templates (WA / Email / RCS)
  └─ Announcements
Automation
  ├─ Workflows
  ├─ Keywords
  ├─ Time Triggers
  ├─ AI Assistants
  └─ Queue
Capture & Commerce
  ├─ Smart Forms
  ├─ Catalog & Payments
  ├─ QR / Chat links
  └─ Meta Ads
Integrations
  ├─ Shopify / Woo
  ├─ Webhooks
  └─ API Keys
Analytics
Settings (People, Preferences, Domains, Billing)
```

---

## 6. Data model additions (minimum for parity)

Add migrations (do not leave schema drift):

- `contact_attributes` / `contact_attribute_values` — custom fields
- `conversation_status`, `assigned_user_id`, `frt_due_at`, `ctwa_referral`, `intervened_at` on conversations
- `canned_replies`
- `sequences`, `sequence_steps`, `sequence_enrollments`
- `smart_forms`, `smart_form_fields`, `smart_form_responses`
- `catalog_products`, `payment_links`, `payment_events`
- `ai_assistants`, `ai_knowledge_docs`
- `integrations` (shopify/woo tokens)
- `usage_events` (if billing)

Keep existing: contacts, tags, templates, campaigns, messages, queue, automations.flow_graph, email_* tables.

---

## 7. Master prompt (copy-paste for Cursor / coding agents)

Use this as the **single source prompt** when building the clone on top of Sateri Connect.

```text
You are a senior full-stack engineer building a production WhatsApp-first omnichannel CRM called “Sateri Connect” (own brand — do NOT use Cheerio trademarks/logos).

GOAL
Build feature + UX parity with Cheerio AI (app.cheerio.in class product): Team Inbox, Contacts/Labels/Attributes, Template Library, Broadcast Campaigns, Sequences, Visual Workflows, Keywords, Smart Forms, Catalog/Payments, Integrations (Shopify/Woo/Meta), Analytics, AI Assistants — starting from the existing CodeIgniter 4 codebase in this repo.

NON-GOALS
- Do not scrape or copy Cheerio proprietary assets.
- Do not put DB credentials in .env; use Config\Database::applyBySubdomain().
- Do not invent a second page-header pattern; follow ui-page-header-standard (breadcrumb left, title+provider chip, header_actions right).
- Controllers stay thin; domain logic in app/Libraries/; shared JS in public/assets/js/; partials in app/Views/partials/.
- When adding DB columns/tables, add migrations in the same task.
- Prefer AJAX for inbox/campaign/workflow interactions.
- Never commit secrets. Ask before git commit.

STACK
- Backend: CodeIgniter 4, MySQL, php spark workers (queue/campaigns/automations)
- Providers: dual WhatsApp (Cheerio Direct API + Meta Cloud) via WhatsAppCloudAPI facade; Email via EmailProvider
- Frontend: evolve current AdminLTE/Bootstrap UI toward purple SaaS tokens (DM Sans/Onest, brand #8e53f7 / #4b3786), keep Bootstrap 5 unless a module truly needs a canvas lib
- Errors: ErrorPresenter + app_error.php only

DESIGN SYSTEM
- Purple primary SaaS console, light surfaces #fafafa, soft borders #e6e6e6, 12px radius
- Left grouped sidebar IA:
  Dashboard | Inbox (WA/IG/Messenger) | Data (Contacts/Labels/Attributes/Import) | Marketing (Campaigns/Sequences/Templates) | Automation (Workflows/Keywords/Time Triggers/AI/Queue) | Capture & Commerce (Smart Forms/Catalog/QR/Meta Ads) | Integrations | Analytics | Settings
- Inbox = 3-pane; Workflow/Template builders = full-bleed canvas
- Status chips, date-range filters, toasts, empty states with one CTA

PHASE ORDER (implement in this order; ship each phase testable)
Phase 1 — Visual IA shell + nav rename (no behavior break)
Phase 2 — Team Inbox 2.0 statuses + assignment + FRT + filters + contact rail
Phase 3 — Workflow node parity (incoming WA, template send, response message, conditions, delay, assign agent/bot, update attribute/status, email send, webhook; then Shopify/Meta triggers behind flags)
Phase 4 — Sequences + campaign analytics parity
Phase 5 — Smart Forms + Catalog/Payments + CTWA fields
Phase 6 — AI Assistants + Company Brain (RAG or simple doc Q&A)
Phase 7 — Billing/usage optional

INBOX STATUS MODEL (required)
OPEN, PENDING, RESOLVED, CHATBOT, INTERVENED, ACTIVE, EXPIRED, UNASSIGNED, FRT_EXCEEDED, CTWA
Routing order on inbound: time-trigger → keyword → default workflow → online agent → unassigned OPEN.

WORKFLOW
Extend existing automations flow_graph canvas. Each node must have clear JSON schema, validation, and runtime handler in AutomationEngine. Support enable/disable, duplicate, test-run logs.

CAMPAIGNS
Keep wizard: Name → Audience/Attributes → Template → Media → Schedule/Run.
Audience via labels/tags/attributes preview counts (AJAX).
Queue via existing message_queue + campaigns:process / queue:process.

TESTING
For every phase: migrations, focused PHPUnit/feature tests where practical, manual smoke (login → dashboard → inbox → campaign wizard → workflow toggle), and branded error screen still works.

DELIVERABLES PER PHASE
1) Short plan of files to touch
2) Migrations + code
3) UI matching design tokens
4) Test notes / commands run
5) Update docs/CHANGELOG_YYYY-MM-DD.md

START NOW with Phase 1 only unless I ask otherwise: restyle shell + IA navigation to match the design system above without breaking existing routes/permissions.
```

---

## 8. Phase-1 immediate prompt (smallest next step)

```text
Phase 1 only on Sateri Connect:
1. Add CSS variables for purple SaaS theme in public/assets/css/app.css + sidebar.css (do not break existing btn-wa; map btn-wa to brand purple or dual-class).
2. Switch fonts to DM Sans + Onest in layouts/main.php.
3. Restructure $navGroups labels/order to Cheerio-like IA (Inbox/Data/Marketing/Automation/Capture/System) while keeping the same URLs and can() permissions.
4. Keep header_actions standard.
5. No DB changes.
6. Smoke: login pages render, sidebar groups correct, dashboard OK.
```

---

## 9. Manual QA checklist (when you can log into Cheerio UI)

Use a browser (SPA needs JS). After login, screenshot and tick:

- [ ] Sidebar groups and every leaf screen
- [ ] WhatsApp inbox filters + assign + status changes
- [ ] Contact upload + attribute mapping
- [ ] Template create (WA carousel / variables)
- [ ] Campaign launch + live analytics
- [ ] Sequence create
- [ ] Workflow canvas: add each node type, save, toggle
- [ ] Keywords + time triggers
- [ ] Smart form publish + response → contact
- [ ] Catalog message + payment link
- [ ] Shopify abandoned cart trigger
- [ ] AI assistant reply in inbox
- [ ] Settings: people, API key, webhooks, plan
- [ ] Mobile width: sidebar collapse, inbox panes

> Automated login from this environment failed: CloudFront/S3 SPA + opaque auth API (`userLogin` constants exist in bundle; public REST login paths returned 404). Browser login is required for pixel QA.

---

## 10. Effort estimate (realistic)

| Phase | Calendar (1 senior + AI) |
|-------|---------------------------|
| 1 Shell / IA | 3–5 days |
| 2 Inbox 2.0 | 2–3 weeks |
| 3 Workflow parity | 3–4 weeks |
| 4 Sequences / analytics | 2–3 weeks |
| 5 Forms / commerce / CTWA | 3–4 weeks |
| 6 AI | 2–4 weeks |
| 7 Billing | 2–3 weeks |
| **MVP Cheerio-like (Ph 1–4)** | **~2–2.5 months** |
| **Full parity (Ph 1–6)** | **~4–5 months** |

---

## 11. Relation to this repo’s 28 Jul changelog

Already done and reusable for the clone path:

- Page header + section UI standard
- Campaign wizard (WA + Email)
- Branded error screens
- Multi-tenant DB without `.env` credentials
- Templates validation / provider error clarity

Treat those as **Phase 0 complete**. Next value is **Inbox 2.0 + workflow node parity**, not another campaign rewrite.

---

## 12. Security note

Credentials were used only for attempted API login research. **Change the Cheerio account password** and avoid pasting production passwords into chat. Store secrets in a password manager; app secrets stay encrypted in Settings / `Database.php` switch cases only.
