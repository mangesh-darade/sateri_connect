# Schema + Model Sync Rule

Whenever you create a table or add/change/remove a column, update **all three** in the same change:

1. **Migration**: `app/Database/Migrations/<date>_<Name>.php`. Guard with `tableExists` / `fieldExists` so it is safe to re-run. Tenant tables only; master tables go in `TenantEnsureMaster` (never run `migrate -g master`).
2. **Schema snapshot**: `app/Database/Schema/tables.php` (used by `SchemaRepairService` to self-heal client DBs). Regenerate with `php spark schema:snapshot -tenant <key>` from a fully migrated tenant DB. If that is not possible, add the table/column entry by hand in the same format and say so.
3. **Model**: `app/Models/<Name>Model.php`. New table gets a Model. New column goes into `$allowedFields` (and casts/validation where relevant). Removed column leaves no references.

Also: check queries, views and services that read or write the changed table.
In the final message, list the three files touched, or state why one was not needed.
