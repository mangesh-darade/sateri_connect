# Social Scheduler (Facebook, Instagram, YouTube, LinkedIn)

Status: **Phase 1 in progress.** Done: migration `CreateSocialScheduler` + schema snapshot + 5 models, Facebook/Instagram publishers, `SocialPublishService`, `social:publish` command. Also done: `GoogleDriveService` (service account, list + stream download), `SocialMediaDelivery` + public signed route `social/media/{token}` (needs a publicly reachable `app.baseURL`, not localhost). Also done: sidebar group "Social Scheduler" (Calendar, Media Library, Accounts), permissions `social.view|create|accounts` in `PermissionSeeder`, Facebook Login OAuth (`SocialAccountService`), Drive folder scan + advisory validation (`SocialLibraryService`), bulk scheduler + calendar actions (`SocialScheduleService`), pages under `app/Views/social/`, `public/assets/js/social.js`. Also done: YouTube (resumable upload, token refresh) and LinkedIn member posts (text/image/video) publishers with OAuth connect, and an AI caption/hook/hashtag helper (`SocialCaptionService`, reuses Settings → AI). Also done: WhatsApp broadcast target (`WhatsAppBroadcastPublisher`: starts a normal campaign with an approved template and a customer-group audience at the due time; migration `AddSocialTargetOptions`); unit tests in `tests/SocialSchedulerUnitTest.php` (`php tests/SocialSchedulerUnitTest.php`). Not done: best-time suggestions, metrics sync, A/B tests, approval flow, LinkedIn company pages. Nothing has been exercised against live Meta/Google yet. Platform limits and App Review rules change often; re-verify against official docs before building each phase.

## 1. Goal

A multi-tenant scheduler where a client connects social accounts, drops media into a Google Drive folder, and sets up e.g. 20 days of posts/Reels in one go. The platform auto-publishes on time, tracks results, and suggests improvements (captions, timing).

No guarantee of virality is made or implied. The product helps with timing, hooks, captions, testing and analytics only.

## 2. Platform capabilities

| Platform | Publish | Scheduling | Constraints |
|---|---|---|---|
| Facebook Page (post, video, Reel) | Graph API (`/feed`, `/videos`, `/video_reels`) | Native `scheduled_publish_time` (10 min to 30 days) | `pages_manage_posts`, `pages_read_engagement`; Meta App Review |
| Instagram (image, Reel, carousel, Story) | Content Publishing API (container, then `media_publish`) | None; our cron publishes | Business/Creator account only; `instagram_content_publish`; daily publish cap (~100) |
| YouTube (video, Shorts) | Data API v3 `videos.insert` (resumable) | `publishAt` (upload as private first) | Quota is per Google Cloud project: default 10,000 units/day, upload = 1,600 units, so ~6 uploads/day for **all clients combined** until a quota extension is granted. Unaudited projects upload locked to private. |
| LinkedIn (page / member) | Posts API + Videos API | None; our cron publishes | Partner / Community Management API approval |
| WhatsApp Status | No official API | n/a | Only via Cheerio (unofficial, ban risk). Mark "experimental". Prefer the existing WhatsApp campaign broadcast for the same content. |
| Later | TikTok, Pinterest, X, Telegram channel | | TikTok unaudited apps post private only |

Phase 0 (start immediately, takes weeks): create Sateri's Meta, Google and LinkedIn developer apps; begin Meta Business Verification + Advanced Access, YouTube API audit + quota extension, LinkedIn product access.

Assumption: all clients connect through Sateri's own apps via OAuth (one app, many clients).

## 3. Media source: Google Drive

Drive links are **not** passed to Meta/YouTube (large files hit the virus-scan interstitial and quota limits). Flow:

1. Client creates a "Sateri Content" folder and either shares it with the platform's **service account** email, or picks it with Google Picker (`drive.file` scope). Avoid full `drive.readonly` (restricted scope, needs a security assessment).
2. At publish time the server downloads the file via Drive API to a temp path.
3. The file is uploaded as binary to each platform (FB/YouTube/LinkedIn resumable upload; Instagram Reels resumable upload). If a public URL is unavoidable, serve a short-lived signed URL from our server.
4. Temp file is deleted after publish (success or terminal failure).

