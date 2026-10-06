# PROJECT MAP — SATERI CONNECT

## [Project Overview]
- **Application:** Sateri Connect / WhatsApp & Email Automation Platform
- **Stack:** PHP 8.2+ | CodeIgniter 4.7.4 | MySQL (elintomreach_db) | Bootstrap 5.3.2 | jQuery 3.7.1 | Lucide Icons / FontAwesome 6
- **Environment:** Local WAMP (WampServer 64-bit on Windows)
- **Active Providers:**
  - WhatsApp: Cheerio Direct API & Meta Cloud API
  - Email: Amazon SES (Active, primary), SMTP, Cheerio Direct API, SendGrid
  - Omnichannel: Instagram Messaging & Facebook Messenger

---

## [Navigation: Menu & Submenu Hierarchy]

| Section / Group | Main Menu Item | Submenus | Target URL / Pattern | Required Permission |
|---|---|---|---|---|
| **Core** | **Dashboard** | — | `/dashboard` | `dashboard.view` |
| **Inbox** | **Team Inbox** | • WhatsApp<br>• Messenger<br>• Instagram | `/chat?channel=whatsapp`<br>`/chat?channel=messenger`<br>`/chat?channel=instagram` | `chat.view` |
| | **Quick Replies** | — | `/quick-replies` | `chat.view` |
| **Data** | **Contacts** | • All Contacts<br>• Customer Groups<br>• Custom Attributes<br>• Import Contacts<br>• Duplicate Check | `/contacts`<br>`/customer-groups`<br>`/attributes`<br>`/contacts/import`<br>`/contacts/duplicates` | `contacts.view` |
| **Marketing** | **Broadcasts** | — (Filter by channel) | `/campaigns` | `campaigns.view` |
| | **Email Manager** | • Email Manager (Tabs)<br>• Email List Verifier<br>• Compose Single Email<br>• Compose Bulk Email | `/email-manager`<br>`/email-manager?tab=verifier`<br>`/emails/send`<br>`/emails/bulk` | `emails.view` / `emails.send` |
| | **Template Library** | • WhatsApp Templates<br>• Email Templates<br>• Create Template | `/templates`<br>`/templates?channel=email`<br>`/templates/create` | `templates.view` |
| **Automation** | **Workflows** | • WhatsApp Workflows<br>• Email Workflows (Auto Drips) | `/automations`<br>`/automations?channel=email` | `automations.view` |
| | **Sequences** | — | `/sequences` | `sequences.view` |
| | **Keywords** | • All Keywords<br>• Create Keyword | `/keywords`<br>`/keywords/create` | `keywords.view` |
| | **Queue** | — | `/queue` | `queue.view` / `automations.view` |
| **Analytics** | **Analytics** | • Overview Analytics<br>• Campaign Reports<br>• Delivery Reports | `/analytics`<br>`/reports`<br>`/reports/delivery` | `reports.view` |
| **System** | **Users** | — | `/users` | `users.view` |
| | **Roles & Permissions**| — | `/roles` | `roles.view` |
| | **Setup Workspace** | • Local Guide<br>• Production Guide<br>• Meta Publish Guide<br>• Meta Screenshot Guide<br>• Automations Guide | `/guide/local`<br>`/guide/production`<br>`/guide/meta-official`<br>`/guide/meta-screenshots`<br>`/guide/automations` | `guide.view` |
| **Footer / Config**| **Settings** | General, WhatsApp, Email Provider, Meta, AI Copilot, API Tokens | `/settings` | `settings.view` |
| | **API Docs** | REST API v1 Specification | `/api/docs` | Public / Developer |

---

## [Screens & Actions Audit]

### 1. Dashboard
- **Route:** `GET /dashboard` | `Dashboard::index`
- **View:** `app/Views/dashboard/index.php`
- **Actions:**
  - View KPIs (Total contacts, messages sent, broadcast delivery rate, active automations)
  - Activity log stream
  - Quick action shortcuts (Send Single Email, New Campaign, Add Contact)

