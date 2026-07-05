<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/operators.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/topup.php';
require_once dirname(__DIR__) . '/lib/topup_config.php';

function topup_submit_response_data(array $row, array $fallback = []): array
{
    $requestId = (string)($row['request_id'] ?? $fallback['request_id'] ?? '');
    $status = (string)($row['status'] ?? $fallback['status'] ?? 'PENDING');
    $amount = (float)($row['amount'] ?? $fallback['amount'] ?? 0);
    $walletDebit = (float)($row['wallet_debit_amount'] ?? $fallback['wallet_debit_amount'] ?? $amount);

    return [
        'request_id' => $requestId,
        'status' => $status !== '' ? $status : 'PENDING',
        'topup_number' => (string)($row['topup_number'] ?? $fallback['topup_number'] ?? ''),
        'operator' => normalize_operator($row['operator'] ?? $fallback['operator'] ?? ''),
        'amount' => $amount,
        'amount_bdt' => (float)($row['amount_bdt'] ?? $fallback['amount_bdt'] ?? $amount),
        'commission_per_1000' => (float)($row['commission_per_1000'] ?? $fallback['commission_per_1000'] ?? 0),
        'commission_bdt' => (float)($row['commission_bdt'] ?? $fallback['commission_bdt'] ?? 0),
        'wallet_debit_bdt' => (float)($row['wallet_debit_bdt'] ?? $fallback['wallet_debit_bdt'] ?? $amount),
        'wallet_debit_amount' => $walletDebit,
        'wallet_debit_currency' => (string)($row['wallet_debit_currency'] ?? $fallback['wallet_debit_currency'] ?? 'BDT'),
        'rate_used' => (float)($row['rate_used'] ?? $fallback['rate_used'] ?? 0),
        'total_debit' => $walletDebit,
    ];
}

function topup_submit_finish_response(array $payload, ?array $telegramRow = null, array $logPayload = []): void
{
    http_response_code(200);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $canContinue = false;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        $canContinue = true;
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
        $canContinue = true;
    } else {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }

    if ($canContinue && is_array($telegramRow) && $telegramRow !== []) {
        ignore_user_abort(true);
        if ($logPayload !== [] && function_exists('system_log')) {
            system_log(
                (string)($logPayload['type'] ?? 'TOPUP_SUBMIT'),
                (string)($logPayload['ref_id'] ?? ''),
                (string)($logPayload['message'] ?? 'Topup request created successfully'),
                (array)($logPayload['context'] ?? [])
            );
        }
        topup_notify_telegram_request($telegramRow);
    } elseif (is_array($telegramRow) && $telegramRow !== []) {
        $requestId = (string)($telegramRow['request_id'] ?? '');
        $bucket = (string)($telegramRow['_bucket'] ?? 'PENDING');
        if ($requestId !== '' && $bucket !== '') {
            @fb_patch('TOPUP_REQUESTS/' . $bucket . '/' . $requestId, [
                'telegram_sent' => false,
                'telegram_error' => 'Telegram notification deferred after fast app response',
                'updated_at' => now_ts(),
            ]);
        }
    }

    exit;
}

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(false);
$user = $auth['user'];
$uid = (string)$user['uid'];
$userPhone = (string)$user['phone'];

$body = api_read_json_body();

$previewToken = trim((string)($body['preview_token'] ?? ''));
if ($previewToken === '') {
    api_response(false, 'TOPUP_PREVIEW_REQUIRED', 'Top-up preview token is required.', [], 422);
}

$tokenHash = topup_preview_token_hash($previewToken);
$claim = topup_claim_preview_token($tokenHash, $uid);
if (empty($claim['ok'])) {
    topup_api_error($claim);
}

