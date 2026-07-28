<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_engagement_path(string $postId): string
{
    return 'ZNEWS_ENGAGEMENT/' . znews_firebase_key($postId, 'post_id');
}

function znews_like_path(string $postId, string $uid): string
{
    return 'ZNEWS_LIKES/'
        . znews_firebase_key($postId, 'post_id')
        . '/'
        . znews_firebase_key($uid, 'uid');
}

function znews_share_path(string $postId, string $shareId): string
{
    return 'ZNEWS_SHARES/'
        . znews_firebase_key($postId, 'post_id')
        . '/'
        . znews_firebase_key($shareId, 'share_id');
}

function znews_engagement_idempotency_path(
    string $actorUid,
    string $action,
    string $idempotencyKey
): string {
    $action = strtoupper(preg_replace('/[^A-Z0-9_]/', '', $action) ?? '');
    if ($action === '') {
        api_response(false, 'ZNEWS_INVALID_ACTION', 'Invalid engagement action.', [], 422);
    }

    return 'ZNEWS_ENGAGEMENT_IDEMPOTENCY/'
        . znews_firebase_key($actorUid, 'actor_uid')
        . '/'
        . $action
        . '/'
        . hash('sha256', trim($idempotencyKey));
}

function znews_engagement_default_counts(): array
{
    return [
        'like_count' => 0,
        'comment_count' => 0,
        'share_count' => 0,
        'updated_at' => 0,
    ];
}

function znews_engagement_counts(string $postId): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    $row = fb_get(znews_engagement_path($postId));
    $row = is_array($row) ? $row : [];

    return [
        'like_count' => max(0, (int)($row['like_count'] ?? 0)),
        'comment_count' => max(0, (int)($row['comment_count'] ?? 0)),
        'share_count' => max(0, (int)($row['share_count'] ?? 0)),
        'updated_at' => max(0, (int)($row['updated_at'] ?? 0)),
    ];
}

function znews_engagement_overlay(array $post): array
{
    $postId = trim((string)($post['post_id'] ?? ''));
    if ($postId === '') {
        return $post;
    }

    $counts = znews_engagement_counts($postId);
    $post['like_count'] = (int)$counts['like_count'];
    $post['comment_count'] = (int)$counts['comment_count'];
    $post['share_count'] = (int)$counts['share_count'];

    return $post;
}

function znews_engagement_require_public_post(string $postId): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    $post = fb_get(znews_path_post($postId));

    if (!is_array($post)
        || strtoupper(trim((string)($post['status'] ?? ''))) !== 'ACTIVE'
        || strtoupper(trim((string)($post['visibility'] ?? ''))) !== 'PUBLIC'
        || (int)($post['deleted_at'] ?? 0) > 0) {
        api_response(false, 'ZNEWS_POST_NOT_FOUND', 'Post not found.', [], 404);
    }

    return $post;
}

function znews_engagement_adjust_counter(
    string $postId,
    string $field,
    int $delta
): array {
    $postId = znews_firebase_key($postId, 'post_id');
    $allowed = ['like_count', 'comment_count', 'share_count'];
    if (!in_array($field, $allowed, true) || !in_array($delta, [-1, 1], true)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_COUNTER_INVALID',
            'message' => 'Invalid engagement counter request.',
            'http_status' => 422,
        ];
    }

    $path = znews_engagement_path($postId);
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_COUNTER_READ_FAILED',
                'message' => 'Engagement counter could not be loaded.',
                'http_status' => 503,
            ];
        }

        $row = is_array($snapshot['value'] ?? null)
            ? (array)$snapshot['value']
            : znews_engagement_default_counts();

        foreach (['like_count', 'comment_count', 'share_count'] as $counterField) {
            $row[$counterField] = max(0, (int)($row[$counterField] ?? 0));
        }

        $row[$field] = max(0, (int)$row[$field] + $delta);
        $row['post_id'] = $postId;
        $row['updated_at'] = znews_now();

        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(70000);
            continue;
        }

        if (empty($write['ok'])) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_COUNTER_WRITE_FAILED',
                'message' => 'Engagement counter could not be updated.',
                'http_status' => 503,
            ];
        }

        return [
            'ok' => true,
            'counts' => znews_engagement_counts($postId),
        ];
    }

    return [
        'ok' => false,
        'code' => 'ZNEWS_COUNTER_BUSY',
        'message' => 'Engagement counter is busy. Please try again.',
        'http_status' => 409,
    ];
}

