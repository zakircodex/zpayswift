<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_path_mutation_idempotency(
    string $uid,
    string $action,
    string $idempotencyKey
): string {
    $action = strtoupper(preg_replace('/[^A-Z0-9_]/', '', $action) ?? '');
    if ($action === '') {
        api_response(false, 'ZNEWS_INVALID_ACTION', 'Invalid post action.', [], 422);
    }

    return 'ZNEWS_MUTATION_IDEMPOTENCY/'
        . znews_firebase_key($uid, 'uid')
        . '/'
        . $action
        . '/'
        . hash('sha256', trim($idempotencyKey));
}

function znews_mutation_payload_hash(
    string $uid,
    string $postId,
    string $action,
    array $payload
): string {
    ksort($payload);

    return hash('sha256', json_encode([
        'uid' => $uid,
        'post_id' => $postId,
        'action' => strtoupper($action),
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function znews_mutation_claim(
    string $uid,
    string $postId,
    string $action,
    string $idempotencyKey,
    string $payloadHash
): array {
    $path = znews_path_mutation_idempotency($uid, $action, $idempotencyKey);
    $now = znews_now();
    $leaseSeconds = 45;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_MUTATION_READ_FAILED',
                'message' => 'Post action could not be verified.',
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
                    'claimed' => false,
                    'idempotent_replay' => true,
                    'path' => $path,
                    'result' => is_array($existing['result'] ?? null)
                        ? (array)$existing['result']
                        : [],
                ];
            }

            $leaseExpiresAt = (int)($existing['lease_expires_at'] ?? 0);
            if ($status === 'PROCESSING' && $leaseExpiresAt > $now) {
                return [
                    'ok' => false,
                    'code' => 'ZNEWS_MUTATION_IN_PROGRESS',
                    'message' => 'This post action is already being processed.',
                    'http_status' => 409,
                ];
            }

            if (!in_array($status, ['FAILED', 'PROCESSING'], true)) {
                return [
                    'ok' => false,
                    'code' => 'ZNEWS_MUTATION_INVALID_STATE',
                    'message' => 'Post action is in an invalid state.',
                    'http_status' => 409,
                ];
            }
        } elseif ($existing !== null) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_MUTATION_INVALID_RECORD',
                'message' => 'Post action could not be verified.',
                'http_status' => 409,
            ];
        }

        $claim = [
            'uid' => $uid,
            'post_id' => $postId,
            'action' => strtoupper($action),
            'payload_hash' => $payloadHash,
            'status' => 'PROCESSING',
            'lease_expires_at' => $now + $leaseSeconds,
            'created_at' => is_array($existing) ? (int)($existing['created_at'] ?? $now) : $now,
            'updated_at' => $now,
        ];

        $write = fb_put_if_match($path, $claim, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(100000);
            continue;
        }

        if (empty($write['ok'])) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_MUTATION_CLAIM_FAILED',
                'message' => 'Post action could not be started.',
                'http_status' => 503,
            ];
        }

        return [
            'ok' => true,
            'claimed' => true,
            'idempotent_replay' => false,
            'path' => $path,
            'claim' => $claim,
        ];
    }

    return [
        'ok' => false,
        'code' => 'ZNEWS_MUTATION_BUSY',
        'message' => 'Post action is busy. Please try again.',
        'http_status' => 409,
    ];
}

function znews_mutation_complete(array $claim, array $result): bool
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

function znews_mutation_fail(array $claim, string $code): void
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

function znews_mutation_replay_result(array $claim): array
{
    $result = is_array($claim['result'] ?? null) ? (array)$claim['result'] : [];
    if (!empty($result['reconciliation_required'])) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_RECONCILIATION_REQUIRED',
            'message' => 'Post was changed but its indexes require reconciliation.',
            'http_status' => 503,
            'post' => is_array($result['post'] ?? null) ? (array)$result['post'] : [],
            'idempotent_replay' => true,
        ];
    }

    return [
        'ok' => true,
        'post' => is_array($result['post'] ?? null) ? (array)$result['post'] : [],
        'idempotent_replay' => true,
    ];
}

