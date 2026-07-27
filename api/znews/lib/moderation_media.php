<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/moderation.php';
require_once __DIR__ . '/media_policy.php';

function znews_admin_moderate_post_with_media(
    array $auth,
    string $postId,
    int $expectedUpdatedAt,
    string $idempotencyKey,
    string $action,
    string $copyrightVerdict,
    string $note
): array {
    $result = znews_admin_moderate_post(
        $auth,
        $postId,
        $expectedUpdatedAt,
        $idempotencyKey,
        $action,
        $copyrightVerdict,
        $note
    );

    if (empty($result['ok'])) {
        return $result;
    }

    $postId = znews_firebase_key($postId, 'post_id');
    $rawPost = fb_get(znews_path_post($postId));
    if (!is_array($rawPost)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_RECONCILIATION_REQUIRED',
            'message' => 'Moderation was saved but the post could not be reloaded.',
            'http_status' => 503,
            'post' => is_array($result['post'] ?? null) ? (array)$result['post'] : [],
        ];
    }

    $mediaId = trim((string)($rawPost['image_media_id'] ?? ''));
    if ($mediaId === '') {
        $result['post'] = znews_post_format_with_media($rawPost, true, true);
        return $result;
    }

    $admin = znews_admin_identity($auth);
    $mediaResult = znews_media_apply_moderation(
        $postId,
        $mediaId,
        $action,
        $copyrightVerdict,
        $admin
    );

    if (empty($mediaResult['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($mediaResult['code'] ?? 'ZNEWS_MEDIA_MODERATION_FAILED'),
            'message' => (string)($mediaResult['message'] ?? 'Post was moderated but its image requires reconciliation.'),
            'http_status' => (int)($mediaResult['http_status'] ?? 503),
            'post' => znews_post_format_with_media($rawPost, true, true),
            'data' => [
                'post_moderation_saved' => true,
                'media_id' => $mediaId,
            ],
        ];
    }

    $result['post'] = znews_post_format_with_media($rawPost, true, true);
    $result['media_moderation_replay'] = !empty($mediaResult['idempotent_replay']);

    if (function_exists('system_log')) {
        system_log('ZNEWS_MEDIA_' . strtoupper($action), $mediaId, 'Z News image moderation synced', [
            'post_id' => $postId,
            'media_id' => $mediaId,
            'admin_uid' => (string)($admin['uid'] ?? ''),
            'copyright_verdict' => $copyrightVerdict,
        ]);
    }

    return $result;
}
