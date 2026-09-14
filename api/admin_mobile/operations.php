<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/admin_mobile.php';
require_once dirname(__DIR__) . '/lib/admin_pagination.php';
require_once dirname(__DIR__) . '/lib/admin_topup.php';
require_once dirname(__DIR__) . '/lib/add_money.php';
require_once dirname(__DIR__) . '/lib/bundle.php';
require_once dirname(__DIR__) . '/lib/mfs.php';

function admin_mobile_operation_value(array $row, array $keys, mixed $fallback = ''): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== '' && $row[$key] !== null) {
            return $row[$key];
        }
    }
    return $fallback;
}

function admin_mobile_operation_item(string $type, array $row): array
{
    $type = strtoupper(trim($type));
    $requestId = trim((string)admin_mobile_operation_value($row, ['request_id', 'id']));
    $provider = strtoupper(trim((string)admin_mobile_operation_value($row, ['provider', 'provider_name'])));
    $operator = strtoupper(trim((string)admin_mobile_operation_value($row, ['operator'])));
    $amount = (float)admin_mobile_operation_value($row, [
        'amount_bdt', 'service_amount_bdt', 'amount', 'topup_amount', 'price_amount', 'bundle_price',
    ], 0);
    $currency = strtoupper(trim((string)admin_mobile_operation_value($row, [
        'service_currency', 'currency', 'wallet_currency', 'display_currency',
    ], in_array($type, ['TOPUP', 'BUNDLE', 'BKASH', 'NAGAD'], true) ? 'BDT' : '')));

    $detailKeys = [
        'request_id', 'uid', 'user_name', 'name', 'user_phone', 'phone', 'role',
        'provider', 'provider_name', 'service_type', 'operator', 'topup_number',
        'receiver_number', 'number', 'bundle_name', 'package_name', 'plan_name',
        'amount', 'amount_bdt', 'service_amount_bdt', 'fee', 'fee_bdt', 'fee_rm',
        'total', 'total_pay', 'wallet_debit', 'wallet_debit_amount', 'wallet_debit_currency',
        'currency', 'wallet_currency', 'pricing_country', 'country_code', 'method',
        'payment_account_name', 'transaction_id', 'trxid', 'sender_number', 'sender_details',
        'receipt_url', 'receipt_mime', 'reference', 'note', 'message', 'status_message',
        'status', 'created_at', 'updated_at', 'processing_at', 'completed_at',
    ];
    $details = [];
    foreach ($detailKeys as $key) {
        if (array_key_exists($key, $row)) {
            $details[$key] = $row[$key];
        }
    }

    return [
        'type' => $type,
        'request_id' => $requestId,
        'status' => strtoupper(trim((string)admin_mobile_operation_value($row, ['status', 'request_status'], 'PENDING'))),
        'user_name' => trim((string)admin_mobile_operation_value($row, ['user_name', 'name'])),
        'user_phone' => trim((string)admin_mobile_operation_value($row, ['user_phone', 'phone'])),
        'target_number' => trim((string)admin_mobile_operation_value($row, ['receiver_number', 'topup_number', 'number'])),
        'provider' => $provider,
        'operator' => $operator,
        'product_name' => trim((string)admin_mobile_operation_value($row, ['bundle_name', 'package_name', 'plan_name'])),
        'amount' => round($amount, 2),
        'currency' => $currency,
        'fee' => round((float)admin_mobile_operation_value($row, ['fee', 'fee_bdt', 'fee_rm'], 0), 2),
        'created_at' => max(0, (int)admin_mobile_operation_value($row, ['created_at', 'updated_at'], 0)),
        'details' => $details,
    ];
}

api_require_method('GET');
admin_mobile_require_session(true);

$type = strtoupper(trim((string)($_GET['type'] ?? 'TOPUP')));
$cursor = trim((string)($_GET['cursor'] ?? ''));
$limit = max(1, min(10, (int)($_GET['limit'] ?? 10)));
$query = trim((string)($_GET['query'] ?? ''));
$allowed = ['TOPUP', 'BUNDLE', 'BKASH', 'NAGAD', 'ADD_MONEY'];
if (!in_array($type, $allowed, true)) {
    api_response(false, 'VALIDATION_ERROR', 'Invalid operation type.', ['allowed_types' => $allowed], 422);
}

if ($type === 'TOPUP') {
    $page = admin_topup_read_bucket_page('PENDING', ['query' => $query], $cursor, $limit);
} elseif ($type === 'BUNDLE') {
    $page = admin_firebase_cursor_page(
        'BUNDLE_REQUESTS/PENDING',
        $limit,
        $cursor,
        static function (array $row) use ($query): bool {
            if ($query === '') {
                return true;
            }
            $haystack = strtolower(implode(' ', [
                (string)($row['request_id'] ?? ''),
                (string)($row['user_name'] ?? ''),
                (string)($row['phone'] ?? ''),
                (string)($row['topup_number'] ?? $row['number'] ?? ''),
            ]));
            return str_contains($haystack, strtolower($query));
        },
        static function (array $row, string $requestId): array {
            $row['request_id'] = (string)($row['request_id'] ?? $requestId);
            return $row;
        }
    );
} elseif ($type === 'BKASH' || $type === 'NAGAD') {
    $page = mfs_read_bucket_page('PENDING', ['provider' => $type], $cursor, $limit);
} else {
    $page = add_money_list_admin_page(['status' => 'PENDING'], $cursor, $limit);
}

$items = array_map(
    static fn(array $row): array => admin_mobile_operation_item($type, $row),
    (array)($page['items'] ?? [])
);

api_response(true, 'ADMIN_MOBILE_OPERATIONS_OK', 'Pending operations loaded.', [
    'type' => $type,
    'items' => $items,
    'pagination' => (array)($page['pagination'] ?? []),
]);
