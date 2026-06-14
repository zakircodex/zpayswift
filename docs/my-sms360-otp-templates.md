# Malaysia SMS360 OTP Templates

Malaysia OTP messages are code-controlled in `api/lib/otp_templates.php`.
Only the `%%` placeholder is replaced with the six-digit OTP. The SMS360
sender rejects any Malaysia message that does not exactly match an approved
template. No SMS360 credential is stored in this repository.

## Approved Templates

| Template key | Approved message | Flow | Send function/file | Verify function/file |
|---|---|---|---|---|
| `USER_LOGIN` | `RM0 Z-Pay Swift login OTP is %%. Valid for 5 minutes. Do not share this code.` | User login and resend | `auth_send_otp_sms_by_country()` from `api/auth/user_login_start.php` and `api/auth/user_login_resend_otp.php` | `api/auth/user_login_verify_otp.php` |
| `USER_REGISTER` | `RM0 Z-Pay Swift registration OTP is %%. Valid for 5 minutes. Do not share this code.` | User registration, resend, and admin/subadmin create-user confirmation | `api/auth/user_register_send_otp.php`, `api/auth/user_register_resend_otp.php`, `api/auth/user_create_send_otp.php` | `api/auth/user_register_confirm.php`, `api/auth/user_create_confirm.php` |
| `USER_RESET` | `RM0 Z-Pay Swift account reset OTP is %%. Valid for 5 minutes. Do not share this code.` | User password or PIN reset and resend | `api/auth/user_forgot_send_otp.php`, `api/auth/user_forgot_resend_otp.php` | `api/auth/user_forgot_verify_otp.php` |
| `ADMIN_LOGIN` | `RM0 Z-Pay Swift admin login OTP is %%. Valid for 5 minutes. Do not share this code.` | Admin login and resend | `api/auth/admin_login_start.php`, `api/auth/admin_login_resend_otp.php`; generic role-aware login compatibility | `api/auth/admin_login_verify_otp.php`; generic compatibility verifier |
| `ADMIN_RESET` | `RM0 Z-Pay Swift admin reset OTP is %%. Valid for 5 minutes. Do not share this code.` | Protected admin password or PIN reset compatibility | Role-aware `api/auth/forgot_send_otp.php`, `api/auth/forgot_resend_otp.php` | `api/auth/forgot_verify_otp.php` |
| `SUBADMIN_LOGIN` | `RM0 Z-Pay Swift subadmin login OTP is %%. Valid for 5 minutes. Do not share this code.` | Subadmin login and resend | `api/auth/login_start.php`, `api/auth/login_resend_otp.php` | `api/auth/login_verify_otp.php` |
| `SUBADMIN_RESET` | `RM0 Z-Pay Swift subadmin reset OTP is %%. Valid for 5 minutes. Do not share this code.` | Subadmin password or PIN reset and resend | `api/auth/forgot_send_otp.php`, `api/auth/forgot_resend_otp.php` | `api/auth/forgot_verify_otp.php` |
| `PIN_VERIFY` | `RM0 Z-Pay Swift PIN verification OTP is %%. Valid for 5 minutes. Do not share this code.` | Wallet deduction OTP | `api/wallet_deduct_send_otp.php` | `api/wallet_deduct_confirm.php` |

## OTP Audit

| Flow | Phone-country gateway | Template | TTL | Verification | Audit result |
|---|---|---|---|---|---|
| User login | BD: BulkSMSBD, MY: SMS360 | `USER_LOGIN` | 5 minutes | Password-hashed OTP in `user_login_verify_otp.php` | OK after strict MY template enforcement |
| User registration | BD: BulkSMSBD, MY: SMS360 | `USER_REGISTER` | 5 minutes | `user_register_confirm.php` | OK after strict MY template enforcement |
| User forgot password/PIN | BD: BulkSMSBD, MY: SMS360 | `USER_RESET` | 5 minutes | `user_forgot_verify_otp.php` | OK after strict MY template enforcement |
| Admin login | BD: BulkSMSBD, MY: SMS360 | `ADMIN_LOGIN` | 5 minutes | `admin_login_verify_otp.php` | OK after strict MY template enforcement |
| Admin reset compatibility | BD: BulkSMSBD, MY: SMS360 | `ADMIN_RESET` | 5 minutes | Generic protected forgot verifier | Role-aware template selection added |
| Subadmin login | BD: BulkSMSBD, MY: SMS360 | `SUBADMIN_LOGIN` | 5 minutes | `login_verify_otp.php` | Role-aware template selection added |
| Subadmin forgot password/PIN | BD: BulkSMSBD, MY: SMS360 | `SUBADMIN_RESET` | 5 minutes | `forgot_verify_otp.php` | Role-aware template selection added |
| Admin/subadmin create-user confirmation | BD: BulkSMSBD, MY: SMS360 | `USER_REGISTER` | 5 minutes | `user_create_confirm.php` | OTP is bound to the target user's normalized phone |
| Wallet deduction confirmation | BD: BulkSMSBD, MY: SMS360 | `PIN_VERIFY` | Configured TTL, default 5 minutes | `wallet_deduct_confirm.php` | BD-only gateway and BDT hardcoding fixed |

Transaction PIN checks used by topup, bundle, MFS preview/create, and user
step verification are not SMS OTP flows. They verify the stored `pin_hash`
directly and therefore do not use an SMS template or OTP TTL.

## Country and Logging Rules

- `phone_country=BD` routes OTP SMS to BulkSMSBD.
- `phone_country=MY` routes OTP SMS to SMS360.
- `pricing_country` or `service_country` controls wallet currency, fees, and
  service pricing. It never selects the SMS gateway.
- OTP rows keep `phone_country`, `pricing_country`, `service_country`,
  `currency`, `phone_e164`, `sms_gateway`, `sms_template_key`,
  `sms_status_code`, and `sms_reference_id` where the context is available.
- SMS360 email and API key remain private configuration values and must never
  be committed.
