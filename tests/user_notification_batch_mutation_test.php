<?php
declare(strict_types=1);

$now = 1788750000;
$reads = 0;
$writes = 0;
$lastPatch = [];
$notificationStore = [];
for ($index = 1; $index <= 15; $index++) {
    $id = 'N-' . str_pad((string)$index, 2, '0', STR_PAD_LEFT);
    $notificationStore[$id] = [
        'notification_id' => $id,
        'type' => 'ADMIN_NOTICE',
        'title' => 'Notice ' . $index,
        'body' => 'Test notification',
        'is_read' => false,
        'created_at' => $now - $index,
    ];
}

function now_ts(): int
{
    global $now;
    return $now;
}

function fb_get(string $path, array $query = [])
{
    global $reads, $notificationStore;
    if ($path !== 'USER_NOTIFICATIONS/U-BATCH') {
        throw new RuntimeException('Unexpected Firebase path: ' . $path);
    }
    $reads++;
    return $notificationStore;
}

function fb_patch(string $path, array $data): bool
{
    global $writes, $lastPatch, $notificationStore;
    if ($path !== 'USER_NOTIFICATIONS/U-BATCH') {
        throw new RuntimeException('Unexpected Firebase patch path: ' . $path);
    }
    $writes++;
    $lastPatch = $data;
    foreach ($data as $relativePath => $value) {
        [$id, $field] = explode('/', (string)$relativePath, 2);
        $notificationStore[$id][$field] = $value;
    }
    return true;
}

require_once dirname(__DIR__) . '/api/lib/notifications.php';

$ids = array_slice(array_keys($notificationStore), 0, 14);
$marked = notification_mark_many_read_result('U-BATCH', $ids);
if (empty($marked['ok']) || (int)$marked['marked_count'] !== 14 || (int)$marked['unread_count'] !== 1) {
    fwrite(STDERR, "FAIL: batch mark-read result is incorrect.\n");
    exit(1);
}
if ($reads !== 1 || $writes !== 1 || count($lastPatch) !== 28) {
    fwrite(STDERR, "FAIL: batch mark-read did not use one snapshot and one multi-path write.\n");
    exit(1);
}

$markedRetry = notification_mark_many_read_result('U-BATCH', $ids);
if (empty($markedRetry['ok']) || (int)$markedRetry['marked_count'] !== 14 || $reads !== 2 || $writes !== 1) {
    fwrite(STDERR, "FAIL: batch mark-read retry is not idempotent.\n");
    exit(1);
}

$deleted = notification_delete_many_result('U-BATCH', $ids);
if (empty($deleted['ok']) || (int)$deleted['deleted_count'] !== 14 || (int)$deleted['unread_count'] !== 1) {
    fwrite(STDERR, "FAIL: batch delete result is incorrect.\n");
    exit(1);
}
if ($reads !== 3 || $writes !== 2 || count($lastPatch) !== 56) {
    fwrite(STDERR, "FAIL: batch delete did not use one snapshot and one multi-path write.\n");
    exit(1);
}

$deletedRetry = notification_delete_many_result('U-BATCH', $ids);
if (empty($deletedRetry['ok']) || (int)$deletedRetry['deleted_count'] !== 14 || $reads !== 4 || $writes !== 2) {
    fwrite(STDERR, "FAIL: batch delete retry is not idempotent.\n");
    exit(1);
}

echo "User notification batch mutation test passed (4 reads, 2 writes).\n";
