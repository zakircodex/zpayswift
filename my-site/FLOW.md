# My-Site Product Flow

## 1. Owner Signup Flow

1. Owner opens Z-Pay Swift My-Site onboarding.
2. Owner selects plan:
   - Free Trial
   - Subscription Plan
3. Owner enters site name.
4. Owner confirms BD-only service mode for this white-label feature.
5. System creates a tenant record.
6. Owner gets default panels:
   - User Panel
   - Control Panel
7. Owner can publish immediately.

Target setup time: under 5 minutes.

## 2. Free Plan Flow

URL format:

```text
https://zpayswift.com/site/{user_id}
```

Rules:

- Uses Z-Pay Swift domain.
- Uses Z-Pay Swift branding or limited branding.
- User-panel-like interface.
- 7-day trial.
- After 7 days, site expires if no subscription payment is active.
- Expired free site blocks customer access and owner control actions except payment/renewal.

## 3. Subscription Plan Flow

Subscription plan unlocks:

- Custom site name
- Custom logo
- Custom color/theme
- Custom domain
- Custom commission
- Custom user control
- Custom success/failed control
- Reports
- Balance add/deduct tools
- Account active/disable tools
- Custom Telegram bot/control
- Worker setup

Backend and database remain Z-Pay Swift-owned.

## 4. Customer/User Panel Flow

1. Customer opens tenant site.
2. Customer registers/logs in.
3. OTP/SMS is sent through Z-Pay Swift SMS backend.
4. SMS brand name can use the tenant site name for BD users.
5. Customer submits topup/bundle/MFS request where enabled.
6. Request is saved under Z-Pay Swift backend with tenant scope.
7. Customer can track status and history from the tenant user panel.

## 5. Owner Control Panel Flow

Owner can manage only their own tenant data:

- Users
- Requests
- Wallets
- Reports
- Commission settings
- Success/failed/manual control
- Telegram bot settings
- Worker devices
- Account active/disable
- Balance add/deduct

Main admin can manage all tenants.

## 6. Request Processing Flow

```text
Tenant user panel
  -> Z-Pay Swift API
  -> Firebase/backend with tenant_id
  -> Tenant owner control panel / Telegram / Worker
  -> Result update
  -> Tenant user history and tracking
```

## 7. Expiry Flow

1. System checks `subscription_status` and `subscription_expires_at` before opening tenant pages.
2. If active, panels open normally.
3. If expired:
   - Customer user panel is blocked.
   - Owner control panel becomes limited.
   - Payment/renewal screen remains available.
4. After payment, status becomes active again and the site reopens.
