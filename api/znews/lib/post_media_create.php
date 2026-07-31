<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/post_media_claims.php';
require_once __DIR__ . '/instant_publish.php';

function znews_create_post_with_media(
    array $auth,
    string $title,
    string $text,
    string $mediaId,
    string $contentType,
    string $idempotencyKey
): array {
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
    $uid = znews_firebase_key((string)($user['uid'] ?? ''), 'uid');
    $creator = znews_public_creator_snapshot($user);
    $payloadHash = znews_post_media_payload_hash($uid, $title, $text, $mediaId, $contentType);
    $postId = znews_deterministic_post_id($uid, $idempotencyKey);

    $claim = znews_post_create_claim_v2($uid, $idempotencyKey, $payloadHash, $postId);
    if (empty($claim['ok'])) {
        return $claim;
    }

    if (!empty($claim['idempotent_replay']) && is_array($claim['post'] ?? null)) {
        return [
            'ok' => true,
            'idempotent_replay' => true,
            'post' => znews_post_format_with_media((array)$claim['post'], true),
        ];
    }

    $mediaClaim = [];
    $mediaRow = [];
    if ($mediaId !== '') {
        $mediaClaim = znews_post_media_claim($uid, $mediaId, $postId);
        if (empty($mediaClaim['ok'])) {
            znews_post_create_claim_fail($claim, (string)($mediaClaim['code'] ?? 'ZNEWS_MEDIA_ATTACH_FAILED'));
            return $mediaClaim;
        }
        $mediaRow = (array)($mediaClaim['claimed'] ?? []);
    }

    $now = znews_now();
    $decision = znews_post_publication_decision($mediaRow, trim($title . "\n" . $text));
    $post = [
        'schema_version' => 4,
        'post_id' => $postId,
        'creator_uid' => $uid,
        'creator_name' => (string)($creator['name'] ?? 'Z-Pay User'),
        'creator_photo_url' => (string)($creator['profile_photo_url'] ?? ''),
        'title' => $title,
        'text' => $text,
        'image_media_id' => $mediaId,
        'image_url' => znews_post_media_public_url($mediaId),
        'content_type' => $contentType,
        'visibility' => 'PUBLIC',
        'media_duplicate_status' => $mediaId !== ''
            ? strtoupper(trim((string)($mediaRow['duplicate_status'] ?? 'CLEAR')))
            : 'NONE',
        'like_count' => 0,
        'comment_count' => 0,
        'share_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => 0,
        'source' => 'ZPAY_API',
    ];
    $post = znews_apply_publication_decision($post, $decision, $now);

    $index = [
        'post_id' => $postId,
        'status' => (string)$post['status'],
        'content_type' => $contentType,
        'has_image' => $mediaId !== '',
        'created_at' => $now,
        'updated_at' => $now,
        'published_at' => (int)($post['published_at'] ?? 0),
    ];

    $completedClaim = array_merge((array)($claim['claim'] ?? []), [
        'status' => 'COMPLETED',
        'completed_at' => $now,
        'updated_at' => $now,
        'lease_expires_at' => 0,
    ]);

    $updates = [
        znews_path_post($postId) => $post,
        znews_path_user_post($uid, $postId) => $index,
        znews_path_public_feed($postId) => znews_public_feed_index_for_post($post),
        (string)$claim['path'] => $completedClaim,
    ];

    if ($mediaId !== '') {
        $attached = znews_post_media_attached_row($mediaClaim, $postId, $now);
        $attached = znews_apply_media_publication_decision($attached, $decision, $now);
        $updates[znews_media_path($mediaId)] = $attached;
        $updates[znews_media_owner_path($uid, $mediaId)] = [
            'media_id' => $mediaId,
            'status' => 'ATTACHED',
            'attached_post_id' => $postId,
            'created_at' => (int)($attached['created_at'] ?? $now),
            'updated_at' => $now,
        ];
    }

    if (!fb_patch('', $updates)) {
        if ($mediaClaim) {
            znews_post_media_release_claim($mediaClaim, 'ZNEWS_POST_CREATE_FAILED');
        }
        znews_post_create_claim_fail($claim, 'ZNEWS_POST_CREATE_FAILED');

        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_CREATE_FAILED',
            'message' => 'Post could not be created.',
            'http_status' => 503,
        ];
    }

    if (function_exists('system_log')) {
        system_log('ZNEWS_POST_CREATED', $postId, 'Z Sky 24 post created', [
            'uid' => $uid,
            'post_id' => $postId,
            'content_type' => $contentType,
            'media_id' => $mediaId,
            'status' => (string)$post['status'],
            'publication_mode' => (string)($post['publication_mode'] ?? ''),
        ]);
    }

    return [
        'ok' => true,
        'idempotent_replay' => false,
        'post' => znews_post_format_with_media($post, true),
    ];
}