function znews_update_text_post(
    array $auth,
    string $postId,
    string $text,
    int $expectedUpdatedAt,
    string $idempotencyKey
): array {
    $user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
    $uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
    $postId = znews_firebase_key($postId, 'post_id');

    $owned = znews_post_owner_snapshot($uid, $postId, false);
    $post = (array)$owned['post'];
    $status = znews_normalize_status($post['status'] ?? 'REVIEW', 'REVIEW');

    if ($status === 'BLOCKED') {
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_BLOCKED',
            'message' => 'Blocked posts cannot be edited.',
            'http_status' => 409,
        ];
    }

    $currentUpdatedAt = (int)($post['updated_at'] ?? 0);
    if ($expectedUpdatedAt <= 0 || $currentUpdatedAt !== $expectedUpdatedAt) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_VERSION_CONFLICT',
            'message' => 'This post changed. Reload it before editing.',
            'http_status' => 409,
            'data' => ['current_updated_at' => $currentUpdatedAt],
        ];
    }

    $payloadHash = znews_mutation_payload_hash($uid, $postId, 'UPDATE', [
        'text' => $text,
        'expected_updated_at' => $expectedUpdatedAt,
    ]);
    $claim = znews_mutation_claim(
        $uid,
        $postId,
        'UPDATE',
        $idempotencyKey,
        $payloadHash
    );

    if (empty($claim['ok'])) {
        return $claim;
    }
    if (!empty($claim['idempotent_replay'])) {
        return znews_mutation_replay_result($claim);
    }

    $now = znews_now();
    $updated = $post;
    $updated['text'] = $text;
    $updated['content_type'] = 'TEXT';
    $updated['image_url'] = '';
    $updated['visibility'] = 'PUBLIC';
    $updated['status'] = 'REVIEW';
    $updated['moderation_status'] = 'PENDING';
    $updated['copyright_status'] = 'PENDING';
    $updated['updated_at'] = $now;
    $updated['deleted_at'] = 0;
    $updated['last_edit_at'] = $now;

    $write = fb_put_if_match(
        znews_path_post($postId),
        $updated,
        (string)$owned['etag']
    );

    if ((int)($write['status'] ?? 0) === 412) {
        znews_mutation_fail($claim, 'ZNEWS_POST_VERSION_CONFLICT');
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_VERSION_CONFLICT',
            'message' => 'This post changed. Reload it before editing.',
            'http_status' => 409,
        ];
    }

    if (empty($write['ok'])) {
        znews_mutation_fail($claim, 'ZNEWS_POST_UPDATE_FAILED');
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_UPDATE_FAILED',
            'message' => 'Post could not be updated.',
            'http_status' => 503,
        ];
    }

    $indexUpdates = [
        znews_path_user_post($uid, $postId) => [
            'post_id' => $postId,
            'status' => 'REVIEW',
            'created_at' => (int)($updated['created_at'] ?? $now),
            'updated_at' => $now,
        ],
        znews_path_public_feed($postId) => null,
    ];
    $indexOk = fb_patch('', $indexUpdates);

    $formatted = znews_format_owned_post($updated);
    $result = [
        'post' => $formatted,
        'reconciliation_required' => !$indexOk,
    ];
    if (!znews_mutation_complete($claim, $result)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_MUTATION_FINALIZE_FAILED',
            'message' => 'Post was updated but the request could not be finalized.',
            'http_status' => 503,
            'post' => $formatted,
        ];
    }

    if (function_exists('system_log')) {
        system_log('ZNEWS_POST_UPDATED', $postId, 'Z News post updated', [
            'uid' => $uid,
            'post_id' => $postId,
            'status' => 'REVIEW',
        ]);
    }

    if (!$indexOk) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_RECONCILIATION_REQUIRED',
            'message' => 'Post was updated but its indexes require reconciliation.',
            'http_status' => 503,
            'post' => $formatted,
        ];
    }

    return [
        'ok' => true,
        'post' => $formatted,
        'idempotent_replay' => false,
    ];
}

