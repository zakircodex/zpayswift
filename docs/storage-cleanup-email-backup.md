# Automatic Storage Cleanup Email Backup

## Safety contract

- Automatic cleanup only considers terminal Add Money receipts, expired notification images, and attachments from closed support tickets after the 90-day retention boundary.
- Every selected file must be accepted by the configured mail transport and recorded in `STORAGE_CLEANUP_EMAIL_BACKUPS` before deletion begins.
- Settings reads use the status-bearing datastore request, validate JSON/schema, and require an ETag. Transport, authentication, server, malformed-response, and missing-version errors stop sending and automatic deletion. Shared `fb_get()` behavior is unchanged.
- The file hash and size are checked again immediately before deletion. A changed file is skipped.
- Any email, receipt-write, audit, validation, or hash failure stops the batch without deleting its files.
- Automatic APK deletion is disabled. Gmail blocks APK attachments, including APK files inside archives, so app releases are retained on the server.
- Messages are split into at most 10 attachments and 12 MiB of raw file data per email. A file outside the allowed image/PDF types or above the limit blocks its batch.

## Configuration

The private server config controls bootstrap defaults:

```php
define('STORAGE_CLEANUP_AUTOMATIC_ENABLED', true);
define('STORAGE_CLEANUP_AUTOMATIC_ALLOW_APK_DELETION', false);
define('STORAGE_CLEANUP_EMAIL_BACKUP_ENABLED', true);
define('STORAGE_CLEANUP_EMAIL_BACKUP_RECIPIENT', 'admin@example.com');
```

An authenticated Admin can change the enabled state and recipient under **System Settings > Storage Cleanup Backup**. The Admin value is stored in `APP_CONFIG` and overrides the private default. Audit and system logs record only whether an address is configured; they do not store the address.

Bootstrap defaults are allowed only after a successful versioned read confirms JSON `null` (missing `APP_CONFIG`) or an object with both cleanup email fields and the revision absent. A partial configuration, non-boolean enabled value, invalid recipient, or invalid revision is an error; it is never combined with private defaults. Explicit `false` remains disabled, including when private defaults are enabled. A failed read returns no recipient and cannot bootstrap. Malformed stored settings require operator repair before retrying.

Admin config GET and save return an explicit 503 on an unverifiable read. Save holds the same local process lock as manual/automatic cleanup and uses the verified ETag for a conditional write. A busy run returns 409; a stale write returns `APP_CONFIG_CHANGED`. The server creates a new `storage_cleanup_email_revision` when the recipient or enabled state changes, including changing away and back. The successful response retains its existing fields with this additive revision.

## Run consistency and retries

The CLI passes its validated readiness snapshot into backup execution. Its ETag and configuration hash are rechecked before transport, after backup, before file processing, and immediately before unlink. A changed/disabled/unreadable configuration stops remaining work without switching recipients. The shared local lock prevents supported Admin saves during a run. Direct datastore/private-config changes must be performed while cleanup is stopped; external writers do not participate in the local lock. Checks detect changes at their boundaries, not an atomic transaction spanning an external mail server and filesystem. Already submitted email cannot be recalled.

Receipts bind candidate ID, file SHA-256, byte count, destination hash, and configuration hash. An accepted receipt from a different recipient/revision or different file cannot authorize deletion. A new confirmed backup is required. Legacy accepted receipts without configuration binding are not deletion authority and require a new backup.

Before mail submission, each attachment gets a durable `PENDING` intent written with ETag compare-and-swap and read back for verification. Competing claims, failed reads, and failed intent writes prevent submission. A definite `mail() === false` rejection is marked `FAILED` and may be retried. Exceptions or non-boolean results, interruption after intent creation, and failure to record acceptance leave a pending/uncertain outcome: automatic retry neither resends nor deletes. An operator must reconcile that attempt using server-side evidence before changing its receipt. No automatic recipient fallback or receipt reset is provided. Do not print or export receipt contents.

An accepted, matching receipt can be reused after a safe retry. A settings failure after an email was accepted preserves that receipt but blocks deletion. If an external change is detected after metadata archival, quarantined bytes are retained and counted as orphaned for operator recovery. Files deleted before a later run failure are not restored. Manual cleanup keeps its separate Admin, CSRF, preview-token, typed-confirmation, audit, retention, and current/rollback APK protections; it does not acquire deletion authority from email settings.

## Operations

The production cron runs the CLI tool once daily. Useful diagnostics are:

```bash
php api/tools/cleanup_storage.php --status
php api/tools/cleanup_storage.php --email-test
php api/tools/cleanup_storage.php --dry-run
```

`--email-test` sends no attachments and deletes no files. A successful command means the local mail transport accepted the message; the recipient should still verify inbox or spam delivery.

Cleanup run audits are stored under `STORAGE_CLEANUP_RUNS`. A backup problem before deletion is recorded as `BACKUP_FAILED` with zero deleted files. A later authorization failure stops the remaining files and produces a partial run with the actual deletion count and an `email_backup.code`. CLI `--status` reports `email_settings_code`; settings/receipt errors require investigation and retry rather than assuming an empty configuration. Per-file email receipts store hashes, sizes, run/attempt IDs, transport state, and a hash of the recipient address, never attachment contents or the recipient address itself.

## Local verification

Use isolated fixtures with mocked datastore, mail, and deletion operations:

```bash
php tests/storage_cleanup_settings_safety_test.php
php tests/storage_cleanup_admin_config_test.php
php tests/storage_cleanup_email_backup_test.php
php tests/storage_cleanup_safety_test.php
php tests/automatic_storage_cleanup_test.php
php tests/admin_storage_cleanup_ui_test.php
php tests/run_core_web_tests.php
```

These tests never invoke the production CLI, send real email, or load private server configuration. MySQL integration tests require an explicitly isolated test DSN and are skipped when none is configured.
