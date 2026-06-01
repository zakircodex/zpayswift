<?php
/**
 * Z-Pay Swift example configuration.
 *
 * Copy this file to your private config location and fill values on the server.
 * Keep real credentials out of Git.
 */

declare(strict_types=1);

/* Core application keys */
define('APP_KEY', '');
define('WORKER_KEY', '');
define('ADMIN_KEY', '');

/* Firebase Realtime Database */
define('FIREBASE_DB_URL', 'https://example-default-rtdb.firebaseio.com');
define('FIREBASE_AUTH', '');
define('FIREBASE_DB_SECRET', '');

/* Session and validation */
define('SESSION_TTL_SECONDS', 60 * 60 * 24 * 7);
define('ADMIN_PANEL_SESSION_TTL_SECONDS', 60 * 60 * 2);
define('ADMIN_SESSION_TTL_SECONDS', 60 * 60 * 2);
define('MIN_PASSWORD_LENGTH', 6);
define('USER_PIN_LENGTH', 4);

/* BulkSMSBD OTP SMS */
define('BULKSMSBD_SMS_API_URL', 'https://bulksmsbd.net/api/smsapi');
define('BULKSMSBD_API_KEY', '');
define('BULKSMSBD_SENDER_ID', '');

/* Wallet deduction OTP */
define('WALLET_DEDUCT_OTP_COOLDOWN_SECONDS', 60);
define('WALLET_DEDUCT_OTP_TTL_SECONDS', 300);
define('WALLET_DEDUCT_OTP_MAX_ATTEMPTS', 5);

/* Telegram */
define('TELEGRAM_BOT_TOKEN', '');
define('TELEGRAM_CHAT_ID', '');
define('TELEGRAM_WEBHOOK_SECRET', '');
define('TELEGRAM_BUNDLE_ACTION_KEY', '');
define('TELEGRAM_MFS_ACTION_KEY', '');
define('TELEGRAM_BUNDLE_CHAT_ID', '');
define('ZAW_TELEGRAM_BOT_TOKEN', '');
define('ZAW_TELEGRAM_CHAT_ID', '');

/* Security / IP risk layer */
define('SECURITY_ENABLED', true);
define('SECURITY_HASH_SECRET', '');
define('SECURITY_IP_WHITELIST', []);
define('SECURITY_IP_BLOCKLIST', []);
define('SECURITY_IP_CACHE_TTL_SECONDS', 86400);
define('SECURITY_EXTERNAL_IP_LOOKUP_ENABLED', false);
define('SECURITY_IP_RISK_ENDPOINT', '');
define('SECURITY_IP_RISK_API_KEY', '');
define('SECURITY_IP_RISK_AUTH_HEADER', '');
define('SECURITY_ALLOW_UNKNOWN_IP_RISK', true);
define('SECURITY_BLOCK_VPN', true);
define('SECURITY_BLOCK_PROXY', true);
define('SECURITY_BLOCK_TOR', true);
define('SECURITY_BLOCK_DATACENTER', true);
define('SECURITY_BLOCK_ANONYMOUS', true);
define('SECURITY_BLOCK_HIGH_RISK_SCORE', true);
define('SECURITY_HIGH_RISK_SCORE_BLOCK_AT', 85);
define('SECURITY_IP_RISK_SKIP_PATHS', []);

/* Default user country / wallet currency */
define('DEFAULT_USER_COUNTRY', 'BD');
define('DEFAULT_USER_CURRENCY', 'BDT');

/* MFS / remittance defaults */
define('MYR_TO_BDT_RATE', 31.00);
define('MFS_PROVIDER_BKASH_ENABLED', true);
define('MFS_PROVIDER_NAGAD_ENABLED', true);
define('MFS_MIN_AMOUNT_BDT', 500.00);
define('MFS_MAX_AMOUNT_BDT', 50000.00);
define('MY_REMITTANCE_FEE_ADMIN_RM', 0.00);
define('MY_REMITTANCE_FEE_SUBADMIN_RM', 2.00);
define('MY_REMITTANCE_FEE_RETAILER_RM', 3.00);
define('MY_REMITTANCE_FEE_USER_RM', 5.00);

/*
Firebase runtime config keys used by the code. These are database nodes,
not PHP constants:

APP_CONFIG:
- topup_enabled
- bundle_enabled
- maintenance_mode
- min_topup_amount
- max_topup_amount
- min_bundle_amount
- max_bundle_amount

MFS_CONFIG:
- enabled
- mfs_enabled
- myr_to_bdt_rate
- MYR_TO_BDT_RATE
- exchange_rate.MYR_TO_BDT
- exchange_rates.MYR_TO_BDT
- rates.MYR_TO_BDT
- provider_enabled.BKASH
- provider_enabled.NAGAD
- providers.BKASH.enabled
- providers.NAGAD.enabled
- providers.BKASH.fees.SEND_MONEY
- providers.BKASH.fees.CASH_OUT
- providers.NAGAD.fees.SEND_MONEY
- providers.NAGAD.fees.CASH_OUT
- fees.BD.BKASH.SEND_MONEY
- fees.BD.BKASH.CASH_OUT
- fees.BD.NAGAD.SEND_MONEY
- fees.BD.NAGAD.CASH_OUT
- fees.MY.ADMIN.fee_rm
- fees.MY.SUBADMIN.fee_rm
- fees.MY.RETAILER.fee_rm
- fees.MY.USER.fee_rm
- MY.rate
- MY.remittance_fee_rm
- REMITTANCE.rate
- REMITTANCE.fee_rm

Server/request values read by the code include:
REQUEST_METHOD, REQUEST_URI, SCRIPT_NAME, HTTP_HOST, HTTPS, REMOTE_ADDR,
HTTP_AUTHORIZATION, Authorization, HTTP_X_APP_KEY, HTTP_X_WORKER_KEY,
HTTP_X_ADMIN_KEY, HTTP_X_SESSION_TOKEN, HTTP_X_API_KEY, HTTP_X_CSRF_TOKEN,
HTTP_X_FORWARDED_FOR, HTTP_X_FORWARDED_PROTO, HTTP_X_REAL_IP,
HTTP_CF_CONNECTING_IP, HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN, HTTP_USER_AGENT.
*/