function znews_engagement_set_counter_exact(
    string $postId,
    string $field,
    int $value
): array {
    $postId = znews_firebase_key($postId, 'post_id');
    $allowed = ['like_count', 'comment_count', 'share_count'];
    if (!in_array($field, $allowed, true)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_COUNTER_INVALID',
            'message' => 'Invalid engagement counter request.',
            'http_status' => 422,
        ];
    }

    $path = znews_engagement_path($postId);
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_COUNTER_READ_FAILED',
                'message' => 'Engagement counter could not be loaded.',
                'http_status' => 503,
            ];
        }

        $row = is_array($snapshot['value'] ?? null)
            ? (array)$snapshot['value']
            : znews_engagement_default_counts();

        foreach (['like_count', 'comment_count', 'share_count'] as $counterField) {
            $row[$counterField] = max(0, (int)($row[$counterField] ?? 0));
        }

        $row[$field] = max(0, $value);
        $row['post_id'] = $postId;
        $row['updated_at'] = znews_now();

        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(70000);
            continue;
        }

        if (empty($write['ok'])) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_COUNTER_WRITE_FAILED',
                'message' => 'Engagement counter could not be reconciled.',
                'http_status' => 503,
            ];
        }

        return [
            'ok' => true,
            'counts' => znews_engagement_counts($postId),
        ];
    }

    return [
        'ok' => false,
        'code' => 'ZNEWS_COUNTER_BUSY',
        'message' => 'Engagement counter is busy. Please try again.',
        'http_status' => 409,
    ];
}

function znews_engagement_claim(
    string $actorUid,
    string $postId,
    string $action,
    string $idempotencyKey,
    array $payload
): array {
    $actorUid = znews_firebase_key($actorUid, 'actor_uid');
    $postId = znews_firebase_key($postId, 'post_id');
    ksort($payload);

    $path = znews_engagement_idempotency_path($actorUid, $action, $idempotencyKey);
    $payloadHash = hash('sha256', json_encode([
        'actor_uid' => $actorUid,
        'post_id' => $postId,
        'action' => strtoupper($action),
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $now = znews_now();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_ENGAGEMENT_REQUEST_READ_FAILED',
                'message' => 'Engagement request could not be verified.',
                'http_status' => 503,
            ];
        }

        $existing = $snapshot['value'] ?? null;
        if (is_array($existing)) {
            $existingHash = trim((string)($existing['payload_hash'] ?? ''));
            if ($existingHash === '' || !hash_equals($existingHash, $payloadHash)) {
                return [
                    'ok' => false,
                    'code' => 'ZNEWS_IDEMPOTENCY_CONFLICT',
                    'message' => 'This idempotency key was already used for another request.',
                    'http_status' => 409,
                ];
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
                return [
                    'ok' => false,
                    'code' => 'ZNEWS_ENGAGEMENT_IN_PROGRESS',
                    'message' => 'This engagement request is already being processed.',
                    'http_status' => 409,
                ];
            }

            if (!in_array($status, ['PROCESSING', 'FAILED'], true)) {
                return [
                    'ok' => false,
                    'code' => 'ZNEWS_ENGAGEMENT_INVALID_STATE',
                    'message' => 'Engagement request is in an invalid state.',
                    'http_status' => 409,
                ];
            }
        } elseif ($existing !== null) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_ENGAGEMENT_INVALID_RECORD',
                'message' => 'Engagement request could not be verified.',
                'http_status' => 409,
            ];
        }

        $claim = [
            'actor_uid' => $actorUid,
            'post_id' => $postId,
            'action' => strtoupper($action),
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
            usleep(70000);
            continue;
        }

        if (empty($write['ok'])) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_ENGAGEMENT_CLAIM_FAILED',
                'message' => 'Engagement request could not be started.',
                'http_status' => 503,
            ];
        }

        return [
            'ok' => true,
            'idempotent_replay' => false,
            'path' => $path,
            'claim' => $claim,
        ];
    }

    return [
        'ok' => false,
        'code' => 'ZNEWS_ENGAGEMENT_BUSY',
        'message' => 'Engagement request is busy. Please try again.',
        'http_status' => 409,
    ];
}


function znews_engagement_replay_result(
    array $claim,
    string $reconciliationCode,
    string $reconciliationMessage
): array {
    $result = is_array($claim['result'] ?? null)
        ? (array)$claim['result']
        : [];

    if (!empty($result['reconciliation_required'])) {
        return [
            'ok' => false,
            'code' => $reconciliationCode,
            'message' => $reconciliationMessage,
            'http_status' => 503,
            'idempotent_replay' => true,
        ] + $result;
    }

    return [
        'ok' => true,
        'idempotent_replay' => true,
    ] + $result;
}

function znews_engagement_finish(array $claim, array $result): bool
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

function znews_engagement_fail(array $claim, string $code): void
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
