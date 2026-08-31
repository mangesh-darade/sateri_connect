# WhatsApp Automation Platform

Production-ready WhatsApp marketing and automation platform built on **PHP 8.2+**, **CodeIgniter 4.7**, and **Cheerio Direct APIs** (`https://newprod.api.cheerio.in/direct-apis/`).

Messaging transport is **Cheerio only** (not Meta Graph API). Template / webhook JSON shapes follow the WhatsApp-compatible format that Cheerio documents.

## Features

- Contact management with tags, CSV import/export, and duplicate detection
- Template-based broadcast campaigns with scheduling, pause/resume, and analytics
- Live chat inbox with 24-hour messaging window awareness
- Keyword bot and automation rule engine (including Cheerio workflow sync)
- Message queue with retry and cron workers
- Role-based access control (users, roles, permissions)
- REST API with JWT authentication and rate limiting
- Cheerio / WABA webhook receiver for inbound messages and delivery statuses
- Web installer wizard for WAMP / XAMPP / local setup

## Requirements

- PHP 8.2+ with `intl`, `mbstring`, `json`, `curl`, `openssl`, `mysqli`
- MySQL 8+ (or MariaDB compatible) with **InnoDB** default engine
- Composer 2+ (if `vendor/` is not already installed)
- Apache with `mod_rewrite` (WAMP / XAMPP) or equivalent
- Cheerio account + API key: [https://app.cheerio.in/settings/apikey](https://app.cheerio.in/settings/apikey)

## Quick start (WAMP — Windows)

```powershell
cd c:\wamp64\www\sateri_connect
composer install
copy .env.example .env
php spark key:generate
```

1. Create MySQL database `sateri_connect` (utf8mb4).  
2. Set `app.baseURL = 'http://localhost/sateri_connect/public/'` and DB credentials in `.env`.  
3. Ensure MySQL `default_storage_engine=InnoDB`.  
4. Open http://localhost/sateri_connect/public/install and finish the wizard.  
5. Configure **Settings → Cheerio API** and webhooks (see docs).  
6. Schedule Spark workers for queue / campaigns (see docs).

**Beginners:**  
- Local (WAMP): [docs/GUIDE_LOCAL.md](docs/GUIDE_LOCAL.md) · in-app **Guide → Local**  
- Production: [docs/GUIDE_PRODUCTION.md](docs/GUIDE_PRODUCTION.md) · in-app **Guide → Production**  
- Cheerio flow: [docs/CHEERIO_FLOW.md](docs/CHEERIO_FLOW.md)

Full WAMP walkthrough: [docs/WAMP_SETUP.md](docs/WAMP_SETUP.md).

## Documentation

| Doc | Description |
|-----|-------------|
| [docs/README.md](docs/README.md) | **Documentation index** |
| [docs/CHEERIO_FLOW.md](docs/CHEERIO_FLOW.md) | Cheerio WhatsApp end-to-end flow |
| [docs/CHEERIO_CONFIGURATION.md](docs/CHEERIO_CONFIGURATION.md) | API key, templates, webhooks |
| [docs/WAMP_SETUP.md](docs/WAMP_SETUP.md) | Windows WAMP install (deep) |
| [docs/USER_GUIDE.md](docs/USER_GUIDE.md) | Full product user guide |
| [docs/SETTINGS.md](docs/SETTINGS.md) | Settings tabs step-by-step |
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | General install, migrate, seed |
| [docs/API.md](docs/API.md) | REST endpoints, JWT auth |
| [docs/WEBHOOK_SETUP.md](docs/WEBHOOK_SETUP.md) | Cheerio webhook URL & verify flow |
| [docs/CRON_SETUP.md](docs/CRON_SETUP.md) | Spark workers / cron |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Production checklist |
| [docs/schema.sql](docs/schema.sql) | MySQL schema reference |

## Project layout

```
app/Controllers/     Web + Api controllers
app/Models/          CI4 models
app/Libraries/       Cheerio WhatsApp client, JWT, queue, automations
app/Commands/        Spark CLI workers (queue, campaigns, cheerio:sync, …)
app/Filters/         auth, apiAuth, rateLimit, install, permission
public/              Web root (index.php, assets)
writable/            Logs, cache, session, uploads
docs/                Documentation
```

## License

MIT — see [LICENSE](LICENSE).
