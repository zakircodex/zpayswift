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
    if ($type === 'ADMIN_NOTICE') {
        return 'NOTICE';
    }
    if ($type === 'RINGGIT_RATE_UPDATED') {
        return 'NOTICE';
    }
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

    foreach (['ticket_id', 'message_id', 'transfer_id', 'request_id', 'status', 'notice_id', 'image_id', 'image_mime', 'image_name'] as $key) {
        if (array_key_exists($key, $extra)) {
            $row[$key] = notification_clean_text($extra[$key], 120);
        }
    }
    if (array_key_exists('body_full', $extra)) {
        $row['body_full'] = notification_clean_text($extra['body_full'], 4000);
    }
    if (array_key_exists('image_path', $extra)) {
        $row['image_path'] = notification_clean_text($extra['image_path'], 500);
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
    $notificationId = (string)($row['notification_id'] ?? '');
    $hasImage = (string)($row['image_id'] ?? '') !== '' && (string)($row['image_path'] ?? '') !== '';
    return [
        'notification_id' => $notificationId,
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
        'notice_id' => notification_clean_text($row['notice_id'] ?? '', 80),
        'status' => notification_clean_code($row['status'] ?? '', 40),
        'has_image' => $hasImage,
        'image_endpoint' => $hasImage ? 'notifications/image.php?notification_id=' . rawurlencode($notificationId) : '',
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
        if (!empty($row['deleted'])) {
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
        if (!empty($row['deleted'])) {
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
    $row = fb_get($path);
    if (!is_array($row) || !empty($row['deleted'])) {
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
        if (!is_array($row) || !empty($row['deleted']) || !empty($row['is_read'])) {
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
        if (is_array($row) && empty($row['deleted']) && empty($row['is_read']) && empty($row['read'])) {
            if (fb_patch('USER_NOTIFICATIONS/' . $uid . '/' . $id, ['is_read' => true, 'read_at' => $now])) {
                $count++;
            }
        }
    }
    return $count;
}

function notification_get_for_user(string $uid, string $notificationId): array
{
    $uid = trim($uid);
    $notificationId = notification_clean_text($notificationId, 80);
    if ($uid === '' || $notificationId === '') {
        return [];
    }
    $row = fb_get('USER_NOTIFICATIONS/' . $uid . '/' . $notificationId);
    if (!is_array($row) || !empty($row['deleted'])) {
        return [];
    }
    $row['notification_id'] = (string)($row['notification_id'] ?? $notificationId);
    return $row;
}

function notification_details_for_user(string $uid, string $notificationId): array
{
    $row = notification_get_for_user($uid, $notificationId);
    if ($row === []) {
        return [];
    }
    $public = notification_public_row($row);
    $public['body_full'] = notification_clean_text($row['body_full'] ?? $row['body'] ?? '', 4000);
    return $public;
}

function notification_mark_many_read(string $uid, array $notificationIds): int
{
    $count = 0;
    foreach ($notificationIds as $notificationId) {
        if (notification_mark_read($uid, (string)$notificationId)) {
            $count++;
        }
    }
    return $count;
}

function notification_delete_many(string $uid, array $notificationIds): int
{
    $uid = trim($uid);
    if ($uid === '') {
        return 0;
    }
    $now = notification_now();
    $count = 0;
    foreach ($notificationIds as $notificationId) {
        $notificationId = notification_clean_text($notificationId, 80);
        if ($notificationId === '') {
            continue;
        }
        $path = 'USER_NOTIFICATIONS/' . $uid . '/' . $notificationId;
        $row = fb_get($path);
        if (!is_array($row) || !empty($row['deleted'])) {
            continue;
        }
        if (fb_patch($path, [
            'deleted' => true,
            'deleted_at' => $now,
            'is_read' => true,
            'read_at' => (int)($row['read_at'] ?? 0) ?: $now,
        ])) {
            $count++;
        }
    }
    return $count;
}

function notification_private_storage_root(): string
{
    if (defined('NOTIFICATION_IMAGE_DIR') && trim((string)NOTIFICATION_IMAGE_DIR) !== '') {
        return rtrim((string)NOTIFICATION_IMAGE_DIR, DIRECTORY_SEPARATOR);
    }
    $livePrivate = '/home/zedpayhe/private/zpayswift/notification_images';
    if (PHP_OS_FAMILY !== 'Windows') {
        return $livePrivate;
    }
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage_private' . DIRECTORY_SEPARATOR . 'notifications';
}

function notification_image_id(): string
{
    return 'NI' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function notification_store_binary_image(string $binary, string $originalName = 'notice.jpg'): array
{
    if ($binary === '' || strlen($binary) > 5 * 1024 * 1024) {
        return ['ok' => false, 'code' => 'NOTICE_IMAGE_INVALID'];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'zpn_');
    if ($tmp === false) {
        return ['ok' => false, 'code' => 'NOTICE_IMAGE_TEMP_FAILED'];
    }
    file_put_contents($tmp, $binary);
    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
    }
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime]) || !is_array(@getimagesize($tmp))) {
        @unlink($tmp);
        return ['ok' => false, 'code' => 'NOTICE_IMAGE_TYPE_INVALID'];
    }
    $root = notification_private_storage_root();
    $dir = $root . DIRECTORY_SEPARATOR . date('Y-m');
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        @unlink($tmp);
        return ['ok' => false, 'code' => 'NOTICE_IMAGE_STORE_FAILED'];
    }
    $imageId = notification_image_id();
    $fileName = $imageId . '.' . $allowed[$mime];
    $path = $dir . DIRECTORY_SEPARATOR . $fileName;
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return ['ok' => false, 'code' => 'NOTICE_IMAGE_STORE_FAILED'];
    }
    @chmod($path, 0600);
    return [
        'ok' => true,
        'image_id' => $imageId,
        'image_path' => $path,
        'image_mime' => $mime,
        'image_name' => notification_clean_text($originalName, 120) ?: $fileName,
        'image_size' => filesize($path) ?: strlen($binary),
    ];
}

