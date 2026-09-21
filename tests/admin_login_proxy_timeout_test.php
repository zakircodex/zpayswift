<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/api/admin/proxy.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: Admin proxy source is unavailable.\n");
    exit(1);
}

$checks = [
    'Default timeout remains 20 seconds' => str_contains($source, 'int $timeoutSeconds = 20')
        && str_contains($source, 'CURLOPT_TIMEOUT => max(20, $timeoutSeconds)'),
    'Admin login waits for SMS gateway' => preg_match(
        "/proxy_internal_api_request\('POST', 'auth\/admin_login_start\.php'[^;]*proxy_base_headers\(\), 60\)/s",
        $source
    ) === 1,
    'Admin OTP resend waits for SMS gateway' => preg_match(
        "/proxy_internal_api_request\('POST', 'auth\/admin_login_resend_otp\.php'[^;]*proxy_base_headers\(\), 60\)/s",
        $source
    ) === 1,
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "Admin login proxy timeout tests passed.\n";
