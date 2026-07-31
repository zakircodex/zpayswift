# Z News production checklist

## Required server runtime

- PHP 8.2 or newer.
- HTTPS enabled for all public, creator and admin endpoints.
- PHP extensions: JSON, cURL or HTTPS stream support, Fileinfo, OpenSSL and GD for perceptual image hashing.
- Server clock and timezone synchronised; signed ad timestamps and view leases depend on accurate server time.
- The private upload directory must exist outside the public webroot and be writable only by the PHP account.

## Private configuration

Configure secrets only in the existing private configuration layer. Do not commit them.

- Existing Firebase credentials and API bootstrap configuration.
- Existing app key and session configuration.
- `ZNEWS_AD_NETWORK_SECRETS` or `ZNEWS_AD_INGESTION_SECRET` with at least 32 unpredictable characters per network.
- Optional `ZNEWS_MEDIA_STORAGE_DIR`; otherwise verify the default private storage directory.
- Existing MYR-to-BDT rate must be configured before MYR settlement or MY-account wallet transfer.
- Configure admin-managed BDT-per-unit transfer rates for every non-BDT/non-MYR ad currency before creators request transfers.

## Filesystem safety

- Keep Z News media outside `public_html`.
- Directory permission target: `0750`.
- File permission target: `0640`.
- Confirm PHP can create year/month subdirectories.
- Confirm direct HTTP access to the private storage path returns no content.
- Confirm uploaded PHP, executable and malformed image files are rejected.

## Firebase namespaces

Back up Firebase before first production deployment. Confirm the server account can read/write the isolated `ZNEWS_*` namespaces used by posts, media, moderation, engagement, views, ads, settlements and transfers.

Do not grant browser or Android clients direct write access to financial, moderation, ad, settlement or transfer namespaces. All writes must pass through the PHP API.

## Deployment order

1. Deploy `api/znews/**`.
2. Deploy `api/admin/znews/**`.
3. Create and permission the private media storage directory.
4. Add private ad-network secrets.
5. Verify the existing MYR-to-BDT rate.
6. Configure transfer rates for ad currencies such as USD when required.
7. Run PHP syntax checks and all `tests/znews_*_test.php` suites.
8. Run `tests/znews_http_smoke_test.php` with `ZNEWS_SMOKE_BASE_URL`.
9. Test public feed and one approved public post.
10. Test creator session reads with a non-production test account.
11. Perform controlled write tests only in a dedicated test account and test post.

## Controlled end-to-end scenario

Use a dedicated test creator and admin account.

1. Upload a small valid JPEG/WebP.
2. Create a text-and-image post; verify `REVIEW/PENDING` and that it is absent from the public feed.
3. Approve the post with a valid copyright verdict; verify it becomes `ACTIVE/APPROVED` and public media is accessible.
4. Start a public view, send a heartbeat after the configured interval, wait at least the minimum read duration and complete it.
5. Submit a correctly signed test ad impression bound to that valid view.
6. Verify the impression is `VERIFIED`, but not credited before settlement.
7. Settle the impression as admin; verify the creator base share is 50%, the BDT creator credit is rounded down to whole paisa and never exceeds BDT 0.03 per verified ad, the platform receives the remainder, and the main wallet remains unchanged.
8. Accumulate at least BDT 500 equivalent in the creator's Z News balance.
9. Create a transfer request; verify the amount moves from available to reserved.
10. Approve it as admin; verify one deterministic main-wallet credit and one source-balance consume.
11. Repeat the same approval request and verify no second wallet credit occurs.
12. Create a second request and reject it; verify the reserved amount returns to available and the main wallet is unchanged.

## Release blockers

Do not merge/deploy as production-ready until all of these are evidenced:

- GitHub or local PHP syntax checks pass for every Z News PHP file.
- All Z News regression suites pass from a clean checkout.
- Read-only HTTP smoke test passes on the target cPanel environment.
- Live Firebase test confirms indexes and reconciliation states.
- Signed ad-network test uses the real provider contract or a formally agreed test contract.
- Wallet transfer test confirms the existing wallet ledger/history and idempotency records.
- A backup and rollback plan exists.

## Known integration gaps before public UI launch

- The friendly public route `/znews/post/{postId}` and web UI are not implemented in this backend branch.
- Public visitors can read posts without an account, but the current server-side share-counter endpoint requires an authenticated Z-Pay creator session. A public UI may still use native link sharing, but anonymous shares are not counted.
- Copyright decisions are currently moderation verdicts plus exact/perceptual media duplicate checks; external copyright-provider integration is not configured.
