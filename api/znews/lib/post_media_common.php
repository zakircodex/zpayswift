<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/media.php';
require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/post_access.php';
require_once __DIR__ . '/post_mutations.php';
require_once __DIR__ . '/categories.php';

function znews_post_media_public_url(string $mediaId): string
{
    $mediaId = trim($mediaId);
    if ($mediaId === '') {
        return '';
    }

    $base = function_exists('app_api_base_path') ? app_api_base_path() : '/api';
    return $base . '/znews/public/media.php?media_id=' . rawurlencode($mediaId);
}

function znews_post_media_private_url(string $mediaId): string
{
    $mediaId = trim($mediaId);
    if ($mediaId === '') {
        return '';
    }

    $base = function_exists('app_api_base_path') ? app_api_base_path() : '/api';
    return $base . '/znews/media/content.php?media_id=' . rawurlencode($mediaId);
}

function znews_post_media_admin_url(string $mediaId): string
{
    $mediaId = trim($mediaId);
    if ($mediaId === '') {
        return '';
    }

    $base = function_exists('app_api_base_path') ? app_api_base_path() : '/api';
    return $base . '/admin/znews/media.php?media_id=' . rawurlencode($mediaId);
}

function znews_post_validate_content($textValue, $mediaValue): array
{
    $text = znews_normalize_text($textValue);
    if (znews_text_length($text) > 5000) {
        api_response(
            false,
            'ZNEWS_POST_TEXT_TOO_LONG',
            'Post text must not exceed 5000 characters.',
            ['max_length' => 5000],
            422
        );
    }

    $mediaId = trim((string)$mediaValue);
    if ($mediaId !== '') {
        $mediaId = znews_firebase_key($mediaId, 'media_id');
    }

    if ($text === '' && $mediaId === '') {
        api_response(
            false,
            'ZNEWS_POST_CONTENT_REQUIRED',
            'Post text or image is required.',
            [],
            422
        );
    }

    $contentType = $mediaId === ''
        ? 'TEXT'
        : ($text === '' ? 'IMAGE' : 'TEXT_IMAGE');

    return [
        'text' => $text,
        'media_id' => $mediaId,
        'content_type' => $contentType,
    ];
}

function znews_post_validate_title($value): string
{
    $title = znews_normalize_text($value);
    $title = trim((string)preg_replace('/\s+/u', ' ', $title));
    if (znews_text_length($title) > 160) {
        api_response(
            false,
            'ZNEWS_POST_TITLE_TOO_LONG',
            'Post title must not exceed 160 characters.',
            ['max_length' => 160],
            422
        );
    }
    return $title;
}

function znews_post_media_payload_hash(
    string $uid,
    string $title,
    string $text,
    array $boldRanges,
    array $formattingRuns,
    string $mediaId,
    string $contentType,
    string $category = ''
): string {
    return hash('sha256', json_encode([
        'uid' => $uid,
        'title' => $title,
        'text' => $text,
        'bold_ranges' => $boldRanges,
        'formatting_runs' => $formattingRuns,
        'media_id' => $mediaId,
        'content_type' => $contentType,
        'category' => $category,
        'visibility' => 'PUBLIC',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function znews_post_create_claim_v2(
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
                        'idempotent_replay' => true,
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

            $leaseExpiresAt = (int)($existing['lease_expires_at'] ?? 0);
            $updatedAt = (int)($existing['updated_at'] ?? 0);
            $legacyFreshUntil = $updatedAt > 0 ? $updatedAt + 120 : 0;
            if (
                $status === 'PROCESSING'
                && (
                    $leaseExpiresAt > $now
                    || ($leaseExpiresAt <= 0 && $legacyFreshUntil > $now)
                )
            ) {
                return [
                    'ok' => false,
                    'code' => 'ZNEWS_POST_CREATE_IN_PROGRESS',
                    'message' => 'This post request is already being processed.',
                    'http_status' => 409,
                ];
            }

            if (!in_array($status, ['FAILED', 'PROCESSING'], true)) {
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
            'lease_expires_at' => $now + 90,
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
            'idempotent_replay' => false,
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

function znews_post_format_with_media(array $post, bool $owned = false, bool $admin = false): array
{
    $formatted = $owned ? znews_format_owned_post($post) : znews_format_public_post($post);
    $mediaId = trim((string)($post['image_media_id'] ?? ''));

    $formatted['image_media_id'] = $mediaId;
    $formatted['image_url'] = $mediaId !== ''
        ? znews_post_media_public_url($mediaId)
        : '';
    $formatted['image_width'] = max(0, (int)($post['image_width'] ?? 0));
    $formatted['image_height'] = max(0, (int)($post['image_height'] ?? 0));
    $formatted['media_duplicate_status'] = strtoupper(trim((string)(
        $post['media_duplicate_status'] ?? 'NONE'
    )));

    if ($owned) {
        $formatted['image_preview_url'] = $mediaId !== ''
            ? znews_post_media_private_url($mediaId)
            : '';
    }

    if ($admin) {
        $formatted['image_review_url'] = $mediaId !== ''
            ? znews_post_media_admin_url($mediaId)
            : '';
    }

    return $formatted;
}

function znews_post_create_claim_fail(array $claim, string $code): void
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
