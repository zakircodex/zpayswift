<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/settlements.php';
require_once dirname(__DIR__, 2) . '/lib/wallet.php';
require_once dirname(__DIR__, 2) . '/lib/rates.php';

function znews_transfer_threshold_bdt_micros(): int
{
    return 200 * 1000000;
}

function znews_transfer_lease_seconds(): int
{
    $value = defined('ZNEWS_TRANSFER_LEASE_SECONDS')
        ? (int)constant('ZNEWS_TRANSFER_LEASE_SECONDS')
        : 180;
    return max(60, min(900, $value));
}

function znews_transfer_scan_limit(): int
{
    $value = defined('ZNEWS_TRANSFER_SCAN_LIMIT')
        ? (int)constant('ZNEWS_TRANSFER_SCAN_LIMIT')
        : 5000;
    return max(100, min(20000, $value));
}

function znews_transfer_currency($value): string
{
    $currency = strtoupper(trim((string)$value));
    if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
        api_response(false, 'ZNEWS_TRANSFER_CURRENCY_INVALID', 'Invalid currency.', [], 422);
    }
    return $currency;
}

function znews_transfer_decimal_to_micros($value, string $field = 'amount'): int
{
    if (is_int($value)) {
        $value = (string)$value;
    } elseif (is_float($value)) {
        $value = number_format($value, 6, '.', '');
    } else {
        $value = trim((string)$value);
    }

    if ($value === '' || preg_match('/^\d{1,12}(?:\.\d{1,6})?$/', $value) !== 1) {
        api_response(false, 'ZNEWS_TRANSFER_AMOUNT_INVALID', 'Invalid ' . $field . '.', [], 422);
    }

    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $fraction = str_pad($fraction, 6, '0');
    $micros = ((int)$whole * 1000000) + (int)$fraction;
    if ($micros <= 0 || $micros > 1000000000000000) {
        api_response(false, 'ZNEWS_TRANSFER_AMOUNT_INVALID', 'Invalid ' . $field . '.', [], 422);
    }
    return $micros;
}

function znews_transfer_amount_micros(array $body): int
{
    $rawMicros = $body['source_amount_micros'] ?? $body['amount_micros'] ?? null;
    if ($rawMicros !== null && $rawMicros !== '') {
        $micros = filter_var($rawMicros, FILTER_VALIDATE_INT);
        if ($micros === false || $micros <= 0 || $micros > 1000000000000000) {
            api_response(false, 'ZNEWS_TRANSFER_AMOUNT_INVALID', 'Invalid amount.', [], 422);
        }
        return (int)$micros;
    }
    return znews_transfer_decimal_to_micros(
        $body['source_amount'] ?? $body['amount'] ?? '',
        'amount'
    );
}

function znews_transfer_micros_to_decimal(int $micros): string
{
    $negative = $micros < 0;
    $value = abs($micros);
    $whole = intdiv($value, 1000000);
    $fraction = str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    $fraction = rtrim($fraction, '0');
    return ($negative ? '-' : '') . $whole . ($fraction === '' ? '' : '.' . $fraction);
}

function znews_transfer_rate_row_path(string $currency): string
{
    return 'ZNEWS_TRANSFER_RATES/' . znews_transfer_currency($currency);
}

function znews_transfer_request_path(string $requestId): string
{
    return 'ZNEWS_TRANSFER_REQUESTS/' . znews_firebase_key($requestId, 'request_id');
}

function znews_transfer_user_index_path(string $uid, string $requestId): string
{
    return 'ZNEWS_USER_TRANSFER_REQUESTS/'
        . znews_firebase_key($uid, 'uid')
        . '/'
        . znews_firebase_key($requestId, 'request_id');
}

function znews_transfer_queue_path(string $requestId): string
{
    return 'ZNEWS_TRANSFER_REVIEW_QUEUE/' . znews_firebase_key($requestId, 'request_id');
}

function znews_transfer_idempotency_path(string $uid, string $key): string
{
    return 'ZNEWS_TRANSFER_IDEMPOTENCY/'
        . znews_firebase_key($uid, 'uid')
        . '/'
        . hash('sha256', znews_idempotency_key($key));
}

function znews_transfer_admin_idempotency_path(string $adminUid, string $action, string $key): string
{
    return 'ZNEWS_TRANSFER_ADMIN_IDEMPOTENCY/'
        . znews_firebase_key($adminUid, 'admin_uid')
        . '/'
        . strtoupper(trim($action))
        . '/'
        . hash('sha256', znews_idempotency_key($key));
}

function znews_transfer_rate_admin_idempotency_path(string $adminUid, string $key): string
{
    return 'ZNEWS_TRANSFER_RATE_ADMIN_IDEMPOTENCY/'
        . znews_firebase_key($adminUid, 'admin_uid')
        . '/'
        . hash('sha256', znews_idempotency_key($key));
}

function znews_transfer_request_id(string $uid, string $key): string
{
    return 'ZTR' . strtoupper(substr(hash(
        'sha256',
        znews_firebase_key($uid, 'uid') . '|' . znews_idempotency_key($key)
    ), 0, 29));
}