A scan (`social:scan-drive`) validates new files (see section 6) and lists them in the Bulk Upload screen. Optional folder watch: new file appears, it lands in the draft queue.

## 4. User experience

1. **Connect accounts**: one-click OAuth for each platform; tokens auto-refresh; alert before expiry.
2. **Bulk import**: pick Drive folder or files; each is validated and shown with platform-fit badges (e.g. "Too long for Instagram Reel").
3. **Auto-schedule wizard**: "20 posts, 1 per day, FB + IG + YouTube" fills the calendar using best-time slots.
4. **Calendar**: drag to reschedule; colour-coded status (Draft, Scheduled, Publishing, Published, Failed).
5. **Per-platform captions**: one master caption plus per-platform overrides (hashtags, length, tone).
6. **Failure UX**: clear messages ("Instagram token expired, reconnect"), automatic retries, notification.
7. **Approval flow** (agency use): client approves before publish.

All UI text is English.

## 5. Architecture

Follows the existing layering: Controllers (thin) → `app/Libraries/Social*Service` → Models. Services return `['ok','status','message','data'?|'errors'?]`, never throw to controllers, wrap 3rd-party calls in try/catch `Throwable`, log masked params, and return generic 502 messages (no raw provider errors).

### 5.1 Libraries

- `SocialPublisherInterface` with `FacebookPublisher`, `InstagramPublisher`, `YouTubePublisher`, `LinkedInPublisher` (one adapter per platform, easy to add more).
- `SocialAccountService`: OAuth connect/refresh/disconnect, encrypted token storage.
- `GoogleDriveService`: list/validate/download from the client's folder.
- `SocialMediaValidator`: ratio, duration, size, codec per platform (ffmpeg/ffprobe).
- `SocialScheduleService`: slot planner (auto-schedule wizard), duplicate and rate-limit guards.
- `SocialPublishService`: publishes one target idempotently, updates status.
- `SocialInsightsService`: pulls metrics.
- `SocialCaptionAssistant`: AI caption/hook/hashtag suggestions (Claude API).

### 5.2 Commands (cron)

| Command | Schedule | Job |
|---|---|---|
| `social:publish` | every minute | Loop all active tenants (like `wallet:settle`), switch with `TenantConnection::capture()/restoreIfChanged()`, publish due targets |
| `social:tokens` | hourly | Refresh tokens expiring soon, flag broken accounts |
| `social:insights` | every 6 hours | Sync metrics for recent posts |
| `social:scan-drive` | every 15 minutes | Detect and validate new Drive files |
| `social:cleanup` | daily | Remove stale temp files and old logs |

Publishing must be idempotent: store the platform `external_id` right after creation and never create twice for the same target; use a row lock/status transition (`scheduled` → `publishing`) so overlapping cron runs cannot double-post.

### 5.3 Controllers / routes

`Social` (dashboard, calendar), `SocialAccounts` (connect/disconnect/callbacks), `SocialPosts` (CRUD, bulk import, wizard, approve), `SocialAnalytics`. Register in `app/Config/Routes.php` under `auth` filter groups with `permission:` filters; update `PROJECT_MAP.md`.

## 6. Data model (tenant DB)

Each table needs migration + `app/Database/Schema/tables.php` + Model (per `schema-model-sync` rule).

- `social_accounts`: id, platform, external_id, name, access_token (encrypted), refresh_token (encrypted), token_expires_at, scopes, status (`active|expired|revoked`), created_by, timestamps.
- `social_drive_sources`: id, folder_id, folder_name, mode (`service_account|picker`), last_scanned_at, status.
- `social_media_assets`: id, source_id, drive_file_id, filename, mime, size, duration_sec, width, height, thumbnail_path, checksum, validation (JSON per platform), status, timestamps. Unique on (`drive_file_id`, `checksum`) for duplicate detection.
- `social_posts`: id, asset_id, type (`post|reel|short|story|carousel`), title, caption, hashtags, status (`draft|pending_approval|scheduled|partial|published|failed`), created_by, approved_by, timestamps.
- `social_post_targets`: id, post_id, account_id, caption_override, scheduled_at, status (`scheduled|publishing|published|failed|skipped`), external_id, external_url, attempts, last_error (sanitised), published_at.
- `social_post_metrics`: id, target_id, captured_at, views, likes, comments, shares, reach, watch_time.
- `social_schedule_rules`: id, name, platforms (JSON), slots (JSON), timezone.

