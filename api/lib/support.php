<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/telegram.php';

function support_now(): int
{
    return function_exists('now_ts') ? now_ts() : time();
}

function support_clean_text($value, int $max = 500): string
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

function support_clean_code($value): string
{
    $code = strtoupper(trim((string)$value));
    $code = preg_replace('/[^A-Z0-9_]+/', '_', $code) ?? $code;
    return trim($code, '_');
}

function support_bool($value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === null || $value === '') {
        return $default;
    }
    return in_array(strtoupper(trim((string)$value)), ['1', 'TRUE', 'YES', 'ON', 'ENABLED', 'ACTIVE'], true);
}

function support_int($value, int $default, int $min, int $max): int
{
    $num = is_numeric($value) ? (int)$value : $default;
    return max($min, min($max, $num));
}

function support_ticket_id(): string
{
    return 'SP' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function support_message_id(): string
{
    return 'MSG' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function support_attachment_id(): string
{
    return 'ATT' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function support_telegram_action_key(): string
{
    if (defined('TELEGRAM_SUPPORT_ACTION_KEY') && trim((string)TELEGRAM_SUPPORT_ACTION_KEY) !== '') {
        return trim((string)TELEGRAM_SUPPORT_ACTION_KEY);
    }
    return defined('APP_KEY') ? trim((string)APP_KEY) : '';
}

function support_telegram_signature(string $actionCode, string $ticketId): string
{
    $key = support_telegram_action_key();
    if ($key === '') {
        return '';
    }
    return substr(hash_hmac('sha256', support_clean_code($actionCode) . '|' . trim($ticketId), $key), 0, 16);
}

function support_telegram_callback_data(string $actionCode, string $ticketId): string
{
    $actionCode = strtolower(support_clean_code($actionCode));
    return 'support|' . $actionCode . '|' . trim($ticketId) . '|' . support_telegram_signature($actionCode, $ticketId);
}

function support_telegram_parse_callback_data(string $data): array
{
    $parts = explode('|', trim($data));
    if (count($parts) !== 4 || strtolower($parts[0]) !== 'support') {
        return [];
    }
    $action = strtolower(support_clean_code($parts[1]));
    $ticketId = support_clean_code($parts[2]);
    $signature = trim($parts[3]);
    $expected = support_telegram_signature($action, $ticketId);
    if ($action === '' || $ticketId === '' || $expected === '' || !hash_equals($expected, $signature)) {
        return [];
    }
    return [
        'action' => $action,
        'ticket_id' => $ticketId,
    ];
}

function support_telegram_keyboard(string $ticketId): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => 'View', 'callback_data' => support_telegram_callback_data('v', $ticketId)],
                ['text' => 'Mark Pending', 'callback_data' => support_telegram_callback_data('p', $ticketId)],
            ],
            [
                ['text' => 'Close', 'callback_data' => support_telegram_callback_data('c', $ticketId)],
            ],
        ],
    ];
}

function support_default_config(): array
{
    return [
        'contact_us_enabled' => true,
        'ticket_enabled' => true,
        'whatsapp_enabled' => false,
        'whatsapp_number' => '',
        'call_enabled' => false,
        'support_phone' => '',
        'email_enabled' => false,
        'support_email' => '',
        'support_hours' => 'Every day, 10:00 AM - 10:00 PM',
        'average_response_text' => 'Average response time: within 24 hours.',
        'support_notice' => 'Never share your password, PIN or OTP with anyone.',
        'attachments_enabled' => true,
        'max_attachments' => 3,
        'max_file_size' => 5 * 1024 * 1024,
        'ticket_rate_limit_seconds' => 20,
        'reopen_allowed' => true,
    ];
}

function support_config(): array
{
    $row = fb_get('SUPPORT_CONFIG');
    $row = is_array($row) ? $row : [];
    $config = array_merge(support_default_config(), $row);
    $config['contact_us_enabled'] = support_bool($config['contact_us_enabled'] ?? true, true);
    $config['ticket_enabled'] = support_bool($config['ticket_enabled'] ?? true, true);
    $config['whatsapp_enabled'] = support_bool($config['whatsapp_enabled'] ?? false);
    $config['call_enabled'] = support_bool($config['call_enabled'] ?? false);
    $config['email_enabled'] = support_bool($config['email_enabled'] ?? false);
    $config['attachments_enabled'] = support_bool($config['attachments_enabled'] ?? true, true);
    $config['max_attachments'] = support_int($config['max_attachments'] ?? 3, 3, 0, 3);
    $config['max_file_size'] = support_int($config['max_file_size'] ?? 5242880, 5242880, 1024, 10 * 1024 * 1024);
    $config['ticket_rate_limit_seconds'] = support_int($config['ticket_rate_limit_seconds'] ?? 20, 20, 0, 3600);
    $config['reopen_allowed'] = support_bool($config['reopen_allowed'] ?? true, true);
    return $config;
}