### 2. Inbox / Live Chat (`chat`)
- **Route:** `GET /chat` | `Chat::index`
- **View:** `app/Views/chat/index.php`
- **Actions:**
  - `GET /chat/conversations` — Load conversations list with unread counters
  - `GET /chat/messages/(:num)` — Load message timeline for active conversation
  - `POST /chat/send` — Send text, template, or media message
  - `POST /chat/mark-read` — Mark conversation as read
  - `POST /chat/note` — Add private internal agent note
  - `POST /chat/assign` — Assign conversation to user
  - `POST /chat/status` — Change conversation status (open, closed, pending)
  - `POST /chat/ai-suggest` — Generate AI Copilot reply suggestion
  - `POST /chat/ai-summary` — Generate AI summary of thread
  - `POST /chat/contact-attribute` — Update customer attributes inline
  - `POST /chat/contact-tag` — Add/remove contact tags inline
  - `GET /chat/transfer/export` & `import` — Backup and restore chats

### 3. Quick Replies (`quick-replies`)
- **Route:** `GET /quick-replies` | `QuickReplies::index`
- **View:** `app/Views/quick_replies/index.php`
- **Actions:**
  - `POST /quick-replies` — Create snippet shortcut (`/shortcut`)
  - `POST /quick-replies/(:num)` — Update snippet text
  - `POST /quick-replies/(:num)/delete` — Delete snippet

### 4. Contacts (`contacts`)
- **Route:** `GET /contacts` | `Contacts::index`
- **View:** `app/Views/contacts/index.php`
- **Actions:**
  - `GET /contacts/create` & `POST /contacts` — Create individual contact
  - `GET /contacts/(:num)` — Contact profile & history
  - `GET /contacts/(:num)/edit` & `POST /contacts/(:num)` — Update contact
  - `POST /contacts/(:num)/delete` — Soft delete contact
  - `POST /contacts/(:num)/erase` — GDPR permanent erasure
  - `POST /contacts/bulk-delete` — Batch delete contacts
  - `POST /contacts/bulk-tags` — Batch assign tags/groups
  - `POST /contacts/bulk-consent` — Batch update WhatsApp consent
  - `GET /contacts/duplicates` — Detect duplicate phone/email records
  - `GET /contacts/import` & `POST /contacts/import/preview` / `commit` — Chunked CSV/Excel contact import
  - `GET /contacts/export` — Export filtered contacts to CSV
  - `POST /contacts/sync-cheerio` & `sync-elintom` — Background sync from Cheerio / ElintOm

### 5. Customer Groups (`customer-groups`)
- **Route:** `GET /customer-groups` | `CustomerGroups::index`
- **View:** `app/Views/customer_groups/index.php`
- **Actions:**
  - `POST /customer-groups` / `create` — Create group (tag)
  - `GET /customer-groups/(:num)` — Show group members
  - `POST /customer-groups/(:num)/delete` — Delete group
  - `POST /customer-groups/(:num)/contacts/(:num)/remove` — Remove member from group
  - `GET /customer-groups/export` — Export groups summary or members

### 6. Custom Attributes (`attributes`)
- **Route:** `GET /attributes` | `Attributes::index`
- **View:** `app/Views/attributes/index.php`
- **Actions:**
  - `POST /attributes` — Add custom contact field definition (text, number, date)
  - `POST /attributes/(:num)` — Update attribute definition
  - `POST /attributes/(:num)/delete` — Delete attribute

### 7. Broadcasts / Campaigns (`campaigns`)
- **Route:** `GET /campaigns` | `Campaigns::index`
- **View:** `app/Views/campaigns/index.php`
- **Actions:**
  - `GET /campaigns/create` — Unified Broadcast Wizard (WhatsApp & Email)
  - `POST /campaigns/audience-preview` — Live recipient count preview
  - `POST /campaigns/wizard` — Save draft wizard campaign
  - `POST /campaigns/wizard/(:channel)/(:num)/run` — Run wizard campaign immediately
  - `POST /campaigns/wizard/(:channel)/(:num)/schedule` — Schedule wizard campaign
  - `GET /campaigns/(:num)` — Show WhatsApp campaign analytics
  - `GET /campaigns/email/(:num)` — Show Email campaign details & analytics
  - `POST /campaigns/(:num)/send-now` — Dispatch campaign immediately
  - `POST /campaigns/(:num)/pause` & `resume` — Pause/resume running queue
  - `POST /campaigns/(:num)/cancel` — Cancel queue
  - `POST /campaigns/(:num)/delete` — Delete campaign
  - `GET /campaigns/(:num)/progress` — Polling live progress meter

