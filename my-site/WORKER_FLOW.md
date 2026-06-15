# My-Site Worker Flow

## Goal

Allow each subscription tenant/site owner to process their own topup requests through the existing Z-Pay Swift Android worker app, while keeping backend and database under Z-Pay Swift.

## Phase 1: Reuse Existing Worker App

This is the safest MVP.

Owner flow:

```text
Control Panel
  -> Worker App
  -> Add Device
  -> Generate QR/Device Token
  -> Open Z-Pay Swift Worker App
  -> Scan/Enter Token
  -> Device links to tenant_id
```

Worker app then claims only requests for the assigned tenant.

## Tenant Worker Scope

```text
TENANT_WORKER_DEVICES/{tenant_id}/{device_id}
WORKER_DEVICES/{device_id}/tenant_id = {tenant_id}
```

Worker claim logic should ensure:

- device is online
- device is enabled
- accessibility is enabled
- device belongs to tenant_id
- operator SIM slot matches request operator
- request tenant_id matches device tenant_id

## Request Claim Flow

```text
Tenant user submits topup
  -> TOPUP_REQUESTS/PENDING/{request_id} with tenant_id
  -> Worker claim checks tenant_id
  -> Claim only matching tenant request
  -> PROCESSING
  -> USSD dial
  -> SUCCESS/FAILED result
  -> Wallet settle/refund
  -> History/status update
```

## Operator Mapping

Supported BD operators:

```text
GP
ROBI
AIRTEL
BANGLALINK
TELETALK
```

Aliases can be supported internally, but external UI should show clean operator names.

## Worker App Behavior

Do not break existing worker API contract.

Required behavior:

- Sequential queue
- IDLE/BUSY status
- Auto SIM select
- Auto USSD dial
- Auto continue prompt handling for `1-Yes`
- Success detect: request received, topup successful, TRXID/Txn ID
- Failure detect: insufficient, invalid, failed, unsuccessful
- App stays alive after result
- Only USSD dialog should close

## Tenant Security

Worker must not claim requests from another tenant.

Every worker-sensitive action should validate:

```text
device_id
worker token/key
tenant_id
assigned owner
request tenant_id
operator slot
```

## Phase 2: One-Click Custom Worker APK

Future feature.

Owner clicks:

```text
Build My Worker App
```

System generates a branded APK:

- custom app name
- custom logo
- prefilled tenant_id or QR-first login
- same Z-Pay Swift backend API
- tenant-scoped worker token

This requires:

- APK build server or GitHub Actions
- secure signing key handling
- per-tenant config injection
- artifact expiry/download control

Phase 2 should not be built until Phase 1 is stable.