function support_public_config(): array
{
    $config = support_config();
    return [
        'contact_us_enabled' => (bool)$config['contact_us_enabled'],
        'ticket_enabled' => (bool)$config['ticket_enabled'],
        'whatsapp_enabled' => (bool)$config['whatsapp_enabled'] && support_clean_phone($config['whatsapp_number'] ?? '') !== '',
        'whatsapp_number' => support_clean_phone($config['whatsapp_number'] ?? ''),
        'call_enabled' => (bool)$config['call_enabled'] && support_clean_phone($config['support_phone'] ?? '') !== '',
        'support_phone' => support_clean_phone($config['support_phone'] ?? ''),
        'email_enabled' => (bool)$config['email_enabled'] && filter_var((string)($config['support_email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false,
        'support_email' => support_clean_text($config['support_email'] ?? '', 120),
        'support_hours' => support_clean_text($config['support_hours'] ?? '', 120),
        'average_response_text' => support_clean_text($config['average_response_text'] ?? '', 160),
        'support_notice' => support_clean_text($config['support_notice'] ?? '', 220),
        'attachments_enabled' => (bool)$config['attachments_enabled'],
        'max_attachments' => (int)$config['max_attachments'],
        'max_file_size' => (int)$config['max_file_size'],
        'reopen_allowed' => (bool)$config['reopen_allowed'],
    ];
}

function support_clean_phone($value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }
    $prefix = str_starts_with($raw, '+') ? '+' : '';
    return $prefix . (preg_replace('/\D+/', '', $raw) ?? '');
}

function support_default_categories(): array
{
    $names = [
        'ACCOUNT_LOGIN' => 'Account / Login',
        'ADD_MONEY' => 'Add Money',
        'MOBILE_TOPUP' => 'Mobile Top-Up',
        'BKASH' => 'bKash',
        'NAGAD' => 'Nagad',
        'ZPAY_TRANSFER' => 'Z-Pay Transfer',
        'BUNDLE' => 'Bundle',
        'WALLET_BALANCE' => 'Wallet / Balance',
        'TRANSACTION_ISSUE' => 'Transaction Issue',
        'OTHER' => 'Other',
    ];
    $rows = [];
    $sort = 10;
    foreach ($names as $code => $name) {
        $transaction = !in_array($code, ['ACCOUNT_LOGIN', 'OTHER'], true);
        $rows[$code] = [
            'code' => $code,
            'name' => $name,
            'active' => true,
            'sort_order' => $sort,
            'related_request_enabled' => $transaction,
            'attachment_enabled' => true,
        ];
        $sort += 10;
    }
    return $rows;
}

function support_categories(bool $activeOnly = true): array
{
    $stored = fb_get('SUPPORT_CATEGORIES');
    $rows = is_array($stored) && $stored !== [] ? $stored : support_default_categories();
    $out = [];
    foreach ($rows as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = support_clean_code($row['code'] ?? $key);
        if ($code === '') {
            continue;
        }
        $item = [
            'code' => $code,
            'name' => support_clean_text($row['name'] ?? $code, 80),
            'active' => support_bool($row['active'] ?? true, true),
            'sort_order' => (int)($row['sort_order'] ?? 999),
            'related_request_enabled' => support_bool($row['related_request_enabled'] ?? false),
            'attachment_enabled' => support_bool($row['attachment_enabled'] ?? true, true),
        ];
        if ($activeOnly && !$item['active']) {
            continue;
        }
        $out[] = $item;
    }
    usort($out, static function (array $a, array $b): int {
        return (($a['sort_order'] <=> $b['sort_order']) ?: strcmp($a['name'], $b['name']));
    });
    return $out;
}

function support_category(string $code): array
{
    $code = support_clean_code($code);
    foreach (support_categories(false) as $row) {
        if ($row['code'] === $code) {
            return $row;
        }
    }
    return [];
}

function support_uid_from_auth(array $auth): string
{
    return (string)($auth['uid'] ?? $auth['user']['uid'] ?? '');
}

function support_user_from_auth(array $auth): array
{
    return is_array($auth['user'] ?? null) ? $auth['user'] : [];
}

function support_status_label(string $status): string
{
    $status = support_clean_code($status);
    return [
        'OPEN' => 'Open',
        'PENDING' => 'Pending',
        'REPLIED' => 'Replied',
        'RESOLVED' => 'Resolved',
        'CLOSED' => 'Closed',
    ][$status] ?? ucfirst(strtolower($status));
}

function support_public_ticket(array $row): array
{
    $ticketId = (string)($row['ticket_id'] ?? '');
    $status = support_clean_code($row['status'] ?? 'OPEN') ?: 'OPEN';
    return [
        'ticket_id' => $ticketId,
        'uid' => (string)($row['uid'] ?? ''),
        'user_name' => (string)($row['user_name'] ?? ''),
        'user_phone' => (string)($row['user_phone'] ?? ''),
        'category_code' => (string)($row['category_code'] ?? ''),
        'category_name' => (string)($row['category_name'] ?? $row['category_name_snapshot'] ?? ''),
        'related_type' => (string)($row['related_type'] ?? ''),
        'related_request_id' => (string)($row['related_request_id'] ?? ''),
        'subject' => (string)($row['subject'] ?? ''),
        'status' => $status,
        'status_label' => support_status_label($status),
        'attachment_count' => (int)($row['attachment_count'] ?? 0),
        'last_message_preview' => (string)($row['last_message_preview'] ?? ''),
        'last_message_by' => (string)($row['last_message_by'] ?? ''),
        'created_at' => (int)($row['created_at'] ?? 0),
        'updated_at' => (int)($row['updated_at'] ?? 0),
        'last_message_at' => (int)($row['last_message_at'] ?? $row['updated_at'] ?? 0),
    ];
}

function support_public_message(array $row): array
{
    return [
        'message_id' => (string)($row['message_id'] ?? ''),
        'ticket_id' => (string)($row['ticket_id'] ?? ''),
        'sender_type' => (string)($row['sender_type'] ?? ''),
        'message' => (string)($row['message'] ?? ''),
        'attachment_ids' => array_values(array_filter((array)($row['attachment_ids'] ?? []), 'is_string')),
        'created_at' => (int)($row['created_at'] ?? 0),
    ];
}

function support_public_attachment(array $row): array
{
    return [
        'attachment_id' => (string)($row['attachment_id'] ?? ''),
        'ticket_id' => (string)($row['ticket_id'] ?? ''),
        'message_id' => (string)($row['message_id'] ?? ''),
        'original_name' => (string)($row['original_name'] ?? ''),
        'mime' => (string)($row['mime'] ?? ''),
        'size' => (int)($row['size'] ?? 0),
        'download_endpoint' => 'support/attachment.php?ticket_id=' . rawurlencode((string)($row['ticket_id'] ?? '')) . '&attachment_id=' . rawurlencode((string)($row['attachment_id'] ?? '')),
        'created_at' => (int)($row['created_at'] ?? 0),
    ];
}

function support_read_ticket(string $ticketId): array
{
    $ticketId = support_clean_code($ticketId);
    $row = $ticketId !== '' ? fb_get('SUPPORT_TICKETS/' . $ticketId) : null;
    return is_array($row) ? $row : [];
}

function support_user_can_access(array $auth, array $ticket): bool
{
    $uid = support_uid_from_auth($auth);
    return $uid !== '' && $uid === (string)($ticket['uid'] ?? '');
}

function support_private_storage_root(): string
{
    if (defined('SUPPORT_ATTACHMENT_DIR') && trim((string)SUPPORT_ATTACHMENT_DIR) !== '') {
        return rtrim((string)SUPPORT_ATTACHMENT_DIR, DIRECTORY_SEPARATOR);
    }
    $livePrivate = '/home/zedpayhe/private/zpayswift/support_attachments';
    if (PHP_OS_FAMILY !== 'Windows') {
        return $livePrivate;
    }
    if (is_dir(dirname($livePrivate))) {
        return $livePrivate;
    }
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage_private' . DIRECTORY_SEPARATOR . 'support';
}

function support_upload_files(array $files): array
{
    $items = [];
    foreach ($files as $field => $file) {
        if (!is_array($file)) {
            continue;
        }
        if (is_array($file['name'] ?? null)) {
            $count = count((array)$file['name']);
            for ($i = 0; $i < $count; $i++) {
                $items[] = [
                    'name' => $file['name'][$i] ?? '',
                    'type' => $file['type'][$i] ?? '',
                    'tmp_name' => $file['tmp_name'][$i] ?? '',
                    'error' => $file['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $file['size'][$i] ?? 0,
                    'field' => (string)$field,
                ];
            }
            continue;
        }
        $file['field'] = (string)$field;
        $items[] = $file;
    }
    return array_values(array_filter($items, static fn($f) => (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
}

function support_store_attachments(array $files, string $ticketId, string $messageId, string $uid, array $config): array
{
    $uploads = support_upload_files($files);
    $max = (int)($config['max_attachments'] ?? 3);
    if ($uploads === []) {
        return ['ok' => true, 'items' => []];
    }
    if (empty($config['attachments_enabled'])) {
        return ['ok' => false, 'code' => 'SUPPORT_ATTACHMENTS_DISABLED', 'message' => 'Attachments are not available right now.'];
    }
    if (count($uploads) > $max) {
        return ['ok' => false, 'code' => 'SUPPORT_ATTACHMENT_LIMIT', 'message' => 'You can upload up to ' . $max . ' attachments.'];
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $root = support_private_storage_root();
    $dir = $root . DIRECTORY_SEPARATOR . date('Y-m') . DIRECTORY_SEPARATOR . $ticketId;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return ['ok' => false, 'code' => 'SUPPORT_UPLOAD_FAILED', 'message' => 'Attachment upload failed. Please try again.'];
    }

    $stored = [];
    foreach ($uploads as $file) {
        if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            support_cleanup_attachments($stored);
            return ['ok' => false, 'code' => 'SUPPORT_UPLOAD_FAILED', 'message' => 'Attachment upload failed. Please try again.'];
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp) || $size <= 0 || $size > (int)$config['max_file_size']) {
            support_cleanup_attachments($stored);
            return ['ok' => false, 'code' => 'SUPPORT_ATTACHMENT_INVALID', 'message' => 'Attachment file is invalid or too large.'];
        }
        $mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($tmp);
        }
        if (!isset($allowed[$mime])) {
            support_cleanup_attachments($stored);
            return ['ok' => false, 'code' => 'SUPPORT_ATTACHMENT_TYPE_INVALID', 'message' => 'Only JPG, PNG and WEBP screenshots are allowed.'];
        }
        $imageInfo = @getimagesize($tmp);
        if (!is_array($imageInfo)) {
            support_cleanup_attachments($stored);
            return ['ok' => false, 'code' => 'SUPPORT_ATTACHMENT_TYPE_INVALID', 'message' => 'Only valid image screenshots are allowed.'];
        }
        $id = support_attachment_id();
        $fileName = $id . '.' . $allowed[$mime];
        $target = $dir . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($tmp, $target)) {
            support_cleanup_attachments($stored);
            return ['ok' => false, 'code' => 'SUPPORT_UPLOAD_FAILED', 'message' => 'Attachment upload failed. Please try again.'];
        }
        @chmod($target, 0600);
        $relative = date('Y-m') . '/' . $ticketId . '/' . $fileName;
        $stored[] = [
            'attachment_id' => $id,
            'ticket_id' => $ticketId,
            'message_id' => $messageId,
            'uid' => $uid,
            'original_name' => support_clean_text($file['name'] ?? 'screenshot', 120),
            'mime' => $mime,
            'size' => $size,
            'relative_path' => $relative,
            'created_at' => support_now(),
        ];
    }
    return ['ok' => true, 'items' => $stored];
}

function support_cleanup_attachments(array $items): void
{
    $root = support_private_storage_root();
    foreach ($items as $row) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)($row['relative_path'] ?? ''));
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function support_attachment_absolute_path(array $row): string
{
    $root = realpath(support_private_storage_root());
    $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)($row['relative_path'] ?? ''));
    $path = $root !== false ? $root . DIRECTORY_SEPARATOR . $relative : '';
    $real = $path !== '' ? realpath($path) : false;
    if ($root === false || $real === false || strpos($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
        return '';
    }
    return is_file($real) ? $real : '';
}

