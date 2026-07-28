<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/ad_impressions_common.php';

function znews_ad_signature_header(string $name): string
{
    return trim((string)(api_get_header($name) ?? ''));
}

function znews_ad_signed_request(): array
{
    $network = znews_ad_network_name(znews_ad_signature_header('X-ZNEWS-AD-NETWORK'));
    $timestampRaw = znews_ad_signature_header('X-ZNEWS-AD-TIMESTAMP');
    $nonce = znews_ad_signature_header('X-ZNEWS-AD-NONCE');
    $signature = strtolower(znews_ad_signature_header('X-ZNEWS-AD-SIGNATURE'));
    if (str_starts_with($signature, 'sha256=')) {
        $signature = substr($signature, 7);
    }

    $timestamp = filter_var($timestampRaw, FILTER_VALIDATE_INT);
    if ($timestamp === false || $timestamp <= 0) {
        api_response(false, 'ZNEWS_AD_SIGNATURE_TIMESTAMP_INVALID', 'Invalid signed timestamp.', [], 401);
    }
    if (abs(znews_now() - (int)$timestamp) > znews_ad_signature_tolerance()) {
        api_response(false, 'ZNEWS_AD_SIGNATURE_EXPIRED', 'Signed request is outside the allowed time window.', [], 401);
    }
    if (strlen($nonce) < 16
        || strlen($nonce) > 128
        || preg_match('/^[A-Za-z0-9._~-]+$/', $nonce) !== 1) {
        api_response(false, 'ZNEWS_AD_NONCE_INVALID', 'Invalid signed nonce.', [], 401);
    }
    if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
        api_response(false, 'ZNEWS_AD_SIGNATURE_INVALID', 'Invalid ad signature.', [], 401);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '' || strlen($raw) > 65536) {
        api_response(false, 'ZNEWS_AD_PAYLOAD_INVALID', 'Invalid ad event payload.', [], 400);
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        api_response(false, 'INVALID_JSON', 'Request body must be valid JSON.', [], 400);
    }

    $bodyHash = hash('sha256', $raw);
    $canonical = $network . "\n" . (int)$timestamp . "\n" . $nonce . "\n" . $bodyHash;
    $expected = hash_hmac('sha256', $canonical, znews_ad_network_secret($network));
    if (!hash_equals($expected, $signature)) {
        api_response(false, 'ZNEWS_AD_SIGNATURE_INVALID', 'Invalid ad signature.', [], 401);
    }

    return [
        'network' => $network,
        'timestamp' => (int)$timestamp,
        'nonce' => $nonce,
        'nonce_hash' => hash('sha256', $nonce),
        'signature_hash' => hash('sha256', $signature),
        'payload_hash' => $bodyHash,
        'body' => $body,
    ];
}

function znews_ad_nonce_claim(
    string $network,
    string $nonce,
    string $impressionId,
    string $payloadHash
): array {
    $path = znews_ad_nonce_path($network, $nonce);
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_AD_NONCE_READ_FAILED'];
        }
        $existing = $snapshot['value'] ?? null;
        if (is_array($existing)) {
            $savedImpression = trim((string)($existing['impression_id'] ?? ''));
            $savedHash = trim((string)($existing['payload_hash'] ?? ''));
            if ($savedImpression !== ''
                && hash_equals($savedImpression, $impressionId)
                && $savedHash !== ''
                && hash_equals($savedHash, $payloadHash)) {
                return ['ok' => true, 'idempotent_replay' => true];
            }
            return ['ok' => false, 'code' => 'ZNEWS_AD_NONCE_REPLAY'];
        }
        if ($existing !== null) {
            return ['ok' => false, 'code' => 'ZNEWS_AD_NONCE_INVALID_RECORD'];
        }
        $now = znews_now();
        $write = fb_put_if_match($path, [
            'impression_id' => $impressionId,
            'payload_hash' => $payloadHash,
            'created_at' => $now,
            'expires_at' => $now + (znews_ad_signature_tolerance() * 2),
        ], (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        return [
            'ok' => !empty($write['ok']),
            'idempotent_replay' => false,
            'code' => !empty($write['ok']) ? 'OK' : 'ZNEWS_AD_NONCE_WRITE_FAILED',
        ];
    }
    return ['ok' => false, 'code' => 'ZNEWS_AD_NONCE_BUSY'];
}

