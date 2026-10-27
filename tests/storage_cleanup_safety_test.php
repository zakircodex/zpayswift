<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$root = dirname(__DIR__);
$assertions = 0;
$fixtureRoot = sys_get_temp_dir() . '/zpay-storage-cleanup-' . bin2hex(random_bytes(6));
$roots = [
    'add_money_receipts' => $fixtureRoot . '/add-money',
    'notification_images' => $fixtureRoot . '/notifications',
    'support_attachments' => $fixtureRoot . '/support',
    'old_app_releases' => $fixtureRoot . '/downloads',
];
foreach ($roots as $path) {
    mkdir($path, 0700, true);
}

function storage_test_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function storage_test_file(string $path, int $modifiedAt, string $contents = 'fixture'): string
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents($path, $contents);
    touch($path, $modifiedAt);
    return $path;
}

function storage_test_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

require_once $root . '/api/lib/storage_cleanup.php';

$now = 1793059200;
$old = $now - (120 * 86400);
$older = $now - (180 * 86400);
$recent = $now - (10 * 86400);

$approvedId = 'AM20260101010101ABCDEF12';
$pendingId = 'AM20260101010102ABCDEF12';
$recentId = 'AM20260101010103ABCDEF12';
$approvedRelative = '2026-01/' . $approvedId . '.jpg';
$pendingRelative = '2026-01/' . $pendingId . '.jpg';
$recentRelative = '2026-01/' . $recentId . '.jpg';
$approvedFile = storage_test_file($roots['add_money_receipts'] . '/' . $approvedRelative, $old, 'approved-receipt');
$pendingFile = storage_test_file($roots['add_money_receipts'] . '/' . $pendingRelative, $old, 'pending-receipt');
$recentFile = storage_test_file($roots['add_money_receipts'] . '/' . $recentRelative, $old, 'recent-terminal-receipt');
storage_test_file($roots['add_money_receipts'] . '/2026-01/unrecognized.jpg', $older, 'unknown');

$requests = [
    $approvedId => [
        'request_id' => $approvedId,
        'uid' => 'UID_APPROVED',
        'status' => 'APPROVED',
        'approved_at' => $old,
        'updated_at' => $old,
        'receipt_path' => 'storage/add_money/' . $approvedRelative,
        'receipt_token' => 'TOKEN_APPROVED',
        'receipt_hash' => 'HASH_MUST_STAY',
    ],
    $pendingId => [
        'request_id' => $pendingId,
        'uid' => 'UID_PENDING',
        'status' => 'PENDING',
        'created_at' => $older,
        'updated_at' => $older,
        'receipt_path' => 'storage/add_money/' . $pendingRelative,
        'receipt_token' => 'TOKEN_PENDING',
    ],
    $recentId => [
        'request_id' => $recentId,
        'uid' => 'UID_RECENT',
        'status' => 'APPROVED',
        'approved_at' => $recent,
        'updated_at' => $recent,
        'receipt_path' => 'storage/add_money/' . $recentRelative,
        'receipt_token' => 'TOKEN_RECENT',
    ],
];

$notificationFile = storage_test_file(
    $roots['notification_images'] . '/2026-01/NI20260101010101ABCDEF12.jpg',
    $old,
    'old-notification'
);
$recentNotification = storage_test_file(
    $roots['notification_images'] . '/2026-10/NI20261017010101ABCDEF12.jpg',
    $old,
    'recent-notification'
);
storage_test_file($roots['notification_images'] . '/2026-01/random-banner.jpg', $older, 'unknown-notification');

