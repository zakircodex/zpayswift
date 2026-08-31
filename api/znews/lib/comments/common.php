<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_comment_path(string $postId, string $commentId): string
{
    return 'ZNEWS_COMMENTS/'
        . znews_firebase_key($postId, 'post_id')
        . '/'
        . znews_firebase_key($commentId, 'comment_id');
}

function znews_comment_user_index_path(string $uid, string $commentId): string
{
    return 'ZNEWS_USER_COMMENTS/'
        . znews_firebase_key($uid, 'uid')
        . '/'
        . znews_firebase_key($commentId, 'comment_id');
}

function znews_comment_review_queue_path(string $commentId): string
{
    return 'ZNEWS_COMMENT_REVIEW_QUEUE/'
        . znews_firebase_key($commentId, 'comment_id');
}

function znews_comment_action_path(string $commentId, string $actionId): string
{
    return 'ZNEWS_COMMENT_MODERATION_ACTIONS/'
        . znews_firebase_key($commentId, 'comment_id')
        . '/'
        . znews_firebase_key($actionId, 'action_id');
}

function znews_comment_text($value, int $minimum = 1, int $maximum = 1000): string
{
    $text = znews_normalize_text($value);
    $length = znews_text_length($text);

    if ($length < $minimum) {
        api_response(false, 'ZNEWS_COMMENT_REQUIRED', 'Comment is required.', [], 422);
    }
    if ($length > $maximum) {
        api_response(false, 'ZNEWS_COMMENT_TOO_LONG', 'Comment is too long.', [
            'max_length' => $maximum,
        ], 422);
    }

    return $text;
}

function znews_comment_moderation_note(
    $value,
    bool $required = false,
    int $maximum = 500
): string {
    $note = znews_normalize_text($value);
    $length = znews_text_length($note);

    if ($required && $length < 3) {
        api_response(false, 'ZNEWS_COMMENT_REASON_REQUIRED', 'A moderation reason is required.', [], 422);
    }
    if ($length > $maximum) {
        api_response(false, 'ZNEWS_COMMENT_REASON_TOO_LONG', 'Moderation note is too long.', [
            'max_length' => $maximum,
        ], 422);
    }

    return $note;
}

function znews_comment_id(string $uid, string $idempotencyKey): string
{
    return 'ZNC' . strtoupper(substr(hash(
        'sha256',
        $uid . '|' . trim($idempotencyKey)
    ), 0, 29));
}

function znews_comment_is_public(array $comment): bool
{
    return strtoupper(trim((string)($comment['status'] ?? ''))) === 'ACTIVE'
        && strtoupper(trim((string)($comment['moderation_status'] ?? ''))) === 'APPROVED'
        && (int)($comment['deleted_at'] ?? 0) === 0;
}

function znews_comment_format(array $comment, bool $ownerView = false): array
{
    $out = [
        'comment_id' => trim((string)($comment['comment_id'] ?? '')),
        'post_id' => trim((string)($comment['post_id'] ?? '')),
        'author_uid' => trim((string)($comment['author_uid'] ?? '')),
        'author_name' => trim((string)($comment['author_name'] ?? 'Z-Pay User')),
        'author_photo_url' => trim((string)($comment['author_photo_url'] ?? '')),
        'text' => (string)($comment['text'] ?? ''),
        'created_at' => max(0, (int)($comment['created_at'] ?? 0)),
        'updated_at' => max(0, (int)($comment['updated_at'] ?? 0)),
    ];

    if ($ownerView) {
        $status = strtoupper(trim((string)($comment['status'] ?? 'REVIEW')));
        $out['status'] = $status;
        $out['moderation_status'] = strtoupper(trim((string)($comment['moderation_status'] ?? 'PENDING')));
        $out['deleted_at'] = max(0, (int)($comment['deleted_at'] ?? 0));
        $out['moderation_note'] = trim((string)($comment['moderation_note'] ?? ''));
        $out['can_edit'] = in_array($status, ['ACTIVE', 'REVIEW'], true);
        $out['can_delete'] = $status !== 'DELETED';
    }

    return $out;
}

function znews_comment_cursor_encode(int $createdAt, string $commentId): string
{
    $payload = json_encode([
        'created_at' => max(0, $createdAt),
        'comment_id' => $commentId,
    ], JSON_UNESCAPED_SLASHES);

    return is_string($payload)
        ? rtrim(strtr(base64_encode($payload), '+/', '-_'), '=')
        : '';
}

function znews_comment_cursor_decode($value): array
{
    $cursor = trim((string)$value);
    if ($cursor === '') {
        return [];
    }
    if (strlen($cursor) > 512 || preg_match('/[^A-Za-z0-9_-]/', $cursor) === 1) {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }

    $padding = strlen($cursor) % 4;
    if ($padding > 0) {
        $cursor .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
    $data = is_string($decoded) ? json_decode($decoded, true) : null;
    if (!is_array($data)) {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }

    $createdAt = filter_var($data['created_at'] ?? null, FILTER_VALIDATE_INT);
    $commentId = trim((string)($data['comment_id'] ?? ''));
    if ($createdAt === false || $createdAt < 0 || $commentId === '') {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }

    return [
        'created_at' => (int)$createdAt,
        'comment_id' => znews_firebase_key($commentId, 'cursor comment_id'),
    ];
}

function znews_comment_after_cursor(array $comment, array $cursor): bool
{
    if (!$cursor) {
        return true;
    }

    $createdAt = (int)($comment['created_at'] ?? 0);
    $commentId = (string)($comment['comment_id'] ?? '');
    $cursorTime = (int)($cursor['created_at'] ?? 0);
    $cursorId = (string)($cursor['comment_id'] ?? '');

    return $createdAt > $cursorTime
        || ($createdAt === $cursorTime && strcmp($commentId, $cursorId) > 0);
}

function znews_comment_recount(string $postId): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    $rows = fb_get('ZNEWS_COMMENTS/' . $postId);
    $count = 0;

    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (is_array($row) && znews_comment_is_public($row)) {
                $count++;
            }
        }
    }

    return znews_engagement_set_counter_exact($postId, 'comment_count', $count);
}

function znews_comment_owner_snapshot(
    string $uid,
    string $postId,
    string $commentId,
    bool $allowDeleted = false
): array {
    $uid = znews_firebase_key($uid, 'uid');
    $postId = znews_firebase_key($postId, 'post_id');
    $commentId = znews_firebase_key($commentId, 'comment_id');
    $snapshot = fb_get_with_etag(znews_comment_path($postId, $commentId));

    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        api_response(false, 'ZNEWS_COMMENT_READ_FAILED', 'Comment could not be loaded.', [], 503);
    }

    $comment = $snapshot['value'] ?? null;
    if (!is_array($comment)) {
        api_response(false, 'ZNEWS_COMMENT_NOT_FOUND', 'Comment not found.', [], 404);
    }

    $authorUid = trim((string)($comment['author_uid'] ?? ''));
    if ($authorUid === '' || !hash_equals($authorUid, $uid)) {
        api_response(false, 'ZNEWS_COMMENT_NOT_FOUND', 'Comment not found.', [], 404);
    }

    if (!$allowDeleted
        && strtoupper(trim((string)($comment['status'] ?? ''))) === 'DELETED') {
        api_response(false, 'ZNEWS_COMMENT_NOT_FOUND', 'Comment not found.', [], 404);
    }

    return [
        'comment' => $comment,
        'etag' => (string)$snapshot['etag'],
    ];
}
