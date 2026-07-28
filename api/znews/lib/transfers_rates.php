<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/transfers_common.php';

function znews_transfer_source_rate(string $currency): array
{
    $currency = znews_transfer_currency($currency);

    if ($currency === 'BDT') {
        return [
            'ok' => true,
            'currency' => 'BDT',
            'bdt_per_unit_micros' => 1000000,
            'source' => 'FIXED',
            'updated_at' => 0,
        ];
    }

    if ($currency === 'MYR') {
        $rate = zpay_myr_to_bdt_rate(true);
        if ($rate <= 0) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_TRANSFER_MYR_RATE_MISSING',
                'message' => 'Ringgit rate is missing.',
            ];
        }
        return [
            'ok' => true,
            'currency' => 'MYR',
            'bdt_per_unit_micros' => znews_transfer_decimal_to_micros(
                number_format($rate, 2, '.', ''),
                'rate'
            ),
            'source' => 'MAIN_MYR_BDT_RATE',
            'updated_at' => 0,
        ];
    }

    $row = fb_get(znews_transfer_rate_row_path($currency));
    if (!is_array($row)
        || strtoupper(trim((string)($row['status'] ?? ''))) !== 'ACTIVE'
        || (int)($row['bdt_per_unit_micros'] ?? 0) <= 0) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_SOURCE_RATE_MISSING',
            'message' => 'Conversion rate is not configured for ' . $currency . '.',
        ];
    }

    return [
        'ok' => true,
        'currency' => $currency,
        'bdt_per_unit_micros' => (int)$row['bdt_per_unit_micros'],
        'source' => 'ZNEWS_TRANSFER_RATES',
        'updated_at' => max(0, (int)($row['updated_at'] ?? 0)),
    ];
}

function znews_transfer_rate_public(array $row): array
{
    $micros = max(0, (int)($row['bdt_per_unit_micros'] ?? 0));
    return [
        'currency' => strtoupper(trim((string)($row['currency'] ?? ''))),
        'bdt_per_unit_micros' => $micros,
        'bdt_per_unit' => znews_transfer_micros_to_decimal($micros),
        'status' => strtoupper(trim((string)($row['status'] ?? 'ACTIVE'))),
        'source' => strtoupper(trim((string)($row['source'] ?? 'ADMIN'))),
        'updated_at' => max(0, (int)($row['updated_at'] ?? 0)),
        'updated_by_uid' => trim((string)($row['updated_by_uid'] ?? '')),
    ];
}

function znews_transfer_rates_list(): array
{
    $rows = fb_get('ZNEWS_TRANSFER_RATES');
    $result = [
        znews_transfer_rate_public([
            'currency' => 'BDT',
            'bdt_per_unit_micros' => 1000000,
            'status' => 'ACTIVE',
            'source' => 'FIXED',
        ]),
    ];

    $myr = znews_transfer_source_rate('MYR');
    $result[] = znews_transfer_rate_public([
        'currency' => 'MYR',
        'bdt_per_unit_micros' => !empty($myr['ok'])
            ? (int)$myr['bdt_per_unit_micros']
            : 0,
        'status' => !empty($myr['ok']) ? 'ACTIVE' : 'MISSING',
        'source' => 'MAIN_MYR_BDT_RATE',
    ]);

    if (is_array($rows)) {
        foreach ($rows as $currency => $row) {
            if (!is_array($row)) {
                continue;
            }
            $currency = strtoupper(trim((string)($row['currency'] ?? $currency)));
            if (in_array($currency, ['BDT', 'MYR'], true)) {
                continue;
            }
            $row['currency'] = $currency;
            $result[] = znews_transfer_rate_public($row);
        }
    }

    usort($result, static fn(array $a, array $b): int =>
        strcmp((string)$a['currency'], (string)$b['currency'])
    );

    return ['items' => $result];
}