$closedTicketId = 'SP20260101010101ABCDEF12';
$openTicketId = 'SP20260101010102ABCDEF12';
$closedAttachmentId = 'ATT20260101010101ABCDEF';
$openAttachmentId = 'ATT20260101010102ABCDEF';
$closedAttachmentName = $closedAttachmentId . '.png';
$openAttachmentName = $openAttachmentId . '.png';
$closedAttachmentRelative = '2026-01/' . $closedTicketId . '/' . $closedAttachmentName;
$openAttachmentRelative = '2026-01/' . $openTicketId . '/' . $openAttachmentName;
$closedAttachmentFile = storage_test_file($roots['support_attachments'] . '/' . $closedAttachmentRelative, $old, 'closed-ticket');
$openAttachmentFile = storage_test_file($roots['support_attachments'] . '/' . $openAttachmentRelative, $old, 'open-ticket');
$tickets = [
    $closedTicketId => ['status' => 'CLOSED', 'closed_at' => $old, 'updated_at' => $old],
    $openTicketId => ['status' => 'OPEN', 'created_at' => $older, 'updated_at' => $old],
];
$attachments = [
    $closedTicketId => [
        $closedAttachmentId => [
            'attachment_id' => $closedAttachmentId,
            'ticket_id' => $closedTicketId,
            'relative_path' => $closedAttachmentRelative,
            'created_at' => $old,
        ],
    ],
    $openTicketId => [
        $openAttachmentId => [
            'attachment_id' => $openAttachmentId,
            'ticket_id' => $openTicketId,
            'relative_path' => $openAttachmentRelative,
            'created_at' => $old,
        ],
    ],
];

$currentApk = storage_test_file($roots['old_app_releases'] . '/Z-Pay-Swift-1.18.apk', $older, 'current');
$rollbackApk = storage_test_file($roots['old_app_releases'] . '/Z-Pay-Swift-1.17.apk', $old + 500, 'rollback');
$obsoleteApk = storage_test_file($roots['old_app_releases'] . '/Z-Pay-Swift-1.16.apk', $older, 'obsolete');
storage_test_file($roots['old_app_releases'] . '/other-app.apk', $old + 1000, 'unknown-apk');

$deletedTokens = [];
$auditRows = [];
$dependencies = [
    'list_add_money_requests' => static function () use (&$requests): array {
        return $requests;
    },
    'read_add_money_request' => static function (string $requestId) use (&$requests): array {
        return $requests[$requestId] ?? [];
    },
    'list_support_tickets' => static function () use (&$tickets): array {
        return $tickets;
    },
    'list_support_attachments' => static function () use (&$attachments): array {
        return $attachments;
    },
    'read_support_ticket' => static function (string $ticketId) use (&$tickets): array {
        return $tickets[$ticketId] ?? [];
    },
    'read_support_attachments' => static function (string $ticketId) use (&$attachments): array {
        return $attachments[$ticketId] ?? [];
    },
    'patch_add_money_request' => static function (string $requestId, array $patch) use (&$requests): bool {
        if (!isset($requests[$requestId])) {
            return false;
        }
        $requests[$requestId] = array_replace($requests[$requestId], $patch);
        return true;
    },
    'delete_add_money_token' => static function (string $token) use (&$deletedTokens): bool {
        if ($token !== '') {
            $deletedTokens[] = $token;
        }
        return true;
    },
    'patch_support_attachment' => static function (string $ticketId, string $attachmentId, array $patch) use (&$attachments): bool {
        if (!isset($attachments[$ticketId][$attachmentId])) {
            return false;
        }
        $attachments[$ticketId][$attachmentId] = array_replace($attachments[$ticketId][$attachmentId], $patch);
        return true;
    },
    'write_audit' => static function (string $path, array $row) use (&$auditRows): bool {
        $auditRows[$path] = $row;
        return true;
    },
    'patch_audit' => static function (string $path, array $row) use (&$auditRows): bool {
        $auditRows[$path] = array_replace($auditRows[$path] ?? [], $row);
        return true;
    },
    'delete_file' => static fn(string $path): bool => is_file($path) && unlink($path),
];
$options = [
    'now' => $now,
    'roots' => $roots,
    'current_apk' => 'Z-Pay-Swift-1.18.apk',
    'limit' => 100,
];
$categories = array_keys(storage_cleanup_categories());

$scan = storage_cleanup_scan($categories, $options, $dependencies);
storage_test_expect(storage_cleanup_retention_days() >= 90, 'Retention must never be shorter than 90 days.');
storage_test_expect((int)$scan['total_files'] === 4, 'Only one safe file from each cleanup category should be eligible.');
storage_test_expect((int)$scan['categories']['add_money_receipts']['eligible_files'] === 1, 'Only terminal Add Money receipts should be eligible.');
storage_test_expect((int)$scan['categories']['support_attachments']['eligible_files'] === 1, 'Only closed support ticket attachments should be eligible.');
storage_test_expect((int)$scan['categories']['old_app_releases']['eligible_files'] === 1, 'Current and rollback APKs must remain protected.');

