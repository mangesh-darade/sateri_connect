# Daily changelog — 28 Jul 2026

Summary of work done today on **Sateri Connect** (`dev` branch): UI standards, campaign wizard polish, reusable error screens, and multi-tenant DB config (no `.env` DB merge).

## Test results (same day)

| Suite | Result |
|-------|--------|
| `scripts/check_error_screen.php` | OK |
| `scripts/check_subdomain_db.php` | OK |
| `tests/CampaignWizardFeatureTest.php` | 29 passed |
| `tests/CampaignWizardDeepTest.php` | 45 passed |
| `tests/TemplatesCreateFeatureTest.php` | 51 passed |
| `tests/TemplatesVariableExamplesUnitTest.php` | 13 passed |
| `tests/TemplatesCarouselUnitTest.php` | 12 passed |
| PHPUnit (`phpunit.dist.xml` App suite) | 9 passed |
| HTTP `/login` | 200 |

---

## 1. Page header + section UI standard

**Goal:** Every screen uses one header pattern and consistent section spacing.

- Global header in `app/Views/layouts/main.php`: breadcrumb → title + provider chip → `header_actions`
- Cursor rules:
  - `.cursor/rules/ui-page-header-standard.mdc`
  - `.cursor/rules/reusable-functions.mdc`
  - `.cursor/rules/project-development-standards.mdc` (kept)
- Migrated list/form screens off duplicate toolbars into `header_actions`
- Section CSS in `public/assets/css/app.css`:
  - `--section-gap`, `.page-list` stack, `.page-section`, `.page-hint`, `.launch-card`, filter-bar polish
- Key pages polished: Dashboard, Campaigns, Templates, Contacts, Analytics, Reports, Queue, Emails, Settings, Guides, Keywords, Users, Automations, Customer Groups

## 2. Campaign wizard (WhatsApp + Email)

- Unified Recent Campaigns hub (`/campaigns`)
- Multi-step wizard: Name/Label → Attributes → Template → Media → Schedule/Run
- Endpoints: `wizard-data`, `audience-preview`, `labels`, `wizard`, run/schedule
- Services: `CampaignService`, `EmailCampaignService`
- Migration: `email_html_campaigns.scheduled_at`
- UI/JS: `campaigns/_wizard.php`, `public/assets/js/campaigns.js`
- Media upload null-safety + drag-drop on template header media

## 3. Templates / provider API improvements

- Cheerio/Meta clearer API error extraction
- Body variable-ratio validation (WhatsApp rule) + UI warning
- Template create UX (variables, carousel checks, preview)
- Related commands/tests updated

## 4. Branded reusable error screen

**Goal:** Never show raw CI4 debug dump (`SYSTEMPATH` / huge backtrace) to users.

| Piece | Role |
|-------|------|
| `app/Libraries/ErrorPresenter.php` | Maps exceptions → friendly title/message/hint |
| `app/Libraries/AppExceptionHandler.php` | HTML + AJAX JSON handler |
| `app/Views/errors/html/app_error.php` | Shared branded UI |
| `app/Helpers/error_helper.php` | `present_app_error()` / `render_app_error()` |
| `app/Config/Exceptions.php` | Wires custom handler |

Database errors (e.g. unknown DB name) show a clear screen with optional collapsible technical details in development only.

## 5. Multi-tenant DB — no `.env` credentials

**Goal:** Connection credentials only from `Config\Database::applyBySubdomain()`.

| File | Change |
|------|--------|
| `app/Libraries/SubdomainDatabase.php` | Defaults, subdomain detect, `boot()` wipes `.env` `database.default.*` |
| `app/Config/Database.php` | Tenant switch only (`localhost` → `sateri_connect`) |
| `.env` / `.env.example` | Removed `database.default.*` |
| `app/Controllers/Install.php` | Writes DB into `Database.php` switch, not `.env` |

**Flow:** detect subdomain → `applyBySubdomain($tenant)` → connect.  
Optional CLI force: `database.tenant = herbinn` (which switch case — not credentials).

## 6. Other related work in the same working tree

- Customer groups module (controllers/views/JS + API)
- Auth signup / email verification pieces
- Provider docs (`PROVIDER_*`, `META_PROVIDER_SETUP_GUIDE.md`)
- CSRF filter helper, template_type / users verification migrations
- Sidebar CSS / app.js small updates

---

## How to add a new tenant DB

Edit only `app/Config/Database.php`:

```php
case 'client1':
    $this->default['hostname'] = 'localhost';
    $this->default['username'] = 'root';
    $this->default['password'] = '';
    $this->default['database'] = 'client1_db';
    $this->default['port']     = 3306;
    break;
```

Do **not** put DB credentials in `.env`.

---

## Deploy notes

1. Run pending migrations (email verification, template_type, email campaign `scheduled_at`, etc.).
2. Confirm MySQL database from the active subdomain case exists (e.g. `sateri_connect` for localhost).
3. Hard-refresh browser so `app.css?v=sections2` loads.
4. Smoke: login → dashboard → campaigns wizard → templates list → force a bad DB name once to confirm branded error screen.
