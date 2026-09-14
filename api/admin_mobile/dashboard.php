<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';
require_once dirname(__DIR__) . '/lib/admin_pagination.php';
require_once dirname(__DIR__) . '/lib/admin_topup.php';
require_once dirname(__DIR__) . '/lib/admin_user_filters.php';
require_once dirname(__DIR__) . '/lib/add_money.php';
require_once dirname(__DIR__) . '/lib/bundle.php';
require_once dirname(__DIR__) . '/lib/mfs.php';
require_once dirname(__DIR__) . '/lib/mfs_admin_settings.php';
require_once dirname(__DIR__) . '/lib/support.php';

api_require_method('GET');
$auth = admin_mobile_require_session(true);

$topup = admin_topup_read_bucket_page('PENDING', [], '', 10);
$bundle = admin_firebase_cursor_page(
    'BUNDLE_REQUESTS/PENDING',
    10,
    '',
    null,
    static function (array $row, string $requestId): array {
        $row['request_id'] = (string)($row['request_id'] ?? $requestId);
        return $row;
    }
);
$bkash = mfs_read_bucket_page('PENDING', ['provider' => 'BKASH'], '', 10);
$nagad = mfs_read_bucket_page('PENDING', ['provider' => 'NAGAD'], '', 10);
$addMoney = add_money_list_admin_page(['status' => 'PENDING'], '', 10);
$reviews = admin_firebase_cursor_page(
    'USERS',
    10,
    '',
    static fn(array $user): bool => admin_users_list_account_status($user) === 'REVIEW'
);
$support = support_admin_page('OPEN', '', '', 10);
$config = fb_get('APP_CONFIG');
$config = is_array($config) ? $config : [];

api_response(true, 'ADMIN_MOBILE_DASHBOARD_OK', 'Admin dashboard loaded.', [
    'admin' => [
        'uid' => (string)($auth['user']['uid'] ?? ''),
        'name' => (string)($auth['user']['name'] ?? ''),
    ],
    'pending' => [
        'topup' => admin_mobile_count_page($topup),
        'bundle' => admin_mobile_count_page($bundle),
        'bkash' => admin_mobile_count_page($bkash),
        'nagad' => admin_mobile_count_page($nagad),
        'add_money' => admin_mobile_count_page($addMoney),
        'account_review' => admin_mobile_count_page($reviews),
        'support' => admin_mobile_count_page([
            'items' => (array)($support['items'] ?? []),
            'pagination' => (array)($support['pagination'] ?? []),
        ]),
    ],
    'rate' => mfs_admin_rate_state(),
    'maintenance' => [
        'enabled' => (bool)($config['maintenance_mode'] ?? false),
        'updated_at' => max(0, (int)($config['maintenance_updated_at'] ?? $config['updated_at'] ?? 0)),
        'updated_by_admin_uid' => (string)($config['maintenance_updated_by_admin_uid'] ?? $config['updated_by_admin_uid'] ?? ''),
    ],
    'server_time' => now_ts(),
]);