function support_recent_requests_for_uid(string $uid, int $limit = 30): array
{
    $items = [];
    $month = month_key();
    $sources = [
        ['TOPUP_HISTORY/' . $uid . '/' . $month, 'MOBILE_TOPUP', 'Mobile Top-Up'],
        ['MFS_HISTORY/' . $uid . '/' . $month, 'MFS', 'MFS'],
        ['TRANSFER_HISTORY/' . $uid, 'ZPAY_TRANSFER', 'Z-Pay Transfer'],
        ['ADD_MONEY_BY_USER/' . $uid, 'ADD_MONEY', 'Add Money'],
        ['BUNDLE_HISTORY/' . $uid . '/' . $month, 'BUNDLE', 'Bundle'],
    ];
    foreach ($sources as [$path, $type, $label]) {
        $rows = fb_get($path);
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string)($row['request_id'] ?? $row['transfer_id'] ?? $row['ticket_id'] ?? $key);
            if ($id === '') {
                continue;
            }
            $amount = (string)($row['amount_text'] ?? $row['total_paid_text'] ?? $row['wallet_debit_text'] ?? $row['amount'] ?? '');
            $items[] = [
                'request_id' => $id,
                'type' => (string)($row['type'] ?? $type),
                'label' => $label,
                'title' => $id . ' - ' . $label . ($amount !== '' ? ' - ' . $amount : ''),
                'created_at' => (int)($row['created_at'] ?? $row['updated_at'] ?? 0),
            ];
        }
    }
    usort($items, static fn($a, $b) => ((int)$b['created_at'] <=> (int)$a['created_at']));
    return array_slice($items, 0, max(1, min(100, $limit)));
}

