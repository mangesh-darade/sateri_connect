# Schema + Model Sync Rule (MANDATORY)

**Compulsory, no exceptions.** Whenever you create a table or add/change/remove a column, update **all three** in the same change. The task is NOT done, and must not be reported as done, until all three exist. A migration alone is never enough.

1. **Migration**: `app/Database/Migrations/<date>_<Name>.php`. Guard with `tableExists` / `fieldExists` so it is safe to re-run. Tenant tables only; master tables go in `TenantEnsureMaster` (never run `migrate -g master`).
2. **Schema snapshot**: `app/Database/Schema/tables.php` (used by `SchemaRepairService` to self-heal client DBs). Regenerate with `php spark schema:snapshot -tenant <key>` from a fully migrated tenant DB. If that is not possible, add the table/column entry by hand in the same format and say so.
3. **Model with its schema**: `app/Models/<Name>Model.php`. A new table **must** get a new Model (use the `SelfHealingSchema` trait like the other models, plus `$allowedFields`, casts and validation). A new column **must** be added to the existing Model's `$allowedFields` (and casts/validation where relevant). A removed column leaves no references.

Also: check queries, views and services that read or write the changed table.
In the final message, list the three files touched, or state why one was not needed.
