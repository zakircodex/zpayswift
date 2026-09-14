# Admin Mobile Rollout

The admin Android API is opt-in and disabled by default. It reuses the existing canonical domain operations so wallet locking, request state transitions, audit logs, and notification behavior stay aligned with the web admin panel.

## Deployment order

1. Back up the production database and the current deployed code.
2. Deploy the backend while `ADMIN_MOBILE_ENABLED` remains false.
3. Run the PHP contract and regression tests.
4. Build the admin app with the private app key and a separate admin signing key.
5. Test login, OTP, session lock, and one non-production request of every supported type.
6. Allow notification permission, then verify one new-request alert while the app is backgrounded.
7. Verify that success and failed decisions create a notification in the customer app.
8. Set `ADMIN_MOBILE_ENABLED` to true in the private `api/config.php`.
9. Monitor admin audit logs and request states before wider use.

Private server configuration:

```php
define('ADMIN_MOBILE_ENABLED', true);
define('ADMIN_MOBILE_SESSION_TTL_SECONDS', 60 * 60 * 2);
define('ADMIN_MOBILE_MIN_VERSION_CODE', 1);
```

## Emergency controls

- Set `ADMIN_MOBILE_ENABLED` to false to reject new logins and invalidate all active admin-mobile requests.
- Set an admin device row under `ADMIN_MOBILE_DEVICES/{adminUid}/{deviceKey}` to `REVOKED` to block a lost device.
- Admin push tokens are bound to active admin devices under `ADMIN_MOBILE_PUSH_TOKENS`; logout deactivates the current device tokens.
- Increase `ADMIN_MOBILE_MIN_VERSION_CODE` to require a newer app build.
- Use the existing web admin panel while the mobile channel is disabled.

## Required production checks

```powershell
C:\xampp\php\php.exe tests\admin_mobile_api_contract_test.php
C:\xampp\php\php.exe tests\admin_panel_hardening_test.php
C:\xampp\php\php.exe tests\account_review_atomic_test.php
C:\xampp\php\php.exe tests\add_money_safety_test.php
C:\xampp\php\php.exe tests\mfs_wallet_recovery_test.php
C:\xampp\php\php.exe tests\topup_rules_test.php
```

Do not put `APP_KEY`, Firebase credentials, admin passwords, OTPs, session tokens, or signing passwords in Git, screenshots, tickets, or application logs.
