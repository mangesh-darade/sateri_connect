# Feature roadmap

Built one item at a time, in the order below. Each item is shippable on its own.

## Rules for every item
- **Security:** permission check via `can()` / `requirePermission()`, CSRF on POST, tenant-scoped queries only, no raw provider errors to the UI, `log_activity()` on state changes. Customer content sent to an AI provider is limited to what the feature needs, and tenant keys stay encrypted (`EncryptionService`).
- **Performance:** every list is paginated or capped, every new filter column gets an index in its migration, long work goes through the existing queue or cron commands, never inline in a request.
- **Maintainability:** reuse an existing service before adding one. One new table only when no existing table fits. A new service returns `['ok','status','message','data']`. No interfaces or abstractions until a second implementation exists.
- **Schema changes:** migration + `app/Database/Schema/tables.php` + Model, in the same change.
- **UI text** is English. Page JS lives in `public/assets/js/`, uses `$.ajax` and `APP.toast()`.

## Already in the product (not on this list)
Condition / if-else node in the flow builder, per-module search endpoints, Gemini copilot (suggest, summary, email writer, auto-reply), outbound HTTP/webhook node.

## Phase 1: quick wins
| # | Item | Plan |
|---|------|------|
| 24 | Global search | **Done.** `GET /search`, topbar box, press `/` to focus. |
| 27 | Agent performance | **Done (no resolution time).** `/reports/agents`: conversations, answered, avg first response, replies, open now, per assigned agent. Resolution time and per-sender stats need `messages.user_id` / `conversations.closed_at`, so they are a later schema change. |
| 22 | Business hours + away message | **Done (WhatsApp only).** `BusinessHours` library, Settings → Business Hours tab, hook in `Webhooks::handleInboundMessage`. SLA timers and round-robin assignment are still open (Phase 3). |
| 30 | Meta quality / limit alerts | **Done.** Webhook alerts and RED handling already existed. Added YELLOW / recovery / limit-change alerts in `WhatsAppConsentService::refreshQualityRating` and a `whatsapp:quality-check` cron (every 30 min, all clients). |
| 14 | A/B split node | **Done.** A condition preset `ab_split` (percent to path A, sticky per contact and node) in `AutomationEngine::evaluateCondition`, plus palette and inspector in the flow builder. No per-path result report yet. |

## Phase 2: highest value
| # | Item | Plan |
|---|------|------|
| 5 | Knowledge-base bot | **Done (v1: text FAQ entries).** `/knowledge-base` (permission `ai.use`), table `kb_entries`, `KnowledgeBase` library. Matching is word overlap in PHP, not MySQL FULLTEXT, so Marathi / Hindi work. The top 3 entries are added to the prompt of `AiService::generateReply` and `suggestReplies`. Still open: PDF / website import, vector search. |
| 12 | Human handoff | **Done.** `AiService::handoff()` runs when the customer asks for a person, the bot hits its reply cap, or the model answers `[[HANDOFF]]` (cannot answer). It assigns the chat to the contact's agent or the hand-over agent set in Settings → AI, pauses the bot for 2 hours and notifies the team. Round-robin assignment is still open (Phase 3). |
| 13 | AI provider choice + per-tenant key | Small provider switch inside `AiService` (Gemini now, one more later). Usage billed through `WalletService`. |
| 2 | Payment link in chat | **Done for the inbox (needs the client's own Razorpay keys).** `RazorpayService` is the platform's wallet account, so it is not reused: `CustomerPaymentLinks` uses per-client keys (Settings → Payment Links). Paid status comes from `payments:reconcile` (every 10 min), no public webhook. Still open: automation action, per-contact link history, a webhook for instant status. |
| 1, 16 | Shopify / WooCommerce | Webhook receivers mapping order / cart events to tags and automation triggers. Catalog messages after this. |
| 3 | Click-to-WhatsApp ads tracking | **Done (no CAPI).** The inbox CTWA filter and `conversations.ctwa_referral` existed but nothing filled them. `Webhooks::handleInboundMessage` now stores the ad id (first touch) and `/reports/ctwa` groups conversations by ad. Meta Conversions API feedback is still open (needs a dataset id and token). |

## Phase 3 progress
| # | Item | State |
|---|------|-------|
| 22 | Auto-assign + SLA | **Done.** Settings → Inbox Rules: auto-assign to the least busy agent in a pool (not strict round-robin, so it needs no counter), and a first-response target. The clock starts on the first unanswered inbound message (`Webhooks`), any non-broadcast reply clears it (`MessageModel::clearResponseDue`), and `inbox:sla-check` (every 5 min) raises one alert per overdue chat. WhatsApp only; the timer ignores business hours. |
| 23 | CSAT survey | **Done (WhatsApp, text 1-5).** Settings → Inbox Rules. Resolving a chat sends the question (`CsatSurvey::ask`, once a day per chat, needs the 24 h window); a bare 1-5 reply is recorded before the bots see it (`Webhooks`); ratings 1-2 alert the agent; average per agent is a column in Reports → Agent Performance. No per-chat rating list and no CSAT webhook event yet. |
| 19 | Outgoing webhooks | **Done (3 events).** `/webhook-endpoints` (Settings → Developer API Keys has the link). Events `message.received`, `message.status`, `payment.paid`. `OutgoingWebhooks::dispatch` only queues rows; cron `webhooks:deliver` (every minute) sends with HMAC signature and 5 retries. https:443 and public IPs only, no redirects. Still open: `contact.opted_out` and other events, per-endpoint delivery history page. |

## Phase 3: growth
Sales pipeline (20), tasks (21), SLA and round-robin assignment (22), CSAT (23), WhatsApp Flows (18), AI flow builder (6), lead scoring (9), outgoing webhooks (19), more triggers (15), reseller branding (31), 2FA (33).

## Phase 4: later / needs a product decision
Voice transcription (10), smart send time (11), cohort and ROI analytics (28, 29), SMS / Telegram / RCS channels (25), widgets (26), PWA (35), Marathi / Hindi UI (36), SDKs (32), ops dashboard (34).

## Always in parallel
Fix `TenantDatabaseGuardTest` bootstrap and add regression tests for wallet and webhooks (37).
