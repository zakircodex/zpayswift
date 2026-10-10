<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['SCRIPT_NAME'] = '/api/test.php';
$_SERVER['REQUEST_URI'] = '/api/test.php';

require_once dirname(__DIR__) . '/api/lib/app_paths.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$_SERVER['HTTP_HOST'] = 'attacker.example';
unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
assert_true(app_public_origin() === 'https://zpayswift.com', 'untrusted Host must not control the public origin');
assert_true(str_starts_with(app_api_url('auth/login.php'), 'https://zpayswift.com/api/'), 'API URLs must use the canonical production origin');

$_SERVER['HTTP_HOST'] = 'stage.zpayswift.com';
assert_true(app_public_origin() === 'https://stage.zpayswift.com', 'stage must use its isolated canonical origin');
assert_true(
    app_private_config_path() === '/home/zedpayhe/private/zpayswift-stage/config.php',
    'stage must never fall back to the production private config'
);
assert_true(
    app_private_sms_bridge_path() === '/home/zedpayhe/private/zpayswift-stage/auth_sms_bridge.php',
    'stage must never load the production SMS bridge'
);

putenv('APP_PRIVATE_CONFIG_PATH=/tmp/zpay-stage-test.php');
putenv('APP_PRIVATE_SMS_BRIDGE_PATH=/tmp/zpay-stage-sms-test.php');
assert_true(app_private_config_path() === '/tmp/zpay-stage-test.php', 'private config environment override failed');
assert_true(app_private_sms_bridge_path() === '/tmp/zpay-stage-sms-test.php', 'SMS bridge environment override failed');
putenv('APP_PRIVATE_CONFIG_PATH');
putenv('APP_PRIVATE_SMS_BRIDGE_PATH');

$_SERVER['HTTP_HOST'] = 'localhost:8080';
assert_true(app_public_origin() === 'http://localhost:8080', 'localhost development origin must remain supported');

echo "app path security tests passed\n";
