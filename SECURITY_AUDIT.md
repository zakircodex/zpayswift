# Z-Pay Swift Security Audit

Date: 2026-06-08

## Scope Checked

- cPanel deployment root and copied public files.
- Root `.htaccess` clean routes, legacy redirects, directory listing, and deny rules.
- Public API documentation endpoint.
- `api/lib/*.php` helper files for direct browser access guards.
- User, admin, subadmin, worker, Telegram, Topup, Bundle, MFS route exposure at a static-code level.
- Secret exposure risks in public docs, Telegram text, and admin operator APIs.

## Fixed

- Added defense-in-depth direct access guards to backend helper files under `api/lib`.
- Hardened root `.htaccess` to block direct browsing of:
  - `.git`, `.env`, VCS metadata.
  - `.ini`, `.sql`, `.bak`, `.old`, `.log`, `.zip`, `.tar`, `.gz`, `.yml`, `.yaml`, `.lock`, `.dist`.
  - `error_log`, composer files, package lock files, and common local test files.
  - `/api/lib/*` direct web requests.
- Preserved clean panel routes:
  - `/user`
  - `/admin`
  - `/subadmin`
  - `/api/*`
- Preserved legacy API redirects:
  - `/zpayswift/api/*` to `/api/*`
  - `/zawtopup/api/*` to `/api/*`
- Added public API docs:
  - `/api/endpoints.php`
  - `/api/endpoints.php?format=json`
- Masked admin operator secret PIN values in `operators/list` and `operators/get`.
- Updated operator save behavior so a blank retailer PIN keeps the existing private value when one is already configured.

## Direct Browsing Blocked

These helper files are not public endpoints and now return `404 Not Found` when directly browsed:

- `api/lib/admin_topup.php`
- `api/lib/app_paths.php`
- `api/lib/auth.php`
- `api/lib/auth_sms.php`
- `api/lib/bundle.php`
- `api/lib/bundle_offers.php`
- `api/lib/firebase.php`
- `api/lib/helpers.php`
- `api/lib/mfs.php`
- `api/lib/operator_private.php`
- `api/lib/operators.php`
- `api/lib/roles.php`
- `api/lib/security.php`
- `api/lib/sms_bulksmsbd.php`
- `api/lib/subadmin_api.php`
- `api/lib/telegram.php`
- `api/lib/topup.php`
- `api/lib/users_admin.php`
- `api/lib/wallet.php`
- `api/lib/worker.php`

## Public Allowed URLs

- `/user`
- `/admin`
- `/subadmin`
- `/api/endpoints.php`
- `/api/endpoints.php?format=json`
- Public auth endpoints under `/api/auth/`
- Public receipt/tracking token endpoints under `/api/mfs/`
- Telegram webhook endpoints under `/api/telegram/`
- Worker endpoints under `/api/worker/` with worker key protection.
- Public subadmin API endpoints under `/api/public_api/` with API key protection.

## Protected URLs / APIs

- User APIs require app key plus user session where applicable.
- Admin APIs require admin session.
- Subadmin panel APIs require subadmin session.
- Worker APIs require `X-WORKER-KEY`.
- Public subadmin API requires `X-API-KEY`.
- Telegram webhooks require the configured webhook secret via query or Telegram secret header.

## Secret Handling

No real secrets are documented or committed. The following values must remain private and should never be pasted into Git:

- Firebase auth secret.
- App, admin, worker, and API keys.
- Telegram bot token and webhook/action keys.
- SMS API key.
- Password/PIN/token hashes.
- Retailer secret PIN.

## Remaining Operational Notes

- Worker claim responses still use the protected worker channel and must remain compatible with the tested worker app flow.
- Rotate any production secret that was ever exposed outside the private config or trusted deployment environment.
- Keep private config at `/home/zedpayhe/private/zpayswift/config.php`; legacy fallback to `/home/zedpayhe/private/zawtopup/config.php` remains only for compatibility.

## Test Checklist

1. Open `/api/endpoints.php` and `/api/endpoints.php?format=json`.
2. Open `/api/lib/helpers.php`; expected result is `403` from Apache or `404 Not Found` from PHP guard.
3. Open `/user`, `/admin`, `/subadmin`; dashboards should route as before.
4. Open old `/zpayswift/api/endpoints.php` and `/zawtopup/api/endpoints.php`; both should redirect to `/api/endpoints.php`.
5. Confirm `/api/auth/*`, `/api/worker/*`, `/api/public_api/*`, `/api/mfs/*`, `/api/telegram/*` are not blocked by `.htaccess`.
6. Admin Operators list/get should show masked retailer PIN state, not the raw PIN.
7. Saving an operator with blank retailer PIN should keep the existing private PIN if one is already set.
8. Run PHP syntax checks on changed PHP files.
