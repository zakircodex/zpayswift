# My-Site Implementation Plan

## Current Scope Completed

This phase builds a frontend/demo foundation only inside `my-site/`.

Completed:

- Landing page: `my-site/index.html`
- Onboarding demo: `my-site/onboarding/index.html`
- User panel demo: `my-site/user/index.html`
- Control panel demo: `my-site/control/index.html`
- Expired page demo: `my-site/expired/index.html`
- Shared dark blue glassmorphism CSS
- Demo localStorage tenant store
- Tenant config loader
- Trial/active/expired UI guard demo

## Demo Only

The following are placeholders only:

- Login/Register
- Topup request
- Bundle request
- bKash/Nagad request
- History and tracking
- Balance add/deduct
- Success/failed controls
- Commission saving
- Telegram control
- Worker QR/token generation
- Subscription payment/renewal
- Domain verification

No real backend call is made in this phase.

## Hard Safety Rule

Live Z-Pay Swift code must not be touched while building this frontend demo.

Do not modify:

- `/api`
- `/user`
- `/admin`
- `/subadmin`
- worker code
- Telegram code
- SMS code
- Firebase logic
- root `.htaccess`
- private config

## Target Product

My-Site is a Shopify-like white-label SaaS feature powered by Z-Pay Swift backend and database.

A tenant owner can create a site in under 5 minutes with:

- Free trial URL under Z-Pay Swift domain
- Subscription plan with custom site name, color, logo, domain
- Tenant user panel
- Tenant owner control panel
- Commission settings
- User/request/balance/report controls
- Tenant Telegram control
- Existing Z-Pay Swift worker app QR/token link

## Tenant Boundary

Tenant owner can manage only own tenant data.
Main Z-Pay Swift admin can manage all tenants.

## Next Implementation Phase

Build backend APIs under a new safe namespace only, for example:

```text
/api/my_site/*
```

Do not modify existing production endpoints until tenant middleware and tests are ready.
