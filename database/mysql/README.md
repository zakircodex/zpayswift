# MySQL migration

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
- Login testing may use the stage-only OTP preview for exactly one private,
  allowlisted phone. It stays disabled by default, never contacts an SMS
  provider, and must remain limited to the `USER_LOGIN` purpose.

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

8. Compare representative production query ordering and pagination without
   printing source values:

   `php api/tools/verify_firebase_mysql_queries.php --config=/absolute/migration.php`

The importer reads each top-level Firebase tree twice and only marks it
verified when the source stayed stable and its canonical hash matches MySQL.
It also compares a canonical root inventory signature before and after a full
run; added or removed source trees make the run fail closed and require another
reconciliation pass. Query parity checks separately cover shallow reads,
ordered windows and limits against the live Firebase source.
The final cutover remains a separate, explicitly scheduled maintenance step.

## Production boundary

- Never reuse the staging database. Create a fresh production database and a
  least-privilege production database user.
- Apply `001_firebase_compat_production.sql`; its guard row is permanently
  pinned to `PRODUCTION`.
- Production migration requires two private write switches plus the
  `--confirm-production` argument. Final delta additionally requires
  `--confirm-final-delta` while `.deploy-in-progress` is present.
- Runtime MySQL requests verify the database guard once per PHP request.
- The current Firebase config and Firebase data remain available for rollback.

The complete release, reconciliation, cutover and rollback procedure is in
`database/mysql/PRODUCTION_CUTOVER.md`.

## Stage deployment

The `codex/mysql-stage` branch has a staging-only `.cpanel.yml`. It invokes
`scripts/deploy_mysql_stage.sh`, which refuses any repository, branch or public
root other than the isolated stage paths. Before promotion it verifies the
private MySQL runtime, the `STAGE` database guard and disabled outbound
integrations, then injects password protection into the deployment package.
The password file stays outside both Git and the document root.

The deployer never removes `.stage-not-ready`. Removing that lock is a separate
manual release decision after DNS, TLS, authentication and smoke tests pass.
