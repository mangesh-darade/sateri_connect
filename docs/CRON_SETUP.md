# Cron Setup

Background Spark commands keep the message queue, campaigns, automations, templates, and logs healthy.

Replace `/path/to/APIWA` with your install path, e.g.:

```
/Applications/XAMPP/xamppfiles/htdocs/APIWA
```

Use the same PHP binary Apache uses when possible:

```bash
which php
# XAMPP macOS often: /Applications/XAMPP/xamppfiles/bin/php
```

## Commands

| Command | Purpose | Suggested schedule |
|---------|---------|-------------------|
| `php spark queue:process` | Send pending queue jobs via Cloud API | Every minute |
| `php spark campaigns:process` | Start/continue scheduled & running campaigns | Every minute |
| `php spark automations:process` | Evaluate automation rules / delayed steps | Every minute |
| `php spark queue:retry` | Re-queue failed jobs within retry policy | Every minute or every 5 min |
| `php spark templates:sync` | Refresh Cheerio message templates | Daily |
| `php spark logs:cleanup` | Purge old activity/webhook/rate-limit rows | Daily |

Optional argument for cleanup: `php spark logs:cleanup 30` (retain 30 days).

## Crontab examples

Edit crontab:

```bash
crontab -e
```

### Every minute workers

```cron
* * * * * cd /Applications/XAMPP/xamppfiles/htdocs/APIWA && /Applications/XAMPP/xamppfiles/bin/php spark queue:process >> writable/logs/cron-queue.log 2>&1
* * * * * cd /Applications/XAMPP/xamppfiles/htdocs/APIWA && /Applications/XAMPP/xamppfiles/bin/php spark campaigns:process >> writable/logs/cron-campaigns.log 2>&1
* * * * * cd /Applications/XAMPP/xamppfiles/htdocs/APIWA && /Applications/XAMPP/xamppfiles/bin/php spark automations:process >> writable/logs/cron-automations.log 2>&1
* * * * * cd /Applications/XAMPP/xamppfiles/htdocs/APIWA && /Applications/XAMPP/xamppfiles/bin/php spark queue:retry >> writable/logs/cron-retry.log 2>&1
```

### Daily jobs (03:15 server time)

```cron
15 3 * * * cd /Applications/XAMPP/xamppfiles/htdocs/APIWA && /Applications/XAMPP/xamppfiles/bin/php spark templates:sync >> writable/logs/cron-templates.log 2>&1
20 3 * * * cd /Applications/XAMPP/xamppfiles/htdocs/APIWA && /Applications/XAMPP/xamppfiles/bin/php spark logs:cleanup 30 >> writable/logs/cron-cleanup.log 2>&1
```

### Single wrapper (optional)

Create `bin/cron-minute.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
PHP="${PHP_BIN:-php}"
$PHP spark queue:process
$PHP spark campaigns:process
$PHP spark automations:process
$PHP spark queue:retry
```

```cron
* * * * * /Applications/XAMPP/xamppfiles/htdocs/APIWA/bin/cron-minute.sh >> /Applications/XAMPP/xamppfiles/htdocs/APIWA/writable/logs/cron-minute.log 2>&1
```

## Windows Task Scheduler

1. Create a task that runs every 1 minute.  
2. Action: start program  
   - Program: `C:\xampp\php\php.exe`  
   - Arguments: `spark queue:process`  
   - Start in: `C:\xampp\htdocs\APIWA`  
3. Repeat for `campaigns:process`, `automations:process`, `queue:retry`.  
4. Separate daily tasks for `templates:sync` and `logs:cleanup`.

## Verification

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/APIWA
php spark list | grep -E 'queue|campaigns|automations|templates|logs'
php spark queue:process
```

Watch `writable/logs/` and the **Queue** admin page for processed jobs.

## Production tips

- Run workers under a dedicated OS user with access to `.env` and `writable/`.  
- Prefer systemd timers or Supervisor for long-running / overlapping protection if volume is high.  
- Ensure only one `queue:process` instance runs at a time if your queue does not use row locking (add `flock` if needed):

```cron
* * * * * flock -n /tmp/apiwa-queue.lock -c 'cd /path/to/APIWA && php spark queue:process' >> writable/logs/cron-queue.log 2>&1
```

- Keep `CI_ENVIRONMENT = production` and monitor failed jobs via **Queue** and **Reports**.
