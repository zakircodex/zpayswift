# Z Builder Implementation Plan

## Product Name

**Z Builder by Z-Pay Swift**

Tagline:

```text
নিজের topup site নিজেই তৈরি করুন
```

English subtitle:

```text
Create your own topup and payment support site in minutes.
```

## Current Scope Completed

This phase builds a frontend/demo foundation inside `my-site/` folder. The folder path is still `my-site/` for compatibility with the current live preview, but the product name is now **Z Builder**.

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
- Basic tenant API foundation under `/api/my_site/`

## Demo Only

The following are placeholders only:

- Builder owner account/register/login
- Topup request
- Bundle request
- bKash/Nagad request
- History and tracking
- Balance add/deduct
- Success/failed controls
- Commission saving
- Telegram control
- Worker QR/link code generation
- Subscription payment/renewal
- Domain verification

## Production Direction

Z Builder must become a production/business-level site builder where:

- A site owner creates a separate Z Builder account.
- This account is not linked to a normal Z-Pay Swift user account.
- Owner can create and manage their own tenant site.
- Free plan expires after 7 days.
- Paid plans can later be 3 months, 6 months, and 1 year.
- Payment is manual at first and can be automated later.
- Z-Pay Swift admin controls plan and approval later.

## Hard Safety Rule

Live Z-Pay Swift code must not be broken while building Z Builder.

Do not modify existing production logic without a specific task:

- live topup flow
- live bundle flow
- live MFS flow
- live wallet ledger
- live worker dialing system
- live Telegram callbacks
- live user/admin/subadmin panels
- private config and secrets

## Target Product

Z Builder is a Shopify-like white-label SaaS feature powered by Z-Pay Swift backend and database.

A tenant owner can create a site in under 5 minutes with:

- Free trial under Z-Pay Swift domain
- Subscription plan with custom site name, color, logo, domain
- Tenant user panel
- Tenant owner control panel
- Commission settings
- User/request/balance/report controls
- Tenant Telegram control
- Existing Z-Pay Swift worker app QR/link code

## Tenant Boundary

Tenant owner can manage only own tenant data.
Main Z-Pay Swift admin can manage all tenants.

## Next Implementation Phase

Build separate Z Builder owner account APIs under a safe namespace, for example:

```text
/api/my_site/auth_register.php
/api/my_site/auth_login.php
/api/my_site/owner_session.php
```

Keep API response format unchanged and do not expose secrets.