function znews_delete_post(
    array $auth,
    string $postId,
    int $expectedUpdatedAt,
    string $idempotencyKey
): array {
    $user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
    $uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
    $postId = znews_firebase_key($postId, 'post_id');

    $owned = znews_post_owner_snapshot($uid, $postId, true);
    $post = (array)$owned['post'];
    $currentUpdatedAt = (int)($post['updated_at'] ?? 0);
    $status = znews_normalize_status($post['status'] ?? 'REVIEW', 'REVIEW');

    $payloadHash = znews_mutation_payload_hash($uid, $postId, 'DELETE', [
        'expected_updated_at' => $expectedUpdatedAt,
    ]);
    $claim = znews_mutation_claim(
        $uid,
        $postId,
        'DELETE',
        $idempotencyKey,
        $payloadHash
    );

    if (empty($claim['ok'])) {
        return $claim;
    }
    if (!empty($claim['idempotent_replay'])) {
        return znews_mutation_replay_result($claim);
    }

    if ($status === 'DELETED') {
        $formatted = znews_format_owned_post($post);
        znews_mutation_complete($claim, [
            'post' => $formatted,
            'reconciliation_required' => false,
        ]);

        return [
            'ok' => true,
            'post' => $formatted,
            'idempotent_replay' => true,
        ];
    }

    if ($expectedUpdatedAt <= 0 || $currentUpdatedAt !== $expectedUpdatedAt) {
        znews_mutation_fail($claim, 'ZNEWS_POST_VERSION_CONFLICT');
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_VERSION_CONFLICT',
            'message' => 'This post changed. Reload it before deleting.',
            'http_status' => 409,
            'data' => ['current_updated_at' => $currentUpdatedAt],
        ];
    }

    $now = znews_now();
    $deleted = $post;
    $deleted['status'] = 'DELETED';
    $deleted['moderation_status'] = 'DELETED';
    $deleted['deleted_at'] = $now;
    $deleted['updated_at'] = $now;

    $write = fb_put_if_match(
        znews_path_post($postId),
        $deleted,
        (string)$owned['etag']
    );

    if ((int)($write['status'] ?? 0) === 412) {
        znews_mutation_fail($claim, 'ZNEWS_POST_VERSION_CONFLICT');
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_VERSION_CONFLICT',
            'message' => 'This post changed. Reload it before deleting.',
            'http_status' => 409,
        ];
    }

    if (empty($write['ok'])) {
        znews_mutation_fail($claim, 'ZNEWS_POST_DELETE_FAILED');
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_DELETE_FAILED',
            'message' => 'Post could not be deleted.',
            'http_status' => 503,
        ];
    }

    $indexUpdates = [
        znews_path_user_post($uid, $postId) => [
            'post_id' => $postId,
            'status' => 'DELETED',
            'created_at' => (int)($deleted['created_at'] ?? $now),
            'updated_at' => $now,
            'deleted_at' => $now,
        ],
        znews_path_public_feed($postId) => null,
    ];
    $indexOk = fb_patch('', $indexUpdates);

    $formatted = znews_format_owned_post($deleted);
    $result = [
        'post' => $formatted,
        'reconciliation_required' => !$indexOk,
    ];
    if (!znews_mutation_complete($claim, $result)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_MUTATION_FINALIZE_FAILED',
            'message' => 'Post was deleted but the request could not be finalized.',
            'http_status' => 503,
            'post' => $formatted,
        ];
    }

    if (function_exists('system_log')) {
        system_log('ZNEWS_POST_DELETED', $postId, 'Z News post deleted', [
            'uid' => $uid,
            'post_id' => $postId,
        ]);
    }

    if (!$indexOk) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_RECONCILIATION_REQUIRED',
            'message' => 'Post was deleted but its indexes require reconciliation.',
            'http_status' => 503,
            'post' => $formatted,
        ];
    }

    return [
        'ok' => true,
        'post' => $formatted,
        'idempotent_replay' => false,
    ];
}
