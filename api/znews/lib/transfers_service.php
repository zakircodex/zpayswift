<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/transfers_balances.php';

function znews_transfer_quote(string $uid, string $currency, int $sourceAmountMicros): array
{
    $uid = znews_firebase_key($uid, 'uid');
    $currency = znews_transfer_currency($currency);
    if ($sourceAmountMicros <= 0) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_AMOUNT_INVALID', 'http_status' => 422];
    }

    $balance = fb_get(znews_settlement_creator_balance_path($uid, $currency));
    if (!is_array($balance)) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_BALANCE_NOT_FOUND', 'http_status' => 404];
    }
    $availableMicros = max(0, (int)($balance['available_micros'] ?? 0));
    if ($availableMicros < $sourceAmountMicros) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_INSUFFICIENT_BALANCE',
            'http_status' => 409,
            'available_micros' => $availableMicros,
        ];
    }

    $sourceRate = znews_transfer_source_rate($currency);
    if (empty($sourceRate['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($sourceRate['code'] ?? 'ZNEWS_TRANSFER_SOURCE_RATE_MISSING'),
            'message' => (string)($sourceRate['message'] ?? 'Source conversion rate is missing.'),
            'http_status' => 409,
        ];
    }
    $sourceRateMicros = (int)$sourceRate['bdt_per_unit_micros'];
    $bdtMicros = znews_transfer_safe_multiply_rate($sourceAmountMicros, $sourceRateMicros);
    if ($bdtMicros < znews_transfer_threshold_bdt_micros()) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_MINIMUM_NOT_MET',
            'message' => 'Minimum transfer amount is BDT 500.',
            'http_status' => 422,
            'bdt_equivalent_micros' => $bdtMicros,
            'threshold_bdt_micros' => znews_transfer_threshold_bdt_micros(),
        ];
    }

    $user = fb_get('USERS/' . $uid);
    if (!is_array($user)) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_USER_NOT_FOUND', 'http_status' => 404];
    }
    if (strtoupper(trim((string)($user['status'] ?? 'ACTIVE'))) !== 'ACTIVE') {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_USER_NOT_ACTIVE', 'http_status' => 403];
    }
    $wallet = fb_get('USER_WALLETS/' . $uid);
    if (!is_array($wallet)) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_WALLET_NOT_FOUND', 'http_status' => 404];
    }

    $destinationCurrency = wallet_account_currency($user, $wallet);
    $destinationMicros = $bdtMicros;
    $myrRateMicros = 0;
    if ($destinationCurrency === 'MYR') {
        $myrRate = zpay_myr_to_bdt_rate(true);
        if ($myrRate <= 0) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_MYR_RATE_MISSING', 'http_status' => 409];
        }
        $myrRateMicros = znews_transfer_decimal_to_micros(
            number_format($myrRate, 2, '.', ''),
            'rate'
        );
        $destinationMicros = znews_transfer_safe_divide_rate($bdtMicros, $myrRateMicros);
    } elseif ($destinationCurrency !== 'BDT') {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_DESTINATION_CURRENCY_UNSUPPORTED', 'http_status' => 409];
    }

    $destinationMinor = znews_transfer_round_micros_to_minor($destinationMicros);
    if ($destinationMinor <= 0) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_DESTINATION_AMOUNT_TOO_SMALL', 'http_status' => 422];
    }

    return [
        'ok' => true,
        'code' => 'ZNEWS_TRANSFER_QUOTE_OK',
        'http_status' => 200,
        'quote' => [
            'uid' => $uid,
            'source_currency' => $currency,
            'source_amount_micros' => $sourceAmountMicros,
            'source_amount' => znews_transfer_micros_to_decimal($sourceAmountMicros),
            'source_to_bdt_rate_micros' => $sourceRateMicros,
            'source_to_bdt_rate' => znews_transfer_micros_to_decimal($sourceRateMicros),
            'source_rate_source' => (string)$sourceRate['source'],
            'bdt_equivalent_micros' => $bdtMicros,
            'bdt_equivalent' => znews_transfer_micros_to_decimal($bdtMicros),
            'threshold_bdt_micros' => znews_transfer_threshold_bdt_micros(),
            'threshold_bdt' => '500',
            'destination_currency' => $destinationCurrency,
            'destination_amount_micros' => $destinationMicros,
            'destination_amount_minor' => $destinationMinor,
            'destination_amount' => number_format($destinationMinor / 100, 2, '.', ''),
            'myr_to_bdt_rate_micros' => $myrRateMicros,
            'myr_to_bdt_rate' => znews_transfer_micros_to_decimal($myrRateMicros),
            'account_country' => wallet_account_country_code($user, $wallet),
            'available_source_micros' => $availableMicros,
            'created_at' => znews_now(),
        ],
    ];
}

