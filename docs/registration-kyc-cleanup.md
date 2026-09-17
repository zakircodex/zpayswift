# Registration KYC cleanup

Temporary registration documents and selfies remain outside the public webroot.
They become eligible for deletion 72 hours after the registration pre-auth
record expires. Finalized accounts, review evidence, active cleanup leases, and
identity records that already belong to a user always fail closed and remain
untouched.

## Automatic operation

Authenticated registration traffic schedules a bounded post-response cleanup.
A private state file and non-blocking file lock allow only one run per configured
interval, so parallel registration requests cannot start duplicate cleanup jobs.
The defaults run at most once per hour and process at most 25 eligible records.

Check the last automatic run from cPanel Terminal without exposing file paths:

```bash
php api/tools/cleanup_registration_kyc.php --status
```

## cPanel cron fallback

For low-traffic periods, configure this hourly cPanel Cron Job as a second
trigger. The cleanup itself uses Firebase compare-and-swap claims, so overlap
with the automatic trigger remains safe:

```bash
cd /home/zedpayhe/public_html && /usr/local/bin/php api/tools/cleanup_registration_kyc.php --limit=100
```

Run a read-only check before enabling the cron:

```bash
cd /home/zedpayhe/public_html && /usr/local/bin/php api/tools/cleanup_registration_kyc.php --dry-run --limit=100
```

Keep cleanup enabled unless a production incident is being investigated. Its
interval, retention and batch sizes live only in the private configuration.
