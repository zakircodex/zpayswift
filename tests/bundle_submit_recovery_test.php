<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$GLOBALS['bundle_recovery_store'] = [];
$GLOBALS['bundle_recovery_reads'] = [];
$assertions = 0;

function fb_get(string $path)
{
    $GLOBALS['bundle_recovery_reads'][] = $path;
    return $GLOBALS['bundle_recovery_store'][$path] ?? null;
}

function fb_put(string $path, $value): bool
{
    $GLOBALS['bundle_recovery_store'][$path] = $value;
    return true;
}

function fb_patch(string $path, array $data): bool
{
    if ($path === '') {
        foreach ($data as $childPath => $value) {
            $GLOBALS['bundle_recovery_store'][$childPath] = $value;
        }
        return true;
    }

    $current = $GLOBALS['bundle_recovery_store'][$path] ?? [];
    $GLOBALS['bundle_recovery_store'][$path] = array_merge(is_array($current) ? $current : [], $data);
    return true;
}

function bundle_recovery_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/api/lib/bundle.php';

$bdRateReadsBefore = count($GLOBALS['bundle_recovery_reads']);
$bdBreakdown = bundle_wallet_breakdown(
    'BUNDLE_BD_USER',
    100.0,
    ['uid' => 'BUNDLE_BD_USER', 'pricing_country' => 'BD', 'wallet_currency' => 'BDT'],
    ['available_balance' => 500.0, 'wallet_currency' => 'BDT']
);
bundle_recovery_expect(
    count($GLOBALS['bundle_recovery_reads']) === $bdRateReadsBefore
    && ($bdBreakdown['wallet_currency'] ?? '') === 'BDT'
    && (float)($bdBreakdown['wallet_hold_amount'] ?? 0) === 100.0
    && (float)($bdBreakdown['rate_used'] ?? -1) === 0.0,
    'BD Bundle calculation must remain BDT-only and skip MYR rate lookup.'
);

$previewToken = 'bundle-recovery-token';
$previewPath = 'BUNDLE_PREVIEWS/' . bundle_preview_token_hash($previewToken);
$requestPath = 'BUNDLE_REQUESTS/PENDING/BUNDLE_RECOVERY_1';
$GLOBALS['bundle_recovery_store'][$previewPath] = [
    'uid' => 'BUNDLE_USER',
    'status' => 'USED',
    'used' => true,
    'request_id' => 'BUNDLE_RECOVERY_1',
];
$GLOBALS['bundle_recovery_store'][$requestPath] = [
    'request_id' => 'BUNDLE_RECOVERY_1',
    'uid' => 'BUNDLE_USER',
    'status' => 'WAITING_ADMIN',
    'offer_id' => 'OFFER_1',
    'operator' => 'GP',
    'operator_name' => 'Grameenphone',
    'bundle_number' => '01300000000',
    'bundle_name' => 'Test Bundle',
    'service_amount' => 100,
    'service_amount_bdt' => 100,
    'service_currency' => 'BDT',
    'bundle_commission' => 3,
    'commission_currency' => 'BDT',
    'wallet_debit_amount' => 97,
    'wallet_debit_currency' => 'BDT',
    'wallet_debit_bdt' => 97,
    'wallet_debit_myr' => 0,
    'balance_after' => 903,
    'telegram_sent' => false,
    'telegram_error' => '',
    'phone' => '01300000000',
    'internal_note' => 'private',
];

$recovered = bundle_recover_request_from_preview_token('BUNDLE_USER', $previewToken);
bundle_recovery_expect(
    !empty($recovered['ok'])
    && ($recovered['request_id'] ?? '') === 'BUNDLE_RECOVERY_1'
    && ($recovered['request']['_bucket'] ?? '') === 'PENDING',
    'Committed Bundle request must recover from its owned preview token.'
);

$forbidden = bundle_recover_request_from_preview_token('OTHER_USER', $previewToken);
bundle_recovery_expect(
    empty($forbidden['ok']) && ($forbidden['code'] ?? '') === 'BUNDLE_RECOVERY_FORBIDDEN',
    'Bundle recovery must reject a different authenticated user.'
);

$response = bundle_submit_response_data((array)$recovered['request']);
bundle_recovery_expect(
    ($response['request_id'] ?? '') === 'BUNDLE_RECOVERY_1'
    && ($response['status'] ?? '') === 'WAITING_ADMIN'
    && (float)($response['wallet_debit_amount'] ?? 0) === 97.0
    && (float)($response['balance_after'] ?? 0) === 903.0,
    'Recovered response must preserve canonical request and wallet summary fields.'
);
bundle_recovery_expect(
    !array_key_exists('uid', $response)
    && !array_key_exists('phone', $response)
    && !array_key_exists('internal_note', $response),
    'Recovered response must not expose private request fields.'
);

