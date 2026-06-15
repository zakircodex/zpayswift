# My-Site Database Structure Plan

This document defines the planned tenant data model. It must not rename or move existing Z-Pay Swift Firebase paths.

## Main Rule

Existing paths stay intact. Tenant support should be added with scoped fields and indexes, for example `tenant_id`, `owner_uid`, and tenant index paths.

## Tenant Root

```text
TENANTS/{tenant_id}
```

Suggested fields:

```json
{
  "tenant_id": "tenant_123",
  "owner_uid": "OWNER_UID",
  "plan": "FREE|SUBSCRIPTION",
  "subscription_status": "TRIAL|ACTIVE|EXPIRED|SUSPENDED",
  "trial_started_at": "ISO_DATE",
  "subscription_expires_at": "ISO_DATE",
  "site_name": "My BD Topup",
  "site_slug": "owner-id",
  "free_url": "https://zpayswift.com/site/owner-id",
  "custom_domain": "example.com",
  "domain_status": "NONE|PENDING|VERIFIED|FAILED",
  "brand_logo_url": "",
  "theme_color": "#0b1b3a",
  "service_country": "BD",
  "sms_brand_name": "My BD Topup",
  "created_at": "ISO_DATE",
  "updated_at": "ISO_DATE"
}
```

## Tenant Settings

```text
TENANT_SETTINGS/{tenant_id}
```

Suggested groups:

- commission
- topup_rules
- bundle_rules
- mfs_rules
- telegram
- worker
- domain
- branding
- security

## Tenant Users

Keep existing user records. Add tenant scope instead of moving users.

```text
USERS/{uid}/tenant_id = {tenant_id}
TENANT_USERS/{tenant_id}/{uid} = true
```

Owner can only read/manage users under their own `TENANT_USERS/{tenant_id}` index.

## Tenant Requests

Do not rename existing request paths. Add tenant scope to request records.

```text
TOPUP_REQUESTS/{status}/{request_id}/tenant_id
BUNDLE_REQUESTS/{status}/{request_id}/tenant_id
MFS_REQUESTS/{status}/{request_id}/tenant_id
```

Add tenant indexes:

```text
TENANT_REQUESTS/{tenant_id}/TOPUP/{request_id}
TENANT_REQUESTS/{tenant_id}/BUNDLE/{request_id}
TENANT_REQUESTS/{tenant_id}/MFS/{request_id}
```

## Tenant Wallet Scope

Existing wallet records can remain in place. Tenant owner tools must only affect users linked to the same tenant.

```text
USER_WALLETS/{uid}
TENANT_WALLETS/{tenant_id}/{uid}
TENANT_WALLET_LEDGER/{tenant_id}/{ledger_id}
```

## Tenant Worker Devices

First phase should reuse the existing worker app with tenant token/QR login.

```text
TENANT_WORKER_DEVICES/{tenant_id}/{device_id}
WORKER_DEVICES/{device_id}/tenant_id = {tenant_id}
```

## Tenant Telegram

```text
TENANT_TELEGRAM/{tenant_id}
```

Fields should use encrypted/private storage for secrets. Do not store bot tokens in public docs or frontend code.

## Tenant Subscription Payments

```text
TENANT_SUBSCRIPTIONS/{tenant_id}/{payment_id}
```

Suggested fields:

- amount
- currency
- plan
- paid_at
- valid_from
- valid_until
- status
- payment_method
- receipt_url
- admin_note

## Access Rule

```text
Main Admin: all tenants
Tenant Owner: only own tenant_id
Tenant User: own account only
Worker App: only assigned tenant_id requests
```
