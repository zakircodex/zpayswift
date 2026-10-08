# MySQL staging migration

This directory contains the Firebase-compatible MySQL staging store. It is
deliberately inactive unless a private configuration explicitly selects the
`mysql` datastore driver.

## Safety boundary

- Production remains on Firebase during staging and reconciliation.
- Use a dedicated database and database user for staging.
- Keep runtime and migration configs under
  `/home/zedpayhe/private/zpayswift-stage`, outside the document root.
- Leave `/home/zedpayhe/stage.zpayswift.com/.stage-not-ready` present until
  schema, import, verification and smoke tests all pass.
- Stage SMS, Telegram, push and operator credentials must remain empty.

## Initial import

1. Apply `001_firebase_compat.sql` to the isolated staging database.
2. Derive isolated runtime and migration configs with the CLI-only
   `api/tools/build_stage_runtime_config.php`; credentials are read only from
   protected environment variables. The generated runtime config replaces all
   production secrets and disables live outbound integrations.
3. A migration write additionally verifies the `STAGE` row in
   `zps_environment_guard`, so an accidental production DSN is rejected.
4. Run a read-only inventory first:

   `php api/tools/migrate_firebase_to_mysql.php --config=/absolute/migration.php --dry-run`

5. Enable `MYSQL_MIGRATION_ALLOW_WRITE` only in that private config, then run:

   `php api/tools/migrate_firebase_to_mysql.php --config=/absolute/migration.php --execute`

6. Re-run reconciliation while production stays live:

   `php api/tools/migrate_firebase_to_mysql.php --config=/absolute/migration.php --execute --mode=reconcile`

7. Verify without changing application data:

   `php api/tools/migrate_firebase_to_mysql.php --config=/absolute/migration.php --verify-only`

The importer reads each top-level Firebase tree twice and only marks it
verified when the source stayed stable and its canonical hash matches MySQL.
It also compares the root Firebase ETag before and after a full run; live writes
make the run fail closed and require another reconciliation pass.
The final cutover remains a separate, explicitly scheduled maintenance step.