function notification_user_country_bucket(array $user): string
{
    $text = strtoupper(implode(' ', [
        $user['pricing_country'] ?? '',
        $user['country'] ?? '',
        $user['country_code'] ?? '',
        $user['phone_country'] ?? '',
        $user['phone'] ?? '',
    ]));
    if (str_contains($text, 'MALAYSIA') || str_contains($text, ' MY') || str_starts_with(trim($text), 'MY') || str_contains($text, '+60')) {
        return 'MY';
    }
    if (str_contains($text, 'BANGLADESH') || str_contains($text, ' BD') || str_starts_with(trim($text), 'BD') || str_contains($text, '+880')) {
        return 'BD';
    }
    $phone = preg_replace('/\D+/', '', (string)($user['phone'] ?? '')) ?? '';
    if (str_starts_with($phone, '60')) {
        return 'MY';
    }
    if (str_starts_with($phone, '880')) {
        return 'BD';
    }
    return '';
}

function notification_user_status(array $user): string
{
    $status = strtoupper(trim((string)($user['status'] ?? 'ACTIVE')));
    return $status !== '' ? notification_clean_code($status, 40) : 'ACTIVE';
}

function notification_active_statuses(): array
{
    return ['ACTIVE', 'APPROVED'];
}

function notification_user_is_active(array $user): bool
{
    return in_array(notification_user_status($user), notification_active_statuses(), true);
}

function notification_user_currency(array $user): string
{
    $currency = strtoupper(trim((string)($user['currency'] ?? $user['wallet_currency'] ?? '')));
    if (in_array($currency, ['MYR', 'RM', 'RINGGIT'], true)) {
        return 'MYR';
    }
    if (in_array($currency, ['BDT', 'TK', 'TAKA'], true)) {
        return 'BDT';
    }
    $country = strtoupper(trim((string)($user['pricing_country'] ?? $user['market_country'] ?? $user['service_country'] ?? '')));
    return $country === 'MY' ? 'MYR' : ($country === 'BD' ? 'BDT' : '');
}

function notification_mask_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) <= 6) {
        return str_repeat('*', max(0, strlen($digits) - 2)) . substr($digits, -2);
    }
    return substr($digits, 0, 3) . str_repeat('*', max(3, strlen($digits) - 6)) . substr($digits, -3);
}

