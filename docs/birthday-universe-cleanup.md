# Birthday Universe cleanup

Birthday photos, drafts and public records are stored outside the public webroot
and remain private unless served through the validated media endpoints. Active
Universes expire after the configured retention period (90 days by default).
The first cleanup pass marks them `EXPIRED`; a seven-day grace period protects
against accidental deletion before the final record and media cleanup.

The cleanup command uses a short Firebase compare-and-swap lease, so overlapping
cron runs do not process the same records concurrently. Each run applies its
limit independently to actionable Universes, drafts, stale photo records and
expired transient guards. Active records therefore cannot starve expired drafts.
Replaced photos receive a 24-hour safety window before their private files are
removed. Expired idempotency, rate-limit and view-dedup records are also purged.

## Verify before scheduling

```bash
cd /home/zedpayhe/public_html
/usr/local/bin/php api/tools/cleanup_birthday_universes.php --dry-run --limit=100
```

## cPanel cron

Run the bounded cleanup once per hour:

```bash
cd /home/zedpayhe/public_html && /usr/local/bin/php api/tools/cleanup_birthday_universes.php --limit=100
```

The last completed result is available with:

```bash
/usr/local/bin/php api/tools/cleanup_birthday_universes.php --status
```

Use `BIRTHDAY_UNIVERSE_STORAGE_DIR` only when the hosting layout requires a
different private directory. Never point it at `public_html`.