$preview = (array)($claim['preview'] ?? []);
$duplicateRequestId = trim((string)($claim['request_id'] ?? $preview['request_id'] ?? ''));
if (!empty($claim['duplicate']) && $duplicateRequestId !== '') {
    $existingRow = topup_find_request($duplicateRequestId);
    $fallbackData = topup_submit_response_data([], [
        'request_id' => $duplicateRequestId,
        'status' => 'PENDING',
        'topup_number' => (string)($preview['topup_number'] ?? $preview['number'] ?? ''),
        'operator' => (string)($preview['operator'] ?? ''),
        'amount' => (float)($preview['amount'] ?? 0),
        'amount_bdt' => (float)($preview['amount'] ?? 0),
        'wallet_debit_amount' => (float)($preview['wallet_debit_amount'] ?? $preview['amount'] ?? 0),
        'wallet_debit_bdt' => (float)($preview['wallet_debit_bdt'] ?? $preview['amount'] ?? 0),
        'wallet_debit_currency' => (string)($preview['wallet_currency'] ?? $preview['wallet_debit_currency'] ?? 'BDT'),
        'rate_used' => (float)($preview['rate'] ?? 0),
    ]);
    $data = is_array($existingRow)
        ? topup_submit_response_data($existingRow, $fallbackData)
        : $fallbackData;
    $data['idempotent_replay'] = true;
    api_response(true, 'TOPUP_REQUEST_CREATED', 'Topup request submitted', $data);
}

$failPreview = static function (string $code, string $message) use ($tokenHash): void {
    topup_mark_preview_failed($tokenHash, $code, $message);
};

$operator = normalize_operator($preview['operator'] ?? '');
$amount = topup_money($preview['amount'] ?? 0);
$countryCode = topup_country_code($preview['country_code'] ?? 'BD');
$topupNumber = topup_normalize_number_for_country($countryCode, $preview['topup_number'] ?? $preview['number'] ?? '');

if (!topup_is_valid_number_for_country($countryCode, $topupNumber) || $operator === '' || $amount <= 0) {
    $failPreview('TOPUP_PREVIEW_INVALID', 'Top-up preview data is invalid.');
    api_response(false, 'TOPUP_PREVIEW_INVALID', 'Top-up preview data is invalid. Please preview again.', [], 422);
}

$bodyNumber = topup_normalize_number_for_country($countryCode, $body['topup_number'] ?? $body['number'] ?? '');
$bodyOperator = normalize_operator($body['operator'] ?? '');
$bodyAmount = isset($body['amount']) && is_numeric($body['amount']) ? topup_money($body['amount']) : 0;
if (($bodyNumber !== '' && $bodyNumber !== $topupNumber)
    || ($bodyOperator !== '' && $bodyOperator !== $operator)
    || ($bodyAmount > 0 && abs($bodyAmount - $amount) > 0.001)
) {
    $failPreview('TOPUP_PREVIEW_MISMATCH', 'Top-up preview does not match this request.');
    api_response(false, 'TOPUP_PREVIEW_MISMATCH', 'Top-up preview does not match this request. Please preview again.', [], 422);
}

$amountValidation = topup_validate_request($countryCode, $operator, $amount, true, true);
if (empty($amountValidation['ok'])) {
    $failPreview((string)($amountValidation['code'] ?? 'TOPUP_AMOUNT_INVALID'), (string)($amountValidation['message'] ?? 'Invalid top-up amount.'));
    topup_api_error($amountValidation);
}

/*
|--------------------------------------------------------------------------
| Operator runtime check
|--------------------------------------------------------------------------
*/
$runtime = (array)($amountValidation['operator'] ?? []);

/*
|--------------------------------------------------------------------------
| Create request + hold balance
|--------------------------------------------------------------------------
*/
$requestId = make_topup_request_id();
$financials = topup_commission_breakdown($uid, $amount, $user);
$walletDebit = (float)$financials['wallet_debit_amount'];