function znews_transfer_create_claim(
    string $uid,
    string $key,
    string $payloadHash,
    string $requestId
): array {
    $path = znews_transfer_idempotency_path($uid, $key);
    $now = znews_now();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_REQUEST_READ_FAILED', 'http_status' => 503];
        }
        $existing = $snapshot['value'] ?? null;
        if (is_array($existing)) {
            $savedHash = trim((string)($existing['payload_hash'] ?? ''));
            if ($savedHash === '' || !hash_equals($savedHash, $payloadHash)) {
                return ['ok' => false, 'code' => 'ZNEWS_IDEMPOTENCY_CONFLICT', 'http_status' => 409];
            }
            if (strtoupper(trim((string)($existing['status'] ?? ''))) === 'COMPLETED') {
                return [
                    'ok' => true,
                    'idempotent_replay' => true,
                    'request_id' => $requestId,
                    'request' => is_array($existing['result'] ?? null) ? (array)$existing['result'] : [],
                    'path' => $path,
                ];
            }
            if ((int)($existing['lease_expires_at'] ?? 0) > $now) {
                return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_REQUEST_IN_PROGRESS', 'http_status' => 409];
            }
        } elseif ($existing !== null) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_REQUEST_INVALID', 'http_status' => 409];
        }

        $claim = [
            'uid' => $uid,
            'request_id' => $requestId,
            'payload_hash' => $payloadHash,
            'status' => 'PROCESSING',
            'lease_expires_at' => $now + 90,
            'created_at' => is_array($existing) ? (int)($existing['created_at'] ?? $now) : $now,
            'updated_at' => $now,
        ];
        $write = fb_put_if_match($path, $claim, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_REQUEST_CLAIM_FAILED', 'http_status' => 503];
        }
        return ['ok' => true, 'idempotent_replay' => false, 'claim' => $claim, 'path' => $path];
    }

    return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_REQUEST_BUSY', 'http_status' => 409];
}