function notification_user_public_summary(string $uid, array $user): array
{
    return [
        'uid' => notification_clean_text($uid, 80),
        'name' => notification_clean_text($user['name'] ?? $user['full_name'] ?? $user['display_name'] ?? '', 100),
        'phone_masked' => notification_mask_phone((string)($user['phone'] ?? $user['mobile'] ?? '')),
        'email' => notification_clean_text($user['email'] ?? '', 120),
        'status' => notification_user_status($user),
        'pricing_country' => notification_clean_code($user['pricing_country'] ?? $user['market_country'] ?? $user['service_country'] ?? '', 10),
        'currency' => notification_user_currency($user),
    ];
}

function notification_audience_label(string $audience): string
{
    $audience = notification_clean_code($audience, 20);
    return match ($audience) {
        'ACTIVE' => 'Active Users',
        'INACTIVE' => 'Inactive Users',
        'BD' => 'BD Users',
        'MY' => 'MY Users',
        'SPECIFIC' => 'Specific User',
        default => 'All Users',
    };
}

function notification_audience_statuses(string $audience): array
{
    $audience = notification_clean_code($audience, 20);
    if ($audience === 'ACTIVE' || $audience === 'ALL' || $audience === 'BD' || $audience === 'MY') {
        return notification_active_statuses();
    }
    if ($audience !== 'INACTIVE') {
        return [];
    }
    $rows = fb_get('USERS');
    $statuses = [];
    if (is_array($rows)) {
        foreach ($rows as $user) {
            if (!is_array($user)) {
                continue;
            }
            $status = notification_user_status($user);
            if (!in_array($status, notification_active_statuses(), true)) {
                $statuses[$status] = true;
            }
        }
    }
    $out = array_keys($statuses);
    sort($out);
    return $out;
}

function notification_target_users(string $audience, string $specificUid = ''): array
{
    $audience = notification_clean_code($audience, 20);
    $specificUid = trim($specificUid);
    if ($audience === 'SPECIFIC') {
        $user = $specificUid !== '' ? fb_get('USERS/' . $specificUid) : null;
        return is_array($user) ? [$specificUid => $user] : [];
    }
    $rows = fb_get('USERS');
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $uid => $user) {
        if (!is_array($user)) {
            continue;
        }
        $isActive = notification_user_is_active($user);
        if ($audience === 'INACTIVE') {
            if ($isActive) {
                continue;
            }
            $out[(string)$uid] = $user;
            continue;
        }
        if (!$isActive) {
            continue;
        }
        $bucket = notification_user_country_bucket($user);
        if ($audience === 'BD' && $bucket !== 'BD') {
            continue;
        }
        if ($audience === 'MY' && $bucket !== 'MY') {
            continue;
        }
        $out[(string)$uid] = $user;
    }
    return $out;
}

function notification_target_count(string $audience, string $specificUid = ''): int
{
    return count(notification_target_users($audience, $specificUid));
}

