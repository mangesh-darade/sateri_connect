# Daily changelog — 29 Jul 2026

## Implemented (Cheerio clone plan — Phase 1–4 core + hardening)

### Phase 1 — Layout / design
- Purple SaaS theme tokens (`--brand-500 #8e53f7`, Onest + DM Sans)
- Nav IA: **Inbox → Data → Marketing → Automation → Analytics → System**
- Keywords under Automation; Team Inbox elevated; Sequences nav item
- Visual unify: charts, errors, builder, SweetAlert → brand purple

### Phase 2 — Team Inbox 2.0
- Migration: status VARCHAR + `frt_due_at`, `intervened_at`, `ctwa_referral`
- `InboxStatus` library + Chat filters (active / expired / CTWA / FRT / statuses)
- Status API + UI: Resolve/Reopen + status dropdown (open/pending/intervened/chatbot/resolved)
- `ConversationSeeder` dummy chats (`91999900xxxx`)

### Phase 3–4 — Workflows + Sequences
- Delay nodes pause/resume via `automation_delayed_jobs`
- Actions: `send_email`, `assign_bot`, `update_chat_status`; condition: `attribute_condition`
- `campaign_sent` on WhatsApp campaign start
- Sequences module `/sequences` (CRUD, enroll, due steps, exit-on-reply)
- Fullscreen workflow builder + Save toast z-index fix

### Permissions
- New: `sequences.view|create|edit|delete`, `guide.view`
- Guide + Sequences gated; nav respects `can()`
- `PermissionSeeder` re-syncs **system roles only** (custom roles preserved)
- Audit command: `php spark permissions:audit`

### Critical fix
- Delay resume now resolves `next_on_true` as **step_order → real rule id** (no restart/loop)
- Terminal delay with null resume does not restart the graph
- Atomic claim on delayed jobs (`affectedRows`)

### Guides updated
- `GUIDE_LOCAL.md` — Inbox 2.0, Workflows, Sequences, migrate/seed, tests, path → `sateri_connect`
- `GUIDE_PRODUCTION.md` — cron notes, permissions, smoke steps
- `USER_GUIDE.md` — module sections aligned (see below)

### Tests
- `php spark inbox:test` → 20 pass
- `php spark workflow:test` → 24 pass
- `php spark permissions:audit` → 16 pass (+ nested suites)
- `php spark functional:smoke` → 63 pass

## How to verify locally
1. `php spark migrate`
2. `php spark db:seed PermissionSeeder`
3. `php spark functional:smoke`
4. Hard-refresh browser (`Ctrl+F5`) — builder CSS `?v=5`, chat.js `?v=inbox2`
5. Worker: `php spark automations:process` (delays + sequences)