### 8. Email Manager (`email-manager`)
- **Route:** `GET /email-manager` | `EmailManager::index`
- **View:** `app/Views/email_manager/index.php`
- **Tabs:**
  - **Campaigns:** Draft, create, preview, schedule, and send HTML email campaigns with customer group selection & physical file attachments.
  - **Templates & Builder:** Save reusable HTML email templates with `{{name}}` personalization and default attachments (e.g. PDF brochure).
  - **Drips:** Sequential auto-drip email chains with interval triggers.
  - **Verifier:** Live syntax, MX DNS, and mailbox deliverability verification.
  - **Senders / Domains:** Custom sender signatures and verified identities.
- **Actions:**
  - `POST /email-manager/builders` — Save template with physical attachment upload
  - `POST /email-manager/builders/(:num)/delete` — Delete template
  - `POST /email-manager/campaigns` — Save HTML campaign with audience and attachment
  - `POST /email-manager/campaigns/(:num)/send` — Dispatch campaign
  - `POST /email-manager/campaigns/(:num)/delete` — Delete campaign
  - `POST /email-manager/drips` — Save drip sequence
  - `POST /email-manager/verify` — Run batch email verification
  - `POST /email-manager/senders` — Save sender signature

### 9. Dedicated Email Dispatchers (`emails`)
- **Route:** `GET /emails/send` (Single) & `GET /emails/bulk` (Bulk) | `Emails::single` & `Emails::bulk`
- **Views:** `app/Views/emails/single.php` & `app/Views/emails/bulk.php`
- **Actions:**
  - `POST /emails/send` — Personalized single email with physical file attachment (5MB max)
  - `POST /emails/bulk` — Batch personalized send with customer group filtering, search picker, and physical file attachment
  - `GET /emails/track/open/(:num)` — Invisible 1x1 open tracking pixel
  - `GET /emails/track/click/(:num)` — Click tracking redirect
  - `GET/POST /emails/unsubscribe` — One-click compliance unsubscription

### 10. Template Library (`templates`)
- **Route:** `GET /templates` | `Templates::index`
- **View:** `app/Views/templates/index.php`
- **Actions:**
  - `GET /templates/create` & `POST /templates` — Submit WhatsApp template to Meta
  - `POST /templates/sync` — Sync approved templates from Meta / Cheerio
  - `GET /templates/(:num)` & `preview` — View template layout
  - `POST /templates/(:num)/send-test` — Send live test to test phone number
  - `POST /templates/(:num)/delete` — Delete template

### 11. Automations & Workflows (`automations`)
- **Route:** `GET /automations` | `Automations::index`
- **View:** `app/Views/automations/index.php`
- **Actions:**
  - `GET /automations/create` & `POST /automations` — Create trigger rule
  - `GET /automations/(:num)/builder` — Visual drag-and-drop Flow Graph Builder
  - `POST /automations/(:num)/builder` — Save flow graph nodes & edges
  - `POST /automations/(:num)/toggle` — Enable/disable automation rule
  - `POST /automations/(:num)/delete` — Delete rule

### 12. Keywords (`keywords`)
- **Route:** `GET /keywords` | `Keywords::index`
- **View:** `app/Views/keywords/index.php`
- **Actions:**
  - `GET /keywords/create` & `POST /keywords` — Create keyword auto-reply trigger
  - `GET /keywords/(:num)/edit` & `POST /keywords/(:num)` — Update keyword
  - `POST /keywords/(:num)/delete` — Delete keyword
  - `POST /keywords/reorder` — Priority reorder

### 13. Queue Monitor (`queue`)
- **Route:** `GET /queue` | `Queue::index`
- **View:** `app/Views/queue/index.php`
- **Actions:**
  - `GET /queue/stats` — Real-time queue counters (pending, processing, failed)
  - `POST /queue/process` — Trigger manual queue processing batch
  - `POST /queue/retry-all` — Retry all failed queue items
  - `POST /queue/(:num)/retry` — Retry single queue item
  - `POST /queue/(:num)/cancel` — Cancel queue item

### 14. Reports & Delivery Analytics (`reports`)
- **Route:** `GET /reports` | `Reports::index`
- **Views:** `app/Views/reports/index.php`, `delivery.php`
- **Actions:**
  - `GET /reports/campaigns` — Campaign-wise delivery summary
  - `GET /reports/delivery` — Message-by-message delivery logs with filter
  - `GET /reports/campaign-contacts` — Recipient drill-down
  - `GET /reports/export-excel` & `export-pdf` — Export printable reports

