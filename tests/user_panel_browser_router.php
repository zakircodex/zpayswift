<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($path === '/api/user/proxy.php') {
    header('Content-Type: application/json; charset=utf-8');
    $action = trim((string)($_GET['action'] ?? ''));
    $user = [
        'uid' => 'U-LOCAL-TEST',
        'name' => 'LOCAL TEST USER',
        'phone' => '60123456789',
        'email' => 'test@example.invalid',
        'role' => 'USER',
        'status' => 'ACTIVE',
        'pricing_country' => 'MY',
        'wallet_currency' => 'MYR',
        'created_at' => 1700000000,
    ];
    $wallet = [
        'available_balance' => 619.03,
        'hold_balance' => 0,
        'display_available_balance' => 619.03,
        'display_hold_balance' => 0,
        'wallet_currency' => 'MYR',
        'display_currency' => 'MYR',
        'pricing_country' => 'MY',
        'rate_myr_bdt' => 28.50,
    ];
    $responses = [
        'me' => ['user' => $user, 'csrf' => 'LOCAL-CSRF'],
        'dashboard_bootstrap' => [
            'user' => $user,
            'csrf' => 'LOCAL-CSRF',
            'wallet_summary' => ['wallet' => $wallet, 'pricing_country' => 'MY'],
            'request_logs' => ['items' => [['_test' => true]], 'history_complete' => false],
        ],
        'wallet_summary' => ['wallet' => $wallet, 'pricing_country' => 'MY'],
        'notifications_unread' => ['unread_count' => 2],
        'notifications_list' => [
            'items' => [[
                'notification_id' => 'N-LOCAL',
                'title' => 'Account update',
                'body' => 'This is local browser-test content.',
                'created_at' => 1700000000,
                'is_read' => false,
            ]],
            'unread_count' => 1,
        ],
        'request_logs' => ['items' => [], 'rows' => [], 'logs' => [], 'month' => '2026-07'],
        'profile_get' => ['profile' => $user + ['wallet_currency' => 'MYR']],
        'support_config' => [
            'config' => [
                'support_notice' => 'Never share your password, PIN or OTP.',
                'support_hours' => 'Every day, 10:00 AM - 10:00 PM',
                'average_response_text' => 'Average response time: within 24 hours.',
                'email_enabled' => true,
                'support_email' => 'support@example.invalid',
                'whatsapp_enabled' => false,
            ],
            'categories' => [['code' => 'OTHER', 'name' => 'Other']],
        ],
        'support_list' => ['tickets' => []],
        'transfer_favorites' => ['favorites' => []],
        'add_money_settings' => [
            'profile' => [
                'pricing_country' => 'MY',
                'currency' => 'MYR',
                'payment_accounts' => [[
                    'account_id' => 'LOCAL-RHB',
                    'country' => 'MY',
                    'currency' => 'MYR',
                    'method' => 'BANK',
                    'display_name' => 'RHB BANK',
                    'account_name' => 'LOCAL TEST USER',
                    'account_number' => '1234567890',
                    'is_active' => true,
                ]],
            ],
            'history' => [],
        ],
        'bundle_offers_panel' => ['offers' => []],
        'bundle_offers' => ['offers' => []],
    ];
    $data = $responses[$action] ?? [];
    echo json_encode(['ok' => true, 'code' => 'SUCCESS', 'message' => 'Local test response', 'data' => $data]);
    exit;
}

if (str_starts_with($path, '/api/') || str_starts_with($path, '/assets/')) {
    return false;
}

$routes = [
    '/user/' => 'dashboard.php',
    '/user/dashboard' => 'dashboard.php',
    '/user/add-money' => 'add-money.php',
    '/user/transfer' => 'transfer.php',
    '/user/topup' => 'topup.php',
    '/user/bundle' => 'bundle.php',
    '/user/bkash' => 'bkash.php',
    '/user/nagad' => 'nagad.php',
    '/user/history' => 'history.php',
    '/user/services' => 'services.php',
    '/user/notifications' => 'notifications.php',
    '/user/profile' => 'profile.php',
    '/user/contact-us' => 'contact-us.php',
    '/user/support' => 'support.php',
    '/user/z-pay-transfer' => 'transfer.php',
    '/user/bundles' => 'bundle.php',
    '/user/send-money' => 'bkash.php',
];

if (isset($routes[$path])) {
    session_name('zawtopup_user');
    session_start();
    $_SESSION['user_session_token'] = 'LOCAL_BROWSER_TEST_SESSION';
    $_SESSION['user_csrf'] = 'LOCAL-CSRF';
    require $root . '/api/user/' . $routes[$path];
    exit;
}

http_response_code(404);
echo 'Not found';