function support_verify_related_request(string $uid, string $requestId): bool
{
    $requestId = trim($requestId);
    if ($requestId === '') {
        return true;
    }
    foreach (support_recent_requests_for_uid($uid, 100) as $row) {
        if ((string)$row['request_id'] === $requestId) {
            return true;
        }
    }
    foreach ([
        'TOPUP_REQUESTS/PENDING/' . $requestId,
        'REQUEST_STATUS/' . $requestId,
        'MFS_REQUESTS/' . $requestId,
        'ADD_MONEY_REQUESTS/' . $requestId,
        'ADD_MONEY_BY_USER/' . $uid . '/' . $requestId,
        'TRANSFERS/' . $requestId,
    ] as $path) {
        $row = fb_get($path);
        if (is_array($row) && in_array($uid, [
            (string)($row['uid'] ?? ''),
            (string)($row['user_uid'] ?? ''),
            (string)($row['sender_uid'] ?? ''),
            (string)($row['receiver_uid'] ?? ''),
        ], true)) {
            return true;
        }
    }
    return false;
}

function support_create_ticket(array $auth, array $body, array $files = []): array
{
    $config = support_config();
    if (empty($config['contact_us_enabled']) || empty($config['ticket_enabled'])) {
        return ['ok' => false, 'code' => 'SUPPORT_DISABLED', 'message' => 'Support requests are not available right now.', 'status' => 503];
    }

    $uid = support_uid_from_auth($auth);
    $user = support_user_from_auth($auth);
    if ($uid === '') {
        return ['ok' => false, 'code' => 'AUTH_REQUIRED', 'message' => 'Login required.', 'status' => 401];
    }

    $idem = support_clean_text($body['idempotency_key'] ?? '', 120);
    if ($idem !== '') {
        $existing = fb_get('SUPPORT_IDEMPOTENCY/' . $uid . '/' . hash('sha256', $idem));
        $existingId = is_array($existing) ? (string)($existing['ticket_id'] ?? '') : (string)$existing;
        if ($existingId !== '') {
            $ticket = support_read_ticket($existingId);
            if ($ticket !== []) {
                return ['ok' => true, 'duplicate' => true, 'ticket' => $ticket];
            }
        }
    }

    $rate = (int)($config['ticket_rate_limit_seconds'] ?? 0);
    $last = (int)(fb_get('SUPPORT_RATE_LIMIT/' . $uid . '/last_created_at') ?? 0);
    if ($rate > 0 && $last > 0 && support_now() - $last < $rate) {
        return ['ok' => false, 'code' => 'SUPPORT_RATE_LIMITED', 'message' => 'Please wait before submitting another support request.', 'status' => 429];
    }

    $category = support_category((string)($body['category_code'] ?? $body['category'] ?? ''));
    if ($category === [] || empty($category['active'])) {
        return ['ok' => false, 'code' => 'SUPPORT_CATEGORY_INVALID', 'message' => 'Please select a valid support category.', 'status' => 422];
    }
    $subject = support_clean_text($body['subject'] ?? '', 120);
    $message = support_clean_text($body['message'] ?? $body['body'] ?? '', 2500);
    if ($subject === '') {
        return ['ok' => false, 'code' => 'SUPPORT_SUBJECT_REQUIRED', 'message' => 'Please enter a subject.', 'status' => 422];
    }
    if ($message === '' || strlen($message) < 4) {
        return ['ok' => false, 'code' => 'SUPPORT_MESSAGE_REQUIRED', 'message' => 'Please describe your issue.', 'status' => 422];
    }
    $relatedId = support_clean_text($body['related_request_id'] ?? $body['request_id'] ?? '', 80);
    $relatedType = support_clean_code($body['related_type'] ?? '');
    if ($relatedId !== '' && !support_verify_related_request($uid, $relatedId)) {
        return ['ok' => false, 'code' => 'SUPPORT_REQUEST_NOT_OWNED', 'message' => 'The selected request could not be verified.', 'status' => 403];
    }

    $now = support_now();
    $ticketId = support_ticket_id();
    $messageId = support_message_id();
    $stored = support_store_attachments($files, $ticketId, $messageId, $uid, $config);
    if (empty($stored['ok'])) {
        return ['ok' => false, 'code' => $stored['code'], 'message' => $stored['message'], 'status' => 422];
    }
    $attachments = (array)($stored['items'] ?? []);
    $attachmentIds = array_values(array_map(static fn($row) => (string)$row['attachment_id'], $attachments));

    $ticket = [
        'ticket_id' => $ticketId,
        'uid' => $uid,
        'user_name' => (string)($user['name'] ?? ''),
        'user_phone' => (string)($user['phone'] ?? ''),
        'user_email' => (string)($user['email'] ?? ''),
        'category_code' => $category['code'],
        'category_name' => $category['name'],
        'category_name_snapshot' => $category['name'],
        'related_type' => $relatedType,
        'related_request_id' => $relatedId,
        'subject' => $subject,
        'status' => 'OPEN',
        'attachment_count' => count($attachments),
        'created_at' => $now,
        'updated_at' => $now,
        'last_message_at' => $now,
        'last_message_by' => 'USER',
        'last_message_preview' => $message,
    ];
    $msg = [
        'message_id' => $messageId,
        'ticket_id' => $ticketId,
        'sender_uid' => $uid,
        'sender_type' => 'USER',
        'message' => $message,
        'attachment_ids' => $attachmentIds,
        'created_at' => $now,
        'read_by_user' => true,
        'read_by_admin' => false,
    ];
    $ok = fb_put('SUPPORT_TICKETS/' . $ticketId, $ticket)
        && fb_put('SUPPORT_USER_INDEX/' . $uid . '/' . $ticketId, ['ticket_id' => $ticketId, 'updated_at' => $now, 'status' => 'OPEN'])
        && fb_put('SUPPORT_MESSAGES/' . $ticketId . '/' . $messageId, $msg);

    if (!$ok) {
        support_cleanup_attachments($attachments);
        return ['ok' => false, 'code' => 'SUPPORT_CREATE_FAILED', 'message' => 'Support request could not be submitted.', 'status' => 500];
    }
    foreach ($attachments as $attachment) {
        fb_put('SUPPORT_ATTACHMENTS/' . $ticketId . '/' . $attachment['attachment_id'], $attachment);
    }
    if ($idem !== '') {
        fb_put('SUPPORT_IDEMPOTENCY/' . $uid . '/' . hash('sha256', $idem), ['ticket_id' => $ticketId, 'created_at' => $now]);
    }
    fb_put('SUPPORT_RATE_LIMIT/' . $uid, ['last_created_at' => $now]);
    support_notify_telegram_new_ticket($ticket);
    return ['ok' => true, 'ticket' => $ticket];
}

