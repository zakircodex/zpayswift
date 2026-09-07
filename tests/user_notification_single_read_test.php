<?php
declare(strict_types=1);

$reads = 0;
$now = 1788750000;
$querySeen = [];

function now_ts(): int
{
    global $now;
    return $now;
}

function fb_get(string $path, array $query = [])
{
    global $reads, $now, $querySeen;
    $reads++;
    $querySeen = $query;
    if ($path !== 'USER_NOTIFICATIONS/U-TEST') {
        throw new RuntimeException('Unexpected Firebase path: ' . $path);
    }
    return [
        'N-NEW' => [
            'type' => 'TRANSFER_SUCCESS',
            'title' => 'Transfer complete',
            'body' => 'Done',
            'is_read' => false,
            'created_at' => $now - 10,
        ],
        'N-READ' => [
            'type' => 'ADMIN_NOTICE',
            'title' => 'Notice',
            'body' => 'Read item',
            'is_read' => true,
            'created_at' => $now - 20,
        ],
        'N-DELETED' => [
            'type' => 'ADMIN_NOTICE',
            'title' => 'Deleted',
            'deleted' => true,
            'created_at' => $now - 5,
        ],
        'N-OLD' => [
            'type' => 'ADMIN_NOTICE',
            'title' => 'Old notice',
            'body' => 'Outside the visible window',
            'is_read' => false,
            'created_at' => $now - (31 * 24 * 60 * 60),
        ],
    ];
}

require_once dirname(__DIR__) . '/api/lib/notifications.php';

$rows = notification_rows_for_user('U-TEST');
$items = notification_list_from_rows($rows, 50, 0, 'ALL');
$unread = notification_unread_count_from_rows($rows);

if ($reads !== 1) {
    fwrite(STDERR, "FAIL: notification list snapshot was read {$reads} times.\n");
    exit(1);
}
if (($querySeen['orderBy'] ?? '') !== '"created_at"'
    || (int)($querySeen['startAt'] ?? 0) !== $now - (30 * 24 * 60 * 60)
    || (int)($querySeen['limitToLast'] ?? 0) !== 250) {
    fwrite(STDERR, "FAIL: notification snapshot is not bounded to the recent 30-day window.\n");
    exit(1);
}
if (count($items) !== 2 || ($items[0]['notification_id'] ?? '') !== 'N-NEW') {
    fwrite(STDERR, "FAIL: shared snapshot changed notification list semantics.\n");
    exit(1);
}
if ($unread !== 1) {
    fwrite(STDERR, "FAIL: shared snapshot changed unread count semantics.\n");
    exit(1);
}

echo "User notification single-read test passed (1 Firebase snapshot).\n";
