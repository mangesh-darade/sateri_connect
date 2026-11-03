# Social AI video, metrics and Ads (plan)

Status: **plan only, nothing built yet.** Builds on `docs/SOCIAL_SCHEDULER.md` and `docs/ROADMAP.md`. Follow the roadmap rules (permissions, CSRF, tenant scope, `log_activity()`, migration + `tables.php` + Model in the same change, English UI, services return `['ok','status','message','data']`).

Platform rules and App Review requirements change often. Re-check the official Meta Marketing API, Conversions API and Google docs before building each step.

## Where we are

- Publish to Facebook, Instagram, YouTube, LinkedIn and WhatsApp broadcast works in code, but **has not run against live Meta/Google yet**.
- `social_posts.status` already allows `pending_approval` and has `approved_by`, but no code uses them. No approve screen, no permission.
- `social_post_metrics` is described in the scheduler doc but the table does not exist. Nothing reads insights.
- AI (`SocialCaptionService` via `SocialAiClient`) writes captions from a text topic only. It never sees the video.
- Ads: nothing. No `ads_management` scope. `conversations.ctwa_referral` stores the ad id and `/reports/ctwa` groups by it.

## Step 1: Live test + approval flow

**Phase 0 (start now, long lead, parallel to all code):**
- Meta app: Business Verification, Advanced Access for `pages_manage_posts`, `pages_read_engagement`, `instagram_content_publish`, `instagram_manage_insights`, and later `ads_management`, `ads_read`, `business_management`. Request the ads scopes in the same review round so the app is reviewed once.
- Google: YouTube API audit + quota extension (default ~6 uploads/day for all clients combined).
- LinkedIn: Community Management API access.
- Meta test Page, Instagram Business test account and a sandbox ad account (see `docs/SOCIAL_TEST_ACCOUNTS.md`).

**Live test checklist** (one tenant, test accounts only): connect via OAuth, scan a Drive folder, schedule one Reel to FB + IG + YouTube, confirm the public `social/media/{token}` URL works on a public `app.baseURL`, force a failure (expired token) and check the message and retry, cancel and reschedule from the calendar, run `social:publish` twice at once and confirm no double post.

**Approval flow (code):**
- Permission `social.approve` (seed + `permissions:audit`). Setting "Require approval" in Social Setup, default on.
- Creating or bulk-scheduling with approval on sets the post to `pending_approval`; its targets stay un-schedulable. `social.approve` users get an Approve / Reject (with reason) action in the calendar and a "Waiting for approval" filter.
- Approve sets `approved_by`, status `scheduled`. Reject returns it to `draft` with the reason. Notify the creator (existing notification path). `log_activity()` on both.
- `SocialPublishService` refuses any target whose post is not `scheduled`. That is the safety net.
- Schema: add `rejected_reason VARCHAR(255) NULL`, `approved_at DATETIME NULL` to `social_posts` (migration + `tables.php` + `SocialPostModel`).

## Step 2: Metrics sync

- Table `social_post_metrics` (target_id, captured_at, views, likes, comments, shares, reach, watch_time_sec) with index (`target_id`, `captured_at`). Migration + `tables.php` + Model.
- `SocialInsightsService`: per platform, Facebook video/Reel insights, Instagram media insights (`instagram_manage_insights`), YouTube Analytics. Map provider errors, never show raw ones.
- Cron `social:insights` every 6 h, via `EachTenant` like the other workers. Fetch posts published in the last 30 days only, then stop (cap API calls, one snapshot per target per run).
- UI: per-post numbers on the calendar card, a simple "Top posts" table (views, likes, comments per platform), CSV export.
- Metrics are the input for Step 3 (what worked) and Step 4 (boost winners).

## Step 3: AI video understanding, captions and repurpose

