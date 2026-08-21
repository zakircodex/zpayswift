<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$maintenanceEnabled = false;
$maintenanceReads = [];
$maintenanceAssertions = 0;

function maintenance_expect(bool $condition, string $message): void
{
    global $maintenanceAssertions;
    $maintenanceAssertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function maintenance_source(string $path): string
{
    $source = file_get_contents($path);
    if ($source === false) {
        fwrite(STDERR, "FAIL: could not read {$path}\n");
        exit(1);
    }

    return $source;
}

function fb_get(string $path, array $query = [])
{
    global $maintenanceEnabled, $maintenanceReads;
    $maintenanceReads[] = $path;
    return $path === 'APP_CONFIG/maintenance_mode' ? $maintenanceEnabled : null;
}

$root = dirname(__DIR__);
require_once $root . '/api/lib/system_maintenance.php';

maintenance_expect(system_maintenance_active() === false, 'Maintenance OFF must keep User service available.');
maintenance_expect(!system_user_service_is_blocked(['role' => 'USER']), 'Maintenance OFF must allow USER.');
maintenance_expect(!system_user_service_is_blocked(['role' => 'RETAILER']), 'Maintenance OFF must allow RETAILER.');

$maintenanceEnabled = true;
maintenance_expect(system_maintenance_active(), 'Canonical maintenance flag must enable the service lock.');
maintenance_expect(system_user_service_is_blocked(['role' => 'USER']), 'Maintenance ON must block USER.');
maintenance_expect(system_user_service_is_blocked(['role' => 'RETAILER']), 'Maintenance ON must block RETAILER.');
maintenance_expect(!system_user_service_is_blocked(['role' => 'ADMIN']), 'Maintenance must not block ADMIN.');
maintenance_expect(!system_user_service_is_blocked(['role' => 'SUBADMIN']), 'Current Subadmin behavior must remain outside the User maintenance lock.');
maintenance_expect(
    $maintenanceReads !== [] && count(array_unique($maintenanceReads)) === 1
        && $maintenanceReads[0] === 'APP_CONFIG/maintenance_mode',
    'The shared helper must read only the canonical Admin maintenance flag.'
);

$helper = maintenance_source($root . '/api/lib/system_maintenance.php');
$bootstrap = maintenance_source($root . '/api/bootstrap.php');
$auth = maintenance_source($root . '/api/lib/auth.php');
$authAndroid = maintenance_source($root . '/api/lib/auth_android.php');
$proxy = maintenance_source($root . '/api/user/proxy.php');
$adminLogin = maintenance_source($root . '/api/auth/admin_login_start.php');
$adminConfig = maintenance_source($root . '/api/admin/config/save.php');
$loginPage = maintenance_source($root . '/api/user/index.php');
$loginJs = maintenance_source($root . '/api/user/assets/pages/login-page.js');
$shellHead = maintenance_source($root . '/api/user/includes/head.php');
$shellJs = maintenance_source($root . '/api/user/assets/user-shell.js');

maintenance_expect(str_contains($helper, "fb_get('APP_CONFIG/maintenance_mode')"), 'Shared helper must use APP_CONFIG/maintenance_mode.');
maintenance_expect(
    str_contains($helper, "api_response(false, 'MAINTENANCE'") && str_contains($helper, '[], 503)'),
    'Maintenance denial must preserve the API envelope and HTTP 503.'
);
maintenance_expect(str_contains($bootstrap, "require_once __DIR__ . '/lib/system_maintenance.php'"), 'Bootstrap must load the shared helper.');
maintenance_expect(str_contains($adminConfig, "'maintenance_mode' => \$maintenanceMode"), 'Admin must keep writing the canonical maintenance field.');

$rolePos = strpos($auth, "\$user['role'] = auth_status_value");
$maintenancePos = strpos($auth, 'system_require_user_service_available($user)', $rolePos === false ? 0 : $rolePos);
$devicePos = strpos($auth, 'auth_enforce_active_device_for_user', $rolePos === false ? 0 : $rolePos);
maintenance_expect(
    $rolePos !== false && $maintenancePos !== false && $devicePos !== false
        && $rolePos < $maintenancePos && $maintenancePos < $devicePos,
    'Authenticated User boundary must enforce maintenance before returning/touching the session.'
);
maintenance_expect(
    str_contains($auth, "'code' => 'MAINTENANCE'")
        && strpos($auth, 'system_user_service_is_blocked($user)') < strpos($auth, '$token = random_token(32)', strpos($auth, 'function auth_issue_website_user_session')),
    'Final Website session issuance must re-check maintenance before creating a token.'
);
maintenance_expect(
    str_contains($authAndroid, 'system_require_user_service_available($user)')
        && str_contains($authAndroid, "auth_app_quick_login_error('MAINTENANCE'"),
    'Trusted/PIN/biometric session paths must enforce the same maintenance lock.'
);

$loginEndpoints = [
    'check_number.php' => '$body = api_read_json_body()',
    'verify_password.php' => '$body = api_read_json_body()',
    'verify_pin.php' => '$body = api_read_json_body()',
    'login_send_otp.php' => '$body = api_read_json_body()',
    'pin_login.php' => '$body = api_read_json_body()',
    'biometric_login.php' => '$body = api_read_json_body()',
    'user_login_start.php' => '$body = api_read_json_body()',
    'user_login_verify_otp.php' => '$body = api_read_json_body()',
    'user_login_resend_otp.php' => '$body = api_read_json_body()',
];
foreach ($loginEndpoints as $file => $firstFlowStatement) {
    $source = maintenance_source($root . '/api/auth/' . $file);
    $guard = strpos($source, 'system_require_user_service_available();');
    $flow = strpos($source, $firstFlowStatement);
    maintenance_expect(
        $guard !== false && $flow !== false && $guard < $flow,
        "{$file} must reject maintenance before its login flow."
    );
}

$otpVerify = maintenance_source($root . '/api/auth/user_login_verify_otp.php');
$otpResend = maintenance_source($root . '/api/auth/user_login_resend_otp.php');
$otpSend = maintenance_source($root . '/api/auth/login_send_otp.php');
maintenance_expect(
    strpos($otpVerify, 'system_require_user_service_available();') < strpos($otpVerify, 'auth_otp_claim_verification'),
    'OTP completion must be blocked before OTP claim/session issuance.'
);
maintenance_expect(
    strpos($otpSend, 'system_require_user_service_available();') < strpos($otpSend, "auth_otp_send_rate_state('USER_LOGIN'"),
    'Maintenance must block before sending a new login OTP.'
);
maintenance_expect(
    strpos($otpResend, 'system_require_user_service_available();') < strpos($otpResend, 'auth_send_otp_sms_by_country'),
    'Maintenance must block login OTP resend before SMS delivery.'
);

$cacheGuard = strpos($proxy, 'system_require_user_service_available($freshUser)');
$cacheReturn = strpos($proxy, 'return $freshUser;', $cacheGuard === false ? 0 : $cacheGuard);
maintenance_expect($cacheGuard !== false && $cacheReturn !== false && $cacheGuard < $cacheReturn, 'Cached web sessions must not bypass maintenance.');
maintenance_expect(
    substr_count($proxy, "=== 'MAINTENANCE'") >= 3
        && str_contains($proxy, "case 'maintenance_status':")
        && str_contains($proxy, 'system_maintenance_message()'),
    'Web proxy must preserve maintenance responses for session, finalization, and trusted login paths.'
);
maintenance_expect(
    strpos($proxy, "=== 'MAINTENANCE'") < strpos($proxy, 'user_proxy_clear_session();', strpos($proxy, 'function user_proxy_require_login')),
    'Maintenance must not clear an otherwise valid web session.'
);

$transactionEndpoints = [
    'api/topup/submit.php',
    'api/bundle/submit.php',
    'api/mfs/create.php',
    'api/add_money/submit.php',
    'api/wallet/get.php',
];
foreach ($transactionEndpoints as $relativePath) {
    maintenance_expect(
        str_contains(maintenance_source($root . '/' . $relativePath), 'auth_require_user('),
        "{$relativePath} must remain behind the shared authenticated User boundary."
    );
}
foreach (['transfer_recipient', 'transfer_preview', 'transfer_create'] as $action) {
    $actionPos = strpos($proxy, "case '{$action}':");
    $actionSource = $actionPos === false ? '' : substr($proxy, $actionPos, 900);
    maintenance_expect(
        $actionPos !== false && str_contains($actionSource, 'user_proxy_require_login'),
        "{$action} must remain behind the web session guard."
    );
}

maintenance_expect(!str_contains($adminLogin, 'system_require_user_service_available'), 'Admin login must remain available during maintenance.');
maintenance_expect(!str_contains(maintenance_source($root . '/api/subadmin/proxy.php'), 'system_require_user_service_available'), 'Subadmin behavior must remain unchanged in this task.');

maintenance_expect(
    str_contains($loginPage, 'id="loginMaintenanceView"')
        && str_contains($loginPage, 'id="retryLoginMaintenance"')
        && str_contains($loginJs, "post('maintenance_status'")
        && str_contains($loginJs, 'showMaintenance()'),
    'Login must render a server-driven maintenance state with Retry.'
);
maintenance_expect(
    str_contains($shellHead, 'user-service-checking')
        && str_contains($shellHead, 'id="userMaintenanceView"')
        && str_contains($shellJs, 'showMaintenanceState()')
        && str_contains($shellJs, 'retryUserMaintenance'),
    'Authenticated pages must prevent content flash and expose a Retry maintenance state.'
);
maintenance_expect(!str_contains($loginJs, 'localStorage') && !str_contains($shellJs, 'localStorage'), 'Maintenance state must not trust persistent client storage.');
maintenance_expect(
    substr_count($helper . $loginPage . $shellHead, system_maintenance_message()) >= 3,
    'Backend and Web maintenance copy must stay consistent.'
);

echo "User maintenance enforcement tests passed ({$maintenanceAssertions} assertions).\n";
