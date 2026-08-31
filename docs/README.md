# Documentation Index

WhatsApp Automation Platform (`sateri_connect`) — full documentation set.

**Messaging:** [Cheerio Direct APIs](https://newprod.api.cheerio.in/direct-apis/)  
**Base URL (WAMP local):** `http://localhost/sateri_connect/public/`

---

## Start here (beginners)

| Order | Doc | Who it's for |
|------:|-----|----------------|
| **0a** | **[GUIDE_LOCAL.md](GUIDE_LOCAL.md)** | Local WAMP testing (step-by-step) |
| **0b** | **[GUIDE_PRODUCTION.md](GUIDE_PRODUCTION.md)** | Live server / HTTPS / Cheerio production |
| **0c** | **[CHEERIO_FLOW.md](CHEERIO_FLOW.md)** | Cheerio WhatsApp end-to-end flow |
| **0d** | **[CHEERIO_CONFIGURATION.md](CHEERIO_CONFIGURATION.md)** | API key, templates, webhooks |
| — | **[MODULE_TEST_REPORT.md](MODULE_TEST_REPORT.md)** | Module test + production-ready verdict |
| — | **[PRODUCTION_SECURITY.md](PRODUCTION_SECURITY.md)** | Security hardening |
| — | **[DEPLOY_YEVLE.md](DEPLOY_YEVLE.md)** | Deploy on `yevle.elintpos.in` |
| — | **In-app Guide** | Sidebar → **Guide → Local** or **Guide → Production** |
| 1 | [WAMP_SETUP.md](WAMP_SETUP.md) | Windows WAMP deep install |
| 2 | [INSTALLATION.md](INSTALLATION.md) | General install (XAMPP / Linux too) |
| 3 | [USER_GUIDE.md](USER_GUIDE.md) | Day-to-day use of every module |
| 4 | [SETTINGS.md](SETTINGS.md) | Cheerio / App / SMTP / Webhooks settings |
| 5 | [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md) | Connect Cheerio / WABA webhooks |
| 6 | [CRON_SETUP.md](CRON_SETUP.md) | Queue / campaign workers |
| 7 | [API.md](API.md) | REST API + JWT |
| 8 | [DEPLOYMENT.md](DEPLOYMENT.md) | Production checklist |
| — | [MULTI_CLIENT_TENANCY.md](MULTI_CLIENT_TENANCY.md) | Single domain + separate DB per client |
| — | [schema.sql](schema.sql) | Database schema reference |

Meta publish / go-live: **[META_PUBLISH_GO_LIVE.md](META_PUBLISH_GO_LIVE.md)** · API test calls: **[META_APP_REVIEW_API_TESTING.md](META_APP_REVIEW_API_TESTING.md)** · also `META_TESTING.md`, `META_CONFIGURATION.md`, `META_FLOW.md`.

---

## Quick links (local)

| Page | URL |
|------|-----|
| Login | http://localhost/sateri_connect/public/login |
| Installer | http://localhost/sateri_connect/public/install |
| Dashboard | http://localhost/sateri_connect/public/dashboard |
| Settings | http://localhost/sateri_connect/public/settings |
| Webhook | http://localhost/sateri_connect/public/webhooks |

---

## Default admin (after install)

Set during **Install → Admin**. If you used the guided local setup:

- Email: `admin@sateri_connect.local`
- Password: *(the one you chose; common local default was `Admin@12345` — change it)*

---

## Architecture (short)

```
Browser / Cheerio  →  Apache (public/)  →  CodeIgniter 4
                                        →  MySQL (sateri_connect)
Cheerio Direct API  ←  Queue workers (spark)
Cheerio / WABA webhooks  →  /webhooks
```

Outbound messaging uses **Cheerio Direct APIs only** (`x-api-key`).  
Webhook JSON uses the standard WhatsApp Cloud API shape that Cheerio documents.
