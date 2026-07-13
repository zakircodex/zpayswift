<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function notification_now(): int
{
    return function_exists('now_ts') ? now_ts() : time();
}

function notification_clean_text($value, int $max = 500): string
{
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $text) ?? $text;
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max);
    }
    return substr($text, 0, $max);
}

function notification_clean_code($value, int $max = 80): string
{
    $code = strtoupper(trim((string)$value));
    $code = preg_replace('/[^A-Z0-9_:-]+/', '_', $code) ?? '';
    return substr($code, 0, $max);
}

function notification_category_for_type(string $type): string
{
    $type = notification_clean_code($type);
    if (str_starts_with($type, 'SUPPORT_')) {
        return 'SUPPORT';
    }
    if (str_starts_with($type, 'ACCOUNT_') || $type === 'SECURITY_REVIEW' || $type === 'LOGIN_ALERT') {
        return 'SECURITY';
    }
    return 'TRANSACTIONS';
}

function notification_id_from_key(string $uid, string $idempotencyKey): string
{
    return 'UN' . strtoupper(substr(hash('sha256', $uid . '|' . $idempotencyKey), 0, 24));
}

function notification_record_user(
    string $uid,
    string $type,
    string $title,
    string $body,
    string $entityType = '',
    string $entityId = '',
    string $idempotencyKey = '',
    array $extra = []
): array {
    $uid = trim($uid);
    $type = notification_clean_code($type);
    $title = notification_clean_text($title, 100);
    $body = notification_clean_text($body, 220);
    $entityType = notification_clean_code($entityType, 60);
    $entityId = notification_clean_text($entityId, 120);
    $idempotencyKey = notification_clean_text($idempotencyKey, 180);

    if ($uid === '' || $type === '' || $title === '') {
        return ['ok' => false, 'code' => 'NOTIFICATION_INVALID'];
    }

    if ($idempotencyKey === '') {
        $idempotencyKey = $type . ':' . $entityId . ':' . notification_now();
    }

    $id = notification_id_from_key($uid, $idempotencyKey);
    $path = 'USER_NOTIFICATIONS/' . $uid . '/' . $id;
    $existing = fb_get($path);
    if (is_array($existing)) {
        return ['ok' => true, 'duplicate' => true, 'notification_id' => $id];
    }

    $now = notification_now();
    $row = [
        'notification_id' => $id,
        'uid' => $uid,
        'type' => $type,
        'category' => notification_category_for_type($type),
        'title' => $title,
        'body' => $body,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'is_read' => false,
        'created_at' => $now,
        'read_at' => 0,
        'idempotency_key' => hash('sha256', $idempotencyKey),
    ];

    foreach (['ticket_id', 'message_id', 'transfer_id', 'request_id', 'status'] as $key) {
        if (array_key_exists($key, $extra)) {
            $row[$key] = notification_clean_text($extra[$key], 120);
        }
    }

    if (!fb_put($path, $row)) {
        return ['ok' => false, 'code' => 'NOTIFICATION_SAVE_FAILED'];
    }

    return ['ok' => true, 'notification_id' => $id, 'row' => $row];
}

function notification_public_row(array $row): array
{
    $type = notification_clean_code($row['type'] ?? '');
    $category = notification_clean_code($row['category'] ?? notification_category_for_type($type));
    return [
        'notification_id' => (string)($row['notification_id'] ?? ''),
        'type' => $type,
        'category' => $category,
        'title' => notification_clean_text($row['title'] ?? '', 100),
        'body' => notification_clean_text($row['body'] ?? '', 220),
        'entity_type' => notification_clean_code($row['entity_type'] ?? '', 60),
        'entity_id' => notification_clean_text($row['entity_id'] ?? '', 120),
        'ticket_id' => notification_clean_text($row['ticket_id'] ?? '', 80),
        'message_id' => notification_clean_text($row['message_id'] ?? '', 80),
        'transfer_id' => notification_clean_text($row['transfer_id'] ?? '', 80),
        'request_id' => notification_clean_text($row['request_id'] ?? '', 80),
        'status' => notification_clean_code($row['status'] ?? '', 40),
        'is_read' => !empty($row['is_read']) || !empty($row['read']),
        'created_at' => (int)($row['created_at'] ?? 0),
        'read_at' => (int)($row['read_at'] ?? 0),
    ];
}

