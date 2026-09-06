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

/* Canonical server origin used by authenticated internal HTTP calls. */
define('APP_PUBLIC_ORIGIN', 'https://zpayswift.com');
define('WORKER_CLAIM_LEASE_SECONDS', 180);

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
define('OTP_MAX_ATTEMPTS', 5);
define('OTP_RESEND_LIMIT', 5);
define('OTP_RESEND_COOLDOWN_SECONDS', 60);
define('OTP_SEND_LIMIT_PER_HOUR', 12);
/* Admin password failures: 5 attempts in 15 minutes, followed by a 15-minute lock. */
define('ADMIN_LOGIN_MAX_FAILED_ATTEMPTS', 5);
define('ADMIN_LOGIN_ATTEMPT_WINDOW_SECONDS', 15 * 60);
define('ADMIN_LOGIN_LOCK_SECONDS', 15 * 60);
/* New Add Money receipt capabilities expire; historical unversioned links remain compatible. */
define('RECEIPT_TOKEN_TTL_SECONDS', 60 * 60 * 24 * 30);
/* Abandoned pre-registration KYC is eligible 72 hours after its session expires. */
define('REGISTRATION_KYC_TEMP_TTL_SECONDS', 60 * 60 * 72);
define('REGISTRATION_KYC_CLEANUP_BATCH_LIMIT', 100);

/* BulkSMSBD OTP SMS */
define('BULKSMSBD_SMS_API_URL', 'https://bulksmsbd.net/api/smsapi');
define('BULKSMSBD_API_KEY', '');
define('BULKSMSBD_SENDER_ID', '');

/* SMS360 Malaysia OTP SMS */
define('SMSS360_API_URL', 'https://www.smss360.com/api/sendsms.php');
define('SMSS360_EMAIL', '');
define('SMSS360_API_KEY', '');

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
define('TELEGRAM_TOPUP_ACTION_KEY', '');
define('TELEGRAM_ACCOUNT_REVIEW_ACTION_KEY', '');
define('TELEGRAM_ADD_MONEY_ACTION_KEY', '');
define('TELEGRAM_SUPPORT_ADMIN_IDS', '');
define('TELEGRAM_BUNDLE_CHAT_ID', '');
define('ZAW_TELEGRAM_BOT_TOKEN', '');
define('ZAW_TELEGRAM_CHAT_ID', '');
define('NOTIFICATION_BOT_TOKEN', '');
define('NOTIFICATION_TELEGRAM_WEBHOOK_SECRET', '');
define('NOTIFICATION_TELEGRAM_ADMIN_IDS', '');

/* Security / IP risk layer */
define('SECURITY_ENABLED', true);
define('SECURITY_HASH_SECRET', '');
/* Dedicated private key for Z Sky 24 cross-domain handoff (minimum 32 characters). */
define('ZNEWS_HANDOFF_ENCRYPTION_KEY', '');
/* Dedicated HMAC key for short-lived, guest-only Web ad delivery permits. */
define('ZNEWS_AD_DELIVERY_SIGNING_KEY', '');
define('ZNEWS_AD_DELIVERY_PERMIT_TTL_SECONDS', 120);

/*
 * Z Sky 24 Adsterra Web banner delivery. Copy these values from the approved
 * Adsterra post-reader banner tag; never put the Publisher API token here.
 */
define('ADSTERRA_ZSKY24_WEB_ADS_ENABLED', false);
define('ADSTERRA_ZSKY24_POST_READER_FORMAT', 'NATIVE_BANNER');
define('ADSTERRA_ZSKY24_POST_READER_KEY', '');
define('ADSTERRA_ZSKY24_POST_READER_SCRIPT_URL', '');
define('ADSTERRA_ZSKY24_NATIVE_INITIAL_HEIGHT', 300);
/* Used only when POST_READER_FORMAT is BANNER. */
define('ADSTERRA_ZSKY24_POST_READER_SIZE', '300x250');
define('ADSTERRA_ZSKY24_WEB_ALLOWED_SCRIPT_HOSTS', '');
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
define('SECURITY_CLOUDFLARE_IP_COUNTRY_ENABLED', true);
define('SECURITY_REQUIRE_CLOUDFLARE_FOR_COUNTRY', false);
// Cloudflare's published ranges; keep synchronized with https://www.cloudflare.com/ips/.
define('SECURITY_CLOUDFLARE_TRUSTED_PROXY_CIDRS', [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
]);
// Set true only when the origin firewall accepts requests exclusively from Cloudflare.
define('SECURITY_CLOUDFLARE_ORIGIN_LOCKED', false);
// Preserve the current review policy while keeping GPS/IP mismatch and IP-risk signals distinct.
define('REGISTRATION_GPS_IP_MISMATCH_VPN_SUSPECTED', true);

/* Partner public API hardening */
define('SUBADMIN_API_ALLOW_QUERY_KEY', false);

/* Default user country / wallet currency */
define('DEFAULT_USER_COUNTRY', 'BD');
define('DEFAULT_USER_CURRENCY', 'BDT');

/* MFS / remittance defaults */
define('MYR_TO_BDT_RATE', 31.00);
define('MFS_PROVIDER_BKASH_ENABLED', true);
define('MFS_PROVIDER_NAGAD_ENABLED', true);
define('MFS_MIN_AMOUNT_BDT', 500.00);
define('MFS_MAX_AMOUNT_BDT', 100000.00);

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
- MY.rate
- REMITTANCE.rate

MFS_SETTINGS:
- rate_myr_bdt
- fees.MY.TIERS.TIER1.USER
- fees.MY.TIERS.TIER1.RETAILER
- fees.MY.TIERS.TIER1.SUBADMIN
- fees.MY.TIERS.TIER2.USER
- fees.MY.TIERS.TIER2.RETAILER
- fees.MY.TIERS.TIER2.SUBADMIN
- fees.MY.TIERS.TIER3.USER
- fees.MY.TIERS.TIER3.RETAILER
- fees.MY.TIERS.TIER3.SUBADMIN
- fees.BD.BKASH
- fees.BD.NAGAD

Server/request values read by the code include:

zakir

REQUEST_METHOD, REQUEST_URI, SCRIPT_NAME, HTTP_HOST, HTTPS, REMOTE_ADDR,
HTTP_AUTHORIZATION, Authorization, HTTP_X_APP_KEY, HTTP_X_WORKER_KEY,
HTTP_X_ADMIN_KEY, HTTP_X_SESSION_TOKEN, HTTP_X_API_KEY, HTTP_X_CSRF_TOKEN,
HTTP_X_FORWARDED_FOR, HTTP_X_FORWARDED_PROTO, HTTP_X_REAL_IP,
HTTP_CF_CONNECTING_IP, HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN, HTTP_USER_AGENT.
*/
