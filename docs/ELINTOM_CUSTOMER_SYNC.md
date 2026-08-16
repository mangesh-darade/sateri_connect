# ElintOm → Sateri Connect customer sync (URL)

Sateri Connect pulls customers from ElintOm over HTTP.

## ElintOm (minimal)

| File | Role |
|------|------|
| `app/models/Sateri_contacts_model.php` | Reads syncable customers from `sma_companies` |
| `app/libraries/Sateri_contacts_api.php` | Builds JSON payload |
| `Api3` action `sateri_contacts` | Auth via existing Api3 `privatekey` |

Endpoint:

```http
POST {elintom_domain}/api3/eshop
Content-Type: application/x-www-form-urlencoded

privatekey=YOUR_API_PRIVATE_KEY&action=sateri_contacts
```

ElintOm requirements: `sma_settings.api_access` enabled, and `api_privatekey` set.

## Sateri Connect

Config is stored in the `settings` table (no Settings UI):

| key | group | notes |
|-----|-------|--------|
| `elintom_base_url` | `elintom` | e.g. `http://localhost/ElintOm` (no trailing slash) |
| `elintom_api_private_key` | `elintom` | Same as ElintOm `sma_settings.api_privatekey`. Prefer plaintext without `enc:` prefix if inserting by SQL; or save via `SettingsService::set` so it is encrypted. |

Example SQL (plaintext private key — OK; decrypt only runs on `enc:` values):

```sql
INSERT INTO settings (`key`, `value`, `group`, `is_encrypted`)
VALUES
  ('elintom_base_url', 'http://localhost/ElintOm', 'elintom', 0),
  ('elintom_api_private_key', 'YOUR_API_PRIVATE_KEY', 'elintom', 0)
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `group` = VALUES(`group`);
```

Then: **Contacts → Sync ElintOm customers** (`POST /contacts/sync-elintom`, permission `contacts.import`).
