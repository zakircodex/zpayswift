<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/engagement.php';

function znews_share_channel($value): string
{
    $channel = strtoupper(trim((string)$value));
    $allowed = ['FACEBOOK', 'WHATSAPP', 'TELEGRAM', 'COPY_LINK', 'OTHER'];

    if (!in_array($channel, $allowed, true)) {
        api_response(false, 'ZNEWS_SHARE_CHANNEL_INVALID', 'Invalid share channel.', [
            'allowed' => $allowed,
        ], 422);
    }

    return $channel;
}

function znews_share_id(
    string $uid,
    string $postId,
    string $channel,
    int $now
): string {
    $bucket = gmdate('YmdH', $now);
    return 'ZNS' . strtoupper(substr(hash(
        'sha256',
        $uid . '|' . $postId . '|' . $channel . '|' . $bucket
    ), 0, 29));
}

function znews_share_recount(string $postId): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    $rows = fb_get('ZNEWS_SHARES/' . $postId);
    $count = 0;

    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (is_array($row)
                && strtoupper(trim((string)($row['status'] ?? 'RECORDED'))) === 'RECORDED') {
                $count++;
            }
        }
    }

    return znews_engagement_set_counter_exact($postId, 'share_count', $count);
}

function znews_record_share(
    array $auth,
    string $postId,
    string $channel,
    string $idempotencyKey
): array {
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
    $postId = znews_firebase_key($postId, 'post_id');
    $channel = znews_share_channel($channel);
    znews_engagement_require_public_post($postId);

    $claim = znews_engagement_claim(
        $uid,
        $postId,
        'SHARE_CREATE',
        $idempotencyKey,
        ['channel' => $channel]
    );
    if (empty($claim['ok'])) {
        return $claim;
    }
    if (!empty($claim['idempotent_replay'])) {
        return znews_engagement_replay_result(
            $claim,
            'ZNEWS_SHARE_RECONCILIATION_REQUIRED',
            'Share was recorded but its counter requires reconciliation.'
        );
    }

    $now = znews_now();
    $shareId = znews_share_id($uid, $postId, $channel, $now);
    $path = znews_share_path($postId, $shareId);

    $snapshot = fb_get_with_etag($path);
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        znews_engagement_fail($claim, 'ZNEWS_SHARE_READ_FAILED');
        return [
            'ok' => false,
            'code' => 'ZNEWS_SHARE_READ_FAILED',
            'message' => 'Share could not be verified.',
            'http_status' => 503,
        ];
    }

    $existing = $snapshot['value'] ?? null;
    if (is_array($existing)) {
        $counterSync = strtoupper(trim((string)($existing['counter_sync_status'] ?? 'OK')));
        if ($counterSync === 'REQUIRED') {
            $recount = znews_share_recount($postId);
            if (empty($recount['ok'])) {
                znews_engagement_fail($claim, 'ZNEWS_SHARE_RECONCILIATION_REQUIRED');
                return [
                    'ok' => false,
                    'code' => 'ZNEWS_SHARE_RECONCILIATION_REQUIRED',
                    'message' => 'Share was recorded but its counter requires reconciliation.',
                    'http_status' => 503,
                ];
            }
            @fb_patch($path, [
                'counter_sync_status' => 'OK',
                'counter_synced_at' => znews_now(),
            ]);
        }

        $result = [
            'share_id' => $shareId,
            'channel' => $channel,
            'counts' => znews_engagement_counts($postId),
        ];
        znews_engagement_finish($claim, $result);

        return [
            'ok' => true,
            'idempotent_replay' => true,
        ] + $result;
    }

    if ($existing !== null) {
        znews_engagement_fail($claim, 'ZNEWS_SHARE_INVALID_RECORD');
        return [
            'ok' => false,
            'code' => 'ZNEWS_SHARE_INVALID_RECORD',
            'message' => 'Share could not be verified.',
            'http_status' => 409,
        ];
    }

    $row = [
        'share_id' => $shareId,
        'post_id' => $postId,
        'uid' => $uid,
        'channel' => $channel,
        'status' => 'RECORDED',
        'counter_sync_status' => 'PENDING',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
    if ((int)($write['status'] ?? 0) === 412) {
        znews_engagement_fail($claim, 'ZNEWS_SHARE_CONFLICT');
        return [
            'ok' => false,
            'code' => 'ZNEWS_SHARE_CONFLICT',
            'message' => 'Share was recorded by another request.',
            'http_status' => 409,
        ];
    }
    if (empty($write['ok'])) {
        znews_engagement_fail($claim, 'ZNEWS_SHARE_WRITE_FAILED');
        return [
            'ok' => false,
            'code' => 'ZNEWS_SHARE_WRITE_FAILED',
            'message' => 'Share could not be recorded.',
            'http_status' => 503,
        ];
    }

    $counter = znews_engagement_adjust_counter($postId, 'share_count', 1);
    if (empty($counter['ok'])) {
        @fb_patch($path, [
            'counter_sync_status' => 'REQUIRED',
            'counter_sync_error' => (string)($counter['code'] ?? 'UNKNOWN'),
            'updated_at' => znews_now(),
        ]);
        znews_engagement_finish($claim, [
            'share_id' => $shareId,
            'channel' => $channel,
            'counts' => znews_engagement_counts($postId),
            'reconciliation_required' => true,
        ]);

        return [
            'ok' => false,
            'code' => 'ZNEWS_SHARE_RECONCILIATION_REQUIRED',
            'message' => 'Share was recorded but its counter requires reconciliation.',
            'http_status' => 503,
            'share_id' => $shareId,
        ];
    }

    @fb_patch($path, [
        'counter_sync_status' => 'OK',
        'counter_synced_at' => znews_now(),
    ]);

    $result = [
        'share_id' => $shareId,
        'channel' => $channel,
        'counts' => (array)$counter['counts'],
    ];
    if (!znews_engagement_finish($claim, $result)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_SHARE_FINALIZE_FAILED',
            'message' => 'Share was recorded but the request could not be finalized.',
            'http_status' => 503,
        ] + $result;
    }

    if (function_exists('system_log')) {
        system_log('ZNEWS_POST_SHARED', $shareId, 'Z News post shared', [
            'post_id' => $postId,
            'uid' => $uid,
            'channel' => $channel,
        ]);
    }

    return [
        'ok' => true,
        'idempotent_replay' => false,
    ] + $result;
}
