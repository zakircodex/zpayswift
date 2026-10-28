<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$root = dirname(__DIR__);
$assertions = 0;

function cleanup_email_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function cleanup_email_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = array_diff(scandir($path) ?: [], ['.', '..']);
    foreach ($items as $item) {
        $target = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($target) ? cleanup_email_remove_tree($target) : unlink($target);
    }
    rmdir($path);
}

require_once $root . '/api/lib/storage_cleanup.php';
require_once $root . '/api/lib/storage_cleanup_email.php';

$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zpay-cleanup-email-' . bin2hex(random_bytes(5));
$notificationRoot = $fixture . DIRECTORY_SEPARATOR . 'notifications';
$apkRoot = $fixture . DIRECTORY_SEPARATOR . 'downloads';
mkdir($notificationRoot, 0700, true);
mkdir($apkRoot, 0700, true);

$imagePath = $notificationRoot . DIRECTORY_SEPARATOR . 'NI20200101000000ABCDEF12.png';
$imageBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
file_put_contents($imagePath, $imageBytes);
$candidate = storage_cleanup_candidate('notification_images', [
    'relative_path' => basename($imagePath),
    'path' => $imagePath,
    'name' => basename($imagePath),
    'size_bytes' => filesize($imagePath),
    'modified_at' => 1577836800,
], 1577836800, 'EXPIRED', ['generated_at' => 1577836800]);

$receipts = [];
$messages = [];
$dependencies = [
    'settings' => static fn(): array => storage_cleanup_email_settings_from_config([
        'ok' => true, 'state' => 'VALID', 'etag' => '"config-1"',
        'row' => ['storage_cleanup_email_enabled' => true, 'storage_cleanup_email_recipient' => 'backup@example.com'],
    ]),
    'roots' => static fn(): array => [
        'notification_images' => $notificationRoot,
        'old_app_releases' => $apkRoot,
    ],
    'read_receipt' => static function (string $candidateId) use (&$receipts): array {
        return ['ok' => true, 'state' => isset($receipts[$candidateId]) ? 'VALID' : 'MISSING',
            'row' => $receipts[$candidateId] ?? [], 'etag' => '"fixture"'];
    },
    'write_receipt' => static function (string $candidateId, array $row) use (&$receipts): bool {
        $receipts[$candidateId] = $row;
        return true;
    },
    'send_message' => static function (string $to, string $subject, string $body, string $headers) use (&$messages): bool {
        $messages[] = compact('to', 'subject', 'body', 'headers');
        return true;
    },
    'now' => static fn(): int => 1800000000,
];

$result = storage_cleanup_email_backup_candidates([$candidate], 'SCRTEST001', $dependencies);
cleanup_email_expect(!empty($result['ok']), 'A safe image backup should be accepted.');
cleanup_email_expect(count($messages) === 1, 'The first backup should send exactly one message.');
cleanup_email_expect(count($receipts) === 1, 'The accepted backup must write an idempotency receipt.');
cleanup_email_expect(str_contains($messages[0]['headers'], 'multipart/mixed'), 'Backup email must use a multipart attachment envelope.');
cleanup_email_expect(str_contains($messages[0]['body'], basename($imagePath)), 'Backup email must include the safe attachment name.');
cleanup_email_expect(isset($result['fingerprints'][$candidate['candidate_id']]), 'Backup result must bind deletion to an exact file fingerprint.');

$repeat = storage_cleanup_email_backup_candidates([$candidate], 'SCRTEST002', $dependencies);
cleanup_email_expect(!empty($repeat['ok']) && (int)$repeat['files_reused'] === 1, 'A matching receipt should be reused safely.');
cleanup_email_expect(count($messages) === 1, 'An already backed-up file must not be emailed twice.');

$failedDependencies = $dependencies;
$receipts = [];
$failedDependencies['send_message'] = static fn(string $to, string $subject, string $body, string $headers): bool => false;
$failed = storage_cleanup_email_backup_candidates([$candidate], 'SCRTEST003', $failedDependencies);
cleanup_email_expect(empty($failed['ok']) && $failed['code'] === 'EMAIL_BACKUP_SEND_FAILED', 'Mail transport failure must fail closed.');

