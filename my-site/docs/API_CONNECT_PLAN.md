# Z Builder API Connect Plan

## Rule

Do not connect Z Builder directly to existing live transaction endpoints until tenant isolation is ready.

## Product Account Rule

Z Builder owner account must be separate from normal Z-Pay Swift user account.

Suggested owner paths:

```text
Z_BUILDER_OWNERS/{owner_id}
Z_BUILDER_OWNER_SESSIONS/{session_hash}
```

Tenant/site paths stay scoped:

```text
TENANTS/{tenant_id}
TENANT_SETTINGS/{tenant_id}
TENANT_SUBSCRIPTIONS/{tenant_id}
TENANT_USERS/{tenant_id}
TENANT_REQUESTS/{tenant_id}
```

## Suggested Safe API Namespace

Keep current namespace for compatibility:

```text
/api/my_site/tenant_create.php
/api/my_site/tenant_get.php
/api/my_site/subscription_check.php
```

Next owner-account APIs:

```text
/api/my_site/auth_register.php
/api/my_site/auth_login.php
/api/my_site/auth_session.php
/api/my_site/auth_logout.php
/api/my_site/owner_dashboard.php
/api/my_site/subscription_request.php
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

Every Z Builder owner/tenant API must verify:

- app key or public-safe route design
- owner session for control panel
- tenant_id ownership
- subscription status
- role/permission
- object belongs to tenant_id

## Subscription Guard

Before creating request or allowing owner control:

```text
TENANTS/{tenant_id}/subscription_status must be ACTIVE or TRIALING
subscription_expires_at must be in the future
TENANTS/{tenant_id}/status must be ACTIVE
```

Plans:

```text
FREE_TRIAL = 7 days
SUBSCRIPTION_3M = 3 months
SUBSCRIPTION_6M = 6 months
SUBSCRIPTION_12M = 1 year
```

Payment starts manual first. Z-Pay Swift admin will approve subscription later.

## Data Ownership Checks

For every user/request/wallet lookup:

```text
object.tenant_id === current_session.tenant_id
```

Main admin bypass must be explicit and logged.

## OTP/SMS Plan

Z Builder white-label OTP in this phase:

- BD only
- Sent through Z-Pay Swift SMS backend
- SMS brand can use tenant site name
- Tenant owner cannot see SMS provider secret
- MY SMS is not included in this feature phase

## Worker Plan

Worker app phase 1:

- Existing Z-Pay Swift worker app remains unchanged until API middleware is ready.
- Link worker device by QR/link code.
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
