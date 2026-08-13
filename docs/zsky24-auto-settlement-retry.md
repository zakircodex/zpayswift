# Z Sky 24 automatic settlement retry (retired)

Per-view automatic creator credit is retired. Creator payouts now use the
canonical period-review/direct-Z-Pay payout flow.

Do not configure or run an automatic-settlement retry cron. The retained CLI
entrypoint exits without reading retry rows or changing balances, so an old cron
cannot apply a legacy payout after deployment.

Historical retry rows and settlement code are not deleted or migrated by this
retirement. Review them separately if reconciliation is required.