$invalidDependencies = $dependencies;
$invalidDependencies['settings'] = static fn(): array => [
    'ok' => true, 'ready' => true, 'config_hash' => 'fixture', 'read_version' => 'fixture',
    'enabled' => true,
    'recipient' => "backup@example.com\r\nBcc: attacker@example.com",
];
$invalid = storage_cleanup_email_backup_candidates([$candidate], 'SCRTEST004', $invalidDependencies);
cleanup_email_expect(empty($invalid['ok']) && $invalid['code'] === 'EMAIL_BACKUP_NOT_READY', 'Header injection in the recipient must be rejected.');

$apkPath = $apkRoot . DIRECTORY_SEPARATOR . 'Z-Pay-Swift-old.apk';
file_put_contents($apkPath, 'apk-test');
$apkCandidate = storage_cleanup_candidate('old_app_releases', [
    'relative_path' => basename($apkPath),
    'path' => $apkPath,
    'name' => basename($apkPath),
    'size_bytes' => filesize($apkPath),
    'modified_at' => 1577836800,
], 1577836800, 'SUPERSEDED');
$apkResult = storage_cleanup_email_backup_candidates([$apkCandidate], 'SCRTEST005', $failedDependencies);
cleanup_email_expect(empty($apkResult['ok']) && $apkResult['code'] === 'EMAIL_BACKUP_APK_BLOCKED', 'APK files must never enter automatic email deletion.');

$auditRows = [];
$deleteCalls = 0;
touch($imagePath, 1577836800);
$executeDependencies = [
    'write_audit' => static function (string $path, array $row) use (&$auditRows): bool {
        $auditRows[$path] = $row;
        return true;
    },
    'patch_audit' => static function (string $path, array $row) use (&$auditRows): bool {
        $auditRows[$path] = array_replace($auditRows[$path] ?? [], $row);
        return true;
    },
    'delete_file' => static function (string $path) use (&$deleteCalls): bool {
        $deleteCalls++;
        return unlink($path);
    },
];
$executeOptions = [
    'now' => 1800000000,
    'roots' => ['notification_images' => $notificationRoot],
    'limit' => 10,
    'trigger' => 'AUTOMATIC',
    'pre_delete_batch' => static fn(array $candidates, string $runId): array => [
        'ok' => false,
        'code' => 'EMAIL_BACKUP_SEND_FAILED',
    ],
];
$blocked = storage_cleanup_execute(
    ['notification_images'],
    'SYSTEM_AUTOMATIC_STORAGE_CLEANUP',
    $executeOptions,
    $executeDependencies
);
cleanup_email_expect(empty($blocked['ok']) && $blocked['code'] === 'STORAGE_CLEANUP_BACKUP_FAILED', 'Automatic deletion must stop when backup fails.');
cleanup_email_expect($deleteCalls === 0 && is_file($imagePath), 'Backup failure must result in zero file deletions.');
cleanup_email_expect((string)array_values($auditRows)[0]['status'] === 'BACKUP_FAILED', 'Backup failure must leave an explicit audit status.');

$tamperOptions = $executeOptions;
$tamperOptions['pre_delete_batch'] = static function (array $candidates, string $runId) use ($imagePath): array {
    $item = $candidates[0];
    $candidateId = strtolower((string)$item['candidate_id']);
    $fingerprint = [
        'sha256' => (string)hash_file('sha256', $imagePath),
        'size_bytes' => (int)filesize($imagePath),
    ];
    file_put_contents($imagePath, 'changed-after-backup');
    touch($imagePath, 1577836800);
    return [
        'ok' => true,
        'code' => 'EMAIL_BACKUP_ACCEPTED',
        'fingerprints' => [$candidateId => $fingerprint],
        'authorize_delete' => static fn(array $candidate): array => ['ok' => true],
    ];
};
$tampered = storage_cleanup_execute(
    ['notification_images'],
    'SYSTEM_AUTOMATIC_STORAGE_CLEANUP',
    $tamperOptions,
    $executeDependencies
);
cleanup_email_expect(empty($tampered['ok']) && (int)$tampered['deleted_files'] === 0, 'A file changed after backup must not be deleted.');
cleanup_email_expect($deleteCalls === 0 && is_file($imagePath), 'Fingerprint mismatch must remain fail-closed.');

cleanup_email_remove_tree($fixture);
fwrite(STDOUT, "PASS: {$assertions} storage cleanup email backup assertions\n");
