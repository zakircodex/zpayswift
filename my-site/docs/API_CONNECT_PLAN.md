# My-Site API Connect Plan

## Rule

Do not connect this demo UI directly to existing live endpoints until tenant isolation is ready.

## Suggested New API Namespace

```text
/api/my_site/tenant_create.php
/api/my_site/tenant_get.php
/api/my_site/tenant_update.php
/api/my_site/subscription_check.php
/api/my_site/domain_verify_start.php
/api/my_site/domain_verify_check.php
/api/my_site/owner_login.php
/api/my_site/owner_dashboard.php
/api/my_site/user_register_start.php
/api/my_site/user_register_verify.php
/api/my_site/request_create.php
/api/my_site/request_history.php
/api/my_site/worker_link_start.php
/api/my_site/worker_link_verify.php
```

## Response Format

Must preserve existing Z-Pay Swift API response format:

```json
{
  "ok": true,
  "code": "SUCCESS",
  "message": "Done",
  "data": {}
}
```

## Required Middleware

Every tenant API must verify:

- app key/session where required
- owner session for control panel
- tenant_id ownership
- subscription status
- role permission
- object belongs to tenant_id

## Tenant Guard

Before creating request or allowing owner control:

```text
TENANTS/{tenant_id}/subscription_status must be ACTIVE or TRIALING
subscription_expires_at must be in the future
TENANTS/{tenant_id}/status must be ACTIVE
```

## Data Ownership Checks

For every user/request/wallet lookup:

```text
object.tenant_id === current_session.tenant_id
```

Main admin bypass must be explicit and logged.

## OTP/SMS Plan

White-label OTP in this phase:

- BD only
- Sent through Z-Pay Swift SMS backend
- SMS brand can use tenant site name
- Tenant owner cannot see SMS provider secret
- MY SMS is not included in this feature phase

## Worker Plan

Worker app phase 1:

- Existing Z-Pay Swift worker app remains unchanged until API middleware is ready.
- Link worker device by QR/token.
- Save worker device tenant_id.
- Worker claim must filter by same tenant_id.
- Worker result must update only same tenant request.

## Telegram Plan

Tenant Telegram actions must be signed and tenant-scoped.

Required checks:

- callback signature
- tenant_id
- request belongs to tenant
- owner/admin permission
- subscription status where needed

## Do Not Break

- Existing Firebase paths
- Existing API response shape
- Telegram callbacks
- Worker API contract
- Existing user/admin/subadmin panels