function support_notify_telegram_new_ticket(array $ticket): void
{
    $ticketId = (string)($ticket['ticket_id'] ?? '');
    if ($ticketId === '') {
        return;
    }
    $message = "New Support Ticket\n\n"
        . "Ticket: " . $ticketId . "\n"
        . "User: " . support_clean_text($ticket['user_name'] ?? '', 80) . "\n"
        . "UID: " . (string)($ticket['uid'] ?? '') . "\n"
        . "Phone: " . (string)($ticket['user_phone'] ?? '') . "\n"
        . "Category: " . (string)($ticket['category_name'] ?? '') . "\n"
        . "Related Request: " . ((string)($ticket['related_request_id'] ?? '') ?: '-') . "\n"
        . "Subject: " . (string)($ticket['subject'] ?? '') . "\n"
        . "Status: OPEN";
    $queueId = telegram_queue_create('SUPPORT_TICKET', $ticketId, $message);
    $sent = support_telegram_send_message($message, support_telegram_keyboard($ticketId));
    if (!empty($sent['ok'])) {
        telegram_queue_mark_sent($queueId);
        fb_patch('SUPPORT_TICKETS/' . $ticketId, ['telegram_sent' => true, 'telegram_sent_at' => support_now()]);
    } else {
        telegram_queue_mark_failed($queueId, (string)($sent['message'] ?? 'Telegram send failed'));
        fb_patch('SUPPORT_TICKETS/' . $ticketId, ['telegram_sent' => false, 'telegram_error' => substr((string)($sent['message'] ?? ''), 0, 200)]);
    }
}