**Understand the video** (`SocialVideoAnalyzer`):
- On Drive scan (or on demand from a post), send the video to Gemini video input and store the result on `social_media_assets.ai_analysis` (JSON: summary, topics, spoken-language transcript, 3 hook ideas, suggested thumbnail timestamp, content warnings). Add column + migration + `tables.php` + Model.
- Limit: files above the model's size/length limit are skipped with a clear message ("too long to analyse"). The call is queued (cron), never in the page request.
- Privacy: say in Social Setup that video is sent to the selected AI provider. Opt-in switch, default off.

**Captions:** `SocialCaptionService::suggest` gets the analysis as extra context, plus the top 3 captions from Step 2 metrics as "what worked". Platform-wise title, description, tags for YouTube. Keep `HONESTY` rules. Output stays a suggestion the user picks.

**Plan assistance:** the auto-schedule wizard can ask AI for a content mix over N days. Best-time slots come from the account's own metrics once there are about 20 posts, industry defaults before that. The result is a draft calendar for approval, never auto-published.

**Repurpose** (`SocialRepurposeService`, ffmpeg): produce 9:16, 1:1 and 16:9 versions and a cut to a limit. Needs ffmpeg/ffprobe on the server (check at setup; the doc already lists `SocialMediaValidator`). Run in the queue, store outputs as new assets linked to the parent.

## Step 4: Ads v1 (Click-to-WhatsApp)

**What this is:** an ad on Facebook / Instagram (feed, Reels, Stories) whose button opens a WhatsApp chat. WhatsApp itself is not an ad platform through the API. The ad is paid to Meta from the client's own ad account and payment method, **not** from the Sateri wallet.

**Connect:** extend Facebook Login scopes with `ads_management`, `ads_read`, `business_management` (only after Meta approves them). Client picks one ad account and the Page + WhatsApp number to use. Store in `social_accounts` (platform `meta_ads`) or a new `ad_accounts` table; tokens encrypted like the others.

**Tables** (tenant DB, each with migration + `tables.php` + Model):
- `ad_campaigns`: id, ad_account_id, name, objective (`messages|traffic`), daily_budget_minor, currency, starts_at, ends_at, status (`draft|paused|active|ended|rejected|error`), meta_campaign_id, meta_adset_id, meta_ad_id, source_target_id (boosted post), created_by, approved_by, last_error, timestamps. Index on status.
- `ad_creatives`: id, campaign_id, headline, primary_text, cta, media/post reference, ai_generated flag.
- `ad_metrics_daily`: campaign_id, date, spend_minor, impressions, reach, clicks, conversations, cost_per_conversation_minor. Unique (`campaign_id`, `date`).

**Flow:**
1. **Create from scratch** (wizard) or **Boost** a published post (button on the calendar card, uses `external_id`).
2. Objective Messages, WhatsApp destination, location + age + (simple) interests, daily budget, dates. AI proposes 3 headline / text / CTA variants, same honesty rules, with Meta's special ad categories prompt (credit, jobs, housing, social issues).
3. `AdsService` creates campaign, ad set and ad with **status PAUSED** on Meta.
4. A review screen shows exactly what will run, the daily budget and an estimated max total. Activating needs an explicit confirm by a user with `ads.activate`; separate from `ads.create`.
5. Safety: per-tenant max daily budget setting (hard cap, enforced server-side), no budget raise above the cap, never auto-activate, never auto-raise spend. Activate / pause / end all `log_activity()`.
6. Cron `ads:sync` (every 30 min): status and review result (map rejection reasons to plain text), spend and metrics into `ad_metrics_daily`. Alert on rejected ads and on spend near the cap.

**Tie to `/reports/ctwa`:** CTWA ads created here carry the ad id, so `conversations.ctwa_referral` already matches. Add spend and cost-per-conversation to the report by joining `ad_campaigns`.meta_ad_id. Later add leads won / revenue by ad.

**Permissions:** `ads.view`, `ads.create`, `ads.activate`, `ads.accounts`. Menu group "Ads" next to Social.

**AI help after launch:** a daily suggestion ("Ad X: cost per chat is 2x your average, consider pausing"). Suggestion with a button, never an automatic change.

### Step 4 UX: guided AI wizard (Brief, Generate, Launch)

Screen Ads > New campaign, three steps on one page. All UI text in English.

