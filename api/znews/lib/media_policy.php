<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/media.php';
require_once __DIR__ . '/post_media_attach.php';

function znews_media_public_approved_verdicts(): array
{
    return ['CLEAR', 'ORIGINAL_CONFIRMED', 'LICENSED'];
}

function znews_media_public_record_strict(string $mediaId): array
{
    $row = znews_media_public_record($mediaId);
    $moderationStatus = strtoupper(trim((string)($row['moderation_status'] ?? '')));
    $copyrightStatus = strtoupper(trim((string)($row['copyright_status'] ?? '')));

    if (
        $moderationStatus !== 'APPROVED'
        || !in_array($copyrightStatus, znews_media_public_approved_verdicts(), true)
    ) {
        api_response(false, 'ZNEWS_MEDIA_NOT_FOUND', 'Image not found.', [], 404);
    }

    return $row;
}

function znews_media_apply_moderation(
    string $postId,
    string $mediaId,
    string $action,
    string $copyrightVerdict,
    array $admin
): array {
    $postId = znews_firebase_key($postId, 'post_id');
    $mediaId = znews_firebase_key($mediaId, 'media_id');
    $action = strtoupper(trim($action));
    $approved = $action === 'APPROVE';

    if (!in_array($action, ['APPROVE', 'REJECT'], true)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_INVALID_ACTION',
            'message' => 'Invalid media moderation action.',
            'http_status' => 422,
        ];
    }

    $path = znews_media_path($mediaId);
    $now = znews_now();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_MEDIA_READ_FAILED',
                'message' => 'Image moderation record could not be loaded.',
                'http_status' => 503,
            ];
        }

        $row = $snapshot['value'] ?? null;
        if (!is_array($row)) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_MEDIA_NOT_FOUND',
                'message' => 'Image not found.',
                'http_status' => 404,
            ];
        }

        $attachedPostId = trim((string)($row['attached_post_id'] ?? ''));
        if (
            strtoupper(trim((string)($row['status'] ?? ''))) !== 'ATTACHED'
            || $attachedPostId === ''
            || !hash_equals($attachedPostId, $postId)
            || (int)($row['deleted_at'] ?? 0) > 0
        ) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_MEDIA_RECONCILIATION_REQUIRED',
                'message' => 'Image attachment requires reconciliation.',
                'http_status' => 503,
            ];
        }

        $targetModeration = $approved ? 'APPROVED' : 'REJECTED';
        $currentModeration = strtoupper(trim((string)($row['moderation_status'] ?? '')));
        $currentCopyright = strtoupper(trim((string)($row['copyright_status'] ?? '')));

        if (
            $currentModeration === $targetModeration
            && $currentCopyright === $copyrightVerdict
        ) {
            return ['ok' => true, 'idempotent_replay' => true, 'media' => $row];
        }

        $updated = $row;
        $updated['moderation_status'] = $targetModeration;
        $updated['copyright_status'] = $copyrightVerdict;
        $updated['reviewed_by_uid'] = trim((string)($admin['uid'] ?? ''));
        $updated['reviewed_by_name'] = trim((string)($admin['name'] ?? 'Z-Pay Admin'));
        $updated['reviewed_at'] = $now;
        $updated['updated_at'] = $now;

        $write = fb_put_if_match($path, $updated, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(100000);
            continue;
        }

        if (empty($write['ok'])) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_MEDIA_MODERATION_WRITE_FAILED',
                'message' => 'Image moderation decision could not be saved.',
                'http_status' => 503,
            ];
        }

        return ['ok' => true, 'idempotent_replay' => false, 'media' => $updated];
    }

    return [
        'ok' => false,
        'code' => 'ZNEWS_MEDIA_VERSION_CONFLICT',
        'message' => 'Image changed during moderation.',
        'http_status' => 409,
    ];
}
