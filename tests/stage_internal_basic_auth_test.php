<?php
declare(strict_types=1);

function stage_basic_auth_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/api/lib/app_paths.php';

$_SERVER['HTTP_HOST'] = 'stage.zpayswift.com';
$_SERVER['PHP_AUTH_USER'] = 'stage-user';
$_SERVER['PHP_AUTH_PW'] = 'stage-password';

$plainHeaders = ['Accept: application/json'];
stage_basic_auth_expect(
    app_internal_request_headers($plainHeaders) === $plainHeaders,
    'Basic credentials must not be forwarded without the stage runtime guard'
);

define('APP_ENVIRONMENT', 'stage');
$expected = 'Authorization: Basic ' . base64_encode('stage-user:stage-password');
$forwarded = app_internal_request_headers($plainHeaders);
stage_basic_auth_expect(
    in_array($expected, $forwarded, true),
    'verified stage Basic credentials were not forwarded to the internal request'
);
stage_basic_auth_expect(
    count(array_filter($forwarded, static fn(string $header): bool => str_starts_with($header, 'Authorization:'))) === 1,
    'the stage Authorization header was duplicated'
);

$bearerHeaders = ['Accept: application/json', 'Authorization: Bearer session-token'];
stage_basic_auth_expect(
    app_internal_request_headers($bearerHeaders) === $bearerHeaders,
    'an existing Authorization header must never be overwritten'
);

$_SERVER['HTTP_HOST'] = 'zpayswift.com';
stage_basic_auth_expect(
    app_internal_request_headers($plainHeaders) === $plainHeaders,
    'production-host requests must not forward the stage Basic credentials'
);

$_SERVER['HTTP_HOST'] = 'stage.zpayswift.com';
unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('fallback-user:fallback-password');
stage_basic_auth_expect(
    in_array('Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'], app_internal_request_headers($plainHeaders), true),
    'the CGI Authorization-header fallback was not forwarded'
);

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer must-not-forward';
stage_basic_auth_expect(
    app_internal_request_headers($plainHeaders) === $plainHeaders,
    'non-Basic authorization must not be forwarded by the stage helper'
);

$criticalSources = [
    'api/admin/proxy.php',
    'api/auth/user_create_confirm.php',
    'api/subadmin/proxy.php',
    'api/user/proxy.php',
    'api/user_create_by_subadmin.php',
    'api/user_convert_retailer.php',
    'api/users_list.php',
    'api/wallet_add_balance.php',
    'api/wallet_deduct_confirm.php',
    'api/wallet_deduct_send_otp.php',
    'api/wallet_ledger_list.php',
    'api/lib/admin_mobile.php',
];
$root = dirname(__DIR__);
foreach ($criticalSources as $relativePath) {
    $source = (string)file_get_contents($root . '/' . $relativePath);
    stage_basic_auth_expect(
        str_contains($source, 'app_internal_request_headers('),
        "stage Basic forwarding is missing from {$relativePath}"
    );
}

echo "stage internal Basic Auth tests passed\n";
