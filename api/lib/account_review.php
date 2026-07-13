<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/notifications.php';

function account_review_telegram_bot_token(): string
{
    return defined('TELEGRAM_BOT_TOKEN') ? trim((string)TELEGRAM_BOT_TOKEN) : '';
}

function account_review_telegram_chat_id(): string
{
    return defined('TELEGRAM_CHAT_ID') ? trim((string)TELEGRAM_CHAT_ID) : '';
}

function account_review_action_key(): string
{
    if (defined('TELEGRAM_ACCOUNT_REVIEW_ACTION_KEY')) {
        $key = trim((string)TELEGRAM_ACCOUNT_REVIEW_ACTION_KEY);
        if ($key !== '') {
            return $key;
        }
    }

    return defined('APP_KEY') ? trim((string)APP_KEY) : '';
}

function account_review_telegram_enabled(): bool
{
    return account_review_telegram_bot_token() !== ''
        && account_review_telegram_chat_id() !== ''
        && account_review_action_key() !== '';
}

function account_review_action_details(string $action): array
{
    $code = strtolower(trim($action));
    $map = [
        'a' => 'APPROVE',
        'r' => 'REJECT',
        'v' => 'VIEW',
    ];

    return isset($map[$code])
        ? ['ok' => true, 'code' => $code, 'action' => $map[$code]]
        : ['ok' => false, 'code' => '', 'action' => ''];
}

function account_review_signature(string $uid, string $action): string
{
    $details = account_review_action_details($action);
    $code = (string)($details['code'] ?? '');

    if ($uid === '' || $code === '' || account_review_action_key() === '') {
        return '';
    }

    return substr(hash_hmac('sha256', $code . '|' . $uid, account_review_action_key()), 0, 16);
}

function account_review_callback_data(string $action, string $uid): string
{
    $uid = trim($uid);
    $details = account_review_action_details($action);

    if (
        empty($details['ok'])
        || $uid === ''
        || preg_match('/^[A-Za-z0-9_-]{6,39}$/', $uid) !== 1
    ) {
        return '';
    }

    $code = (string)$details['code'];
    $data = 'acct|' . $code . '|' . $uid . '|' . account_review_signature($uid, $code);

    return strlen($data) <= 64 ? $data : '';
}

function account_review_parse_callback_data(string $callbackData): array
{
    $parts = explode('|', trim($callbackData));

    if (count($parts) !== 4 || strtolower((string)$parts[0]) !== 'acct') {
        return [
            'ok' => false,
            'code' => 'INVALID_CALLBACK',
            'message' => 'Invalid account review callback',
            'data' => ['reason' => 'format'],
        ];
    }

    $details = account_review_action_details((string)$parts[1]);
    $uid = trim((string)$parts[2]);
    $signature = trim((string)$parts[3]);

    if (empty($details['ok'])) {
        return [
            'ok' => false,
            'code' => 'INVALID_CALLBACK',
            'message' => 'Invalid account review callback',
            'data' => ['reason' => 'action'],
        ];
    }

    if ($uid === '' || preg_match('/^[A-Za-z0-9_-]{6,39}$/', $uid) !== 1) {
        return [
            'ok' => false,
            'code' => 'INVALID_CALLBACK',
            'message' => 'Invalid account review callback',
            'data' => ['reason' => 'uid'],
        ];
    }

    $expected = account_review_signature($uid, (string)$details['code']);
    if ($signature === '' || $expected === '' || !hash_equals($expected, $signature)) {
        return [
            'ok' => false,
            'code' => 'INVALID_SIGNATURE',
            'message' => 'Invalid account review callback signature',
            'data' => ['reason' => 'signature', 'uid' => $uid],
        ];
    }

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Account review callback verified',
        'data' => [
            'uid' => $uid,
            'action' => (string)$details['action'],
            'action_code' => (string)$details['code'],
        ],
    ];
}

function account_review_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function account_review_yes_no($value): string
{
    return $value ? 'Yes' : 'No';
}

