<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/engagement.php';

function znews_like_active(array $row): bool
{
    return !empty($row['active'])
        && strtoupper(trim((string)($row['status'] ?? 'ACTIVE'))) === 'ACTIVE';
}

function znews_like_recount(string $postId): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    $rows = fb_get('ZNEWS_LIKES/' . $postId);
    $count = 0;

    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (is_array($row) && znews_like_active($row)) {
                $count++;
            }
        }
    }

    return znews_engagement_set_counter_exact($postId, 'like_count', $count);
}

function znews_like_status(string $postId, string $uid): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    $uid = znews_firebase_key($uid, 'uid');
    znews_engagement_require_public_post($postId);

    $row = fb_get(znews_like_path($postId, $uid));
    $liked = is_array($row) && znews_like_active($row);

    return [
        'post_id' => $postId,
        'liked' => $liked,
        'counts' => znews_engagement_counts($postId),
    ];
}

function znews_like_set(
    array $auth,
    string $postId,
    bool $liked,
    string $idempotencyKey
): array {
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
    $postId = znews_firebase_key($postId, 'post_id');
    znews_engagement_require_public_post($postId);

    $claim = znews_engagement_claim(
        $uid,
        $postId,
        'LIKE_SET',
        $idempotencyKey,
        ['liked' => $liked]
    );
    if (empty($claim['ok'])) {
        return $claim;
    }
    if (!empty($claim['idempotent_replay'])) {
        return znews_engagement_replay_result(
            $claim,
            'ZNEWS_LIKE_RECONCILIATION_REQUIRED',
            'Like was saved but its counter requires reconciliation.'
        );
    }

    $path = znews_like_path($postId, $uid);
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            znews_engagement_fail($claim, 'ZNEWS_LIKE_READ_FAILED');
            return [
                'ok' => false,
                'code' => 'ZNEWS_LIKE_READ_FAILED',
                'message' => 'Like status could not be loaded.',
                'http_status' => 503,
            ];
        }

        $current = is_array($snapshot['value'] ?? null)
            ? (array)$snapshot['value']
            : [];
        $currentLiked = znews_like_active($current);

        if ($currentLiked === $liked) {
            $counterSync = strtoupper(trim((string)($current['counter_sync_status'] ?? 'OK')));
            if ($counterSync === 'REQUIRED') {
                $recount = znews_like_recount($postId);
                if (empty($recount['ok'])) {
                    znews_engagement_fail($claim, 'ZNEWS_LIKE_RECONCILIATION_REQUIRED');
                    return [
                        'ok' => false,
                        'code' => 'ZNEWS_LIKE_RECONCILIATION_REQUIRED',
                        'message' => 'Like was saved but its counter requires reconciliation.',
                        'http_status' => 503,
                    ];
                }
                @fb_patch($path, [
                    'counter_sync_status' => 'OK',
                    'counter_synced_at' => znews_now(),
                ]);
            }

            $result = [
                'liked' => $liked,
                'counts' => znews_engagement_counts($postId),
            ];
            if (!znews_engagement_finish($claim, $result)) {
                return [
                    'ok' => false,
                    'code' => 'ZNEWS_LIKE_FINALIZE_FAILED',
                    'message' => 'Like was saved but the request could not be finalized.',
                    'http_status' => 503,
                ];
            }

            return [
                'ok' => true,
                'idempotent_replay' => true,
                'liked' => $liked,
                'counts' => $result['counts'],
            ];
        }

        $now = znews_now();
        $row = [
            'post_id' => $postId,
            'uid' => $uid,
            'active' => $liked,
            'status' => $liked ? 'ACTIVE' : 'REMOVED',
            'created_at' => (int)($current['created_at'] ?? $now),
            'updated_at' => $now,
            'removed_at' => $liked ? 0 : $now,
            'counter_sync_status' => 'PENDING',
        ];

        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(70000);
            continue;
        }

        if (empty($write['ok'])) {
            znews_engagement_fail($claim, 'ZNEWS_LIKE_WRITE_FAILED');
            return [
                'ok' => false,
                'code' => 'ZNEWS_LIKE_WRITE_FAILED',
                'message' => 'Like status could not be saved.',
                'http_status' => 503,
            ];
        }

        $counter = znews_engagement_adjust_counter(
            $postId,
            'like_count',
            $liked ? 1 : -1
        );

        if (empty($counter['ok'])) {
            @fb_patch($path, [
                'counter_sync_status' => 'REQUIRED',
                'counter_sync_error' => (string)($counter['code'] ?? 'UNKNOWN'),
                'updated_at' => znews_now(),
            ]);
            znews_engagement_finish($claim, [
                'liked' => $liked,
                'counts' => znews_engagement_counts($postId),
                'reconciliation_required' => true,
            ]);

            return [
                'ok' => false,
                'code' => 'ZNEWS_LIKE_RECONCILIATION_REQUIRED',
                'message' => 'Like was saved but its counter requires reconciliation.',
                'http_status' => 503,
                'liked' => $liked,
            ];
        }

        @fb_patch($path, [
            'counter_sync_status' => 'OK',
            'counter_synced_at' => znews_now(),
        ]);

        $result = [
            'liked' => $liked,
            'counts' => (array)$counter['counts'],
        ];
        if (!znews_engagement_finish($claim, $result)) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_LIKE_FINALIZE_FAILED',
                'message' => 'Like was saved but the request could not be finalized.',
                'http_status' => 503,
                'liked' => $liked,
                'counts' => $result['counts'],
            ];
        }

        if (function_exists('system_log')) {
            system_log(
                $liked ? 'ZNEWS_POST_LIKED' : 'ZNEWS_POST_UNLIKED',
                $postId,
                $liked ? 'Z News post liked' : 'Z News post unliked',
                ['post_id' => $postId, 'uid' => $uid]
            );
        }

        return [
            'ok' => true,
            'idempotent_replay' => false,
            'liked' => $liked,
            'counts' => $result['counts'],
        ];
    }

    znews_engagement_fail($claim, 'ZNEWS_LIKE_BUSY');
    return [
        'ok' => false,
        'code' => 'ZNEWS_LIKE_BUSY',
        'message' => 'Like status is busy. Please try again.',
        'http_status' => 409,
    ];
}
