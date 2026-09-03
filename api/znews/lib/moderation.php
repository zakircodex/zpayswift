<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/instant_publish.php';

require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/post_access.php';

function znews_admin_identity(array $auth): array
{
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];

    return [
        'uid' => znews_firebase_key((string)($user['uid'] ?? ''), 'admin_uid'),
        'name' => trim((string)($user['name'] ?? 'Z-Pay Admin')),
        'role' => strtoupper(trim((string)($user['role'] ?? 'ADMIN'))),
    ];
}

function znews_moderation_note($value, bool $required = false, int $maximum = 1000): string
{
    $note = znews_normalize_text($value);
    $length = znews_text_length($note);

    if ($required && $length < 3) {
        api_response(false, 'ZNEWS_MODERATION_REASON_REQUIRED', 'A moderation reason is required.', [], 422);
    }

    if ($length > $maximum) {
        api_response(false, 'ZNEWS_MODERATION_NOTE_TOO_LONG', 'Moderation note is too long.', [
            'max_length' => $maximum,
        ], 422);
    }

    return $note;
}

function znews_copyright_verdict($value, bool $forApproval): string
{
    $verdict = strtoupper(trim((string)$value));
    $allowed = $forApproval
        ? ['CLEAR', 'ORIGINAL_CONFIRMED', 'LICENSED']
        : ['COPYRIGHT_MATCH', 'PLAGIARISM', 'POLICY_REJECTED', 'OTHER'];

    if (!in_array($verdict, $allowed, true)) {
        api_response(
            false,
            'ZNEWS_INVALID_COPYRIGHT_VERDICT',
            'A valid copyright verdict is required.',
            ['allowed' => $allowed],
            422
        );
    }

    return $verdict;
}

function znews_path_admin_idempotency(string $adminUid, string $action, string $key): string
{
    $action = strtoupper(preg_replace('/[^A-Z0-9_]/', '', $action) ?? '');
    if ($action === '') {
        api_response(false, 'ZNEWS_INVALID_ACTION', 'Invalid moderation action.', [], 422);
    }

    return 'ZNEWS_ADMIN_IDEMPOTENCY/'
        . znews_firebase_key($adminUid, 'admin_uid')
        . '/'
        . hash('sha256', $action . '|' . trim($key));
}

function znews_path_moderation_action(string $postId, string $actionId): string
{
    return 'ZNEWS_MODERATION_ACTIONS/'
        . znews_firebase_key($postId, 'post_id')
        . '/'
        . znews_firebase_key($actionId, 'action_id');
}

function znews_path_copyright_check(string $postId, string $checkId): string
{
    return 'ZNEWS_COPYRIGHT_CHECKS/'
        . znews_firebase_key($postId, 'post_id')
        . '/'
        . znews_firebase_key($checkId, 'check_id');
}

