# Production readiness & security

Audit date context: local WAMP lab hardened for safer production deploys.

## Structure (target)

```
whstapp/
  public/          ← ONLY web DocumentRoot
  app/
  writable/        ← not web-accessible (.htaccess deny)
  docs/
  _unused/         ← quarantined junk (do not deploy)
  .env             ← never commit / never web-accessible
```

Root `.htaccess` rewrites into `public/` if DocumentRoot is mis-set.

---

## Hardening applied in code

| Area | Change |
|------|--------|
| Cookies | `Secure` auto-on when `ENVIRONMENT=production` |
| DB debug | Off in production; on in development |
| Exception traces | Hide password/token/secret fields |
| Filters | `secureheaders` + `invalidchars` enabled |
| Installer | `writable/install.lock` + DB flag; lock fails closed |
| Webhooks | Invalid signature always **403** |
| Chat uploads | MIME allowlist + 16MB limit (same as Media) |
| Media | Requires `chat.send` / `chat.view` |
| API | JWT user loads role permissions; routes use `permission:*` |
| `.gitignore` | Ignores `.env.*`, SQL dumps, `_unused/` |
| Cleanup | Junk moved to `_unused/` |

---

## Quarantined (`_unused/`)

- `.env.bak-install-test`
- `apiwa_database.sql`
- stock `env`
- `welcome_message.php`
- writable lab junk (cookies HTML, tunnel notes)

Production env template: `docs/deploy/env.production.yevle.example`

---

## Still required on the live server (ops)

1. DocumentRoot = `public/`  
2. `.env` with `CI_ENVIRONMENT=production`, HTTPS `baseURL`, strong `encryption.key` + `JWT_SECRET`  
3. `app.forceGlobalSecureRequests = true`  
4. Real DB user (not root)  
5. HTTPS certificate  
6. Cron: `queue:process`, `campaigns:process`, `automations:process`  
7. Cheerio: permanent API key, live number, webhook on HTTPS, app published  
8. Do **not** upload `_unused/`, SQL dumps, or bak env files  
9. Keep `writable/install.lock` after first install  
10. Set Cloudflare/`proxyIPs` if behind a proxy  

See [GUIDE_PRODUCTION.md](GUIDE_PRODUCTION.md) and [DEPLOY_YEVLE.md](DEPLOY_YEVLE.md).

---

## Local vs production

| | Local WAMP | Production |
|--|------------|------------|
| `CI_ENVIRONMENT` | `development` | `production` |
| Cookie Secure | false | true (auto) |
| Toolbar | on (except webhooks/api) | off (`CI_DEBUG=false`) |
| Webhook URL | tunnel | real HTTPS domain |

Local `.env` stays development on purpose so WAMP keeps working.
