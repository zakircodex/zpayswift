# Z-Pay Swift Document AI (retired)

Document AI verification is no longer part of registration. This directory is
retained temporarily so production deployment overwrites any previously active
Passenger application with a fail-closed retired service.

The service keeps these compatibility routes:

- `GET /health` reports `DOCUMENT_AI_RETIRED`.
- `POST /v1/document/verify` returns HTTP 410 and does not read uploads, load an
  OCR engine, or call another service.

Registration still requires the canonical private identity-document upload and
selfie upload. Those checks are implemented by the PHP registration/KYC flow and
do not depend on this retired service.

After production has been verified to run the retired version, obsolete private
Document AI environment values may be removed manually from hosting configuration.