function znews_transfer_admin_update_rate(
    array $auth,
    string $currency,
    int $rateMicros,
    int $expectedUpdatedAt,
    string $idempotencyKey
): array {
    $currency = znews_transfer_currency($currency);
    if (in_array($currency, ['BDT', 'MYR'], true)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_RATE_MANAGED_ELSEWHERE',
            'http_status' => 409,
        ];
    }
    if ($rateMicros <= 0 || $rateMicros > 1000000000000) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_TRANSFER_RATE_INVALID',
            'http_status' => 422,
        ];
    }

    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $adminUid = znews_firebase_key((string)($user['uid'] ?? ''), 'admin_uid');
    $key = znews_idempotency_key($idempotencyKey);
    $idemPath = znews_transfer_rate_admin_idempotency_path($adminUid, $key);
    $payloadHash = hash('sha256', json_encode([
        'currency' => $currency,
        'rate_micros' => $rateMicros,
        'expected_updated_at' => $expectedUpdatedAt,
    ], JSON_UNESCAPED_SLASHES));
    $now = znews_now();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $idem = fb_get_with_etag($idemPath);
        if (empty($idem['ok']) || !is_string($idem['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_RATE_REQUEST_READ_FAILED', 'http_status' => 503];
        }
        $existing = $idem['value'] ?? null;
        if (is_array($existing)) {
            $savedHash = trim((string)($existing['payload_hash'] ?? ''));
            if ($savedHash === '' || !hash_equals($savedHash, $payloadHash)) {
                return ['ok' => false, 'code' => 'ZNEWS_IDEMPOTENCY_CONFLICT', 'http_status' => 409];
            }
            if (strtoupper(trim((string)($existing['status'] ?? ''))) === 'COMPLETED') {
                return [
                    'ok' => true,
                    'code' => 'ZNEWS_TRANSFER_RATE_ALREADY_UPDATED',
                    'http_status' => 200,
                    'idempotent_replay' => true,
                    'rate' => is_array($existing['result'] ?? null) ? (array)$existing['result'] : [],
                ];
            }
            if ((int)($existing['lease_expires_at'] ?? 0) > $now) {
                return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_RATE_REQUEST_IN_PROGRESS', 'http_status' => 409];
            }
        } elseif ($existing !== null) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_RATE_REQUEST_INVALID', 'http_status' => 409];
        }

        $claim = [
            'admin_uid' => $adminUid,
            'currency' => $currency,
            'payload_hash' => $payloadHash,
            'status' => 'PROCESSING',
            'lease_expires_at' => $now + 60,
            'created_at' => is_array($existing) ? (int)($existing['created_at'] ?? $now) : $now,
            'updated_at' => $now,
        ];
        $saved = fb_put_if_match($idemPath, $claim, (string)$idem['etag']);
        if ((int)($saved['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        if (empty($saved['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_RATE_REQUEST_CLAIM_FAILED', 'http_status' => 503];
        }

        $ratePath = znews_transfer_rate_row_path($currency);
        $snapshot = fb_get_with_etag($ratePath);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_RATE_READ_FAILED', 'http_status' => 503];
        }
        $current = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        $currentUpdatedAt = (int)($current['updated_at'] ?? 0);
        if ($expectedUpdatedAt !== $currentUpdatedAt) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_TRANSFER_RATE_VERSION_CONFLICT',
                'http_status' => 409,
                'current_updated_at' => $currentUpdatedAt,
            ];
        }

        $row = [
            'currency' => $currency,
            'bdt_per_unit_micros' => $rateMicros,
            'bdt_per_unit' => znews_transfer_micros_to_decimal($rateMicros),
            'status' => 'ACTIVE',
            'source' => 'ADMIN',
            'updated_by_uid' => $adminUid,
            'created_at' => (int)($current['created_at'] ?? $now),
            'updated_at' => $now,
        ];
        $write = fb_put_if_match($ratePath, $row, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_RATE_VERSION_CONFLICT', 'http_status' => 409];
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_RATE_WRITE_FAILED', 'http_status' => 503];
        }

        $result = znews_transfer_rate_public($row);
        $claim['status'] = 'COMPLETED';
        $claim['result'] = $result;
        $claim['lease_expires_at'] = 0;
        $claim['completed_at'] = znews_now();
        $claim['updated_at'] = znews_now();
        @fb_put($idemPath, $claim);

        return [
            'ok' => true,
            'code' => 'ZNEWS_TRANSFER_RATE_UPDATED',
            'http_status' => 200,
            'idempotent_replay' => false,
            'rate' => $result,
        ];
    }

    return ['ok' => false, 'code' => 'ZNEWS_TRANSFER_RATE_REQUEST_BUSY', 'http_status' => 409];
}