function account_review_message(array $user): string
{
    $uid = trim((string)($user['uid'] ?? ''));
    $status = strtoupper(trim((string)($user['account_status'] ?? $user['status'] ?? 'REVIEW')));
    $createdAt = (int)($user['created_at'] ?? time());

    return "⚠️ <b>New Account Review Required</b>\n\n"
        . "Name: <b>" . account_review_h($user['name'] ?? '-') . "</b>\n"
        . "Phone: <code>" . account_review_h($user['phone_e164'] ?? $user['phone'] ?? '-') . "</code>\n"
        . "UID: <code>" . account_review_h($uid) . "</code>\n"
        . "Phone Country: <b>" . account_review_h($user['phone_country'] ?? '-') . "</b>\n"
        . "GPS Country: <b>" . account_review_h($user['gps_country'] ?? '-') . "</b>\n"
        . "IP Country: <b>" . account_review_h($user['ip_country'] ?? 'UNKNOWN') . "</b>\n"
        . "IP Source: <b>" . account_review_h($user['ip_source'] ?? '-') . "</b>\n"
        . "Pricing Country: <b>" . account_review_h($user['pricing_country'] ?? '-') . "</b>\n"
        . "Currency: <b>" . account_review_h($user['currency'] ?? '-') . "</b>\n"
        . "Mismatch: <b>" . account_review_yes_no(!empty($user['country_mismatch'])) . "</b>\n"
        . "VPN Suspected: <b>" . account_review_yes_no(!empty($user['vpn_suspected'])) . "</b>\n"
        . "Review Reason: " . account_review_h($user['account_review_reason'] ?? '-') . "\n"
        . "Status: <b>" . account_review_h($status) . "</b>\n"
        . "Created At: " . account_review_h(date('Y-m-d H:i:s', $createdAt));
}

function account_review_keyboard(string $uid): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => '✅ Approve', 'callback_data' => account_review_callback_data('a', $uid)],
                ['text' => '❌ Reject', 'callback_data' => account_review_callback_data('r', $uid)],
            ],
            [
                ['text' => '👁 View', 'callback_data' => account_review_callback_data('v', $uid)],
            ],
        ],
    ];
}

function account_review_telegram_api(string $method, array $payload): array
{
    if (!account_review_telegram_enabled()) {
        return [
            'ok' => false,
            'code' => 'CONFIG_ERROR',
            'message' => 'Telegram account review config missing',
            'data' => [],
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'code' => 'CURL_MISSING',
            'message' => 'PHP cURL extension is not available',
            'data' => [],
        ];
    }

    $ch = curl_init(
        'https://api.telegram.org/bot'
        . account_review_telegram_bot_token()
        . '/'
        . ltrim($method, '/')
    );
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = is_string($raw) ? json_decode($raw, true) : null;
    $ok = $http >= 200 && $http < 300 && is_array($json) && !empty($json['ok']);

    return [
        'ok' => $ok,
        'code' => $ok ? 'SUCCESS' : 'TELEGRAM_ERROR',
        'message' => $ok
            ? 'Telegram request sent'
            : (string)($json['description'] ?? ($error !== '' ? $error : 'Telegram request failed')),
        'data' => [
            'http' => $http,
            'response' => is_array($json) ? $json : [],
        ],
    ];
}

function account_review_send_telegram(string $uid, array $user): array
{
    if ($uid === '' || strtoupper((string)($user['account_status'] ?? $user['status'] ?? '')) !== 'REVIEW') {
        return [
            'ok' => false,
            'code' => 'NOT_REVIEW_ACCOUNT',
            'message' => 'Account does not require review',
            'data' => [],
        ];
    }

    $res = account_review_telegram_api('sendMessage', [
        'chat_id' => account_review_telegram_chat_id(),
        'text' => account_review_message($user),
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => account_review_keyboard($uid),
    ]);

    if (empty($res['ok'])) {
        return $res;
    }

    $result = (array)($res['data']['response']['result'] ?? []);

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Account review notification sent',
        'data' => [
            'message_id' => (int)($result['message_id'] ?? 0),
            'chat_id' => (string)($result['chat']['id'] ?? account_review_telegram_chat_id()),
        ],
    ];
}