function notification_filter_match(array $row, string $filter): bool
{
    $filter = strtoupper(trim($filter));
    if ($filter === '' || $filter === 'ALL') {
        return true;
    }
    $public = notification_public_row($row);
    if ($filter === 'UNREAD') {
        return empty($public['is_read']);
    }
    if ($filter === 'TRANSACTIONS') {
        return $public['category'] === 'TRANSACTIONS';
    }
    return $public['category'] === notification_clean_code($filter);
}

function notification_list_for_user(string $uid, int $limit = 20, int $before = 0, string $filter = 'ALL'): array
{
    $uid = trim($uid);
    if ($uid === '') {
        return [];
    }
    $rows = fb_get('USER_NOTIFICATIONS/' . $uid);
    $rows = is_array($rows) ? $rows : [];
    $cutoff = notification_now() - (366 * 24 * 60 * 60);
    $items = [];
    foreach ($rows as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['notification_id'] = (string)($row['notification_id'] ?? $id);
        $createdAt = (int)($row['created_at'] ?? 0);
        if ($createdAt <= 0 || $createdAt < $cutoff || ($before > 0 && $createdAt >= $before)) {
            continue;
        }
        if (!notification_filter_match($row, $filter)) {
            continue;
        }
        $items[] = notification_public_row($row);
    }
    usort($items, static fn(array $a, array $b): int =>
        ((int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0))
    );
    return array_slice($items, 0, max(1, min(50, $limit)));
}

function notification_unread_count(string $uid): int
{
    $rows = fb_get('USER_NOTIFICATIONS/' . trim($uid));
    if (!is_array($rows)) {
        return 0;
    }
    $count = 0;
    $cutoff = notification_now() - (366 * 24 * 60 * 60);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((int)($row['created_at'] ?? 0) < $cutoff) {
            continue;
        }
        if (empty($row['is_read']) && empty($row['read'])) {
            $count++;
        }
    }
    return min(99, $count);
}

function notification_mark_read(string $uid, string $notificationId): bool
{
    $uid = trim($uid);
    $notificationId = notification_clean_text($notificationId, 80);
    if ($uid === '' || $notificationId === '') {
        return false;
    }
    $path = 'USER_NOTIFICATIONS/' . $uid . '/' . $notificationId;
    if (!is_array(fb_get($path))) {
        return false;
    }
    return fb_patch($path, [
        'is_read' => true,
        'read_at' => notification_now(),
    ]);
}

function notification_mark_entity_read(string $uid, string $entityType, string $entityId): void
{
    $rows = fb_get('USER_NOTIFICATIONS/' . trim($uid));
    if (!is_array($rows)) {
        return;
    }
    $entityType = notification_clean_code($entityType, 60);
    $entityId = notification_clean_text($entityId, 120);
    $now = notification_now();
    foreach ($rows as $id => $row) {
        if (!is_array($row) || !empty($row['is_read'])) {
            continue;
        }
        if (notification_clean_code($row['entity_type'] ?? '', 60) === $entityType
            && notification_clean_text($row['entity_id'] ?? '', 120) === $entityId) {
            fb_patch('USER_NOTIFICATIONS/' . trim($uid) . '/' . $id, [
                'is_read' => true,
                'read_at' => $now,
            ]);
        }
    }
}

function notification_mark_all_read(string $uid): int
{
    $uid = trim($uid);
    $rows = fb_get('USER_NOTIFICATIONS/' . $uid);
    if ($uid === '' || !is_array($rows)) {
        return 0;
    }
    $now = notification_now();
    $count = 0;
    foreach ($rows as $id => $row) {
        if (is_array($row) && empty($row['is_read']) && empty($row['read'])) {
            if (fb_patch('USER_NOTIFICATIONS/' . $uid . '/' . $id, ['is_read' => true, 'read_at' => $now])) {
                $count++;
            }
        }
    }
    return $count;
}
