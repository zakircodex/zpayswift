<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

function stage_runtime_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$configPath = $argv[1] ?? '';
if ($configPath === '' || !is_file($configPath) || is_link($configPath)) {
    stage_runtime_fail('Private stage runtime configuration is unavailable.');
}

require $configPath;
require dirname(__DIR__) . '/api/lib/mysql.php';

$valid = defined('APP_ENVIRONMENT')
    && constant('APP_ENVIRONMENT') === 'stage'
    && defined('APP_PUBLIC_ORIGIN')
    && constant('APP_PUBLIC_ORIGIN') === 'https://stage.zpayswift.com'
    && defined('DATASTORE_DRIVER')
    && constant('DATASTORE_DRIVER') === 'mysql'
    && defined('FIREBASE_DB_URL')
    && constant('FIREBASE_DB_URL') === 'https://invalid.local';
if (!$valid) {
    stage_runtime_fail('Stage runtime guard mismatch.');
}

$outboundConstants = [
    'BULKSMSBD_API_KEY',
    'BULKSMSBD_SENDER_ID',
    'SMSS360_EMAIL',
    'SMSS360_API_KEY',
    'TELEGRAM_BOT_TOKEN',
    'TELEGRAM_CHAT_ID',
    'TELEGRAM_WEBHOOK_SECRET',
    'TELEGRAM_BUNDLE_ACTION_KEY',
    'TELEGRAM_MFS_ACTION_KEY',
    'TELEGRAM_TOPUP_ACTION_KEY',
    'TELEGRAM_ACCOUNT_REVIEW_ACTION_KEY',
    'TELEGRAM_ADD_MONEY_ACTION_KEY',
    'TELEGRAM_SUPPORT_ADMIN_IDS',
    'TELEGRAM_BUNDLE_CHAT_ID',
    'ZAW_TELEGRAM_BOT_TOKEN',
    'ZAW_TELEGRAM_CHAT_ID',
    'NOTIFICATION_BOT_TOKEN',
    'NOTIFICATION_TELEGRAM_WEBHOOK_SECRET',
    'NOTIFICATION_TELEGRAM_ADMIN_IDS',
];
foreach ($outboundConstants as $name) {
    if (defined($name) && trim((string) constant($name)) !== '') {
        stage_runtime_fail('Live outbound integration is enabled in stage.');
    }
}

try {
    zpay_mysql_assert_environment('STAGE');
} catch (Throwable) {
    stage_runtime_fail('Stage MySQL environment guard failed.');
}

fwrite(STDOUT, "Stage runtime guard passed.\n");
