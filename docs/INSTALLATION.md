# Installation Guide

WhatsApp Automation Platform on **XAMPP** (macOS / Windows / Linux) with CodeIgniter 4.7.

## 1. Prerequisites

| Component | Version |
|-----------|---------|
| PHP | 8.2+ |
| MySQL / MariaDB | 8.0+ / 10.4+ |
| Composer | 2.x |
| Apache | with `mod_rewrite` enabled |

Required PHP extensions: `intl`, `mbstring`, `json`, `curl`, `openssl`, `mysqli`.

> **macOS XAMPP note:** Some XAMPP builds ship without `intl`. If `php -m | grep intl` is empty, install PHP via Homebrew (`brew install php`) for CLI, or enable/build `intl` for the XAMPP binary. CodeIgniter 4 will not boot without the `intl` extension (`Locale` class).

### XAMPP notes

- Start **Apache** and **MySQL** from the XAMPP control panel.
- Document root is typically `htdocs/`. This project lives at `htdocs/sateri_connect`.
- Prefer pointing a vhost or Alias at `sateri_connect/public` so `app/`, `writable/`, and `.env` are not web-accessible.

## 2. Install dependencies

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/sateri_connect   # adjust path on Windows/Linux
composer install
```

## 3. Environment file

```bash
cp .env.example .env
php spark key:generate
```

Edit `.env`:

```ini
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost/sateri_connect/public/'
database.default.hostname = localhost
database.default.database = sateri_connect
database.default.username = root
database.default.password =
database.default.DBDriver = MySQLi
JWT_SECRET = your-long-random-secret
```

Generate a strong `JWT_SECRET` (e.g. `openssl rand -hex 32`).

## 4. Permissions (`writable/`)

The web server user must write to:

```
writable/cache
writable/logs
writable/session
writable/uploads
writable/uploads/media
writable/uploads/imports
writable/uploads/exports
```

```bash
chmod -R ug+rwX writable
# On Linux production, also chown to the web user (www-data, apache, nginx)
```

`writable/uploads/.htaccess` denies direct HTTP access to uploaded files; serve media via the authenticated `Media::serve` route.

## 5. Create the database

In phpMyAdmin or MySQL CLI:

```sql
CREATE DATABASE sateri_connect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## 6. Migrate and seed (CLI)

If you prefer CLI over the installer wizard:

```bash
php spark migrate
php spark db:seed DatabaseSeeder
```

This creates all tables (see [schema.sql](schema.sql)) and seeds roles, permissions, default settings, and sample keywords.

Create an admin user via the installer **Admin** step, or insert one manually with a `password_hash()` password and an admin `role_id`.

## 7. Installer wizard

Visit:

```
http://localhost/sateri_connect/public/install
```

Steps:

1. **Welcome / requirements** — PHP version and extensions check  
2. **Database** — writes DB credentials to `.env`  
3. **Migrate** — runs migrations + seeders  
4. **Admin** — creates the first administrator account  
5. **Cheerio** — optional Cheerio API credentials  
6. **Finish** — sets `app_installed` and redirects to login  

After install, `/install` should no longer be needed. The `install` filter skips the installer routes while the app is uninstalled.

## 8. Apache / clean URLs

`public/.htaccess` ships with CI4 rewrite rules. Ensure:

```apache
AllowOverride All
```

for the `public` directory (XAMPP default usually allows this under `htdocs`).

`app.Config\App::$indexPage` is empty (`''`) so URLs omit `index.php`.

Optional VirtualHost:

```apache
<VirtualHost *:80>
    ServerName sateri_connect.local
    DocumentRoot "/Applications/XAMPP/xamppfiles/htdocs/sateri_connect/public"
    <Directory "/Applications/XAMPP/xamppfiles/htdocs/sateri_connect/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Set `app.baseURL = 'http://sateri_connect.local/'` to match.

## 9. Cron workers

Campaigns, the message queue, automations, template sync, and log cleanup require CLI workers. See [CRON_SETUP.md](CRON_SETUP.md).

Minimum for development (every minute):

```cron
* * * * * cd /Applications/XAMPP/xamppfiles/htdocs/sateri_connect && php spark queue:process >> writable/logs/cron-queue.log 2>&1
* * * * * cd /Applications/XAMPP/xamppfiles/htdocs/sateri_connect && php spark campaigns:process >> writable/logs/cron-campaigns.log 2>&1
* * * * * cd /Applications/XAMPP/xamppfiles/htdocs/sateri_connect && php spark automations:process >> writable/logs/cron-automations.log 2>&1
```

## 10. First login and Cheerio setup

1. Open `/login` with the admin account from the installer.  
2. Configure Cheerio API key under **Settings** (or finish Cheerio during install).  
3. Follow [CHEERIO_CONFIGURATION.md](CHEERIO_CONFIGURATION.md) and [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md).  
4. Sync message templates from Cheerio under **Templates ? Sync**.

## Troubleshooting

| Issue | Fix |
|-------|-----|
| 404 on all routes | Enable `mod_rewrite`; check `AllowOverride All`; confirm DocumentRoot is `public/` |
| Redirect loop to `/install` | Ensure migrations ran and `settings.app_installed` is set |
| CSRF errors on AJAX | Send header `X-CSRF-TOKEN` matching the CSRF meta/cookie (see `Security.php`) |
| Cannot write uploads | Fix `writable/` permissions |
| Blank page | Check `writable/logs/`; set `CI_ENVIRONMENT = development` temporarily |

## Next steps

- [API.md](API.md) — REST API  
- [DEPLOYMENT.md](DEPLOYMENT.md) — production hardening  
- [CRON_SETUP.md](CRON_SETUP.md) — full crontab  