**1. Brief.** The AI asks only what it cannot work out itself:
- Website / product URL (AI reads the page for business, offer, language; server-side fetch with SSRF guard).
- Goal (default: WhatsApp chats), daily budget (suggested from past campaigns, within the tenant cap), campaign name (AI suggests), city / area.
- **Existing campaigns:** AI checks `ad_campaigns` + `/reports/ctwa` and offers to reuse the audience and budget of the best one (lowest cost per chat). With no history it suggests an audience from the URL and the contacts' cities.

**2. Generate (one click).** AI returns: 3 variants (headline, text, CTA) with a Facebook/Instagram style preview; audience (location, age, gender, interests); budget, dates and estimated max total; creative from the Social media library / Drive, or a "Boost" of a published post. Everything is editable inline; "Save as draft" is available.

**3. Launch (one click).** Summary of what will run, daily budget and max total. One server call creates campaign, ad set and ad on Meta, validates, then activates. Meta rejection reasons are shown in plain text.

**Rules:** server-side tenant daily budget cap; `ads.activate` required to launch; nothing runs without the explicit Launch click; AI never raises spend; special ad categories (credit, jobs, housing, social issues) are asked; AI output is validated against a schema (budget, age, location, honesty rules) before it is shown.

**Build order:**
1. Tables (`ad_campaigns`, `ad_creatives`, `ad_metrics_daily`, brief fields), migrations + `tables.php` + Models, permissions, budget cap setting.
2. `AdsBriefService` (URL read, audience from past campaigns and contacts).
3. `AdsPlannerService` (AI JSON output + server validation).
4. `AdsService` (Meta Marketing API: create, activate, pause, status, rejection reasons). Ships with a **mock mode** so the whole flow works before Meta approves the scopes.
5. Wizard UI.
6. `ads:sync` cron, dashboard, spend and cost per chat in `/reports/ctwa`.
7. Daily AI suggestions (button, never automatic), then CAPI.

Steps 1 to 3 and 5 need no Meta approval; the live test of step 4 does.

## Step 5: CAPI feedback

- Send business events to Meta Conversions API so Meta optimises for real customers, not just chats: `Lead` when a CTWA contact first writes, `Purchase` / custom `QualifiedLead` when the sale is marked or a payment link is paid (`payment.paid`, already an outgoing event).
- Needs a dataset (pixel) id and a CAPI access token per client in settings (encrypted), and the `ctwa_clid` from the CTWA referral. Check that `Webhooks::handleInboundMessage` stores `ctwa_clid` (today it stores only the ad id); add a column if not.
- `MetaConversionsService`: hash user data (SHA-256, normalised phone) before sending, `event_id` for dedupe, queue + retry through the existing outgoing-webhook style queue. Only for contacts who came from a CTWA ad and have consent.
- Report: events sent and failed per day.

## Step 6: YouTube / Google Ads (later)

Different API (Google Ads), its own developer token approval and billing. Out of scope until Steps 1-5 are live. Decide with the product team whether clients want YouTube video campaigns or only organic YouTube.

## Risks and rules

- Real money: PAUSED by default, caps, confirm step, audit log. Test only on a sandbox ad account.
- Meta can reject ads or restrict an account; show the reason, never retry blindly.
- All Meta/Google review items are on the critical path; code can finish before approval.
- Do not fake engagement or promise results. AI output is a draft.
- One cron per job, idempotent, tenant loop through `Concerns/EachTenant.php`.
- Never log tokens or full responses with personal data.

## Build order

| # | Item | Needs approval first? |
|---|------|----|
| 1 | Approval flow + live test | Meta/Google apps (Phase 0) |
| 2 | Metrics sync | `instagram_manage_insights`, Analytics scope |
| 3 | AI video analysis + repurpose | No |
| 4 | Ads v1 | `ads_management` Advanced Access |
| 5 | CAPI | dataset id + token per client |
| 6 | Google Ads | Google Ads developer token |

Step 3 can be built while Steps 1-2 wait for approvals.
