# Z-Pay Swift My-Site White-Label SaaS

This folder is a planning-only starter for a Shopify-like white-label site system powered by the existing Z-Pay Swift backend.

## Goal

Allow a site owner to create a branded topup/remittance support site in under 5 minutes while Z-Pay Swift keeps the backend, database, OTP/SMS, security, Telegram routing, and worker infrastructure.

## Core Idea

- Free plan uses a Z-Pay Swift hosted URL such as `https://zpayswift.com/site/{user_id}`.
- Subscription plan can use custom site name, logo, color, domain, commission, user control, success/failed control, reports, balance tools, account control, Telegram control, and worker setup.
- Every site has two panels:
  - User Panel: customers use this panel.
  - Control Panel: site owner manages only their own users, requests, wallets, reports, commissions, and workers.
- Main Z-Pay Swift admin keeps global access across all tenants.

## Important Non-Breaking Rules

- Do not change existing API response format.
- Do not rename or move existing Firebase paths.
- Do not break Telegram callbacks.
- Do not break worker API contract.
- Do not expose or commit secrets.
- Use placeholders only in examples.

## Backend Ownership

The tenant site does not own the backend. Z-Pay Swift remains the backend/database owner. Tenant owners receive scoped control over their own tenant data only.

## Current Status

Planning documentation only. No backend, frontend, database, worker, Telegram, or SMS behavior is changed by this folder.