function account_review_apply(
    string $uid,
    string $action,
    string $actorUid,
    string $actorRole = 'ADMIN'
): array {
    $uid = trim($uid);
    $action = strtoupper(trim($action));
    $actorUid = trim($actorUid);
    $actorRole = strtoupper(trim($actorRole));

    if ($uid === '' || !in_array($action, ['APPROVE', 'REJECT'], true)) {
        return [
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'message' => 'Invalid account review action',
            'data' => [],
        ];
    }

    $user = fb_get('USERS/' . $uid);
    if (!is_array($user)) {
        return [
            'ok' => false,
            'code' => 'NOT_FOUND',
            'message' => 'User not found',
            'data' => [],
        ];
    }

    $currentStatus = strtoupper(trim((string)($user['account_status'] ?? $user['status'] ?? 'INACTIVE')));
    if ($action === 'APPROVE' && $currentStatus === 'ACTIVE') {
        return [
            'ok' => true,
            'code' => 'ALREADY_ACTIVE',
            'message' => 'Account is already active',
            'data' => ['uid' => $uid, 'status' => 'ACTIVE'],
        ];
    }

    if ($action === 'REJECT' && $currentStatus === 'REJECTED') {
        return [
            'ok' => true,
            'code' => 'ALREADY_REJECTED',
            'message' => 'Account is already rejected',
            'data' => ['uid' => $uid, 'status' => 'REJECTED'],
        ];
    }

    if (!in_array($currentStatus, ['REVIEW', 'BLOCKED', 'INACTIVE'], true)) {
        return [
            'ok' => false,
            'code' => 'INVALID_STATUS',
            'message' => 'Account cannot be reviewed from its current status',
            'data' => ['uid' => $uid, 'current_status' => $currentStatus],
        ];
    }

    $now = function_exists('now_ts') ? (int)now_ts() : time();
    $newStatus = $action === 'APPROVE' ? 'ACTIVE' : 'REJECTED';
    $patch = [
        'status' => $newStatus,
        'account_status' => $newStatus,
        'review_required' => false,
        'requires_admin_review' => false,
        'review_status' => $action === 'APPROVE' ? 'APPROVED' : 'REJECTED',
        'reviewed_by_uid' => $actorUid,
        'reviewed_by_role' => $actorRole,
        'reviewed_at' => $now,
        'updated_at' => $now,
    ];

    if ($action === 'APPROVE') {
        $patch['approved_by'] = $actorUid;
        $patch['approved_by_uid'] = $actorUid;
        $patch['approved_at'] = $now;
    } else {
        $patch['rejected_by'] = $actorUid;
        $patch['rejected_by_uid'] = $actorUid;
        $patch['rejected_at'] = $now;
    }

    if (!fb_patch('USERS/' . $uid, $patch)) {
        return [
            'ok' => false,
            'code' => 'SERVER_ERROR',
            'message' => 'Failed to update account review status',
            'data' => ['uid' => $uid],
        ];
    }

    $logContext = [
        'uid' => $uid,
        'old_status' => $currentStatus,
        'new_status' => $newStatus,
        'pricing_country' => function_exists('auth_pricing_country_from_user')
            ? auth_pricing_country_from_user($user)
            : (string)($user['pricing_country'] ?? ''),
        'gps_country' => (string)($user['gps_country'] ?? ''),
        'ip_country' => (string)($user['ip_country'] ?? ''),
        'vpn_suspected' => (bool)($user['vpn_suspected'] ?? false),
        'actor_uid' => $actorUid,
        'actor_role' => $actorRole,
    ];

    if (
        function_exists('admin_action_log')
        && in_array($actorRole, ['ADMIN', 'TELEGRAM_ADMIN'], true)
    ) {
        admin_action_log(
            $action === 'APPROVE' ? 'APPROVE_USER_ACCOUNT' : 'REJECT_USER_ACCOUNT',
            $uid,
            $action === 'APPROVE'
                ? 'Admin approved reviewed user account'
                : 'Admin rejected reviewed user account',
            $logContext
        );
    }

    if (function_exists('system_log')) {
        system_log(
            $action === 'APPROVE' ? 'USER_ACCOUNT_APPROVED' : 'USER_ACCOUNT_REJECTED',
            $uid,
            $action === 'APPROVE'
                ? 'Reviewed user account approved'
                : 'Reviewed user account rejected',
            $logContext
        );
    }

    notification_record_user(
        $uid,
        $action === 'APPROVE' ? 'ACCOUNT_APPROVED' : 'ACCOUNT_REJECTED',
        $action === 'APPROVE' ? 'Account Approved' : 'Account Rejected',
        $action === 'APPROVE'
            ? 'Your Z-Pay Swift account has been approved.'
            : 'Your Z-Pay Swift account review was rejected.',
        'ACCOUNT',
        $uid,
        ($action === 'APPROVE' ? 'ACCOUNT_APPROVED:' : 'ACCOUNT_REJECTED:') . $uid . ':' . $now,
        [
            'status' => $newStatus,
        ]
    );

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => $action === 'APPROVE'
            ? 'Account approved successfully'
            : 'Account rejected successfully',
        'data' => [
            'uid' => $uid,
            'status' => $newStatus,
            'account_status' => $newStatus,
            'updated_at' => $now,
        ],
    ];
}