$unknownCurrentOptions = array_replace($options, ['current_apk' => 'Z-Pay-Swift-missing.apk']);
$unknownCurrentScan = storage_cleanup_scan(['old_app_releases'], $unknownCurrentOptions, $dependencies);
storage_test_expect((int)$unknownCurrentScan['total_files'] === 0, 'APK cleanup must stop when the configured current release cannot be verified.');

$preview = storage_cleanup_public_preview($scan);
storage_test_expect(!isset($preview['sample_items'][0]['path']), 'Preview must not expose absolute storage paths.');
storage_test_expect(!isset($preview['sample_items'][0]['relative_path']), 'Preview must not expose private relative paths.');
storage_test_expect($preview['confirmation_phrase'] === 'DELETE 90 DAY FILES', 'Cleanup must require the explicit confirmation phrase.');

$result = storage_cleanup_execute($categories, 'ADMIN_TEST', $options, $dependencies);
storage_test_expect(!empty($result['ok']), 'Eligible cleanup should complete without warnings.');
storage_test_expect((int)$result['deleted_files'] === 4, 'The bounded run should delete exactly four eligible files.');
storage_test_expect(!is_file($approvedFile), 'Old terminal Add Money receipt was not deleted.');
storage_test_expect(is_file($pendingFile), 'Pending Add Money receipt must never be deleted.');
storage_test_expect(is_file($recentFile), 'Recently finalized Add Money receipt must remain protected.');
storage_test_expect(!is_file($notificationFile) && is_file($recentNotification), 'Notification retention boundary is incorrect.');
storage_test_expect(!is_file($closedAttachmentFile) && is_file($openAttachmentFile), 'Open support ticket attachment must remain protected.');
storage_test_expect(is_file($currentApk) && is_file($rollbackApk) && !is_file($obsoleteApk), 'APK current/rollback protection failed.');
storage_test_expect(!empty($requests[$approvedId]['receipt_archived']), 'Deleted Add Money receipt metadata must be archived.');
storage_test_expect(($requests[$approvedId]['receipt_path'] ?? 'missing') === '', 'Archived Add Money receipt path must be cleared.');
storage_test_expect(($requests[$approvedId]['receipt_hash'] ?? '') === 'HASH_MUST_STAY', 'Financial receipt hash must be preserved.');
storage_test_expect($deletedTokens === ['TOKEN_APPROVED'], 'Only the deleted receipt capability token should be removed.');
storage_test_expect(!empty($attachments[$closedTicketId][$closedAttachmentId]['archived']), 'Deleted support attachment metadata must be archived.');
storage_test_expect(count($auditRows) === 1 && (string)array_values($auditRows)[0]['status'] === 'COMPLETED', 'Cleanup must leave a completed audit record.');

$failureId = 'AM20260101010104ABCDEF12';
$failureRelative = '2026-01/' . $failureId . '.jpg';
$failureFile = storage_test_file($roots['add_money_receipts'] . '/' . $failureRelative, $old, 'metadata-failure');
$requests[$failureId] = [
    'request_id' => $failureId,
    'uid' => 'UID_FAILURE',
    'status' => 'APPROVED',
    'approved_at' => $old,
    'updated_at' => $old,
    'receipt_path' => 'storage/add_money/' . $failureRelative,
    'receipt_token' => 'TOKEN_FAILURE',
];
$failureDependencies = $dependencies;
$failureDependencies['patch_add_money_request'] = static fn(string $requestId, array $patch): bool => false;
$failureResult = storage_cleanup_execute(
    ['add_money_receipts'],
    'ADMIN_TEST',
    $options,
    $failureDependencies
);
storage_test_expect(empty($failureResult['ok']) && (int)$failureResult['metadata_failures'] === 1, 'Metadata failure must produce an audited partial result.');
storage_test_expect(is_file($failureFile), 'Receipt must be restored when its metadata cannot be archived.');
storage_test_expect(($requests[$failureId]['receipt_path'] ?? '') === 'storage/add_money/' . $failureRelative, 'Failed metadata cleanup must preserve the receipt reference.');
storage_test_expect(!in_array('TOKEN_FAILURE', $deletedTokens, true), 'Failed metadata cleanup must preserve its receipt capability token.');

storage_test_remove_tree($fixtureRoot);
fwrite(STDOUT, "PASS: {$assertions} storage cleanup safety assertions\n");