function notification_broadcast_admin_notice(array $notice): array
{
    if (!function_exists('fcm_send_to_user')) {
        $fcm = __DIR__ . '/fcm.php';
        if (is_file($fcm)) {
            require_once $fcm;
        }
    }
    $noticeId = notification_clean_text($notice['notice_id'] ?? '', 80);
    $audience = notification_clean_code($notice['audience'] ?? 'ALL', 20) ?: 'ALL';
    $specificUid = trim((string)($notice['specific_uid'] ?? ''));
    $title = notification_clean_text($notice['title'] ?? '', 100);
    $bodyFull = notification_clean_text($notice['body'] ?? '', 4000);
    $body = notification_clean_text($bodyFull, 220);
    if ($noticeId === '' || $title === '') {
        return ['ok' => false, 'code' => 'NOTICE_INVALID'];
    }
    $dedupePath = 'ADMIN_NOTICE_BROADCASTS/' . hash('sha256', $noticeId);
    $existing = fb_get($dedupePath);
    if (is_array($existing)) {
        return ['ok' => true, 'duplicate' => true, 'notice_id' => $noticeId, 'sent' => (int)($existing['sent'] ?? 0)];
    }
    $targets = notification_target_users($audience, $specificUid);
    $sent = 0;
    $pushSent = 0;
    foreach ($targets as $uid => $user) {
        $extra = [
            'notice_id' => $noticeId,
            'body_full' => $bodyFull,
        ];
        foreach (['image_id', 'image_path', 'image_mime', 'image_name'] as $key) {
            if ((string)($notice[$key] ?? '') !== '') {
                $extra[$key] = (string)$notice[$key];
            }
        }
        $record = notification_record_user(
            (string)$uid,
            'ADMIN_NOTICE',
            $title,
            $body !== '' ? $body : 'Notice from Z-Pay Swift.',
            'ADMIN_NOTICE',
            $noticeId,
            'ADMIN_NOTICE:' . $noticeId,
            $extra
        );
        if (!empty($record['ok']) && empty($record['duplicate'])) {
            $sent++;
        }
        if (function_exists('fcm_send_to_user')) {
            $push = fcm_send_to_user(
                (string)$uid,
                $title,
                $body !== '' ? $body : 'Notice from Z-Pay Swift.',
                [
                    'type' => 'ADMIN_NOTICE',
                    'notification_id' => (string)($record['notification_id'] ?? ''),
                    'notice_id' => $noticeId,
                    'title' => $title,
                    'body' => $body !== '' ? $body : 'Notice from Z-Pay Swift.',
                ],
                'ADMIN_NOTICE:' . $noticeId . ':' . (string)$uid
            );
            $pushSent += (int)($push['sent'] ?? 0);
        }
    }
    fb_put($dedupePath, [
        'notice_id' => $noticeId,
        'broadcast_id' => $noticeId,
        'type' => 'ADMIN_NOTICE',
        'audience' => $audience,
        'audience_label' => notification_audience_label($audience),
        'included_statuses' => notification_audience_statuses($audience),
        'title' => $title,
        'specific_uid' => $specificUid,
        'sent' => $sent,
        'push_sent' => $pushSent,
        'failed' => 0,
        'status' => 'SENT',
        'created_by' => notification_clean_text($notice['created_by'] ?? '', 80),
        'created_at' => notification_now(),
    ]);
    return [
        'ok' => true,
        'notice_id' => $noticeId,
        'sent' => $sent,
        'push_sent' => $pushSent,
        'included_statuses' => notification_audience_statuses($audience),
        'recipient_count' => count($targets),
    ];
}

function notification_rate_target_users(): array
{
    $rows = fb_get('USERS');
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $uid => $user) {
        if (!is_array($user)) {
            continue;
        }
        $statusValue = trim((string)($user['account_status'] ?? ''));
        if ($statusValue === '') {
            $statusValue = trim((string)($user['status'] ?? ''));
        }
        $status = notification_clean_code($statusValue, 40);
        if (!in_array($status, notification_active_statuses(), true)) {
            continue;
        }
        $countryValue = trim((string)($user['pricing_country'] ?? ''));
        if ($countryValue === '') {
            $countryValue = trim((string)($user['market_country'] ?? $user['service_country'] ?? ''));
        }
        $country = notification_clean_code($countryValue, 10);
        if ($country !== 'MY') {
            continue;
        }
        $currencyValue = trim((string)($user['currency'] ?? ''));
        if ($currencyValue === '') {
            $currencyValue = trim((string)($user['wallet_currency'] ?? ''));
        }
        $currencyRaw = strtoupper($currencyValue);
        $currency = in_array($currencyRaw, ['MYR', 'RM', 'RINGGIT'], true) ? 'MYR' : '';
        if ($currency !== 'MYR') {
            continue;
        }
        $out[(string)$uid] = $user;
    }
    return $out;
}

function notification_rate_target_count(): int
{
    return count(notification_rate_target_users());
}

function notification_rate_title_body(float $rate): array
{
    $rate = round($rate, 2);
    return [
        'title' => 'Ringgit Rate Updated',
        'body' => 'Today\'s rate is RM 1 = ' . number_format($rate, 2, '.', '') . ' BDT.',
    ];
}

