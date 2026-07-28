# WAMP Setup (Windows) — Deep Guide

Install and run this project on **WAMP64** (Apache + MySQL/MariaDB + PHP).

Project path used in this guide:

```
c:\wamp64\www\whstapp
```

Public URL:

```
http://localhost/whstapp/public/
```

---

## 1. Prerequisites

| Component | Required |
|-----------|----------|
| WAMP64 | Apache + MySQL **or** MariaDB running (green) |
| PHP | **8.2+** (Apache module). CLI may differ — prefer matching versions |
| Extensions | `intl`, `mbstring`, `json`, `curl`, `openssl`, `mysqli` |
| Composer | Optional if `vendor/` already present |
| Browser | Chrome / Edge |

### Check PHP (CLI)

```powershell
php -v
php -m | findstr /i "intl mbstring curl mysqli openssl json"
```

### Check Apache PHP

Create `public\_phpinfo.php` temporarily with `<?php phpinfo();`, open it, confirm version ≥ 8.2 and extensions, then delete the file.

---

## 2. Place the project

```
c:\wamp64\www\whstapp\
  app\
  public\          ← web entry (index.php)
  writable\
  vendor\
  .env
  spark
  composer.json
```

If `vendor` is missing:

```powershell
cd c:\wamp64\www\whstapp
composer install
```

---

## 3. Critical: InnoDB (not MyISAM)

WAMP MySQL sometimes ships with `default_storage_engine = MyISAM`.  
CodeIgniter migrations need **InnoDB** (foreign keys + utf8mb4 indexes).

In MySQL:

```sql
SET GLOBAL default_storage_engine = InnoDB;
-- Prefer also setting in my.ini and restart MySQL:
-- default_storage_engine=InnoDB
```

If migrations fail with `Specified key was too long; max key length is 1000 bytes`, you are almost certainly on MyISAM — switch to InnoDB and retry.

---

## 4. Environment (`.env`)

```powershell
cd c:\wamp64\www\whstapp
copy .env.example .env
php spark key:generate
```

Edit `.env`:

```ini
CI_ENVIRONMENT = development

app.baseURL = 'http://localhost/whstapp/public/'

database.default.hostname = localhost
database.default.database = apiwa
database.default.username = root
database.default.password =
database.default.DBDriver = MySQLi
database.default.port = 3306
database.default.charset = utf8mb4
database.default.DBCollat = utf8mb4_unicode_ci

JWT_SECRET = change-me-to-a-long-random-string
```

**Do not** use macOS socket paths like `/Applications/XAMPP/.../mysql.sock` on Windows.

---

## 5. Create database

phpMyAdmin (`http://localhost/phpmyadmin`) or CLI:

```sql
CREATE DATABASE apiwa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Optional: import full dump:

```powershell
c:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe -u root apiwa -e "SOURCE c:/wamp64/www/whstapp/apiwa_database.sql"
```

(Adjust MySQL path/version to your WAMP install.)

---

## 6. Installer wizard (recommended)

1. Start **WAMP** (Apache + MySQL green).  
2. Open http://localhost/whstapp/public/install  
3. Complete steps:

| Step | Action |
|-----:|--------|
| 1 Welcome | Get Started |
| 2 Requirements | All green → Continue |
| 3 Database | `localhost`, `root`, db `apiwa`, base URL with trailing slash |
| 4 Migrate | Run Migrations & Seed |
| 5 Admin | Create super-admin email + password (≥ 8 chars) |
| 6 Cheerio | Optional — can Skip (configure later in Settings) |
| 7 Finish | **Complete Installation** (marks `app_installed=1`) |

4. Login at http://localhost/whstapp/public/login  

### CLI alternative (instead of wizard migrate)

```powershell
cd c:\wamp64\www\whstapp
php spark migrate
php spark db:seed DatabaseSeeder
```

Then create admin via installer Admin step only, or insert a user with `password_hash()`.

---

## 7. Apache / rewrite

`public/.htaccess` must be allowed:

- `AllowOverride All` for `c:/wamp64/www` (or your vhost)  
- `mod_rewrite` enabled in WAMP  

Clean URLs example:

```
http://localhost/whstapp/public/dashboard
```

not `.../index.php/dashboard`.

### Optional virtual host

Point DocumentRoot to `c:/wamp64/www/whstapp/public` and set:

```ini
app.baseURL = 'http://whstapp.test/'
```

---

## 8. Writable folders

These must be writable by Apache:

```
writable/
writable/cache
writable/logs
writable/session
writable/uploads
```

On Windows this is usually fine; if sessions fail, check folder permissions / antivirus locks.

---

## 9. After install — configure product

1. [SETTINGS.md](SETTINGS.md) — Cheerio + timezone  
2. [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md) — tunnel + Cheerio verify  
3. [USER_GUIDE.md](USER_GUIDE.md) — templates, contacts, campaigns  
4. [CRON_SETUP.md](CRON_SETUP.md) — Windows Task Scheduler for `spark` workers  

### Manual worker test

```powershell
cd c:\wamp64\www\whstapp
php spark queue:process
php spark campaigns:process
```

---

## 10. Re-run installer

If you need a clean wizard again:

1. Backup DB / `.env`.  
2. Set `app_installed` to `0` in `settings`, **or** drop/recreate `apiwa`.  
3. Visit `/install`.  

Seeders are idempotent for roles/permissions/settings/keywords so migrate+seed can be re-run safely after partial installs.

---

## 11. Common WAMP issues

| Issue | Fix |
|-------|-----|
| Port 80 busy (Skype/IIS) | Change Apache port or free 80; update `app.baseURL` |
| CLI PHP ≠ Apache PHP | Use WAMP PHP path for `spark` / Task Scheduler |
| `intl` missing | Enable `extension=intl` in the **Apache** `php.ini`, restart Apache |
| SSL error 60 on Cheerio sync | Apache `phpForApache.ini` has empty `curl.cainfo` — see section 11a |
| Installer requirements all Fail | Old view bug; use current `app/Views/install/requirements.php` |
| Finish doesn’t install | Must POST **Complete Installation** (not only open Finish page) |
| MyISAM / key length errors | Force InnoDB (section 3) |
| Composer not in PATH | Use full path to `composer.phar` or rely on committed `vendor/` |

### 11a. Fix SSL “unable to get local issuer certificate” (curl 60)

Cheerio sync / Graph API calls fail on many WAMP installs because **Apache** uses `phpForApache.ini`, which leaves CA paths blank (CLI `php.ini` may already be fine).

1. Confirm `cacert.pem` exists next to your active PHP, e.g.  
   `C:\wamp64\bin\php\php8.4.15\cacert.pem`  
   (If missing, download from https://curl.se/ca/cacert.pem)
2. Edit **`phpForApache.ini`** (not only `php.ini`) for that PHP version:

```ini
[curl]
curl.cainfo = "C:\wamp64\bin\php\php8.4.15\cacert.pem"

[openssl]
openssl.cafile = "C:\wamp64\bin\php\php8.4.15\cacert.pem"
```

3. **Restart Apache** from the WAMP tray icon.
4. Retry **Templates → Sync from Cheerio**.

This app also falls back to `writable/certs/cacert.pem` when php.ini is unset.

---

## 12. Security (local → production)

Local WAMP is for development. Before production:

- `CI_ENVIRONMENT = production`  
- Strong `encryption.key` + `JWT_SECRET`  
- Non-root DB user  
- HTTPS + real domain  
- See [DEPLOYMENT.md](DEPLOYMENT.md)
