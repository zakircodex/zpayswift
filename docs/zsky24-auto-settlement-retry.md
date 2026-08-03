# Z Sky 24 automatic settlement retry

Failed automatic creator-credit attempts are stored under
`ZNEWS_AUTO_SETTLEMENT_RETRIES`. The worker is CLI-only and reuses the existing
exact-once settlement service.

Recommended cPanel cron (every five minutes):

```cron
*/5 * * * * /usr/local/bin/php /home/zedpayhe/repositories/zpayswift/tools/zsky24_auto_settlement_retry.php --limit=50 >/dev/null 2>&1
```

Confirm the PHP path and repository path in cPanel before enabling the cron.
Rows use bounded exponential backoff and stop automatically after 12 failed
attempts. Failed rows remain available for investigation; they never accept a
client-provided settlement amount.
