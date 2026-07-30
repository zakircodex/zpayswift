<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/moderation.php';

function znews_admin_block_published_post(
    array $auth,
    string $postId,
    int $expectedUpdatedAt,
    string $idempotencyKey,
    string $copyrightVerdict,
    string $note
): array {
    $admin = znews_admin_identity($auth);
    $adminUid = (string)$admin['uid'];
    $postId = znews_firebase_key($postId, 'post_id');
    $payloadHash = znews_admin_payload_hash($adminUid, $postId, 'REJECT', [
        'expected_updated_at' => $expectedUpdatedAt,
        'copyright_verdict' => $copyrightVerdict,
        'note' => $note,
        'mode' => 'POST_PUBLICATION_BLOCK',
    ]);

    $claim = znews_admin_action_claim(
        $adminUid,
        $postId,
        'REJECT',
        $idempotencyKey,
        $payloadHash
    );
    if (empty($claim['ok'])) {
        return $claim;
    }
    if (!empty($claim['idempotent_replay'])) {
        return znews_admin_replay($claim);
    }

    $snapshot = fb_get_with_etag(znews_path_post($postId));
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        znews_admin_action_fail($claim, 'ZNEWS_POST_READ_FAILED');
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_READ_FAILED',
            'message' => 'Post could not be loaded.',
            'http_status' => 503,
        ];
    }

    $post = $snapshot['value'] ?? null;
    if (!is_array($post)) {
        znews_admin_action_fail($claim, 'ZNEWS_POST_NOT_FOUND');
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_NOT_FOUND',
            'message' => 'Post not found.',
            'http_status' => 404,
        ];
    }

    $status = znews_normalize_status($post['status'] ?? '', '');
    $currentUpdatedAt = (int)($post['updated_at'] ?? 0);
    if ($status !== 'ACTIVE' || (int)($post['deleted_at'] ?? 0) > 0) {
        znews_admin_action_fail($claim, 'ZNEWS_POST_NOT_ACTIVE');
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_NOT_ACTIVE',
            'message' => 'This post is not currently public.',
            'http_status' => 409,
        ];
    }
    if ($expectedUpdatedAt <= 0 || $expectedUpdatedAt !== $currentUpdatedAt) {
        znews_admin_action_fail($claim, 'ZNEWS_POST_VERSION_CONFLICT');
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_VERSION_CONFLICT',
            'message' => 'This post changed. Reload it before blocking.',
            'http_status' => 409,
            'data' => ['current_updated_at' => $currentUpdatedAt],
        ];
    }

    $now = znews_now();
    $actionId = (string)($claim['action_id'] ?? znews_make_id('ZMA'));
    $checkId = 'ZCC' . strtoupper(substr(hash('sha256', $actionId . '|COPYRIGHT'), 0, 29));
    $updated = $post;
    $updated['status'] = 'BLOCKED';
    $updated['moderation_status'] = 'REJECTED';
    $updated['copyright_status'] = $copyrightVerdict;
    $updated['moderation_note'] = $note;
    $updated['reviewed_by_uid'] = $adminUid;
    $updated['reviewed_by_name'] = (string)$admin['name'];
    $updated['reviewed_at'] = $now;
    $updated['blocked_at'] = $now;
    $updated['post_publication_action'] = 'BLOCKED';
    $updated['updated_at'] = $now;

    $write = fb_put_if_match(znews_path_post($postId), $updated, (string)$snapshot['etag']);
    if ((int)($write['status'] ?? 0) === 412) {
        znews_admin_action_fail($claim, 'ZNEWS_POST_VERSION_CONFLICT');
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_VERSION_CONFLICT',
            'message' => 'This post changed. Reload it before blocking.',
            'http_status' => 409,
        ];
    }
    if (empty($write['ok'])) {
        znews_admin_action_fail($claim, 'ZNEWS_MODERATION_WRITE_FAILED');
        return [
            'ok' => false,
            'code' => 'ZNEWS_MODERATION_WRITE_FAILED',
            'message' => 'Post could not be blocked.',
            'http_status' => 503,
        ];
    }

    $creatorUid = znews_firebase_key((string)($updated['creator_uid'] ?? ''), 'creator_uid');
    $actionRow = [
        'action_id' => $actionId,
        'post_id' => $postId,
        'action' => 'REJECT',
        'mode' => 'POST_PUBLICATION_BLOCK',
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
        'method' => 'ADMIN_POST_PUBLICATION_REVIEW',
        'reviewed_by_uid' => $adminUid,
        'reviewed_by_name' => (string)$admin['name'],
        'note' => $note,
        'created_at' => $now,
    ];

    $indexOk = fb_patch('', [
        znews_path_user_post($creatorUid, $postId) => [
            'post_id' => $postId,
            'status' => 'BLOCKED',
            'created_at' => (int)($updated['created_at'] ?? $now),
            'updated_at' => $now,
            'published_at' => (int)($updated['published_at'] ?? 0),
            'blocked_at' => $now,
        ],
        znews_path_public_feed($postId) => null,
        znews_path_moderation_action($postId, $actionId) => $actionRow,
        znews_path_copyright_check($postId, $checkId) => $copyrightRow,
    ]);

    $formatted = znews_format_owned_post($updated);
    $result = [
        'post' => $formatted,
        'post_publication_block' => true,
        'reconciliation_required' => !$indexOk,
    ];
    if (!znews_admin_action_finish($claim, $result)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_MODERATION_FINALIZE_FAILED',
            'message' => 'Post was blocked but the request could not be finalized.',
            'http_status' => 503,
            'post' => $formatted,
        ];
    }

    if (function_exists('system_log')) {
        system_log('ZNEWS_POST_BLOCKED_AFTER_PUBLICATION', $postId, 'Published Z Sky 24 post blocked', [
            'post_id' => $postId,
            'creator_uid' => $creatorUid,
            'admin_uid' => $adminUid,
            'copyright_verdict' => $copyrightVerdict,
        ]);
    }

    if (!$indexOk) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_MODERATION_RECONCILIATION_REQUIRED',
            'message' => 'Post was blocked but its indexes require reconciliation.',
            'http_status' => 503,
            'post' => $formatted,
        ];
    }

    return [
        'ok' => true,
        'post' => $formatted,
        'post_publication_block' => true,
        'idempotent_replay' => false,
    ];
}
