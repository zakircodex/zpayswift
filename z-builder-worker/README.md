# Z Builder Worker

Android worker app template for Z Builder generated APKs.

Rules:
- Keep existing worker queue, USSD dial, SIM mapping, and result handling stable.
- Do not hardcode public secrets.
- Generated builds must inject app name, package name, API base, app id, and per-app token at build time.
- Existing `/api/worker/*` contract must not be broken.

Next build step:
1. Z Builder owner enters app name and package name.
2. Server creates a per-owner worker app record.
3. GitHub Actions/worker builder injects config into this template.
4. APK is built and returned as a download artifact.
