<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/ad_impressions_analytics.php';

function znews_ad_recheck_impression(
    string $impressionId,
    ?int $expectedUpdatedAt = null
): array {
    $impressionId = znews_firebase_key($impressionId, 'impression_id');
    $path = znews_ad_impression_path($impressionId);
    $snapshot = fb_get_with_etag($path);
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        return ['ok' => false, 'code' => 'ZNEWS_AD_IMPRESSION_READ_FAILED', 'http_status' => 503];
    }
    $row = $snapshot['value'] ?? null;
    if (!is_array($row)) {
        return ['ok' => false, 'code' => 'ZNEWS_AD_IMPRESSION_NOT_FOUND', 'http_status' => 404];
    }
    $currentUpdatedAt = (int)($row['updated_at'] ?? 0);
    if ($expectedUpdatedAt !== null
        && ($expectedUpdatedAt <= 0 || $expectedUpdatedAt !== $currentUpdatedAt)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_AD_IMPRESSION_VERSION_CONFLICT',
            'http_status' => 409,
            'current_updated_at' => $currentUpdatedAt,
        ];
    }

    $oldStatus = strtoupper(trim((string)($row['status'] ?? 'REVIEW')));
    if (in_array($oldStatus, ['VERIFIED', 'REJECTED'], true)) {
        return [
            'ok' => true,
            'idempotent_replay' => true,
            'impression' => znews_ad_public_result($row),
            'row' => $row,
        ];
    }

    $payload = [
        'view_id' => znews_firebase_key((string)($row['view_id'] ?? ''), 'view_id'),
        'post_id' => znews_firebase_key((string)($row['post_id'] ?? ''), 'post_id'),
        'network' => znews_ad_network_name((string)($row['network'] ?? '')),
        'ad_unit_id' => znews_ad_clean_value($row['ad_unit_id'] ?? '', 'ad_unit_id', 160),
        'occurred_at' => (int)($row['occurred_at'] ?? 0),
    ];
    $evaluation = znews_ad_require_view_and_post($payload);
    $newStatus = strtoupper(trim((string)$evaluation['status']));
    $risk = max(0, min(100, (int)$evaluation['risk_score']));
    $reasons = array_values(array_unique(array_map('strval', (array)$evaluation['reasons'])));
    $post = is_array($evaluation['post'] ?? null) ? (array)$evaluation['post'] : [];
    $creatorUid = trim((string)($post['creator_uid'] ?? $row['creator_uid'] ?? ''));

    if ($creatorUid === '') {
        $newStatus = 'REVIEW';
        $risk = max(70, $risk);
        $reasons[] = 'CREATOR_UID_MISSING';
    }

    if ($newStatus === 'VERIFIED') {
        $slot = znews_ad_claim_slot(
            (string)$payload['view_id'],
            (string)$payload['network'],
            (string)$payload['ad_unit_id'],
            $impressionId
        );
        if (empty($slot['ok'])) {
            $newStatus = 'REVIEW';
            $risk = max(70, $risk);
            $reasons[] = (string)($slot['code'] ?? 'AD_SLOT_CLAIM_FAILED');
        } elseif (!empty($slot['duplicate'])) {
            $newStatus = 'REJECTED';
            $risk = max(90, $risk);
            $reasons[] = 'DUPLICATE_AD_SLOT';
        }
    }

    if ($newStatus === 'VERIFIED') {
        $limit = znews_ad_apply_view_limit((string)$payload['view_id'], $impressionId);
        if (empty($limit['ok'])) {
            $newStatus = 'REVIEW';
            $risk = max(70, $risk);
            $reasons[] = (string)($limit['code'] ?? 'AD_VIEW_LIMIT_CHECK_FAILED');
        } elseif (empty($limit['allowed'])) {
            $newStatus = 'REJECTED';
            $risk = max(90, $risk);
            $reasons[] = 'AD_VIEW_LIMIT_REACHED';
        }
    }

    $now = znews_now();
    $row['creator_uid'] = $creatorUid;
    $row['status'] = $newStatus;
    $row['verification_status'] = match ($newStatus) {
        'VERIFIED' => 'VERIFIED',
        'PENDING_VIEW' => 'PENDING',
        'REVIEW' => 'REVIEW_REQUIRED',
        default => 'REJECTED',
    };
    $row['risk_score'] = min(100, $risk);
    $row['risk_reasons'] = array_values(array_unique($reasons));
    $row['rechecked_at'] = $now;
    $row['updated_at'] = $now;
    $row['earning_eligible'] = false;
    $row['settlement_status'] = 'NOT_SETTLED';
    $row['credit_status'] = 'NOT_CREDITED';
    $row['reconciliation_required'] = true;
    $row['reconciliation_code'] = 'RECHECK_INDEX_SYNC';

    $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
    if ((int)($write['status'] ?? 0) === 412) {
        return ['ok' => false, 'code' => 'ZNEWS_AD_IMPRESSION_VERSION_CONFLICT', 'http_status' => 409];
    }
    if (empty($write['ok'])) {
        return ['ok' => false, 'code' => 'ZNEWS_AD_IMPRESSION_RECHECK_FAILED', 'http_status' => 503];
    }

    $indexPayload = [
        'impression_id' => $impressionId,
        'post_id' => (string)$payload['post_id'],
        'view_id' => (string)$payload['view_id'],
        'network' => (string)$payload['network'],
        'status' => $newStatus,
        'currency' => (string)($row['currency'] ?? ''),
        'reported_revenue_micros' => max(0, (int)($row['reported_revenue_micros'] ?? 0)),
        'created_at' => (int)($row['created_at'] ?? $now),
        'updated_at' => $now,
    ];
    $updates = [
        znews_ad_post_index_path((string)$payload['post_id'], $impressionId) => $indexPayload,
        znews_ad_view_index_path((string)$payload['view_id'], $impressionId) => $indexPayload,
        znews_ad_review_queue_path($impressionId) => in_array($newStatus, ['PENDING_VIEW', 'REVIEW'], true)
            ? [
                'impression_id' => $impressionId,
                'post_id' => (string)$payload['post_id'],
                'view_id' => (string)$payload['view_id'],
                'network' => (string)$payload['network'],
                'status' => $newStatus,
                'risk_score' => min(100, $risk),
                'risk_reasons' => array_values(array_unique($reasons)),
                'created_at' => (int)($row['created_at'] ?? $now),
                'updated_at' => $now,
            ]
            : null,
    ];
    $indexesOk = fb_patch('', $updates);
    $analytics = znews_ad_analytics_transition(
        (string)$payload['post_id'],
        $impressionId,
        $newStatus,
        (string)($row['currency'] ?? ''),
        max(0, (int)($row['reported_revenue_micros'] ?? 0))
    );
    $reconcile = !$indexesOk || empty($analytics['ok']);
    @fb_patch($path, [
        'reconciliation_required' => $reconcile,
        'reconciliation_code' => $reconcile ? 'RECHECK_INDEX_SYNC' : null,
        'updated_at' => znews_now(),
    ]);

    return [
        'ok' => !$reconcile,
        'code' => $reconcile
            ? 'ZNEWS_AD_IMPRESSION_RECONCILIATION_REQUIRED'
            : 'ZNEWS_AD_IMPRESSION_RECHECKED',
        'http_status' => $reconcile ? 503 : 200,
        'idempotent_replay' => false,
        'impression' => znews_ad_public_result($row),
        'row' => $row,
        'reconciliation_required' => $reconcile,
    ];
}

