<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['SCRIPT_NAME'] = '/api/test.php';
$_SERVER['REQUEST_URI'] = '/api/test.php';

require_once dirname(__DIR__) . '/api/lib/app_paths.php';
require_once dirname(__DIR__) . '/api/my_site/_owner_auth.php';

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
assert_true(str_starts_with(zb_verify_link('test-token'), 'https://zpayswift.com/z-builder/'), 'Z-Builder links must use the canonical origin');

$_SERVER['HTTP_HOST'] = 'localhost:8080';
assert_true(app_public_origin() === 'http://localhost:8080', 'localhost development origin must remain supported');

echo "app path security tests passed\n";
