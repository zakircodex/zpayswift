<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/post_media_claims.php';
require_once __DIR__ . '/instant_publish.php';

function znews_update_post_with_media(
    array $auth,
    string $postId,
    string $title,
    bool $titleProvided,
    string $text,
    bool $textProvided,
    bool $mediaProvided,
    string $requestedMediaId,
    string $category,
    bool $categoryProvided,
    int $expectedUpdatedAt,
    string $idempotencyKey
): array {
    $user = is_array($auth['user'] ?? null) ? (array)$auth['user'] : [];
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

    $currentText = (string)($post['text'] ?? '');
    $targetText = $textProvided ? $text : $currentText;
    $currentTitle = (string)($post['title'] ?? '');
    $targetTitle = $titleProvided ? znews_post_validate_title($title) : $currentTitle;
    $currentCategory = strtoupper(trim((string)($post['category'] ?? '')));
    $targetCategory = $categoryProvided ? znews_normalize_category($category, false) : $currentCategory;
    $currentMediaId = trim((string)($post['image_media_id'] ?? ''));
    $targetMediaId = $mediaProvided ? trim($requestedMediaId) : $currentMediaId;
    if ($targetMediaId !== '') {
        $targetMediaId = znews_firebase_key($targetMediaId, 'media_id');
    }

    $content = znews_post_validate_content($targetText, $targetMediaId);
    $text = (string)$content['text'];
    $targetMediaId = (string)$content['media_id'];
    $contentType = (string)$content['content_type'];

    $oldMedia = znews_post_existing_media($uid, $currentMediaId, $postId);
    if ($currentMediaId !== '' && empty($oldMedia['ok'])) {
        return $oldMedia;
    }

    $payloadHash = znews_mutation_payload_hash($uid, $postId, 'UPDATE_CONTENT', [
        'text' => $text,
        'title' => $targetTitle,
        'title_provided' => $titleProvided,
        'text_provided' => $textProvided,
        'media_provided' => $mediaProvided,
        'target_media_id' => $targetMediaId,
        'category' => $targetCategory,
        'category_provided' => $categoryProvided,
        'expected_updated_at' => $expectedUpdatedAt,
    ]);

    $claim = znews_mutation_claim(
        $uid,
        $postId,
        'UPDATE_CONTENT',
        $idempotencyKey,
        $payloadHash
    );

    if (empty($claim['ok'])) {
        return $claim;
    }
    if (!empty($claim['idempotent_replay'])) {
        return znews_mutation_replay_result($claim);
    }

    $newMediaClaim = [];
    $newMediaRow = [];
    if ($targetMediaId !== '' && $targetMediaId !== $currentMediaId) {
        $newMediaClaim = znews_post_media_claim($uid, $targetMediaId, $postId);
        if (empty($newMediaClaim['ok'])) {
            znews_mutation_fail($claim, (string)($newMediaClaim['code'] ?? 'ZNEWS_MEDIA_ATTACH_FAILED'));
            return $newMediaClaim;
        }
        $newMediaRow = (array)($newMediaClaim['claimed'] ?? []);
    } elseif ($targetMediaId !== '' && $currentMediaId !== '') {
        $newMediaRow = (array)($oldMedia['row'] ?? []);
    }

    $now = znews_now();
    $decision = znews_post_publication_decision($newMediaRow, trim($targetTitle . "\n" . $text));
    $updated = $post;
    $updated['schema_version'] = max(5, (int)($post['schema_version'] ?? 1));
    $updated['title'] = $targetTitle;
    $updated['text'] = $text;
    $updated['category'] = $targetCategory;
    $updated['image_media_id'] = $targetMediaId;
    $updated['image_url'] = znews_post_media_public_url($targetMediaId);
    $updated['content_type'] = $contentType;
    $updated['image_width'] = max(0, (int)($newMediaRow['optimized_width'] ?? $newMediaRow['width'] ?? 0));
    $updated['image_height'] = max(0, (int)($newMediaRow['optimized_height'] ?? $newMediaRow['height'] ?? 0));
    $updated['visibility'] = 'PUBLIC';
    $updated['media_duplicate_status'] = $targetMediaId !== ''
        ? strtoupper(trim((string)($newMediaRow['duplicate_status'] ?? 'CLEAR')))
        : 'NONE';
    $updated['updated_at'] = $now;
    $updated['deleted_at'] = 0;
    $updated['last_edit_at'] = $now;
    $updated = znews_apply_publication_decision($updated, $decision, $now);

    $write = fb_put_if_match(
        znews_path_post($postId),
        $updated,
        (string)$owned['etag']
    );

    if ((int)($write['status'] ?? 0) === 412) {
        if ($newMediaClaim) {
            znews_post_media_release_claim($newMediaClaim, 'ZNEWS_POST_VERSION_CONFLICT');
        }
        znews_mutation_fail($claim, 'ZNEWS_POST_VERSION_CONFLICT');

        return [
            'ok' => false,
            'code' => 'ZNEWS_POST_VERSION_CONFLICT',
            'message' => 'This post changed. Reload it before editing.',
            'http_status' => 409,
        ];
    }

    if (empty($write['ok'])) {
        if ($newMediaClaim) {
            znews_post_media_release_claim($newMediaClaim, 'ZNEWS_POST_UPDATE_FAILED');
        }
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
            'status' => (string)$updated['status'],
            'content_type' => $contentType,
            'category' => $targetCategory,
            'has_image' => $targetMediaId !== '',
            'created_at' => (int)($updated['created_at'] ?? $now),
            'updated_at' => $now,
            'published_at' => (int)($updated['published_at'] ?? 0),
        ],
    ];
    $indexUpdates = array_merge($indexUpdates, znews_public_feed_index_updates_for_post($updated));

    if ($newMediaClaim) {
        $attached = znews_post_media_attached_row($newMediaClaim, $postId, $now);
        $attached = znews_apply_media_publication_decision($attached, $decision, $now);
        $indexUpdates[znews_media_path($targetMediaId)] = $attached;
        $indexUpdates[znews_media_owner_path($uid, $targetMediaId)] = [
            'media_id' => $targetMediaId,
            'status' => 'ATTACHED',
            'attached_post_id' => $postId,
            'created_at' => (int)($attached['created_at'] ?? $now),
            'updated_at' => $now,
        ];
    } elseif ($targetMediaId !== '' && !empty($decision['publish'])) {
        $indexUpdates[znews_media_path($targetMediaId)] = znews_apply_media_publication_decision(
            $newMediaRow,
            $decision,
            $now
        );
    }

    if ($currentMediaId !== '' && $currentMediaId !== $targetMediaId) {
        $detached = znews_post_media_detached_row((array)$oldMedia['row'], $postId, $now);
        $indexUpdates[znews_media_path($currentMediaId)] = $detached;
        $indexUpdates[znews_media_owner_path($uid, $currentMediaId)] = [
            'media_id' => $currentMediaId,
            'status' => 'DETACHED',
            'previous_post_id' => $postId,
            'created_at' => (int)($detached['created_at'] ?? $now),
            'updated_at' => $now,
        ];
    }

    $indexOk = fb_patch('', $indexUpdates);

    if (!$indexOk && $newMediaClaim) {
        @fb_patch(znews_media_path($targetMediaId), [
            'status' => 'RECONCILIATION_REQUIRED',
            'attached_post_id' => $postId,
            'failure_code' => 'ZNEWS_POST_MEDIA_INDEX_FAILED',
            'updated_at' => znews_now(),
            'attach_lease_expires_at' => 0,
        ]);
    }

    $formatted = znews_post_format_with_media($updated, true);
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
        system_log('ZNEWS_POST_UPDATED', $postId, 'Z Sky 24 post content updated', [
            'uid' => $uid,
            'post_id' => $postId,
            'content_type' => $contentType,
            'old_media_id' => $currentMediaId,
            'new_media_id' => $targetMediaId,
            'status' => (string)$updated['status'],
            'publication_mode' => (string)($updated['publication_mode'] ?? ''),
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