Master DB: none required initially. Platform-level quota counters (YouTube) live in `platform_settings`.

## 7. Multi-tenancy rules

- Tokens, Drive folders and posts live in the tenant DB only.
- Cron commands loop tenants and always restore the previous tenant afterwards; build services/models after the switch.
- Per-tenant limits: plan-based posts/month and daily per-platform caps; global YouTube quota guard across tenants (platform-level, first-come with fair share).
- Permissions: `social.view`, `social.publish`, `social.approve`, `social.accounts`, `social.analytics`. Add to permission seed and run `permissions:audit`.
- Every state change calls `log_activity()`.
- Billing: no per-post wallet charge (decided).

## 8. Policy and safety

- No fake engagement, follower automation or scraping. Official APIs only.
- Meta spam / inauthentic behaviour: cap posting frequency per account, never mass-duplicate the same content across many accounts.
- YouTube reused/repetitious content: block identical re-uploads; warn on near-duplicates.
- Duplicate guard (checksum + caption similarity), rate-limit guard per platform, music/copyright flag where detectable.
- Map provider errors with a mapper (extend `MetaApiErrorMapper` pattern); never show raw errors.
- Honour platform data-deletion and disconnect: revoke tokens and purge on disconnect.

## 9. Growth features ("viral" helpers)

Honest scope: improve odds, not guarantee outcomes.

1. Best-time suggestions from the account's own insights (industry defaults at first).
2. AI hook, caption and hashtag assistant (Claude API); first-3-seconds hook, 5-8 mixed niche/broad hashtags, CTA.
3. Content score checklist: 9:16, hook in 3 s, subtitles, 15-30 s length, thumbnail.
4. A/B tests on caption/thumbnail across days.
5. Repurposing: long video to shorts, quote cards, carousels.
6. Evergreen recycle: resurface top posts after 60-90 days with a fresh caption.
7. Analytics: best format, time, topic.
8. Trend inbox (topics only; trending audio has no official API).
9. Differentiator: comment/lead → existing WhatsApp and Facebook Lead automation.

## 10. Phases

| Phase | Scope | Estimate |
|---|---|---|
| 0 | Developer apps, App Review/audits (parallel, long lead) | weeks |
| 1 | Schema, OAuth, Drive connector, Facebook + Instagram publishers, bulk import, wizard, calendar, `social:publish` | 3-4 weeks |
| 2 | YouTube + LinkedIn, retries, failure alerts, metrics sync | 2-3 weeks |
| 3 | AI captions, best-time, A/B, content score | 2-3 weeks |
| 4 | Repurpose, trend inbox, WhatsApp broadcast, approval flow, TikTok/Pinterest | later |

## 11. Open questions

Decided:
1. Billing: no per-post wallet charge. Scheduler is not metered; only plan-based limits (section 7) apply.
2. Media source: Google Drive only (no local/S3 storage).
3. WhatsApp Status: requested via Meta API. The WhatsApp Cloud API has no documented Status endpoint (verify against current Meta docs before Phase 1). If still absent, fall back to Facebook/Instagram Stories (official) and keep WhatsApp Status as Cheerio-only experimental.

Still open:
1. Service-account share vs. Google Picker as the default Drive connection.
2. Is the YouTube quota extension acceptable as a hard dependency for YouTube in multi-client mode?

## 12. Testing

- Unit tests for validators, slot planner, duplicate/rate-limit guards (pure logic, flat in `tests/`).
- Adapter tests with mocked HTTP (no live API calls in CI).
- Manual end-to-end on a test Facebook Page and Instagram Business account in Meta's development mode before App Review.
