<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$GLOBALS['bundle_recovery_store'] = [];
$assertions = 0;

function fb_get(string $path)
{
    return $GLOBALS['bundle_recovery_store'][$path] ?? null;
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
    && str_contains($submitSource, 'bundle_submit_finish_response(['),
    'Bundle submit must return complete JSON before Telegram work.'
);
bundle_recovery_expect(
    str_contains($proxySource, 'user_proxy_forward_bundle_submit(')
    && str_contains($proxySource, "'canonical_only' => true")
    && str_contains($proxySource, "'max_attempts' => 1")
    && str_contains($proxySource, "'BUNDLE_SUBMIT_STATUS_UNKNOWN'"),
    'Bundle proxy must avoid repeat submission and safely classify an unrecovered result.'
);

echo "Bundle submit recovery tests passed ({$assertions} assertions).\n";
