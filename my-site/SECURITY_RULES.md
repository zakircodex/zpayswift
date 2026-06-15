# My-Site Security Rules

## Main Security Rule

Tenant owner must never access another tenant's users, wallets, requests, reports, workers, Telegram settings, or domain settings.

```text
Main Admin: all tenants
Tenant Owner: own tenant only
Tenant User: own account only
Worker App: assigned tenant only
```

## Required Backend Checks

Every tenant API should validate:

- authenticated session/token
- owner role or tenant role
- tenant_id
- subscription status
- requested user/request/wallet belongs to same tenant
- account status is active where required

Frontend checks are not enough. Enforcement must be backend-side.

## Subscription Enforcement

Before any tenant page or action:

```text
subscription_status
subscription_expires_at
```

Expired tenant must not perform:

- new topup
- bundle request
- MFS request
- balance add/deduct
- success/failed control
- user active/disable
- commission update
- Telegram action
- worker claim/setup

Only payment/renewal can remain open.

## Secrets

Never expose these in public pages, Git, docs, JS, or HTML:

- APP_KEY
- WORKER_KEY
- ADMIN_KEY
- Firebase auth
- Firebase database secret
- SMS API keys
- Telegram bot token
- Telegram webhook/action keys
- OTP code/hash
- PIN/password hash
- API keys
- worker tokens
- signing keys

Example files must use placeholders only.

## OTP/SMS Scope

White-label OTP/SMS feature is BD-only for this phase.

Rules:

- Z-Pay Swift SMS backend sends OTP.
- Tenant SMS brand can use tenant site name.
- MY SMS is not supported in this white-label feature.
- OTP must go to target user's phone, not owner/admin phone.
- OTP TTL and retry rules should remain centralized.

## Domain Security

Custom domain must be verified before activation.

Suggested verification:

- DNS TXT record
- CNAME target check
- HTTPS ready check

Do not allow unverified domains to serve tenant site.

## Telegram Security

Tenant Telegram support must be tenant-scoped.

- Tenant bot token must be stored privately/encrypted.
- Telegram callback must include signed tenant/request scope.
- Owner can only approve/fail/success requests under own tenant.
- Main admin can override globally.

## Worker Security

Worker app must validate:

- worker key/token
- device_id
- tenant_id
- enabled device
- matching request tenant_id
- matching operator slot

Never let a worker claim requests without tenant scope.

## Audit Logs

Every tenant control action should write audit log:

```text
TENANT_AUDIT_LOGS/{tenant_id}/{log_id}
```

Log fields:

- actor_uid
- actor_role
- action
- target_type
- target_id
- before/after summary where safe
- ip
- user_agent
- created_at

Do not log secrets, OTP, PIN, password hash, or API keys.
