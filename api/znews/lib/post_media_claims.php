<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/post_media_common.php';

function znews_post_media_claim(
    string $uid,
    string $mediaId,
    string $postId
): array {
    $uid = znews_firebase_key($uid, 'uid');
    $mediaId = znews_firebase_key($mediaId, 'media_id');
    $postId = znews_firebase_key($postId, 'post_id');
    $path = znews_media_path($mediaId);
    $now = znews_now();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_MEDIA_READ_FAILED',
                'message' => 'Image could not be loaded.',
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

        $ownerUid = trim((string)($row['owner_uid'] ?? ''));
        if ($ownerUid === '' || !hash_equals($ownerUid, $uid)) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_MEDIA_NOT_FOUND',
                'message' => 'Image not found.',
                'http_status' => 404,
            ];
        }

        $status = strtoupper(trim((string)($row['status'] ?? '')));
        $attachedPostId = trim((string)($row['attached_post_id'] ?? ''));
        $attachingPostId = trim((string)($row['attaching_post_id'] ?? ''));
        $leaseExpiresAt = (int)($row['attach_lease_expires_at'] ?? 0);

        if (
            $status === 'ATTACHING'
            && $attachingPostId === $postId
            && $leaseExpiresAt > $now
        ) {
            return [
                'ok' => true,
                'idempotent_replay' => true,
                'path' => $path,
                'original' => $row,
                'claimed' => $row,
                'etag' => (string)$snapshot['etag'],
            ];
        }

        $staleSamePostClaim = $status === 'ATTACHING'
            && $attachingPostId === $postId
            && $leaseExpiresAt <= $now;

        if (
            (!$staleSamePostClaim && $status !== 'STAGED')
            || $attachedPostId !== ''
            || (int)($row['deleted_at'] ?? 0) > 0
        ) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_MEDIA_NOT_AVAILABLE',
                'message' => 'This image is not available for a new post.',
                'http_status' => 409,
            ];
        }

        $claimed = $row;
        $claimed['pre_attach_status'] = 'STAGED';
        $claimed['status'] = 'ATTACHING';
        $claimed['attaching_post_id'] = $postId;
        $claimed['attach_lease_expires_at'] = $now + 90;
        $claimed['updated_at'] = $now;

        $write = fb_put_if_match($path, $claimed, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(100000);
            continue;
        }

        if (empty($write['ok'])) {
            return [
                'ok' => false,
                'code' => 'ZNEWS_MEDIA_ATTACH_CLAIM_FAILED',
                'message' => 'Image could not be reserved for this post.',
                'http_status' => 503,
            ];
        }

        return [
            'ok' => true,
            'idempotent_replay' => false,
            'path' => $path,
            'original' => $row,
            'claimed' => $claimed,
            'etag' => (string)($write['headers']['etag'] ?? ''),
        ];
    }

    return [
        'ok' => false,
        'code' => 'ZNEWS_MEDIA_ATTACH_BUSY',
        'message' => 'Image is busy. Please try again.',
        'http_status' => 409,
    ];
}

function znews_post_media_release_claim(array $claim, string $failureCode): void
{
    $path = trim((string)($claim['path'] ?? ''));
    $original = is_array($claim['original'] ?? null) ? (array)$claim['original'] : [];
    $etag = trim((string)($claim['etag'] ?? ''));

    if ($path === '' || !$original) {
        return;
    }

    $releasedRow = $original;
    $releasedRow['status'] = 'STAGED';
    $releasedRow['attached_post_id'] = '';
    $releasedRow['updated_at'] = znews_now();
    $releasedRow['attach_lease_expires_at'] = 0;
    unset(
        $releasedRow['attaching_post_id'],
        $releasedRow['pre_attach_status'],
        $releasedRow['failure_code']
    );

    $released = false;
    if ($etag !== '') {
        $write = fb_put_if_match($path, $releasedRow, $etag);
        $released = !empty($write['ok']);
    }

    if (!$released) {
        @fb_patch($path, [
            'status' => 'RECONCILIATION_REQUIRED',
            'failure_code' => $failureCode,
            'updated_at' => znews_now(),
            'attach_lease_expires_at' => 0,
        ]);
    }
}

function znews_post_media_attached_row(array $claim, string $postId, int $now): array
{
    $row = is_array($claim['claimed'] ?? null) ? (array)$claim['claimed'] : [];
    $row['status'] = 'ATTACHED';
    $row['attached_post_id'] = $postId;
    $row['attached_at'] = $now;
    $row['updated_at'] = $now;
    $row['attach_lease_expires_at'] = 0;
    unset($row['attaching_post_id'], $row['pre_attach_status']);

    return $row;
}

function znews_post_media_detached_row(array $row, string $postId, int $now): array
{
    $row['status'] = 'DETACHED';
    $row['previous_post_id'] = $postId;
    $row['attached_post_id'] = '';
    $row['detached_at'] = $now;
    $row['updated_at'] = $now;
    $row['attach_lease_expires_at'] = 0;
    unset($row['attaching_post_id']);

    return $row;
}

function znews_post_existing_media(
    string $uid,
    string $mediaId,
    string $postId
): array {
    if ($mediaId === '') {
        return [];
    }

    $row = fb_get(znews_media_path($mediaId));
    if (!is_array($row)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_MEDIA_RECONCILIATION_REQUIRED',
            'message' => 'The post image record is missing.',
            'http_status' => 503,
        ];
    }

    $ownerUid = trim((string)($row['owner_uid'] ?? ''));
    $attachedPostId = trim((string)($row['attached_post_id'] ?? ''));
    if (
        $ownerUid === ''
        || !hash_equals($ownerUid, $uid)
        || $attachedPostId === ''
        || !hash_equals($attachedPostId, $postId)
    ) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_MEDIA_RECONCILIATION_REQUIRED',
            'message' => 'The post image attachment is inconsistent.',
            'http_status' => 503,
        ];
    }

    return ['ok' => true, 'row' => $row];
}