function znews_admin_payload_hash(
    string $adminUid,
    string $postId,
    string $action,
    array $payload
): string {
    ksort($payload);

    return hash('sha256', json_encode([
        'admin_uid' => $adminUid,
        'post_id' => $postId,
        'action' => strtoupper($action),
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function znews_admin_action_claim(
    string $adminUid,
    string $postId,
    string $action,
    string $idempotencyKey,
    string $payloadHash
): array {
    $path = znews_path_admin_idempotency($adminUid, $action, $idempotencyKey);
    $now = znews_now();
    $leaseSeconds = 60;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_MODERATION_CLAIM_READ_FAILED',
                'message' => 'Moderation request could not be verified.',
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
                    'message' => 'This idempotency key was already used for another moderation request.',
                    'http_status' => 409,
                ];
            }

            $status = strtoupper(trim((string)($existing['status'] ?? '')));
            if ($status === 'COMPLETED') {
                return [
                    'ok' => true,
                    'idempotent_replay' => true,
                    'path' => $path,
                    'result' => is_array($existing['result'] ?? null) ? (array)$existing['result'] : [],
                ];
            }

            if ($status === 'PROCESSING' && (int)($existing['lease_expires_at'] ?? 0) > $now) {
                return [
                    'ok' => false,
                    'code' => 'ZNEWS_MODERATION_IN_PROGRESS',
                    'message' => 'This moderation request is already being processed.',
                    'http_status' => 409,
                ];
            }

            if (!in_array($status, ['PROCESSING', 'FAILED'], true)) {
                return [
                    'ok' => false,
                    'code' => 'ZNEWS_MODERATION_INVALID_STATE',
                    'message' => 'Moderation request is in an invalid state.',
                    'http_status' => 409,
                ];
            }
        } elseif ($existing !== null) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_MODERATION_INVALID_RECORD',
                'message' => 'Moderation request could not be verified.',
                'http_status' => 409,
            ];
        }

        $actionId = 'ZMA' . strtoupper(substr(hash('sha256', $path), 0, 29));
        $claim = [
            'admin_uid' => $adminUid,
            'post_id' => $postId,
            'action' => strtoupper($action),
            'action_id' => $actionId,
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
                'code' => 'ZNEWS_MODERATION_CLAIM_FAILED',
                'message' => 'Moderation request could not be started.',
                'http_status' => 503,
            ];
        }

        return [
            'ok' => true,
            'idempotent_replay' => false,
            'path' => $path,
            'claim' => $claim,
            'action_id' => $actionId,
        ];
    }

    return [
        'ok' => false,
        'code' => 'ZNEWS_MODERATION_BUSY',
        'message' => 'Moderation is busy. Please try again.',
        'http_status' => 409,
    ];
}

function znews_admin_action_finish(array $claim, array $result): bool
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

function znews_admin_action_fail(array $claim, string $code): void
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

function znews_admin_replay(array $claim): array
{
    $result = is_array($claim['result'] ?? null) ? (array)$claim['result'] : [];

    if (!empty($result['reconciliation_required'])) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_MODERATION_RECONCILIATION_REQUIRED',
            'message' => 'Moderation completed but indexes require reconciliation.',
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

function znews_admin_queue(int $limit, string $cursor = ''): array
{
    $rows = fb_get('ZNEWS_POSTS');
    $items = [];

    if (is_array($rows)) {
        foreach ($rows as $postId => $post) {
            if (!is_array($post)) {
                continue;
            }

            $status = znews_normalize_status($post['status'] ?? 'REVIEW', 'REVIEW');
            $moderation = strtoupper(trim((string)($post['moderation_status'] ?? 'PENDING')));
            if ($status !== 'REVIEW' || $moderation !== 'PENDING' || (int)($post['deleted_at'] ?? 0) > 0) {
                continue;
            }

            $post['post_id'] = (string)($post['post_id'] ?? $postId);
            $items[] = znews_format_owned_post($post);
        }
    }

    usort($items, static function (array $left, array $right): int {
        $leftTime = (int)($left['created_at'] ?? 0);
        $rightTime = (int)($right['created_at'] ?? 0);
        if ($leftTime === $rightTime) {
            return strcmp((string)($right['post_id'] ?? ''), (string)($left['post_id'] ?? ''));
        }
        return $rightTime <=> $leftTime;
    });

    $cursorValue = znews_cursor_decode($cursor);
    if ($cursorValue !== '') {
        $seen = false;
        $items = array_values(array_filter($items, static function (array $item) use ($cursorValue, &$seen): bool {
            if ($seen) {
                return true;
            }
            if ((string)($item['post_id'] ?? '') === $cursorValue) {
                $seen = true;
            }
            return false;
        }));
    }

    $slice = array_slice($items, 0, $limit + 1);
    $hasMore = count($slice) > $limit;
    if ($hasMore) {
        array_pop($slice);
    }

    $nextCursor = '';
    if ($hasMore && $slice) {
        $last = end($slice);
        $nextCursor = znews_cursor_encode((string)($last['post_id'] ?? ''));
    }

    return [
        'items' => array_values($slice),
        'next_cursor' => $nextCursor,
        'has_more' => $hasMore,
    ];
}

function znews_admin_post_details(string $postId): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    $post = fb_get(znews_path_post($postId));
    if (!is_array($post)) {
        api_response(false, 'ZNEWS_POST_NOT_FOUND', 'Post not found.', [], 404);
    }

    $actions = fb_get('ZNEWS_MODERATION_ACTIONS/' . $postId);
    $checks = fb_get('ZNEWS_COPYRIGHT_CHECKS/' . $postId);

    return [
        'post' => znews_format_owned_post($post),
        'moderation_actions' => is_array($actions) ? array_values($actions) : [],
        'copyright_checks' => is_array($checks) ? array_values($checks) : [],
    ];
}