function support_telegram_send_message(string $message, array $replyMarkup = []): array
{
    if (TELEGRAM_BOT_TOKEN === '' || TELEGRAM_CHAT_ID === '') {
        return [
            'ok' => false,
            'message' => 'Telegram token/chat id not configured',
        ];
    }

    $payload = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $message,
        'disable_web_page_preview' => true,
    ];
    if ($replyMarkup !== []) {
        $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_SLASHES);
    }

    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'message' => $err ?: 'Telegram request failed'];
    }
    $json = json_decode($raw, true);
    if ($status >= 200 && $status < 300 && is_array($json) && !empty($json['ok'])) {
        return ['ok' => true, 'message' => 'Telegram sent', 'data' => $json];
    }
    return ['ok' => false, 'message' => is_array($json) ? (string)($json['description'] ?? 'Telegram send failed') : 'Telegram send failed'];
}

function support_list_for_uid(string $uid, string $status = '', int $limit = 50): array
{
    $idx = fb_get('SUPPORT_USER_INDEX/' . $uid);
    $rows = [];
    if (is_array($idx)) {
        foreach ($idx as $ticketId => $meta) {
            $ticket = support_read_ticket((string)$ticketId);
            if ($ticket === []) {
                continue;
            }
            if ($status !== '' && support_clean_code($ticket['status'] ?? '') !== support_clean_code($status)) {
                continue;
            }
            $rows[] = support_public_ticket($ticket);
        }
    }
    usort($rows, static fn($a, $b) => ((int)$b['last_message_at'] <=> (int)$a['last_message_at']));
    return array_slice($rows, 0, max(1, min(100, $limit)));
}

