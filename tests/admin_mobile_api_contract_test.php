<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function admin_mobile_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function admin_mobile_source(string $relative): string
{
    global $root;
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) {
        fwrite(STDERR, "FAIL: Unable to read {$relative}\n");
        exit(1);
    }
    return $source;
}

$config = admin_mobile_source('api/config.example.php');
$auth = admin_mobile_source('api/lib/auth.php');
$shared = admin_mobile_source('api/lib/admin_mobile.php');

admin_mobile_expect(
    str_contains($config, "define('ADMIN_MOBILE_ENABLED', false)"),
    'Admin mobile must be disabled by default.'
);
admin_mobile_expect(
    str_contains($config, 'ADMIN_MOBILE_SESSION_TTL_SECONDS'),
    'Admin mobile must have a separately configurable short session TTL.'
);
admin_mobile_expect(
    str_contains($auth, "\$channel !== 'ADMIN_MOBILE'")
        && str_contains($auth, 'ADMIN_MOBILE_DEVICES/')
        && str_contains($auth, 'ADMIN_DEVICE_REVOKED'),
    'Canonical admin authorization must enforce mobile device binding and revocation.'
);
admin_mobile_expect(
    str_contains($auth, 'ADMIN_MOBILE_UPDATE_REQUIRED'),
    'Existing mobile sessions must honor the minimum app version.'
);
admin_mobile_expect(
    str_contains($shared, 'api_require_app_key()')
        && !str_contains($shared, 'api_require_admin_key()'),
    'The mobile app key is a request gate and the server admin key must never be required in the APK.'
);
admin_mobile_expect(
    str_contains($shared, "'channel' => 'ADMIN_MOBILE'")
        || str_contains($shared, "'/channel' => 'ADMIN_MOBILE'"),
    'Mobile login must mark its server session channel.'
);
admin_mobile_expect(
    str_contains($shared, "fb_patch('', \$updates)"),
    'Session binding and device registration must use one Firebase multi-location update.'
);

$endpoints = [
    'login_start.php',
    'login_verify_otp.php',
    'login_resend_otp.php',
    'session.php',
    'logout.php',
    'dashboard.php',
    'operations.php',
    'operation_action.php',
    'rate.php',
    'maintenance.php',
    'support.php',
    'reviews.php',
    'device_token.php',
];

foreach ($endpoints as $endpoint) {
    $source = admin_mobile_source('api/admin_mobile/' . $endpoint);
    admin_mobile_expect(
        str_contains($source, "require_once dirname(__DIR__) . '/bootstrap.php'")
            && str_contains($source, "require_once dirname(__DIR__) . '/lib/admin_mobile.php'"),
        "{$endpoint} must use the canonical API bootstrap and mobile guard."
    );
    admin_mobile_expect(
        str_contains($source, 'api_require_method')
            || str_contains($source, "\$method = strtoupper"),
        "{$endpoint} must enforce its HTTP method."
    );
}

$operationAction = admin_mobile_source('api/admin_mobile/operation_action.php');
admin_mobile_expect(
    str_contains($operationAction, 'add_money_process_request')
        && str_contains($operationAction, 'mfs_find_request')
        && str_contains($operationAction, 'mfs_mark_success')
        && str_contains($operationAction, 'topup_mark_success')
        && str_contains($operationAction, 'bundle_mark_success')
        && !str_contains($operationAction, 'admin_mobile_internal_request'),
    'Financial actions must call canonical domain operations directly and verify the MFS provider.'
);
admin_mobile_expect(
    str_contains($operationAction, 'ALREADY_APPLIED')
        && str_contains($operationAction, 'idempotent_replay'),
    'Repeated terminal actions must return an idempotent success when the requested result already exists.'
);

$dashboard = admin_mobile_source('api/admin_mobile/dashboard.php');
admin_mobile_expect(
    str_contains($dashboard, "require_once dirname(__DIR__) . '/lib/rates.php'"),
    'Dashboard rate state must load the canonical rate helper.'
);

$supportLibrary = admin_mobile_source('api/lib/support.php');
admin_mobile_expect(
    str_contains($supportLibrary, 'realpath(__FILE__)')
        && !str_contains($supportLibrary, 'basename(__FILE__) === basename'),
    'Support direct-access protection must compare full paths so the mobile support endpoint can include it.'
);

$rate = admin_mobile_source('api/admin_mobile/rate.php');
admin_mobile_expect(
    str_contains($rate, "zpay_save_myr_to_bdt_rate(\$rate")
        && str_contains($rate, "'ADMIN_MOBILE'"),
    'Rate changes must use the canonical saver and mobile audit source.'
);

$maintenance = admin_mobile_source('api/admin_mobile/maintenance.php');
admin_mobile_expect(
    str_contains($maintenance, "fb_patch('APP_CONFIG', \$patch)")
        && !str_contains($maintenance, "fb_put('APP_CONFIG'"),
    'Maintenance updates must patch only their owned fields.'
);

$support = admin_mobile_source('api/admin_mobile/support.php');
admin_mobile_expect(
    str_contains($support, "'idempotency_key'")
        && str_contains($support, "'source' => 'ADMIN_MOBILE'"),
    'Support replies must be idempotent and identify their mobile source.'
);

$deviceToken = admin_mobile_source('api/admin_mobile/device_token.php');
admin_mobile_expect(
    str_contains($deviceToken, 'admin_mobile_require_session(true)')
        && str_contains($deviceToken, 'admin_push_register_device_token')
        && str_contains($deviceToken, 'admin_push_deactivate_device'),
    'Admin push token registration must require a bound admin mobile session.'
);

$adminPush = admin_mobile_source('api/lib/admin_push.php');
admin_mobile_expect(
    str_contains($adminPush, 'ADMIN_MOBILE_PUSH_TOKENS/')
        && str_contains($adminPush, 'ADMIN_NEW_REQUEST')
        && str_contains($adminPush, 'role !== \'ADMIN\''),
    'Admin request push must target active authenticated admin devices only.'
);

$pushHookFiles = [
    'api/lib/topup.php' => "admin_push_notify_request('TOPUP'",
    'api/lib/bundle.php' => "admin_push_notify_request('BUNDLE'",
    'api/lib/add_money.php' => "admin_push_notify_request('ADD_MONEY'",
    'api/lib/mfs.php' => 'admin_push_notify_request($provider',
    'api/lib/support.php' => "admin_push_notify_request('SUPPORT'",
    'api/lib/account_review.php' => "admin_push_notify_request('ACCOUNT_REVIEW'",
];
foreach ($pushHookFiles as $file => $needle) {
    admin_mobile_expect(
        str_contains(admin_mobile_source($file), $needle),
        "{$file} must notify the admin app when a new actionable request is created."
    );
}

$userNotificationFiles = [
    'api/lib/topup.php' => 'topup_record_user_notification',
    'api/lib/bundle.php' => 'bundle_record_user_notification',
    'api/lib/add_money.php' => 'notification_emit_request_status_notification',
    'api/lib/mfs.php' => 'mfs_record_user_notification',
];
foreach ($userNotificationFiles as $file => $needle) {
    admin_mobile_expect(
        str_contains(admin_mobile_source($file), $needle),
        "{$file} must retain user notification delivery for terminal admin decisions."
    );
}

echo "Admin mobile API contract tests passed ({$assertions} assertions).\n";