function znews_ad_event_claim(
    string $network,
    string $eventId,
    string $impressionId,
    string $payloadHash
): array {
    $path = znews_ad_event_path($network, $eventId);
    $now = znews_now();
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_AD_EVENT_READ_FAILED', 'http_status' => 503];
        }
        $existing = $snapshot['value'] ?? null;
        if (is_array($existing)) {
            $savedHash = trim((string)($existing['payload_hash'] ?? ''));
            if ($savedHash === '' || !hash_equals($savedHash, $payloadHash)) {
                return ['ok' => false, 'code' => 'ZNEWS_AD_EVENT_CONFLICT', 'http_status' => 409];
            }
            $status = strtoupper(trim((string)($existing['status'] ?? '')));
            if ($status === 'COMPLETED') {
                return [
                    'ok' => true,
                    'idempotent_replay' => true,
                    'path' => $path,
                    'result' => is_array($existing['result'] ?? null)
                        ? (array)$existing['result']
                        : [],
                ];
            }
            if ($status === 'PROCESSING'
                && (int)($existing['lease_expires_at'] ?? 0) > $now) {
                return ['ok' => false, 'code' => 'ZNEWS_AD_EVENT_IN_PROGRESS', 'http_status' => 409];
            }
            if (!in_array($status, ['PROCESSING', 'FAILED'], true)) {
                return ['ok' => false, 'code' => 'ZNEWS_AD_EVENT_INVALID_STATE', 'http_status' => 409];
            }
        } elseif ($existing !== null) {
            return ['ok' => false, 'code' => 'ZNEWS_AD_EVENT_INVALID_RECORD', 'http_status' => 409];
        }

        $claim = [
            'impression_id' => $impressionId,
            'network' => $network,
            'provider_event_hash' => hash('sha256', $eventId),
            'payload_hash' => $payloadHash,
            'status' => 'PROCESSING',
            'lease_expires_at' => $now + znews_ad_event_lease(),
            'created_at' => is_array($existing)
                ? (int)($existing['created_at'] ?? $now)
                : $now,
            'updated_at' => $now,
        ];
        $write = fb_put_if_match($path, $claim, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_AD_EVENT_CLAIM_FAILED', 'http_status' => 503];
        }
        return [
            'ok' => true,
            'idempotent_replay' => false,
            'path' => $path,
            'claim' => $claim,
        ];
    }
    return ['ok' => false, 'code' => 'ZNEWS_AD_EVENT_BUSY', 'http_status' => 409];
}

function znews_ad_event_finish(array $claim, array $result): bool
{
    $path = trim((string)($claim['path'] ?? ''));
    $row = is_array($claim['claim'] ?? null) ? (array)$claim['claim'] : [];
    if ($path === '' || !$row) {
        return false;
    }
    $now = znews_now();
    $row['status'] = 'COMPLETED';
    $row['result'] = $result;
    $row['completed_at'] = $now;
    $row['updated_at'] = $now;
    $row['lease_expires_at'] = 0;
    return fb_put($path, $row);
}

function znews_ad_event_fail(array $claim, string $code): void
{
    $path = trim((string)($claim['path'] ?? ''));
    if ($path === '') {
        return;
    }
    @fb_patch($path, [
        'status' => 'FAILED',
        'failure_code' => $code,
        'failed_at' => znews_now(),
        'updated_at' => znews_now(),
        'lease_expires_at' => 0,
    ]);
}
