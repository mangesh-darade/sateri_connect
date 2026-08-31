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

Config is stored in Settings → **ElintOm POS** (keys `elintom_base_url`, `elintom_api_private_key` in the `settings` table):

| key | group | notes |
|-----|-------|--------|
| `elintom_base_url` | `elintom` | e.g. `http://localhost/ElintOm` (no trailing slash) |
| `elintom_api_private_key` | `elintom` | Same as ElintOm `sma_settings.api_privatekey`. Saved encrypted via Settings UI. |

Then: **Settings → ElintOm POS → Sync customers now**, or **Contacts → Sync ElintOm customers** (`POST /contacts/sync-elintom`, permission `contacts.import`).

Optional SQL (if you cannot use the UI yet):

```sql
INSERT INTO settings (`key`, `value`, `group`, `is_encrypted`)
VALUES
  ('elintom_base_url', 'http://localhost/ElintOm', 'elintom', 0),
  ('elintom_api_private_key', 'YOUR_API_PRIVATE_KEY', 'elintom', 0)
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `group` = VALUES(`group`);
```