### 15. User Management (`users`)
- **Route:** `GET /users` | `Users::index`
- **View:** `app/Views/users/index.php`
- **Actions:**
  - `GET /users/create` & `POST /users` — Add team member
  - `GET /users/(:num)/edit` & `POST /users/(:num)` — Update user & assign roles
  - `POST /users/(:num)/delete` — Deactivate user

### 16. Roles & Permissions (`roles`)
- **Route:** `GET /roles` | `Roles::index`
- **View:** `app/Views/roles/index.php`
- **Actions:**
  - `POST /roles` — Create role
  - `POST /roles/update` — Save permission matrix checkboxes
  - `POST /roles/(:num)/delete` — Delete role

### 17. Settings (`settings`)
- **Route:** `GET /settings` | `Settings::index`
- **View:** `app/Views/settings/index.php`
- **Actions:**
  - `POST /settings/save` — Save workspace settings
  - `POST /settings/test-email` — Test email provider connection
  - `POST /settings/test-smtp` — Test SMTP connection
  - `POST /settings/test-cheerio` — Test Cheerio API connection
  - `POST /settings/test-meta` — Test Meta WhatsApp Cloud API credentials
  - `POST /settings/api-tokens/generate` & `delete` — Manage REST API tokens

---

## [DB Tables]
- `activity_logs`: User audit trails and event records
- `api_tokens`: Personal REST API access tokens
- `automation_rules`: Keyword and event trigger rules
- `automations`: Automation metadata and flow graph JSON
- `campaigns`: WhatsApp broadcast campaigns
- `campaign_contacts`: Campaign recipient status tracking (sent, delivered, read, failed)
- `contact_attributes`: Custom attribute values per contact
- `contact_tags`: Many-to-many relationship linking contacts to tags/groups
- `contacts`: Customer contact master (name, email, mobile, consent, opt-in)
- `conversations`: Live chat conversation threads (WhatsApp, Messenger, Instagram)
- `email_builders`: Reusable HTML email templates with attachments
- `email_drips` & `email_drip_steps`: Multi-step email nurture sequences
- `email_html_campaigns`: HTML email marketing broadcasts with attachments & audience
- `email_logs`: Message-by-message email delivery audit log
- `email_senders`: Verified email sender addresses & domains
- `email_unsubscribes`: Email unsubscribed suppression list
- `email_verifications`: User signup email verification tokens
- `keywords`: Keyword exact/contains auto-reply definitions
- `messages`: WhatsApp & omnichannel message logs
- `message_queue`: Asynchronous background message delivery queue
- `permissions` & `roles` & `role_permissions`: RBAC authorization system
- `settings`: System configuration key-value storage
- `tags`: Customer groups and segmentation labels
- `templates`: WhatsApp approved Cloud API / Cheerio templates
- `users`: Platform admin and agent user accounts

---

## [DB Changes Log]
| Table | Change | Reason | Date |
|---|---|---|---|
| `email_builders` | Added `attachment_path VARCHAR(255)`, `attachment_name VARCHAR(191)` | Reusable template physical file attachment support | 2026-10-06 |
| `email_html_campaigns` | Added `attachment_path VARCHAR(255)`, `attachment_name VARCHAR(191)` | Campaign broadcast physical file attachment support | 2026-10-06 |
| `email_logs` | Added `open_count`, `click_count`, `first_opened_at`, `campaign_id` | Email tracking & analytics | 2026-10-06 |
| `email_unsubscribes` | Created table `(id, email, reason, campaign_id, created_at)` | One-click CAN-SPAM / GDPR unsubscribe suppression | 2026-10-06 |

---

## [Frontend Dependencies]
- Bootstrap: `5.3.2`
- jQuery: `3.7.1`
- Lucide Icons: `0.344.0`
- FontAwesome: `6.5.1`

---

## [Auth Flow]
- Session-based RBAC (`app/Filters/AuthFilter.php`)
- Strict permission gates via `can('permission.slug')`
- REST API v1 uses Bearer token / `X-API-Key` (`app/Filters/ApiAuthFilter.php`)