function znews_ad_admin_recheck(
    array $auth,
    string $impressionId,
    int $expectedUpdatedAt,
    string $idempotencyKey
): array {
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $adminUid = znews_firebase_key((string)($user['uid'] ?? ''), 'admin_uid');
    $impressionId = znews_firebase_key($impressionId, 'impression_id');
    $key = znews_idempotency_key($idempotencyKey);
    $path = 'ZNEWS_AD_ADMIN_IDEMPOTENCY/'
        . $adminUid
        . '/'
        . hash('sha256', $key);
    $payloadHash = hash('sha256', json_encode([
        'action' => 'RECHECK',
        'impression_id' => $impressionId,
        'expected_updated_at' => $expectedUpdatedAt,
    ], JSON_UNESCAPED_SLASHES));
    $now = znews_now();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_AD_ADMIN_REQUEST_READ_FAILED', 'http_status' => 503];
        }
        $existing = $snapshot['value'] ?? null;
        if (is_array($existing)) {
            $savedHash = trim((string)($existing['payload_hash'] ?? ''));
            if ($savedHash === '' || !hash_equals($savedHash, $payloadHash)) {
                return ['ok' => false, 'code' => 'ZNEWS_IDEMPOTENCY_CONFLICT', 'http_status' => 409];
            }
            if (strtoupper(trim((string)($existing['status'] ?? ''))) === 'COMPLETED') {
                $savedResult = is_array($existing['result'] ?? null)
                    ? (array)$existing['result']
                    : [];
                return [
                    'ok' => !empty($savedResult['ok']),
                    'code' => (string)($savedResult['code'] ?? 'ZNEWS_AD_IMPRESSION_RECHECKED'),
                    'http_status' => (int)($savedResult['http_status'] ?? (!empty($savedResult['ok']) ? 200 : 500)),
                    'idempotent_replay' => true,
                    'impression' => is_array($savedResult['impression'] ?? null)
                        ? (array)$savedResult['impression']
                        : [],
                    'current_updated_at' => isset($savedResult['current_updated_at'])
                        ? (int)$savedResult['current_updated_at']
                        : null,
                    'reconciliation_required' => !empty($savedResult['reconciliation_required']),
                ];
            }
            if ((int)($existing['lease_expires_at'] ?? 0) > $now) {
                return ['ok' => false, 'code' => 'ZNEWS_AD_ADMIN_REQUEST_IN_PROGRESS', 'http_status' => 409];
            }
        } elseif ($existing !== null) {
            return ['ok' => false, 'code' => 'ZNEWS_AD_ADMIN_REQUEST_INVALID', 'http_status' => 409];
        }

        $claim = [
            'admin_uid' => $adminUid,
            'action' => 'RECHECK',
            'impression_id' => $impressionId,
            'payload_hash' => $payloadHash,
            'status' => 'PROCESSING',
            'lease_expires_at' => $now + 60,
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
            return ['ok' => false, 'code' => 'ZNEWS_AD_ADMIN_REQUEST_CLAIM_FAILED', 'http_status' => 503];
        }

        $result = znews_ad_recheck_impression($impressionId, $expectedUpdatedAt);
        $claim['status'] = 'COMPLETED';
        $claim['result'] = [
            'ok' => !empty($result['ok']),
            'code' => (string)($result['code'] ?? ''),
            'http_status' => (int)($result['http_status'] ?? (!empty($result['ok']) ? 200 : 500)),
            'impression' => is_array($result['impression'] ?? null)
                ? (array)$result['impression']
                : [],
            'current_updated_at' => isset($result['current_updated_at'])
                ? (int)$result['current_updated_at']
                : null,
            'reconciliation_required' => !empty($result['reconciliation_required']),
        ];
        $claim['completed_at'] = znews_now();
        $claim['updated_at'] = znews_now();
        $claim['lease_expires_at'] = 0;
        @fb_put($path, $claim);
        return $result;
    }

    return ['ok' => false, 'code' => 'ZNEWS_AD_ADMIN_REQUEST_BUSY', 'http_status' => 409];
}
