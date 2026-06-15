# My-Site Subscription Rules

## Plans

### Free Plan

- URL: `https://zpayswift.com/site/{user_id}`
- Branding: Z-Pay Swift or limited tenant branding
- Trial: 7 days
- Custom domain: not allowed
- Custom logo/color: limited or not allowed
- Custom commission: limited or not allowed
- Control panel: limited
- After trial: expired until subscription payment is active

### Subscription Plan

- Custom site name
- Custom logo
- Custom color
- Custom domain
- Custom commission
- Custom user control
- Custom success/failed control
- Reports
- Balance add/deduct
- Account active/disable
- Custom Telegram bot/control
- Worker device setup
- Full tenant control panel

## Subscription Status

```text
TRIAL
ACTIVE
EXPIRED
SUSPENDED
CANCELLED
```

## Expiry Logic

Before opening any tenant site page or API action, check:

```text
TENANTS/{tenant_id}/subscription_status
TENANTS/{tenant_id}/subscription_expires_at
```

Allow access when:

- status is `TRIAL` and trial is not older than 7 days
- status is `ACTIVE` and expiry date is in the future

Block access when:

- trial is older than 7 days
- status is `EXPIRED`
- status is `SUSPENDED`
- status is `CANCELLED`

## Expired Site Behavior

Customer user panel:

- Show expired notice.
- Block login, registration, and new transactions.
- Tracking pages can be configurable: either blocked or read-only.

Owner control panel:

- Show subscription expired notice.
- Allow payment/renewal only.
- Block balance add/deduct, commission changes, user changes, request actions, Telegram control, and worker setup.

## Renewal Flow

1. Owner pays subscription.
2. Main admin or payment automation verifies payment.
3. System updates:

```text
subscription_status = ACTIVE
subscription_expires_at = NEW_EXPIRY_DATE
```

4. Tenant site opens again automatically.

## Grace Period

Optional future setting:

```text
TENANT_SETTINGS/{tenant_id}/subscription/grace_days
```

Default should be `0` until explicitly enabled.

## Security Notes

- Do not let expired owners perform money-moving actions.
- Do not let tenant owners bypass expiry from frontend only.
- Enforce subscription status from backend/API layer.
- Main admin can override status manually.