function support_details_for_user(array $auth, string $ticketId): array
{
    $ticket = support_read_ticket($ticketId);
    if ($ticket === []) {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_NOT_FOUND', 'message' => 'Support ticket was not found.', 'status' => 404];
    }
    if (!support_user_can_access($auth, $ticket)) {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_FORBIDDEN', 'message' => 'This ticket is not available.', 'status' => 403];
    }
    return ['ok' => true] + support_details_payload($ticket);
}

function support_details_payload(array $ticket): array
{
    $ticketId = (string)$ticket['ticket_id'];
    $messages = fb_get('SUPPORT_MESSAGES/' . $ticketId);
    $attachments = fb_get('SUPPORT_ATTACHMENTS/' . $ticketId);
    $messages = is_array($messages) ? $messages : [];
    $attachments = is_array($attachments) ? $attachments : [];
    uasort($messages, static fn($a, $b) => ((int)($a['created_at'] ?? 0) <=> (int)($b['created_at'] ?? 0)));
    return [
        'ticket' => support_public_ticket($ticket),
        'messages' => array_values(array_map('support_public_message', $messages)),
        'attachments' => array_values(array_map('support_public_attachment', $attachments)),
    ];
}

function support_reply(array $auth, string $ticketId, string $message, array $files = [], string $senderType = 'USER'): array
{
    $ticket = support_read_ticket($ticketId);
    if ($ticket === []) {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_NOT_FOUND', 'message' => 'Support ticket was not found.', 'status' => 404];
    }
    $uid = support_uid_from_auth($auth);
    $isAdmin = in_array($senderType, ['ADMIN', 'SUPPORT'], true);
    if (!$isAdmin && !support_user_can_access($auth, $ticket)) {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_FORBIDDEN', 'message' => 'This ticket is not available.', 'status' => 403];
    }
    $status = support_clean_code($ticket['status'] ?? 'OPEN');
    if (!$isAdmin && in_array($status, ['CLOSED'], true)) {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_CLOSED', 'message' => 'This ticket is closed.', 'status' => 409];
    }
    $message = support_clean_text($message, 2500);
    if ($message === '') {
        return ['ok' => false, 'code' => 'SUPPORT_MESSAGE_REQUIRED', 'message' => 'Please describe your issue.', 'status' => 422];
    }
    $messageId = support_message_id();
    $config = support_config();
    $stored = support_store_attachments($files, (string)$ticket['ticket_id'], $messageId, (string)$ticket['uid'], $config);
    if (empty($stored['ok'])) {
        return ['ok' => false, 'code' => $stored['code'], 'message' => $stored['message'], 'status' => 422];
    }
    $attachments = (array)($stored['items'] ?? []);
    $now = support_now();
    $newStatus = $isAdmin ? 'REPLIED' : (in_array($status, ['RESOLVED'], true) ? 'OPEN' : 'PENDING');
    $row = [
        'message_id' => $messageId,
        'ticket_id' => (string)$ticket['ticket_id'],
        'sender_uid' => $uid,
        'sender_type' => $isAdmin ? 'ADMIN' : 'USER',
        'message' => $message,
        'attachment_ids' => array_values(array_map(static fn($a) => (string)$a['attachment_id'], $attachments)),
        'created_at' => $now,
        'read_by_user' => !$isAdmin,
        'read_by_admin' => $isAdmin,
    ];
    $patch = [
        'status' => $newStatus,
        'updated_at' => $now,
        'last_message_at' => $now,
        'last_message_by' => $isAdmin ? 'ADMIN' : 'USER',
        'last_message_preview' => $message,
        'attachment_count' => (int)($ticket['attachment_count'] ?? 0) + count($attachments),
    ];
    if (!fb_put('SUPPORT_MESSAGES/' . $ticket['ticket_id'] . '/' . $messageId, $row) || !fb_patch('SUPPORT_TICKETS/' . $ticket['ticket_id'], $patch)) {
        support_cleanup_attachments($attachments);
        return ['ok' => false, 'code' => 'SUPPORT_REPLY_FAILED', 'message' => 'Reply could not be sent.', 'status' => 500];
    }
    fb_patch('SUPPORT_USER_INDEX/' . $ticket['uid'] . '/' . $ticket['ticket_id'], ['updated_at' => $now, 'status' => $newStatus]);
    foreach ($attachments as $attachment) {
        fb_put('SUPPORT_ATTACHMENTS/' . $ticket['ticket_id'] . '/' . $attachment['attachment_id'], $attachment);
    }
    $ticket = support_read_ticket((string)$ticket['ticket_id']);
    return ['ok' => true] + support_details_payload($ticket);
}