function znews_admin_moderate_post(
    array $auth,
    string $postId,
    int $expectedUpdatedAt,
    string $idempotencyKey,
    string $action,
    string $copyrightVerdict,
    string $note
): array {
    $admin = znews_admin_identity($auth);
    $adminUid = (string)$admin['uid'];
    $postId = znews_firebase_key($postId, 'post_id');
    $action = strtoupper(trim($action));
    $approve = $action === 'APPROVE';

    if (!in_array($action, ['APPROVE', 'REJECT'], true)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_INVALID_ACTION',
            'message' => 'Invalid moderation action.',
            'http_status' => 422,
        ];
    }

    $snapshot = fb_get_with_etag(znews_path_post($postId));
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_READ_FAILED',
            'message' => 'Post could not be loaded.',
            'http_status' => 503,
        ];
    }

    $post = $snapshot['value'] ?? null;
    if (!is_array($post)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_NOT_FOUND',
            'message' => 'Post not found.',
            'http_status' => 404,
        ];
    }

    $status = znews_normalize_status($post['status'] ?? 'REVIEW', 'REVIEW');
    $moderationStatus = strtoupper(trim((string)($post['moderation_status'] ?? 'PENDING')));
    $currentUpdatedAt = (int)($post['updated_at'] ?? 0);

    if ($status === 'DELETED') {
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_DELETED',
            'message' => 'Deleted posts cannot be moderated.',
            'http_status' => 409,
        ];
    }

    if ($status !== 'REVIEW' || $moderationStatus !== 'PENDING') {
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_NOT_PENDING_REVIEW',
            'message' => 'This post is not pending moderation.',
            'http_status' => 409,
        ];
    }

    if ($expectedUpdatedAt <= 0 || $expectedUpdatedAt !== $currentUpdatedAt) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_VERSION_CONFLICT',
            'message' => 'This post changed. Reload it before moderating.',
            'http_status' => 409,
            'data' => ['current_updated_at' => $currentUpdatedAt],
        ];
    }

    $payloadHash = znews_admin_payload_hash($adminUid, $postId, $action, [
        'expected_updated_at' => $expectedUpdatedAt,
        'copyright_verdict' => $copyrightVerdict,
        'note' => $note,
    ]);
    $claim = znews_admin_action_claim(
        $adminUid,
        $postId,
        $action,
        $idempotencyKey,
        $payloadHash
    );

    if (empty($claim['ok'])) {
        return $claim;
    }
    if (!empty($claim['idempotent_replay'])) {
        return znews_admin_replay($claim);
    }

    $now = znews_now();
    $actionId = (string)($claim['action_id'] ?? znews_make_id('ZMA'));
    $checkId = 'ZCC' . strtoupper(substr(hash('sha256', $actionId . '|COPYRIGHT'), 0, 29));
    $updated = $post;
    $updated['status'] = $approve ? 'ACTIVE' : 'BLOCKED';
    $updated['moderation_status'] = $approve ? 'APPROVED' : 'REJECTED';
    $updated['copyright_status'] = $copyrightVerdict;
    $updated['moderation_note'] = $note;
    $updated['reviewed_by_uid'] = $adminUid;
    $updated['reviewed_by_name'] = (string)$admin['name'];
    $updated['reviewed_at'] = $now;
    $updated['updated_at'] = $now;
    $updated['published_at'] = $approve ? $now : 0;

    $write = fb_put_if_match(znews_path_post($postId), $updated, (string)$snapshot['etag']);
    if ((int)($write['status'] ?? 0) === 412) {
        znews_admin_action_fail($claim, 'ZNEWS_POST_VERSION_CONFLICT');
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_VERSION_CONFLICT',
            'message' => 'This post changed. Reload it before moderating.',
            'http_status' => 409,
        ];
    }
    if (empty($write['ok'])) {
        znews_admin_action_fail($claim, 'ZNEWS_MODERATION_WRITE_FAILED');
        return [
            'ok' => false,
            'code' => 'ZNEWS_MODERATION_WRITE_FAILED',
            'message' => 'Moderation decision could not be saved.',
            'http_status' => 503,
        ];
    }

    $creatorUid = znews_firebase_key((string)($updated['creator_uid'] ?? ''), 'creator_uid');
    $actionRow = [
        'action_id' => $actionId,
        'post_id' => $postId,
        'action' => $action,
        'admin_uid' => $adminUid,
        'admin_name' => (string)$admin['name'],
        'note' => $note,
        'copyright_verdict' => $copyrightVerdict,
        'created_at' => $now,
    ];
    $copyrightRow = [
        'check_id' => $checkId,
        'post_id' => $postId,
        'verdict' => $copyrightVerdict,
        'method' => 'ADMIN_MANUAL_REVIEW',
        'reviewed_by_uid' => $adminUid,
        'reviewed_by_name' => (string)$admin['name'],
        'note' => $note,
        'created_at' => $now,
    ];
    $userIndex = [
        'post_id' => $postId,
        'status' => $updated['status'],
        'category' => strtoupper(trim((string)($updated['category'] ?? ''))),
        'created_at' => (int)($updated['created_at'] ?? $now),
        'updated_at' => $now,
        'published_at' => $approve ? $now : 0,
    ];
    $existingPublicIndex = $approve ? fb_get(znews_path_public_feed($postId)) : null;
    $canonicalEngagement = $approve ? fb_get('ZNEWS_ENGAGEMENT/' . $postId) : null;
    $publicIndex = $approve
        ? znews_public_feed_index_for_post(
            $updated,
            is_array($existingPublicIndex) ? (array)$existingPublicIndex : [],
            is_array($canonicalEngagement) ? (array)$canonicalEngagement : null
        )
        : null;

    $indexOk = fb_patch('', [
        znews_path_user_post($creatorUid, $postId) => $userIndex,
        znews_path_public_feed($postId) => $publicIndex,
        znews_path_moderation_action($postId, $actionId) => $actionRow,
        znews_path_copyright_check($postId, $checkId) => $copyrightRow,
    ]);

    $formatted = znews_format_owned_post($updated);
    $result = [
        'post' => $formatted,
        'reconciliation_required' => !$indexOk,
    ];
    if (!znews_admin_action_finish($claim, $result)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_MODERATION_FINALIZE_FAILED',
            'message' => 'Moderation was saved but the request could not be finalized.',
            'http_status' => 503,
            'post' => $formatted,
        ];
    }

    if (function_exists('system_log')) {
        system_log('ZNEWS_POST_' . ($approve ? 'APPROVED' : 'REJECTED'), $postId, 'Z Sky 24 moderation decision', [
            'post_id' => $postId,
            'admin_uid' => $adminUid,
            'creator_uid' => $creatorUid,
            'copyright_verdict' => $copyrightVerdict,
        ]);
    }

    if (!$indexOk) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_MODERATION_RECONCILIATION_REQUIRED',
            'message' => 'Moderation completed but indexes require reconciliation.',
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
