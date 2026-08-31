# Deployment Checklist

Production hardening for WhatsApp Automation Platform (CodeIgniter 4.7).

## Before go-live

### Environment

- [ ] `CI_ENVIRONMENT = production`  
- [ ] `app.baseURL` is the public HTTPS URL with trailing slash  
- [ ] `app.forceGlobalSecureRequests = true` in `.env` / `App.php` once HTTPS works  
- [ ] Strong `encryption.key` (`php spark key:generate`)  
- [ ] Strong unique `JWT_SECRET`  
- [ ] Database credentials use a least-privilege MySQL user (not root)  
- [ ] `.env` is **not** web-accessible and not committed to git  

### HTTPS & reverse proxy

- [ ] TLS certificate (Let’s Encrypt or commercial)  
- [ ] DocumentRoot / proxy target is **`public/` only** (preferred)  
- [ ] If DocumentRoot **must** stay project root (Plesk/nginx): keep root `index.php`, run `php spark webroot:publish`, and/or add [nginx-plesk-additional.conf](deploy/nginx-plesk-additional.conf)  
- [ ] If behind nginx/Cloudflare/Load Balancer, configure `App::$proxyIPs` so client IPs and HTTPS detection work  
- [ ] HSTS enabled after confirming HTTPS everywhere  
- [ ] Cheerio webhook URL uses HTTPS  

### Filesystem permissions

```bash
chown -R www-data:www-data writable
chmod -R ug+rwX writable
chmod -R o-rwx app writable vendor .env   # tighten as appropriate
```

- [ ] `writable/uploads` not directly downloadable (`.htaccess` deny; serve via app)  
- [ ] `app/`, `vendor/`, `.env` outside the public web root  

### Database

- [ ] Migrations applied: `php spark migrate`  
- [ ] Seeded roles/permissions on first deploy only  
- [ ] Automated backups (daily dump + retention)  
- [ ] `utf8mb4` charset  

### Queue workers & cron

- [ ] Minute cron (or Supervisor) for `queue:process`, `campaigns:process`, `automations:process`, `queue:retry`  
- [ ] Daily `templates:sync` and `logs:cleanup`  
- [ ] Log rotation for `writable/logs/*.log`  
- [ ] Use `flock` or equivalent to prevent overlapping queue workers  

See [CRON_SETUP.md](CRON_SETUP.md).

### Cheerio / WhatsApp

- [ ] Production System User token (not temporary)  
- [ ] Live phone number + WABA connected  
- [ ] App Review / Advanced Access completed if required  
- [ ] Webhook verified; `messages` subscribed  
- [ ] Webhook Secret configured for signature validation  
- [ ] Approved templates synced  

See [CHEERIO_CONFIGURATION.md](CHEERIO_CONFIGURATION.md) and [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md).

### Security

- [ ] CSRF enabled for web forms (already global except webhook/api/install)  
- [ ] AJAX sends `X-CSRF-TOKEN` header  
- [ ] Session cookie `Secure` + `HttpOnly` + `SameSite` in production  
- [ ] Disable debug toolbar in production (`CI_ENVIRONMENT = production`)  
- [ ] Rate limiting active on API (`rateLimit` filter)  
- [ ] Strong admin passwords; disable unused users  
- [ ] Review role permissions matrix  
- [ ] Fail2ban / WAF optional in front of Apache/nginx  
- [ ] Keep Composer packages updated (`composer update` with testing)  

### Application config

Suggested production `.env` fragments:

```ini
CI_ENVIRONMENT = production
app.baseURL = 'https://wa.example.com/'
app.forceGlobalSecureRequests = true
# database.* = production credentials
encryption.key = <generated>
JWT_SECRET = <generated>
```

Set `$indexPage = ''` (already default in this project) and ensure rewrite rules work under HTTPS.

### Smoke tests after deploy

1. HTTPS admin login  
2. Settings save + SMTP test (if used)  
3. Template sync  
4. Send one template message to a verified number  
5. Inbound reply via webhook  
6. API login + `GET /api/auth/me`  
7. Cron ran within the last minute (queue stats moving)  

### Monitoring

- Watch `writable/logs/log-*.log` and Cheerio webhook failure rates  
- Alert on queue depth growth and failed campaign counters  
- Monitor Cheerio rate limits / API errors in activity logs  

## Rollback notes

- Keep previous release artifact + DB dump.  
- Migrations are forward-only; plan additive schema changes carefully.  
- Rotate compromised API keys immediately in Cheerio and Settings.

## Related docs

- [INSTALLATION.md](INSTALLATION.md)  
- [API.md](API.md)  
- [CRON_SETUP.md](CRON_SETUP.md)  
