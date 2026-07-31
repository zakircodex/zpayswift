# cPanel push deployment

The production deployment no longer depends on cPanel contacting GitHub. The
manual `cPanel Production Deploy` GitHub Actions workflow audits `main`, builds
an allowlisted public package, uploads it with explicit FTPS, and verifies the
deployed commit through `deploy_version.txt`.

## One-time GitHub environment setup

Create a protected GitHub environment named `production` and add these secrets:

| Secret | Value |
| --- | --- |
| `CPANEL_FTP_HOST` | The TLS-enabled cPanel FTP hostname |
| `CPANEL_FTP_PORT` | Usually `21` |
| `CPANEL_FTP_USERNAME` | The cPanel FTP account username |
| `CPANEL_FTP_PASSWORD` | The cPanel FTP account password |
| `CPANEL_FTP_REMOTE_PATH` | The FTP-visible document root, normally `/public_html` |
| `CPANEL_PRIMARY_VERIFY_URL` | `https://zpayswift.com/deploy_version.txt` |
| `CPANEL_SECONDARY_VERIFY_URL` | `https://zsky24.com/deploy_version.txt` when both hosts share the document root |

Do not put credentials in repository files, workflow inputs, issues, pull
requests, or chat. Configure environment approval protection if the GitHub plan
supports it.

## Deploy

1. Merge only after the required PR checks are green.
2. Open **Actions → cPanel Production Deploy → Run workflow** on `main`.
3. Wait for **Audit, upload and verify** to succeed.
4. Record the workflow URL and the exact SHA printed by **Verify deployed commit**.

The upload is non-destructive: it does not delete server-only files and the
package rejects private configuration, logs, archives, SQL dumps, Git metadata,
and environment files.