$hold = wallet_hold_amount($uid, $walletDebit, $requestId, 'TOPUP_HOLD');
if (!($hold['ok'] ?? false)) {
    $code = (string)($hold['code'] ?? 'SERVER_ERROR');

    if ($code === 'INSUFFICIENT_BALANCE') {
        $failPreview('INSUFFICIENT_BALANCE', 'Not enough balance');
        api_response(false, 'INSUFFICIENT_BALANCE', 'Not enough balance', [
            'available_balance' => (float)($hold['available_balance'] ?? 0),
            'required_amount' => (float)($hold['required_amount'] ?? $walletDebit),
        ], 422);
    }

    $failPreview($code, (string)($hold['message'] ?? 'Wallet hold failed'));
    api_response(false, $code, (string)($hold['message'] ?? 'Wallet hold failed'), [], 500);
}

/*
|--------------------------------------------------------------------------
| Save topup request
|--------------------------------------------------------------------------
*/
$pendingExtra = [
    'preview_token_hash' => (string)($preview['_token_hash'] ?? ''),
    'preview_created_at' => (int)($preview['created_at'] ?? 0),
    'preview_expires_at' => (int)($preview['expires_at'] ?? 0),
    'verified_by' => topup_clean_text($preview['verified_by'] ?? $body['verified_by'] ?? '', 30),
];
$pendingRow = topup_pending_request_row(
    $requestId,
    $uid,
    $userPhone,
    $topupNumber,
    $operator,
    $amount,
    $financials,
    $pendingExtra
);
$pendingSaved = fb_put('TOPUP_REQUESTS/PENDING/' . $requestId, $pendingRow);

if (!$pendingSaved) {
    wallet_refund_hold($uid, $walletDebit, $requestId, 'TOPUP_REFUND');
    $failPreview('SERVER_ERROR', 'Failed to create topup request');
    api_response(false, 'SERVER_ERROR', 'Failed to create topup request', [], 500);
}

/*
|--------------------------------------------------------------------------
| Save request status
|--------------------------------------------------------------------------
*/
$statusSaved = create_request_status(
    $requestId,
    'TOPUP',
    $uid,
    'PENDING',
    'Topup request created successfully'
);

if (!$statusSaved) {
    fb_delete('TOPUP_REQUESTS/PENDING/' . $requestId);
    wallet_refund_hold($uid, $walletDebit, $requestId, 'TOPUP_REFUND');
    $failPreview('SERVER_ERROR', 'Failed to create request status');
    api_response(false, 'SERVER_ERROR', 'Failed to create request status', [], 500);
}

topup_mark_preview_used($tokenHash, $requestId);

$deferredLog = [
    'type' => 'TOPUP_SUBMIT',
    'ref_id' => $requestId,
    'message' => 'Topup request created successfully',
    'context' => [
    'uid' => $uid,
    'operator' => $operator,
    'amount' => $amount,
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $financials['wallet_debit_currency'],
    'rate_used' => $financials['rate_used'],
    'topup_number_masked' => topup_mask_number($topupNumber),
    'operator_active' => (bool)($runtime['active'] ?? false),
    ],
];

$topupRow = $pendingRow;
$topupRow['_bucket'] = 'PENDING';
$responseData = topup_submit_response_data($topupRow, [
    'request_id' => $requestId,
    'status' => 'PENDING',
    'topup_number' => $topupNumber,
    'operator' => $operator,
    'amount' => $amount,
    'amount_bdt' => $amount,
    'commission_per_1000' => $financials['commission_per_1000'],
    'commission_bdt' => $financials['commission_bdt'],
    'wallet_debit_bdt' => $financials['wallet_debit_bdt'],
    'wallet_debit_amount' => $walletDebit,
    'wallet_debit_currency' => $financials['wallet_debit_currency'],
    'rate_used' => $financials['rate_used'],
    'total_debit' => $walletDebit,
]);

topup_submit_finish_response([
    'ok' => true,
    'success' => true,
    'code' => 'TOPUP_REQUEST_CREATED',
    'message' => 'Topup request submitted',
    'data' => $responseData,
], $topupRow, $deferredLog);
