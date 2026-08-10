<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($path === '/test/support-viewport') {
    $width = max(320, min(1366, (int)($_GET['width'] ?? 390)));
    $height = max(560, min(1000, (int)($_GET['height'] ?? 844)));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><style>'
        . 'html,body{margin:0;min-height:100%;background:#020a16;display:grid;place-items:start center}'
        . 'iframe{width:' . $width . 'px;height:' . $height . 'px;border:0;background:#07172f}'
        . '</style></head><body><iframe id="supportFrame" src="/user/support" title="Support viewport"></iframe></body></html>';
    exit;
}

if ($path === '/api/user/proxy.php') {
    $action = trim((string)($_GET['action'] ?? ''));
    if ($action === 'support_attachment') {
        header('Content-Type: image/png');
        header('Cache-Control: private, no-store');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
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
                'attachments_enabled' => true,
                'max_attachments' => 3,
                'max_file_size' => 5242880,
                'email_enabled' => true,
                'support_email' => 'support@example.invalid',
                'whatsapp_enabled' => false,
            ],
            'categories' => [
                ['code' => 'ACCOUNT_LOGIN', 'name' => 'Account / Login', 'related_request_enabled' => false, 'attachment_enabled' => true],
                ['code' => 'ADD_MONEY', 'name' => 'Add Money', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'MOBILE_TOPUP', 'name' => 'Mobile Top-Up', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'BKASH', 'name' => 'bKash', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'NAGAD', 'name' => 'Nagad', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'ZPAY_TRANSFER', 'name' => 'Z-Pay Transfer', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'BUNDLE', 'name' => 'Bundle', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'WALLET_BALANCE', 'name' => 'Wallet / Balance', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'TRANSACTION_ISSUE', 'name' => 'Transaction Issue', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'OTHER', 'name' => 'Other', 'related_request_enabled' => false, 'attachment_enabled' => true],
            ],
        ],
        'support_list' => ['tickets' => [
            [
                'ticket_id' => 'SP202608090001',
                'subject' => 'Transfer problem',
                'category_code' => 'ZPAY_TRANSFER',
                'category_name' => 'Z-Pay Transfer',
                'status' => 'REPLIED',
                'status_label' => 'Replied',
                'last_message_preview' => 'We checked your transfer and replied.',
                'created_at' => 1786228200,
                'updated_at' => 1786231800,
                'last_message_at' => 1786231800,
                'user_unread' => true,
            ],
            [
                'ticket_id' => 'SP202608080002',
                'subject' => 'Bundle issue',
                'category_code' => 'BUNDLE',
                'category_name' => 'Bundle',
                'status' => 'CLOSED',
                'status_label' => 'Closed',
                'last_message_preview' => 'This conversation has been closed.',
                'created_at' => 1786141800,
                'updated_at' => 1786145400,
                'last_message_at' => 1786145400,
                'user_unread' => false,
            ],
        ]],
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
    if ($action === 'support_details' || $action === 'support_reply') {
        $ticketId = trim((string)($_GET['ticket_id'] ?? $_POST['ticket_id'] ?? 'SP202608090001')) ?: 'SP202608090001';
        $closed = $ticketId === 'SP202608080002';
        $data = [
            'ticket' => [
                'ticket_id' => $ticketId,
                'subject' => $closed ? 'Bundle issue' : 'Transfer problem',
                'category_code' => $closed ? 'BUNDLE' : 'ZPAY_TRANSFER',
                'category_name' => $closed ? 'Bundle' : 'Z-Pay Transfer',
                'status' => $closed ? 'CLOSED' : 'REPLIED',
                'status_label' => $closed ? 'Closed' : 'Replied',
                'created_at' => 1786228200,
            ],
            'messages' => [
                ['message_id' => 'MSG-1', 'sender_type' => 'USER', 'sender_name' => 'LOCAL TEST USER', 'message' => 'My transfer needs checking.', 'created_at' => 1786228200, 'attachment_ids' => []],
                ['message_id' => 'MSG-2', 'sender_type' => 'SUPPORT', 'sender_name' => 'Z-Pay Swift Support', 'message' => $closed ? 'This ticket is now closed.' : 'We checked your transfer and replied.', 'created_at' => 1786231800, 'attachment_ids' => ['ATT-1']],
            ],
            'attachments' => [
                ['attachment_id' => 'ATT-1', 'message_id' => 'MSG-2', 'original_name' => 'support-reply.png'],
            ],
        ];
        echo json_encode(['ok' => true, 'code' => 'SUCCESS', 'message' => 'Local support response', 'data' => $data]);
        exit;
    }
    if ($action === 'support_create') {
        echo json_encode(['ok' => true, 'code' => 'SUPPORT_TICKET_CREATED', 'message' => 'Local ticket created', 'data' => [
            'ticket' => ['ticket_id' => 'SP202608090003', 'subject' => (string)($_POST['subject'] ?? 'Local ticket'), 'status' => 'OPEN'],
        ]]);
        exit;
    }
    $data = $responses[$action] ?? [];
    echo json_encode(['ok' => true, 'code' => 'SUCCESS', 'message' => 'Local test response', 'data' => $data]);
    exit;
}

if (str_starts_with($path, '/api/') || str_starts_with($path, '/assets/')) {
    return false;
}

$authRoutes = [
    '/auth-test/login' => 'index.php',
    '/auth-test/register' => 'register.php',
    '/auth-test/forgot' => 'forgot.php',
];

if (isset($authRoutes[$path])) {
    session_name('zawtopup_user');
    session_start();
    $_SESSION = [];
    require $root . '/api/user/' . $authRoutes[$path];
    exit;
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
    '/user/information' => 'information.php',
    '/user/info' => 'information.php',
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