function znews_transfer_create(
    array $auth,
    string $currency,
    int $sourceAmountMicros,
    string $idempotencyKey
): array {
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
    $currency = znews_transfer_currency($currency);
    $key = znews_idempotency_key($idempotencyKey);
    $requestId = znews_transfer_request_id($uid, $key);
    $payloadHash = hash('sha256', json_encode([
        'uid' => $uid,
        'currency' => $currency,
        'source_amount_micros' => $sourceAmountMicros,
    ], JSON_UNESCAPED_SLASHES));

    $claim = znews_transfer_create_claim($uid, $key, $payloadHash, $requestId);
    if (empty($claim['ok'])) {
        return $claim;
    }
    if (!empty($claim['idempotent_replay'])) {
        $saved = fb_get(znews_transfer_request_path($requestId));
        return [
            'ok' => is_array($saved),
            'code' => is_array($saved)
                ? 'ZNEWS_TRANSFER_ALREADY_REQUESTED'
                : 'ZNEWS_TRANSFER_RECONCILIATION_REQUIRED',
            'http_status' => is_array($saved) ? 200 : 503,
            'idempotent_replay' => true,
            'request' => is_array($saved) ? znews_transfer_public($saved) : [],
        ];
    }

    $quote = znews_transfer_quote($uid, $currency, $sourceAmountMicros);
    if (empty($quote['ok'])) {
        @fb_patch((string)$claim['path'], [
            'status' => 'FAILED',
            'last_error_code' => (string)($quote['code'] ?? 'ZNEWS_TRANSFER_QUOTE_FAILED'),
            'lease_expires_at' => 0,
            'updated_at' => znews_now(),
        ]);
        return $quote;
    }

    $reservation = znews_transfer_reserve_balance($uid, $currency, $requestId, $sourceAmountMicros);
    if (empty($reservation['ok'])) {
        @fb_patch((string)$claim['path'], [
            'status' => 'FAILED',
            'last_error_code' => (string)($reservation['code'] ?? 'ZNEWS_TRANSFER_RESERVATION_FAILED'),
            'lease_expires_at' => 0,
            'updated_at' => znews_now(),
        ]);
        return [
            'ok' => false,
            'code' => (string)($reservation['code'] ?? 'ZNEWS_TRANSFER_RESERVATION_FAILED'),
            'http_status' => 409,
        ];
    }

    $q = (array)$quote['quote'];
    $now = znews_now();
    $row = [
        'schema_version' => 1,
        'request_id' => $requestId,
        'uid' => $uid,
        'status' => 'PENDING',
        'source_currency' => $currency,
        'source_amount_micros' => $sourceAmountMicros,
        'source_to_bdt_rate_micros' => (int)$q['source_to_bdt_rate_micros'],
        'source_rate_source' => (string)$q['source_rate_source'],
        'bdt_equivalent_micros' => (int)$q['bdt_equivalent_micros'],
        'threshold_bdt_micros' => znews_transfer_threshold_bdt_micros(),
        'destination_currency' => (string)$q['destination_currency'],
        'destination_amount_micros' => (int)$q['destination_amount_micros'],
        'destination_amount_minor' => (int)$q['destination_amount_minor'],
        'myr_to_bdt_rate_micros' => (int)$q['myr_to_bdt_rate_micros'],
        'account_country' => (string)$q['account_country'],
        'reservation_status' => 'RESERVED',
        'main_wallet_credit_status' => 'NOT_CREDITED',
        'main_wallet_transfer_id' => znews_transfer_wallet_transfer_id($requestId),
        'main_wallet_ledger_id' => '',
        'reconciliation_required' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $index = [
        'request_id' => $requestId,
        'uid' => $uid,
        'status' => 'PENDING',
        'source_currency' => $currency,
        'source_amount_micros' => $sourceAmountMicros,
        'bdt_equivalent_micros' => (int)$q['bdt_equivalent_micros'],
        'destination_currency' => (string)$q['destination_currency'],
        'destination_amount_minor' => (int)$q['destination_amount_minor'],
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $claimRow = (array)$claim['claim'];
    $claimRow['status'] = 'COMPLETED';
    $claimRow['lease_expires_at'] = 0;
    $claimRow['completed_at'] = $now;
    $claimRow['updated_at'] = $now;
    $claimRow['result'] = znews_transfer_public($row);

    $stored = fb_patch('', [
        znews_transfer_request_path($requestId) => $row,
        znews_transfer_user_index_path($uid, $requestId) => $index,
        znews_transfer_queue_path($requestId) => $index,
        (string)$claim['path'] => $claimRow,
    ]);
    if (!$stored) {
        $released = znews_transfer_release_balance($uid, $currency, $requestId, $sourceAmountMicros);
        @fb_patch((string)$claim['path'], [
            'status' => empty($released['ok']) ? 'RECONCILIATION_REQUIRED' : 'FAILED',
            'last_error_code' => 'ZNEWS_TRANSFER_REQUEST_WRITE_FAILED',
            'reconciliation_required' => empty($released['ok']),
            'lease_expires_at' => 0,
            'updated_at' => znews_now(),
        ]);
        return [
            'ok' => false,
            'code' => empty($released['ok'])
                ? 'ZNEWS_TRANSFER_RECONCILIATION_REQUIRED'
                : 'ZNEWS_TRANSFER_REQUEST_WRITE_FAILED',
            'http_status' => 503,
        ];
    }

    if (function_exists('system_log')) {
        system_log('ZNEWS_TRANSFER_REQUESTED', $requestId, 'Z Sky 24 balance transfer requested', [
            'uid' => $uid,
            'source_currency' => $currency,
            'source_amount_micros' => $sourceAmountMicros,
            'bdt_equivalent_micros' => (int)$q['bdt_equivalent_micros'],
            'destination_currency' => (string)$q['destination_currency'],
        ]);
    }

    return [
        'ok' => true,
        'code' => 'ZNEWS_TRANSFER_REQUESTED',
        'http_status' => 201,
        'idempotent_replay' => false,
        'request' => znews_transfer_public($row),
        'balance' => is_array($reservation['balance'] ?? null) ? (array)$reservation['balance'] : [],
    ];
}