function notification_record_rate_update_rows(float $rate, string $eventId, array $targets): array
{
    $text = notification_rate_title_body($rate);
    $eventId = notification_clean_text($eventId, 80);
    $created = 0;
    $existing = 0;
    $failed = 0;

    foreach ($targets as $uid => $user) {
        $record = notification_record_user(
            (string)$uid,
            'RINGGIT_RATE_UPDATED',
            $text['title'],
            $text['body'],
            'RINGGIT_RATE',
            $eventId,
            'RINGGIT_RATE_UPDATED:' . $eventId,
            [
                'notice_id' => $eventId,
                'status' => 'SENT',
                'body_full' => $text['body'],
            ]
        );

        if (!empty($record['ok']) && empty($record['duplicate'])) {
            $created++;
            continue;
        }
        if (!empty($record['ok']) && !empty($record['duplicate'])) {
            $existing++;
            continue;
        }
        $failed++;
        if (function_exists('system_log')) {
            system_log('RATE_NOTIFICATION_ROW_WARNING', $eventId, 'Ringgit rate notification row failed', [
                'uid_hash' => hash('sha256', (string)$uid),
                'code' => notification_clean_code($record['code'] ?? 'NOTIFICATION_SAVE_FAILED', 80),
            ]);
        }
    }

    return ['created' => $created, 'existing' => $existing, 'failed' => $failed];
}

function notification_dispatch_rate_update_push(float $rate, string $eventId, array $targets = []): array
{
    if (!function_exists('fcm_send_to_user')) {
        $fcm = __DIR__ . '/fcm.php';
        if (is_file($fcm)) {
            require_once $fcm;
        }
    }

    $rate = round($rate, 2);
    $eventId = notification_clean_text($eventId, 80);
    if ($rate <= 0 || $eventId === '') {
        return ['ok' => false, 'code' => 'RATE_PUSH_INVALID', 'sent' => 0, 'failed' => 0];
    }
    if (!function_exists('fcm_send_to_user')) {
        return ['ok' => false, 'code' => 'FCM_HELPER_MISSING', 'sent' => 0, 'failed' => 0];
    }

    if ($targets === []) {
        $targets = notification_rate_target_users();
    }

    $text = notification_rate_title_body($rate);
    $sent = 0;
    $failed = 0;

    foreach ($targets as $uid => $user) {
        $uid = (string)$uid;
        $notificationId = notification_id_from_key($uid, 'RINGGIT_RATE_UPDATED:' . $eventId);
        if (!is_array(fb_get('USER_NOTIFICATIONS/' . $uid . '/' . $notificationId))) {
            continue;
        }

        $push = fcm_send_to_user(
            $uid,
            $text['title'],
            $text['body'],
            [
                'type' => 'RINGGIT_RATE_UPDATED',
                'notification_id' => $notificationId,
                'notice_id' => $eventId,
                'rate_event_id' => $eventId,
                'destination' => 'NOTIFICATIONS',
                'title' => $text['title'],
                'body' => $text['body'],
                'entity_type' => 'RINGGIT_RATE',
                'entity_id' => $eventId,
                'rate' => number_format($rate, 2, '.', ''),
            ],
            'RINGGIT_RATE_UPDATED:' . $eventId . ':' . $uid
        );
        $sent += (int)($push['sent'] ?? 0);
        if (empty($push['ok'])) {
            $failed++;
            if (function_exists('system_log')) {
                system_log('RATE_PUSH_WARNING', $eventId, 'Ringgit rate push failed', [
                    'uid_hash' => hash('sha256', $uid),
                    'code' => notification_clean_code($push['code'] ?? 'FCM_SEND_FAILED', 80),
                ]);
            }
        }
    }

    return ['ok' => $failed === 0, 'code' => 'RATE_PUSH_DISPATCHED', 'sent' => $sent, 'failed' => $failed];
}