$submitSource = (string)file_get_contents(dirname(__DIR__) . '/api/bundle/submit.php');
$proxySource = (string)file_get_contents(dirname(__DIR__) . '/api/user/proxy.php');
bundle_recovery_expect(
    str_contains($submitSource, "header('Content-Length: '")
    && str_contains($submitSource, "'telegram_skip' => true")
    && str_contains($submitSource, "'balance_after' => bundle_round_money((float)(\$hold['after_available']")
    && str_contains($submitSource, 'bundle_submit_ensure_history(')
    && str_contains($submitSource, "'history_written' => \$historyWritten")
    && str_contains($submitSource, 'bundle_submit_finish_response(['),
    'Bundle submit must preserve the post-hold balance and return complete JSON before Telegram work.'
);
bundle_recovery_expect(
    str_contains($proxySource, 'user_proxy_forward_bundle_submit(')
    && str_contains($proxySource, "'canonical_only' => true")
    && str_contains($proxySource, "'max_attempts' => 1")
    && str_contains($proxySource, 'bundle_write_history($request)')
    && str_contains($proxySource, "'BUNDLE_SUBMIT_STATUS_UNKNOWN'"),
    'Bundle proxy must avoid repeat submission and safely classify an unrecovered result.'
);

$atomicRequestId = 'BUNDLE_ATOMIC_HISTORY_1';
$atomicCreated = create_bundle_pending_request(
    $atomicRequestId,
    'BUNDLE_USER',
    '60123456789',
    '01300000001',
    'GP',
    'Atomic Bundle',
    100,
    '',
    false,
    '',
    [
        'offer_id' => 'OFFER_ATOMIC',
        'service_amount_bdt' => 100,
        'bundle_commission' => 3,
        'you_pay' => 97,
        'payable_amount' => 97,
        'wallet_hold_amount' => 3.16,
        'wallet_debit_amount' => 3.16,
        'wallet_debit_currency' => 'MYR',
        'wallet_currency' => 'MYR',
        'wallet_debit_bdt' => 97,
        'wallet_debit_myr' => 3.16,
        'rate_used' => 30.7,
        'rate_snapshot' => 30.7,
        'rate_applicable' => true,
        'balance_after' => 899.84,
        'telegram_skip' => true,
    ]
);
$atomicRequestPath = 'BUNDLE_REQUESTS/PENDING/' . $atomicRequestId;
$atomicHistoryPath = 'BUNDLE_HISTORY/BUNDLE_USER/' . bundle_month_key() . '/' . $atomicRequestId;
$atomicHistory = $GLOBALS['bundle_recovery_store'][$atomicHistoryPath] ?? [];
bundle_recovery_expect(
    $atomicCreated
    && isset($GLOBALS['bundle_recovery_store'][$atomicRequestPath])
    && is_array($atomicHistory),
    'Bundle create must atomically persist both its pending queue row and monthly history mirror.'
);
bundle_recovery_expect(
    (float)($atomicHistory['balance_after'] ?? -1) === 899.84
    && ($atomicHistory['wallet_currency'] ?? '') === 'MYR'
    && (float)($atomicHistory['wallet_debit_amount'] ?? 0) === 3.16
    && (int)($atomicHistory['completed_at'] ?? -1) === 0,
    'Pending Bundle history must preserve financial fields without inventing a completion timestamp.'
);

$historyDone = [
    'uid' => 'BUNDLE_USER',
    'request_id' => 'BUNDLE_HISTORY_BALANCE_1',
    'status' => 'SUCCESS',
    'bundle_number' => '01300000000',
    'operator' => 'GP',
    'bundle_name' => 'Test Bundle',
    'amount' => 100,
    'wallet_debit_amount' => 97,
    'wallet_debit_currency' => 'BDT',
    'balance_after' => 903,
    'created_at' => time(),
    'completed_at' => time(),
];
bundle_recovery_expect(
    bundle_write_history($historyDone),
    'Completed Bundle history must be written successfully.'
);
$historyPath = 'BUNDLE_HISTORY/BUNDLE_USER/' . bundle_month_key() . '/BUNDLE_HISTORY_BALANCE_1';
$savedHistory = $GLOBALS['bundle_recovery_store'][$historyPath] ?? [];
bundle_recovery_expect(
    is_array($savedHistory) && (float)($savedHistory['balance_after'] ?? -1) === 903.0,
    'Completed Bundle history must preserve the authoritative balance-after snapshot.'
);

$legacyHistoryDone = $historyDone;
$legacyHistoryDone['request_id'] = 'BUNDLE_HISTORY_LEGACY_1';
unset($legacyHistoryDone['balance_after']);
bundle_recovery_expect(
    bundle_write_history($legacyHistoryDone),
    'Legacy Bundle history without a balance snapshot must still be written.'
);
$legacyHistoryPath = 'BUNDLE_HISTORY/BUNDLE_USER/' . bundle_month_key() . '/BUNDLE_HISTORY_LEGACY_1';
$legacyHistory = $GLOBALS['bundle_recovery_store'][$legacyHistoryPath] ?? [];
bundle_recovery_expect(
    is_array($legacyHistory) && !array_key_exists('balance_after', $legacyHistory),
    'Legacy Bundle history must not invent a zero balance when no snapshot exists.'
);

echo "Bundle submit recovery tests passed ({$assertions} assertions).\n";