function support_admin_list(string $status = '', string $query = '', int $limit = 50): array
{
    $rows = fb_get('SUPPORT_TICKETS');
    $rows = is_array($rows) ? $rows : [];
    $out = [];
    $query = strtoupper(trim($query));
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($status !== '' && support_clean_code($row['status'] ?? '') !== support_clean_code($status)) {
            continue;
        }
        $haystack = strtoupper(implode(' ', [
            $row['ticket_id'] ?? '',
            $row['uid'] ?? '',
            $row['user_name'] ?? '',
            $row['user_phone'] ?? '',
            $row['subject'] ?? '',
            $row['related_request_id'] ?? '',
        ]));
        if ($query !== '' && strpos($haystack, $query) === false) {
            continue;
        }
        $out[] = support_public_ticket($row);
    }
    usort($out, static fn($a, $b) => ((int)$b['last_message_at'] <=> (int)$a['last_message_at']));
    return array_slice($out, 0, max(1, min(200, $limit)));
}

function support_admin_set_status(string $ticketId, string $status, array $actor = []): array
{
    $allowed = ['OPEN', 'PENDING', 'REPLIED', 'RESOLVED', 'CLOSED'];
    $status = support_clean_code($status);
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'code' => 'SUPPORT_STATUS_INVALID', 'message' => 'Invalid support status.', 'status' => 422];
    }
    $ticket = support_read_ticket($ticketId);
    if ($ticket === []) {
        return ['ok' => false, 'code' => 'SUPPORT_TICKET_NOT_FOUND', 'message' => 'Support ticket was not found.', 'status' => 404];
    }
    $now = support_now();
    $patch = [
        'status' => $status,
        'updated_at' => $now,
        'status_updated_at' => $now,
        'status_updated_by' => (string)($actor['uid'] ?? $actor['user']['uid'] ?? 'ADMIN'),
    ];
    if ($status === 'RESOLVED') {
        $patch['resolved_at'] = $now;
    }
    if ($status === 'CLOSED') {
        $patch['closed_at'] = $now;
    }
    fb_patch('SUPPORT_TICKETS/' . $ticket['ticket_id'], $patch);
    fb_patch('SUPPORT_USER_INDEX/' . $ticket['uid'] . '/' . $ticket['ticket_id'], ['updated_at' => $now, 'status' => $status]);
    $ticket = support_read_ticket((string)$ticket['ticket_id']);
    return ['ok' => true] + support_details_payload($ticket);
}