function znews_transfer_wallet_transfer_id(string $requestId): string
{
    return 'WTR' . strtoupper(substr(hash('sha256', 'ZNEWS|' . $requestId), 0, 24));
}

function znews_transfer_wallet_operation_ref(string $requestId): string
{
    return 'ZNEWS_BALANCE_TRANSFER:' . znews_firebase_key($requestId, 'request_id');
}

function znews_transfer_safe_multiply_rate(int $amountMicros, int $rateMicros): int
{
    if ($amountMicros < 0 || $rateMicros <= 0) {
        api_response(false, 'ZNEWS_TRANSFER_RATE_INVALID', 'Invalid conversion rate.', [], 422);
    }

    $whole = intdiv($amountMicros, 1000000);
    $fraction = $amountMicros % 1000000;
    if ($whole > intdiv(PHP_INT_MAX, $rateMicros)) {
        api_response(false, 'ZNEWS_TRANSFER_AMOUNT_TOO_LARGE', 'Transfer amount is too large.', [], 422);
    }

    $result = ($whole * $rateMicros) + intdiv($fraction * $rateMicros, 1000000);
    if ($result < 0) {
        api_response(false, 'ZNEWS_TRANSFER_AMOUNT_TOO_LARGE', 'Transfer amount is too large.', [], 422);
    }
    return $result;
}

function znews_transfer_safe_divide_rate(int $amountMicros, int $rateMicros): int
{
    if ($amountMicros < 0 || $rateMicros <= 0) {
        api_response(false, 'ZNEWS_TRANSFER_RATE_INVALID', 'Invalid conversion rate.', [], 422);
    }

    $whole = intdiv($amountMicros, $rateMicros);
    $remainder = $amountMicros % $rateMicros;
    if ($whole > intdiv(PHP_INT_MAX, 1000000)) {
        api_response(false, 'ZNEWS_TRANSFER_AMOUNT_TOO_LARGE', 'Transfer amount is too large.', [], 422);
    }

    return ($whole * 1000000) + intdiv($remainder * 1000000, $rateMicros);
}

function znews_transfer_round_micros_to_minor(int $micros): int
{
    if ($micros < 0) {
        api_response(false, 'ZNEWS_TRANSFER_AMOUNT_INVALID', 'Invalid transfer amount.', [], 422);
    }
    return intdiv($micros + 5000, 10000);
}

function znews_transfer_minor_to_float(int $minor): float
{
    return round($minor / 100, 2);
}

function znews_transfer_public(array $row): array
{
    return [
        'request_id' => trim((string)($row['request_id'] ?? '')),
        'status' => strtoupper(trim((string)($row['status'] ?? 'PENDING'))),
        'source_currency' => strtoupper(trim((string)($row['source_currency'] ?? ''))),
        'source_amount_micros' => max(0, (int)($row['source_amount_micros'] ?? 0)),
        'source_amount' => znews_transfer_micros_to_decimal(max(0, (int)($row['source_amount_micros'] ?? 0))),
        'source_to_bdt_rate_micros' => max(0, (int)($row['source_to_bdt_rate_micros'] ?? 0)),
        'source_to_bdt_rate' => znews_transfer_micros_to_decimal(max(0, (int)($row['source_to_bdt_rate_micros'] ?? 0))),
        'bdt_equivalent_micros' => max(0, (int)($row['bdt_equivalent_micros'] ?? 0)),
        'bdt_equivalent' => znews_transfer_micros_to_decimal(max(0, (int)($row['bdt_equivalent_micros'] ?? 0))),
        'threshold_bdt_micros' => znews_transfer_threshold_bdt_micros(),
        'threshold_bdt' => znews_transfer_micros_to_decimal(znews_transfer_threshold_bdt_micros()),
        'destination_currency' => strtoupper(trim((string)($row['destination_currency'] ?? ''))),
        'destination_amount_minor' => max(0, (int)($row['destination_amount_minor'] ?? 0)),
        'destination_amount' => number_format(max(0, (int)($row['destination_amount_minor'] ?? 0)) / 100, 2, '.', ''),
        'myr_to_bdt_rate_micros' => max(0, (int)($row['myr_to_bdt_rate_micros'] ?? 0)),
        'myr_to_bdt_rate' => znews_transfer_micros_to_decimal(max(0, (int)($row['myr_to_bdt_rate_micros'] ?? 0))),
        'main_wallet_transfer_id' => trim((string)($row['main_wallet_transfer_id'] ?? '')),
        'main_wallet_ledger_id' => trim((string)($row['main_wallet_ledger_id'] ?? '')),
        'rejection_reason' => trim((string)($row['rejection_reason'] ?? '')),
        'created_at' => max(0, (int)($row['created_at'] ?? 0)),
        'approved_at' => max(0, (int)($row['approved_at'] ?? 0)),
        'rejected_at' => max(0, (int)($row['rejected_at'] ?? 0)),
        'updated_at' => max(0, (int)($row['updated_at'] ?? 0)),
        'reconciliation_required' => !empty($row['reconciliation_required']),
    ];
}
