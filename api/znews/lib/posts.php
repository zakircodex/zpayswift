<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_post_payload_hash(string $uid, string $text): string
{
    return hash('sha256', json_encode([
        'uid' => $uid,
        'text' => $text,
        'content_type' => 'TEXT',
        'visibility' => 'PUBLIC',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function znews_deterministic_post_id(string $uid, string $idempotencyKey): string
{
    return 'ZNP' . strtoupper(substr(hash('sha256', $uid . '|' . $idempotencyKey), 0, 29));
}

function znews_format_post(array $post): array
{
    return [
        'post_id' => trim((string)($post['post_id'] ?? '')),
        'creator_uid' => trim((string)($post['creator_uid'] ?? '')),
        'creator_name' => trim((string)($post['creator_name'] ?? 'Z-Pay User')),
        'creator_photo_url' => trim((string)($post['creator_photo_url'] ?? '')),
        'title' => trim((string)($post['title'] ?? '')),
        'text' => (string)($post['text'] ?? ''),
        'image_url' => trim((string)($post['image_url'] ?? '')),
        'content_type' => strtoupper(trim((string)($post['content_type'] ?? 'TEXT'))),
        'visibility' => 'PUBLIC',
        'status' => znews_normalize_status($post['status'] ?? 'REVIEW', 'REVIEW'),
        'moderation_status' => strtoupper(trim((string)($post['moderation_status'] ?? 'PENDING'))),
        'copyright_status' => strtoupper(trim((string)($post['copyright_status'] ?? 'PENDING'))),
        'like_count' => max(0, (int)($post['like_count'] ?? 0)),
        'comment_count' => max(0, (int)($post['comment_count'] ?? 0)),
        'share_count' => max(0, (int)($post['share_count'] ?? 0)),
        'created_at' => (int)($post['created_at'] ?? 0),
        'updated_at' => (int)($post['updated_at'] ?? 0),
    ];
}

function znews_format_public_post(array $post): array
{
    $formatted = znews_format_post($post);
    unset($formatted['moderation_status'], $formatted['copyright_status']);
    return $formatted;
}

function znews_idempotency_claim(
    string $uid,
    string $idempotencyKey,
    string $payloadHash,
    string $postId
): array {
    $path = znews_path_idempotency($uid, $idempotencyKey);
    $now = znews_now();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_IDEMPOTENCY_READ_FAILED',
                'message' => 'Post request could not be verified.',
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
            $existingPostId = trim((string)($existing['post_id'] ?? $postId));

            if ($status === 'COMPLETED') {
                $post = fb_get(znews_path_post($existingPostId));
                if (is_array($post)) {
                    return [
                        'ok' => true,
                        'claimed' => false,
                        'idempotent_replay' => true,
                        'post_id' => $existingPostId,
                        'post' => $post,
                        'path' => $path,
                    ];
                }

                return [
                    'ok' => false,
                    'code' => 'ZNEWS_POST_RECONCILIATION_REQUIRED',
                    'message' => 'Post request requires reconciliation.',
                    'http_status' => 503,
                ];
            }

            if ($status === 'PROCESSING') {
                return [
                    'ok' => false,
                    'code' => 'ZNEWS_POST_CREATE_IN_PROGRESS',
                    'message' => 'This post request is already being processed.',
                    'http_status' => 409,
                ];
            }

            if ($status !== 'FAILED') {
                return [
                    'ok' => false,
                    'code' => 'ZNEWS_IDEMPOTENCY_INVALID_STATE',
                    'message' => 'Post request is in an invalid state.',
                    'http_status' => 409,
                ];
            }
        } elseif ($existing !== null) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_IDEMPOTENCY_INVALID_RECORD',
                'message' => 'Post request could not be verified.',
                'http_status' => 409,
            ];
        }

        $claim = [
            'uid' => $uid,
            'post_id' => $postId,
            'payload_hash' => $payloadHash,
            'status' => 'PROCESSING',
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
                'code' => 'ZNEWS_IDEMPOTENCY_CLAIM_FAILED',
                'message' => 'Post request could not be started.',
                'http_status' => 503,
            ];
        }

        return [
            'ok' => true,
            'claimed' => true,
            'idempotent_replay' => false,
            'post_id' => $postId,
            'path' => $path,
            'claim' => $claim,
        ];
    }

    return [
        'ok' => false,
        'code' => 'ZNEWS_IDEMPOTENCY_BUSY',
        'message' => 'Post request is busy. Please try again.',
        'http_status' => 409,
    ];
}

function znews_create_text_post(array $auth, string $text, string $idempotencyKey): array
{
    $user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
    $uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
    $creator = znews_public_creator_snapshot($user);
    $payloadHash = znews_post_payload_hash($uid, $text);
    $postId = znews_deterministic_post_id($uid, $idempotencyKey);

    $claim = znews_idempotency_claim($uid, $idempotencyKey, $payloadHash, $postId);
    if (empty($claim['ok'])) {
        return $claim;
    }

    if (!empty($claim['idempotent_replay']) && is_array($claim['post'] ?? null)) {
        return [
            'ok' => true,
            'idempotent_replay' => true,
            'post' => znews_format_post((array)$claim['post']),
        ];
    }

    $now = znews_now();
    $post = [
        'schema_version' => 1,
        'post_id' => $postId,
        'creator_uid' => $uid,
        'creator_name' => (string)($creator['name'] ?? 'Z-Pay User'),
        'creator_photo_url' => (string)($creator['profile_photo_url'] ?? ''),
        'text' => $text,
        'image_url' => '',
        'content_type' => 'TEXT',
        'visibility' => 'PUBLIC',
        'status' => 'REVIEW',
        'moderation_status' => 'PENDING',
        'copyright_status' => 'PENDING',
        'like_count' => 0,
        'comment_count' => 0,
        'share_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => 0,
        'source' => 'ZPAY_API',
    ];

    $index = [
        'post_id' => $postId,
        'status' => 'REVIEW',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $completedClaim = array_merge((array)($claim['claim'] ?? []), [
        'status' => 'COMPLETED',
        'completed_at' => $now,
        'updated_at' => $now,
    ]);

    $updates = [
        znews_path_post($postId) => $post,
        znews_path_user_post($uid, $postId) => $index,
        (string)$claim['path'] => $completedClaim,
    ];

    if (!fb_patch('', $updates)) {
        @fb_patch((string)$claim['path'], [
            'status' => 'FAILED',
            'failed_at' => znews_now(),
            'updated_at' => znews_now(),
        ]);

        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_CREATE_FAILED',
            'message' => 'Post could not be created.',
            'http_status' => 503,
        ];
    }

    return [
        'ok' => true,
        'idempotent_replay' => false,
        'post' => znews_format_post($post),
    ];
}
