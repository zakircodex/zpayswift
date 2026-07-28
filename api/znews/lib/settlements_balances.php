<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/settlements_common.php';

function znews_settlement_apply_balance_once(
    string $path,
    string $ownerType,
    string $ownerId,
    string $currency,
    string $settlementId,
    int $amountMicros
): array {
    $currency = znews_ad_currency($currency);
    $settlementId = znews_firebase_key($settlementId, 'settlement_id');
    $eventKey = hash('sha256', $settlementId);

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_BALANCE_READ_FAILED'];
        }

        $row = is_array($snapshot['value'] ?? null)
            ? (array)$snapshot['value']
            : [];
        $events = is_array($row['applied_settlements'] ?? null)
            ? (array)$row['applied_settlements']
            : [];

        if (isset($events[$eventKey])) {
            $event = is_array($events[$eventKey]) ? (array)$events[$eventKey] : [];
            $savedAmount = (int)($event['amount_micros'] ?? -1);
            $savedId = trim((string)($event['settlement_id'] ?? ''));
            if ($savedAmount !== $amountMicros
                || $savedId === ''
                || !hash_equals($savedId, $settlementId)) {
                return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_BALANCE_EVENT_CONFLICT'];
            }

            return [
                'ok' => true,
                'idempotent_replay' => true,
                'balance' => $row,
            ];
        }

        $available = max(0, (int)($row['available_micros'] ?? 0));
        $total = max(0, (int)($row['total_settled_micros'] ?? 0));
        $now = znews_now();

        $events[$eventKey] = [
            'settlement_id' => $settlementId,
            'amount_micros' => $amountMicros,
            'applied_at' => $now,
        ];
        $row['owner_type'] = strtoupper($ownerType);
        $row['owner_id'] = $ownerId;
        $row['currency'] = $currency;
        $row['available_micros'] = $available + $amountMicros;
        $row['total_settled_micros'] = $total + $amountMicros;
        $row['applied_settlements'] = $events;
        $row['main_wallet_transfer_enabled'] = false;
        $row['updated_at'] = $now;
        $row['created_at'] = max(1, (int)($row['created_at'] ?? $now));

        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(60000);
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_BALANCE_WRITE_FAILED'];
        }

        return [
            'ok' => true,
            'idempotent_replay' => false,
            'balance' => $row,
        ];
    }

    return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_BALANCE_BUSY'];
}

function znews_settlement_apply_creator_balance(
    string $uid,
    string $currency,
    string $settlementId,
    int $amountMicros
): array {
    $uid = znews_firebase_key($uid, 'uid');

    return znews_settlement_apply_balance_once(
        znews_settlement_creator_balance_path($uid, $currency),
        'CREATOR',
        $uid,
        $currency,
        $settlementId,
        $amountMicros
    );
}

function znews_settlement_apply_platform_balance(
    string $currency,
    string $settlementId,
    int $amountMicros
): array {
    return znews_settlement_apply_balance_once(
        znews_settlement_platform_balance_path($currency),
        'PLATFORM',
        'ZPAY_SWIFT',
        $currency,
        $settlementId,
        $amountMicros
    );
}
