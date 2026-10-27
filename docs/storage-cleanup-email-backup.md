# Automatic Storage Cleanup Email Backup

## Safety contract

- Automatic cleanup only considers terminal Add Money receipts, expired notification images, and attachments from closed support tickets after the 90-day retention boundary.
- Every selected file must be accepted by the configured mail transport and recorded in `STORAGE_CLEANUP_EMAIL_BACKUPS` before deletion begins.
- The file hash and size are checked again immediately before deletion. A changed file is skipped.
- Any email, receipt-write, audit, validation, or hash failure stops the batch without deleting its files.
- Automatic APK deletion is disabled. Gmail blocks APK attachments, including APK files inside archives, so app releases are retained on the server.
- Messages are split into at most 10 attachments and 12 MiB of raw file data per email. A file outside the allowed image/PDF types or above the limit blocks its batch.

## Configuration

The private server config controls the initial fail-closed defaults:

```php
define('STORAGE_CLEANUP_AUTOMATIC_ENABLED', true);
define('STORAGE_CLEANUP_AUTOMATIC_ALLOW_APK_DELETION', false);
define('STORAGE_CLEANUP_EMAIL_BACKUP_ENABLED', true);
define('STORAGE_CLEANUP_EMAIL_BACKUP_RECIPIENT', 'admin@example.com');
```

An authenticated Admin can change the enabled state and recipient under **System Settings > Storage Cleanup Backup**. The Admin value is stored in `APP_CONFIG` and overrides the private default. Audit and system logs record only whether an address is configured; they do not store the address.

## Operations

The production cron runs the CLI tool once daily. Useful diagnostics are:

```bash
php api/tools/cleanup_storage.php --status
php api/tools/cleanup_storage.php --email-test
php api/tools/cleanup_storage.php --dry-run
```

`--email-test` sends no attachments and deletes no files. A successful command means the local mail transport accepted the message; the recipient should still verify inbox or spam delivery.

Cleanup run audits are stored under `STORAGE_CLEANUP_RUNS`. A backup problem is recorded as `BACKUP_FAILED` with zero deleted files. Per-file email receipts store only hashes, sizes, run IDs, and a hash of the recipient address.
