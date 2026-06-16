# Z Builder Next Steps

## Done In This Phase

- Product name updated to **Z Builder by Z-Pay Swift**.
- Landing page updated.
- Onboarding demo updated.
- User panel demo uses tenant branding.
- Control panel demo uses tenant branding.
- Expired page exists.
- Demo tenant config uses localStorage.
- Dark blue responsive UI improved.
- Basic tenant API foundation added.

## Demo Test

1. Open `my-site/index.html`.
2. Open `my-site/onboarding/index.html`.
3. Create a demo tenant.
4. Open `my-site/user/index.html` and check tenant name/color.
5. Open `my-site/control/index.html` and check owner controls.
6. Use Set Expired and Renew Demo buttons.
7. Confirm only Z Builder/my-site related files changed.

## Important Business Rule

Z Builder account is separate from a normal Z-Pay Swift user account.

Later account flow:

```text
Z Builder owner register/login -> create own site -> control own site
```

## Later Work

- Z Builder owner register/login API.
- Owner session/token guard.
- Create site from logged-in Z Builder owner account.
- Subscription plans: free 7 days, 3 months, 6 months, 1 year.
- Manual payment request and admin approval.
- Server-side subscription guard.
- Tenant owner permissions.
- Tenant customer register/login flow.
- Tenant-scoped topup, bundle, and MFS requests.
- Existing worker app QR/link code.
- Tenant Telegram control.
- Custom domain verification.

## Commit Message

```text
Rebrand my-site demo as Z Builder
```