function notification_broadcast_rate_update(float $rate, string $eventId, string $changedBy = '', string $source = ''): array
{
    if (!function_exists('fcm_send_to_user')) {
        $fcm = __DIR__ . '/fcm.php';
        if (is_file($fcm)) {
            require_once $fcm;
        }
    }
    $rate = round($rate, 2);
    $eventId = notification_clean_text($eventId, 80);
    if ($rate <= 0 || $eventId === '') {
        return ['ok' => false, 'code' => 'RATE_NOTICE_INVALID', 'sent' => 0, 'push_sent' => 0];
    }
    $targets = notification_rate_target_users();
    $dedupePath = 'ADMIN_NOTICE_BROADCASTS/' . hash('sha256', 'RATE:' . $eventId);
    $existing = fb_get($dedupePath);
    if (is_array($existing)) {
        $rows = notification_record_rate_update_rows($rate, $eventId, $targets);
        $push = notification_dispatch_rate_update_push($rate, $eventId, $targets);
        $sent = (int)($existing['sent'] ?? 0) + (int)$rows['created'];
        $rowsExisting = (int)($existing['rows_existing'] ?? 0) + (int)$rows['existing'];
        $rowsFailed = (int)($existing['rows_failed'] ?? 0) + (int)$rows['failed'];
        $pushSent = (int)($existing['push_sent'] ?? 0) + (int)($push['sent'] ?? 0);
        $pushFailed = (int)($existing['push_failed'] ?? 0) + (int)($push['failed'] ?? 0);
        fb_patch($dedupePath, [
            'sent' => $sent,
            'rows_existing' => $rowsExisting,
            'rows_failed' => $rowsFailed,
            'push_sent' => $pushSent,
            'push_failed' => $pushFailed,
            'push_last_attempt_at' => notification_now(),
        ]);
        return [
            'ok' => true,
            'duplicate' => true,
            'sent' => $sent,
            'rows_existing' => $rowsExisting,
            'rows_failed' => $rowsFailed,
            'push_sent' => $pushSent,
            'push_failed' => $pushFailed,
            'recipient_count' => count($targets),
        ];
    }
    $text = notification_rate_title_body($rate);
    $rows = notification_record_rate_update_rows($rate, $eventId, $targets);
    $push = notification_dispatch_rate_update_push($rate, $eventId, $targets);
    $pushSent = (int)($push['sent'] ?? 0);
    $pushFailed = (int)($push['failed'] ?? 0);
    fb_put($dedupePath, [
        'broadcast_id' => $eventId,
        'notice_id' => $eventId,
        'type' => 'RINGGIT_RATE_UPDATED',
        'audience' => 'ACTIVE_MY_MYR',
        'audience_label' => 'Active MYR Users',
        'included_statuses' => notification_active_statuses(),
        'title' => $text['title'],
        'rate' => $rate,
        'sent' => (int)$rows['created'],
        'rows_existing' => (int)$rows['existing'],
        'rows_failed' => (int)$rows['failed'],
        'push_sent' => $pushSent,
        'push_failed' => $pushFailed,
        'failed' => 0,
        'status' => 'SENT',
        'created_by' => notification_clean_text($changedBy, 80),
        'source' => notification_clean_code($source, 40),
        'created_at' => notification_now(),
        'push_last_attempt_at' => notification_now(),
    ]);
    return [
        'ok' => true,
        'sent' => (int)$rows['created'],
        'rows_existing' => (int)$rows['existing'],
        'rows_failed' => (int)$rows['failed'],
        'push_sent' => $pushSent,
        'push_failed' => $pushFailed,
        'recipient_count' => count($targets),
    ];
}

function notification_recent_broadcasts(int $limit = 10): array
{
    $rows = fb_get('ADMIN_NOTICE_BROADCASTS');
    if (!is_array($rows)) {
        return [];
    }
    $items = [];
    foreach ($rows as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $items[] = [
            'broadcast_id' => notification_clean_text($row['broadcast_id'] ?? $row['notice_id'] ?? (string)$id, 80),
            'type' => notification_clean_code($row['type'] ?? 'ADMIN_NOTICE', 80),
            'audience' => notification_clean_text($row['audience_label'] ?? $row['audience'] ?? '', 80),
            'title' => notification_clean_text($row['title'] ?? '', 100),
            'sent' => (int)($row['sent'] ?? 0),
            'push_sent' => (int)($row['push_sent'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
            'status' => notification_clean_code($row['status'] ?? 'SENT', 40),
            'created_at' => (int)($row['created_at'] ?? 0),
        ];
    }
    usort($items, static fn(array $a, array $b): int => ((int)$b['created_at'] <=> (int)$a['created_at']));
    return array_slice($items, 0, max(1, min(20, $limit)));
}
