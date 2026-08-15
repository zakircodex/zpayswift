<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function admin_hardening_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function admin_hardening_source(string $relative): string
{
    global $root;
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) {
        fwrite(STDERR, "FAIL: Unable to read {$relative}\n");
        exit(1);
    }
    return $source;
}

$list = admin_hardening_source('api/admin/users/list.php');
$get = admin_hardening_source('api/admin/users/get.php');
$sharedUsers = admin_hardening_source('api/lib/users_admin.php');
$create = admin_hardening_source('api/admin/users/create.php');
$update = admin_hardening_source('api/admin/users/update.php');
$counts = admin_hardening_source('api/admin/topup/status_counts.php');
$proxy = admin_hardening_source('api/admin/proxy.php');
$dashboardJs = admin_hardening_source('api/admin/assets/dashboard.js');
$mfsJs = admin_hardening_source('api/admin/assets/mfs-panel.js');

foreach ([$list, $get, $sharedUsers] as $source) {
    admin_hardening_expect(
        str_contains($source, 'market_gps_ip_country_mismatch'),
        'Admin user security metadata must use the canonical GPS/IP mismatch helper.'
    );
}

admin_hardening_expect(
    !str_contains($list, '$phoneCountry !== $country'),
    'Admin Users list must not treat phone/pricing country differences as GPS/IP mismatch.'
);
admin_hardening_expect(
    !str_contains($get, 'auth_phone_country_from_user($user) !== admin_user_get_country_code'),
    'Admin User Details must not infer mismatch from phone/pricing country.'
);
admin_hardening_expect(
    !str_contains($update, 'auth_phone_country_from_user($user) !== $country')
        && str_contains($update, "array_key_exists('country_mismatch', \$user)"),
    'Admin user updates must preserve canonical GPS/IP mismatch metadata.'
);

foreach ([$create, $update] as $source) {
    admin_hardening_expect(
        str_contains($source, 'is_valid_role'),
        'Admin user writes must reject invalid roles before normalization.'
    );
    admin_hardening_expect(
        str_contains($source, "'Invalid status'"),
        'Admin user writes must reject invalid statuses.'
    );
}

admin_hardening_expect(
    substr_count($counts, "['shallow' => 'true']") === 1
        && substr_count($counts, 'fb_get(') === 4,
    'Dashboard status counts must use bounded shallow Firebase reads.'
);
admin_hardening_expect(
    !str_contains($proxy, "['error']"),
    'Admin proxy must not expose internal transport errors.'
);
admin_hardening_expect(
    str_contains($dashboardJs, 'refreshInFlight')
        && str_contains($dashboardJs, 'Invalid response from server'),
    'Admin dashboard must prevent overlapping refresh and redact non-JSON responses.'
);
admin_hardening_expect(
    str_contains($mfsJs, "throw new Error('Invalid response from server')"),
    'Admin MFS panel must redact non-JSON server responses.'
);

$unguarded = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/api/admin', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_contains($relative, '/zsky') || str_contains($relative, '/znews')) {
        continue;
    }
    if (in_array($relative, [
        'api/admin/dashboard.php',
        'api/admin/dashboard_zpay.php',
        'api/admin/mfs.php',
        'api/admin/proxy.php',
    ], true)) {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    $hasMethod = is_string($source) && str_contains($source, 'api_require_method');
    $hasGuard = is_string($source) && (
        str_contains($source, 'auth_require_admin_session')
        || str_contains($source, 'zpay_dash_require_admin_or_subadmin')
    );
    if (!$hasMethod || !$hasGuard) {
        $unguarded[] = $relative;
    }
}
admin_hardening_expect(
    $unguarded === [],
    'Every direct Admin endpoint must enforce method and canonical role/session guards: '
        . implode(', ', $unguarded)
);

echo "Admin panel hardening tests passed ({$assertions} assertions).\n";
