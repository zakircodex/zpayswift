<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function worker_sms_archive_clean_device_id($value): string
{
    $deviceId = trim((string)$value);
    return preg_match('/^[A-Za-z0-9._-]{3,80}$/D', $deviceId) === 1 ? $deviceId : '';
}

function worker_sms_archive_clean_sender($value): string
{
    $sender = trim((string)$value);
    if ($sender === '' || strlen($sender) > 480) {
        return $sender === '' ? '' : substr($sender, 0, 480);
    }
    return $sender;
}

function worker_sms_archive_clean_body($value): string
{
    $body = trim((string)$value);
    if ($body === '' || strlen($body) > 16000) {
        return '';
    }
    return $body;
}

function worker_sms_archive_id(
    string $deviceId,
    int $receivedAt,
    int $subscriptionId,
    string $sender,
    string $body
): string {
    $canonical = implode("\n", [
        trim($deviceId),
        (string)max(0, $receivedAt),
        (string)$subscriptionId,
        trim($sender),
        trim($body),
    ]);
    $timestamp = str_pad((string)max(0, $receivedAt), 13, '0', STR_PAD_LEFT);
    return $timestamp . '-' . substr(hash('sha256', $canonical), 0, 24);
}

function worker_sms_archive_valid_id($value): bool
{
    return preg_match('/^[0-9]{13,15}-[a-f0-9]{24}$/D', trim((string)$value)) === 1;
}

function worker_sms_archive_record(array $input): array
{
    $deviceId = worker_sms_archive_clean_device_id($input['device_id'] ?? '');
    $receivedAt = (int)($input['received_at'] ?? 0);
    $subscriptionId = (int)($input['subscription_id'] ?? -1);
    $sender = worker_sms_archive_clean_sender($input['sender'] ?? '');
    $body = worker_sms_archive_clean_body($input['body'] ?? '');
    $archiveId = strtolower(trim((string)($input['archive_id'] ?? '')));
    $requestId = trim((string)($input['request_id'] ?? ''));
    $assignedSlot = strtoupper(trim((string)($input['assigned_slot'] ?? '')));
    $simMode = strtoupper(trim((string)($input['sim_mode'] ?? '')));

    if ($deviceId === '' || $receivedAt <= 0 || $body === '') {
        return [];
    }
    if ($subscriptionId < -1 || $subscriptionId > 1000000) {
        return [];
    }
    if ($requestId !== '' && preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $requestId) !== 1) {
        $requestId = '';
    }
    if (!in_array($assignedSlot, ['', 'SIM1', 'SIM2'], true)) {
        $assignedSlot = '';
    }
    if (!in_array($simMode, ['', 'NAGAD', 'RETAILER'], true)) {
        $simMode = '';
    }

    $expectedId = worker_sms_archive_id($deviceId, $receivedAt, $subscriptionId, $sender, $body);
    if (!worker_sms_archive_valid_id($archiveId) || !hash_equals($expectedId, $archiveId)) {
        return [];
    }

    return [
        'archive_id' => $archiveId,
        'device_id' => $deviceId,
        'received_at' => $receivedAt,
        'sender' => $sender,
        'body' => $body,
        'subscription_id' => $subscriptionId,
        'request_id' => $requestId,
        'assigned_slot' => $assignedSlot,
        'sim_mode' => $simMode,
    ];
}

function worker_sms_archive_store(array $record): bool
{
    $archiveId = strtolower(trim((string)($record['archive_id'] ?? '')));
    if (!worker_sms_archive_valid_id($archiveId)) {
        return false;
    }

    $existing = fb_get('WORKER_SMS_ARCHIVE/' . $archiveId);
    $now = function_exists('now_ts') ? (int)now_ts() : time();
    $record['archive_id'] = $archiveId;
    $record['stored_at'] = is_array($existing) && (int)($existing['stored_at'] ?? 0) > 0
        ? (int)$existing['stored_at']
        : $now;
    $record['last_uploaded_at'] = $now;

    return fb_put('WORKER_SMS_ARCHIVE/' . $archiveId, $record);
}

function worker_sms_archive_page(int $limit, string $cursor = ''): array
{
    $limit = max(1, min(100, $limit));
    $cursor = strtolower(trim($cursor));
    if ($cursor !== '' && !worker_sms_archive_valid_id($cursor)) {
        return [
            'ok' => false,
            'code' => 'INVALID_CURSOR',
            'message' => 'Invalid SMS archive cursor',
        ];
    }

    $query = [
        'orderBy' => json_encode('$key', JSON_UNESCAPED_SLASHES),
        'limitToLast' => $limit + ($cursor !== '' ? 2 : 1),
    ];
    if ($cursor !== '') {
        $query['endAt'] = json_encode($cursor, JSON_UNESCAPED_SLASHES);
    }
    $rows = fb_get('WORKER_SMS_ARCHIVE', $query);
    $rows = is_array($rows) ? $rows : [];
    if ($cursor !== '') {
        unset($rows[$cursor]);
    }

    ksort($rows, SORT_STRING);
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        $rows = array_slice($rows, -$limit, null, true);
    }
    $rows = array_reverse($rows, true);

    $items = [];
    foreach ($rows as $archiveId => $row) {
        if (!worker_sms_archive_valid_id($archiveId) || !is_array($row)) {
            continue;
        }
        $items[] = [
            'archive_id' => (string)$archiveId,
            'device_id' => (string)($row['device_id'] ?? ''),
            'received_at' => (int)($row['received_at'] ?? 0),
            'sender' => (string)($row['sender'] ?? ''),
            'body' => (string)($row['body'] ?? ''),
            'subscription_id' => (int)($row['subscription_id'] ?? -1),
            'request_id' => (string)($row['request_id'] ?? ''),
            'assigned_slot' => (string)($row['assigned_slot'] ?? ''),
            'sim_mode' => (string)($row['sim_mode'] ?? ''),
            'stored_at' => (int)($row['stored_at'] ?? 0),
        ];
    }

    $nextCursor = $hasMore && $items
        ? (string)($items[count($items) - 1]['archive_id'] ?? '')
        : '';

    return [
        'ok' => true,
        'items' => $items,
        'pagination' => [
            'limit' => $limit,
            'cursor' => $cursor,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore && $nextCursor !== '',
        ],
    ];
}

function worker_sms_archive_delete(string $archiveId): array
{
    $archiveId = strtolower(trim($archiveId));
    if (!worker_sms_archive_valid_id($archiveId)) {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'Invalid archive_id'];
    }
    $existing = fb_get('WORKER_SMS_ARCHIVE/' . $archiveId);
    if (!is_array($existing)) {
        return ['ok' => true, 'deleted' => false, 'record' => []];
    }
    if (!fb_delete('WORKER_SMS_ARCHIVE/' . $archiveId)) {
        return ['ok' => false, 'code' => 'SERVER_ERROR', 'message' => 'Failed to delete SMS'];
    }
    return ['ok' => true, 'deleted' => true, 'record' => $existing];
}
