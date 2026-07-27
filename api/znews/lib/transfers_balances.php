<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/transfers_rates.php';

function znews_transfer_balance_event_key(string $action, string $requestId): string
{
    return hash('sha256', strtoupper(trim($action)) . '|' . znews_firebase_key($requestId, 'request_id'));
}

function znews_transfer_balance_transition(
    string $uid,
    string $currency,
    string $requestId,
    int $amountMicros,
    string $action
): array {
    $uid = znews_firebase_key($uid, 'uid');
    $currency = znews_transfer_currency($currency);
    $requestId = znews_firebase_key($requestId, 'request_id');
    $action = strtoupper(trim($action));
    if (!in_array($action, ['RESERVE', 'RELEASE', 'CONSUME'], true) || $amountMicros <= 0) {
        return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_BALANCE_ACTION_INVALID'];
    }

    $path = znews_settlement_creator_balance_path($uid, $currency);
    $eventKey = znews_transfer_balance_event_key($action, $requestId);
    $now = znews_now();

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_BALANCE_READ_FAILED'];
        }
        $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        $events = is_array($row['transfer_events'] ?? null) ? (array)$row['transfer_events'] : [];

        if (isset($events[$eventKey])) {
            $saved = is_array($events[$eventKey]) ? (array)$events[$eventKey] : [];
            if ((int)($saved['amount_micros'] ?? -1) !== $amountMicros
                || strtoupper(trim((string)($saved['action'] ?? ''))) !== $action
                || trim((string)($saved['request_id'] ?? '')) !== $requestId) {
                return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_BALANCE_EVENT_CONFLICT'];
            }
            return [
                'ok' => true,
                'idempotent_replay' => true,
                'balance' => znews_settlement_balance_public($row),
            ];
        }

        $available = max(0, (int)($row['available_micros'] ?? 0));
        $reserved = max(0, (int)($row['reserved_micros'] ?? 0));
        $transferred = max(0, (int)($row['transferred_micros'] ?? 0));

        if ($action === 'RESERVE') {
            if ($available < $amountMicros) {
                return [
                    'ok' => false,
                    'code' => 'ZNEWS_TRANSFER_INSUFFICIENT_BALANCE',
                    'available_micros' => $available,
                    'required_micros' => $amountMicros,
                ];
            }
            $available -= $amountMicros;
            $reserved += $amountMicros;
        } elseif ($action === 'RELEASE') {
            if ($reserved < $amountMicros) {
                return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_RESERVED_BALANCE_MISSING'];
            }
            $reserved -= $amountMicros;
            $available += $amountMicros;
        } else {
            if ($reserved < $amountMicros) {
                return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_RESERVED_BALANCE_MISSING'];
            }
            $reserved -= $amountMicros;
            $transferred += $amountMicros;
        }

        $events[$eventKey] = [
            'action' => $action,
            'request_id' => $requestId,
            'amount_micros' => $amountMicros,
            'created_at' => $now,
        ];
        $row['uid'] = $uid;
        $row['currency'] = $currency;
        $row['available_micros'] = $available;
        $row['reserved_micros'] = $reserved;
        $row['transferred_micros'] = $transferred;
        $row['transfer_events'] = $events;
        $row['updated_at'] = $now;

        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(60000);
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_BALANCE_WRITE_FAILED'];
        }

        return [
            'ok' => true,
            'idempotent_replay' => false,
            'balance' => znews_settlement_balance_public($row),
        ];
    }

    return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_BALANCE_BUSY'];
}

function znews_transfer_reserve_balance(
    string $uid,
    string $currency,
    string $requestId,
    int $amountMicros
): array {
    return znews_transfer_balance_transition($uid, $currency, $requestId, $amountMicros, 'RESERVE');
}

function znews_transfer_release_balance(
    string $uid,
    string $currency,
    string $requestId,
    int $amountMicros
): array {
    return znews_transfer_balance_transition($uid, $currency, $requestId, $amountMicros, 'RELEASE');
}

function znews_transfer_consume_balance(
    string $uid,
    string $currency,
    string $requestId,
    int $amountMicros
): array {
    return znews_transfer_balance_transition($uid, $currency, $requestId, $amountMicros, 'CONSUME');
}
