# MySQL production cutover

This runbook moves Z-Pay Swift from Firebase to a fresh production MySQL
database. Code release and datastore cutover are separate decisions. Merging
or deploying the code does not switch the datastore while the private
production config still has `DATASTORE_DRIVER=firebase`.

## 1. Release the compatible code

1. Merge the reviewed production candidate to `main`.
2. Run the protected `cPanel Production Deploy` GitHub Actions workflow.
3. Confirm `deploy_version.txt` matches the merged commit and run the normal
   login, dashboard, transfer, MFS, top-up, bundle, history and support smoke
   checks. Firebase remains active during this phase.

## 2. Provision production MySQL

1. Create a new production-only database and least-privilege user in cPanel.
   Do not reuse the staging database or user.
2. Apply `database/mysql/001_firebase_compat_production.sql` through phpMyAdmin.
3. Confirm `zps_environment_guard` contains exactly `id=1` and
   `environment=PRODUCTION`.
4. Keep the DSN, username and password in protected server environment
   variables; never place them in Git, a command history, or the document root.

## 3. Build private candidates

From the production repository, inject the protected environment variables for
one process and build private files outside `public_html`:

```bash
php api/tools/build_production_mysql_config.php
```

The builder reads `ZPAY_PRODUCTION_MYSQL_DSN`,
`ZPAY_PRODUCTION_MYSQL_USER`, `ZPAY_PRODUCTION_MYSQL_PASSWORD`,
`ZPAY_PRODUCTION_MIGRATION_ALLOW_WRITE` and
`ZPAY_PRODUCTION_MIGRATION_ALLOW_PRODUCTION_WRITE`. It preserves the current
production settings in `config.mysql-candidate.php`, changes only the datastore
settings, and writes a separate migration config. Both files are mode `0600`.

## 4. Import while Firebase stays live

Run inventory, import, repeated reconciliation and parity checks:

```bash
php api/tools/migrate_firebase_to_mysql.php --config=/home/zedpayhe/private/zpayswift/migration.mysql-production.php --dry-run
php api/tools/migrate_firebase_to_mysql.php --config=/home/zedpayhe/private/zpayswift/migration.mysql-production.php --execute --mode=import --confirm-production
php api/tools/migrate_firebase_to_mysql.php --config=/home/zedpayhe/private/zpayswift/migration.mysql-production.php --execute --mode=reconcile --confirm-production
php api/tools/migrate_firebase_to_mysql.php --config=/home/zedpayhe/private/zpayswift/migration.mysql-production.php --verify-only --confirm-production
php api/tools/verify_firebase_mysql_queries.php --config=/home/zedpayhe/private/zpayswift/migration.mysql-production.php
php scripts/verify_mysql_production_runtime.php /home/zedpayhe/private/zpayswift/config.mysql-candidate.php
```

Repeat reconciliation until the full run reports zero mismatches and zero
failures. These commands do not change the active production runtime config.

## 5. Final cutover

Schedule a short maintenance window, ensure the production repository is clean
and exactly matches `origin/main`, then run:

```bash
ZPAY_CONFIRM_PRODUCTION_CUTOVER=ACTIVATE_PRODUCTION_MYSQL \
  bash scripts/cutover_mysql_production.sh
```

The script creates the public maintenance marker, runs a verified final delta,
checks query parity, backs up the Firebase runtime config, atomically activates
the MySQL candidate, verifies the live config and removes the marker. On any
failure it leaves maintenance enabled; activation failures restore the Firebase
config automatically.

## 6. Verify and rollback

After cutover, verify login, dashboard balances, transfer and receipt links,
MFS, top-up, bundle, current-month history, after-balance values, profile photo,
tagline and support pages. Keep Firebase unchanged during the observation
window.

To roll back, create `.deploy-in-progress`, atomically restore the newest
`cutover-backups/config.firebase.*.php` to the private `config.php`, run the
normal smoke checks, then remove the marker. Diagnose and reconcile MySQL before
attempting another cutover.
