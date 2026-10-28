<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
define('STORAGE_CLEANUP_EMAIL_BACKUP_ENABLED', true);
define('STORAGE_CLEANUP_EMAIL_BACKUP_RECIPIENT', 'old@example.com');

$assertions = 0;
$fixture = sys_get_temp_dir() . '/zpay-cleanup-settings-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700, true);
$imagePath = $fixture . '/NI20200101000000ABCDEF12.png';
$records = [];
$versions = [];
$readFailure = null;
$onRead = null;
$onWrite = null;
$onSend = null;
$messages = [];
$deletes = 0;
$settingsReads = 0;
$auditRows = [];

function settings_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function settings_config(?array $row): void
{
    global $records, $versions;
    $records['APP_CONFIG'] = $row;
    $versions['APP_CONFIG'] = ($versions['APP_CONFIG'] ?? 0) + 1;
}

function settings_row(string $recipient = 'new@example.com', bool $enabled = true): array
{
    return ['storage_cleanup_email_enabled' => $enabled,
        'storage_cleanup_email_recipient' => $recipient,
        'storage_cleanup_email_revision' => str_repeat('b', 32)];
}

function fb_request(string $method, string $path, mixed $data = null, array $query = [], array $headers = [], bool $includeHeaders = false): array
{
    global $records, $versions, $readFailure, $onRead, $settingsReads;
    settings_expect($method === 'GET', 'Only mocked GET reads are expected.');
    settings_expect($includeHeaders && in_array('X-Firebase-ETag: true', $headers, true), 'Reads must request a server version.');
    if ($path === 'APP_CONFIG') {
        $settingsReads++;
    }
    if (is_callable($onRead)) {
        $onRead($path);
    }
    if ($path === 'APP_CONFIG' && $readFailure !== null) {
        if ($readFailure instanceof Throwable) {
            throw $readFailure;
        }
        return $readFailure;
    }
    $row = $records[$path] ?? null;
    if (is_array($row)) {
        ksort($row);
    }
    return ['ok' => true, 'status' => 200, 'body' => json_encode($row, JSON_THROW_ON_ERROR),
        'json' => $row, 'headers' => ['etag' => '"' . ($versions[$path] ?? 0) . '"']];
}

function fb_put_if_match(string $path, mixed $data, string $etag): array
{
    global $records, $versions, $onWrite;
    settings_expect(str_starts_with($path, 'STORAGE_CLEANUP_EMAIL_BACKUPS/'), 'Writes must remain inside mocked receipt storage.');
    if (is_callable($onWrite) && $onWrite($path, $data) === false) {
        return ['ok' => false, 'status' => 503];
    }
    if ($etag !== '"' . ($versions[$path] ?? 0) . '"') {
        return ['ok' => false, 'status' => 412];
    }
    $records[$path] = $data;
    $versions[$path] = ($versions[$path] ?? 0) + 1;
    return ['ok' => true, 'status' => 200];
}

require_once dirname(__DIR__) . '/api/lib/storage_cleanup.php';
require_once dirname(__DIR__) . '/api/lib/storage_cleanup_email.php';

function settings_reset(): void
{
    global $records, $versions, $readFailure, $onRead, $onWrite, $onSend, $messages,
        $deletes, $settingsReads, $auditRows, $imagePath;
    $records = $versions = $messages = $auditRows = [];
    $readFailure = $onRead = $onWrite = $onSend = null;
    $deletes = $settingsReads = 0;
    settings_config(settings_row());
    file_put_contents($imagePath, 'isolated-image-fixture');
    touch($imagePath, 1577836800);
    clearstatcache(true, $imagePath);
}

function settings_backup(array $candidates, string $runId, ?array $approved = null): array
{
    global $fixture, $messages, $onSend;
    return storage_cleanup_email_backup_candidates($candidates, $runId, [
        'roots' => static fn(): array => ['notification_images' => $fixture],
        'send_message' => static function (string $to, string $subject, string $body, string $headers) use (&$messages, &$onSend): mixed {
            $messages[] = $to;
            return is_callable($onSend) ? $onSend() : true;
        },
        'now' => static fn(): int => 1800000000,
    ], $approved);
}

function settings_execute(?array $approved = null, ?callable $afterBackup = null): array
{
    global $fixture, $deletes, $auditRows;
    return storage_cleanup_execute(['notification_images'], 'SYSTEM_AUTOMATIC_STORAGE_CLEANUP', [
        'now' => 1800000000, 'roots' => ['notification_images' => $fixture], 'trigger' => 'AUTOMATIC',
        'pre_delete_batch' => static function (array $candidates, string $runId) use ($approved, $afterBackup): array {
            $result = settings_backup($candidates, $runId, $approved);
            if ($afterBackup !== null) {
                $afterBackup($result);
            }
            return $result;
        },
    ], [
        'write_audit' => static function (string $path, array $row) use (&$auditRows): bool {
            $auditRows[$path] = $row;
            return true;
        },
        'patch_audit' => static function (string $path, array $row) use (&$auditRows): bool {
            $auditRows[$path] = array_replace($auditRows[$path] ?? [], $row);
            return true;
        },
        'delete_file' => static function (string $path) use (&$deletes, $fixture): bool {
            settings_expect(realpath(dirname($path)) === realpath($fixture), 'Deletion may only target the isolated fixture.');
            $deletes++;
            return true;
        },
    ]);
}

try {
    $failures = [
        'timeout' => ['ok' => false, 'status' => 0, 'body' => null],
        'permission' => ['ok' => false, 'status' => 403, 'body' => '{"error":"denied"}'],
        'server' => ['ok' => false, 'status' => 503, 'body' => null],
        'parse' => ['ok' => true, 'status' => 200, 'body' => '{broken'],
        'scalar' => ['ok' => true, 'status' => 200, 'body' => 'false'],
        'list' => ['ok' => true, 'status' => 200, 'body' => '[]'],
        'empty' => ['ok' => true, 'status' => 200, 'body' => ''],
        'schema' => ['ok' => true, 'status' => 200, 'body' => '{"storage_cleanup_email_enabled":"true","storage_cleanup_email_recipient":"old@example.com"}', 'headers' => ['etag' => '"1"']],
        'no_version' => ['ok' => true, 'status' => 200, 'body' => '{}'],
        'exception' => new RuntimeException('mock timeout'),
    ];
    foreach ($failures as $name => $failure) {
        settings_reset();
        $readFailure = $failure;
        $settings = storage_cleanup_email_settings();
        settings_expect(!$settings['ok'] && !$settings['enabled'] && !$settings['ready'] && $settings['recipient'] === '', $name . ': uncertain read must not select defaults.');
        $result = settings_execute();
        settings_expect(!$result['ok'] && $deletes === 0 && $messages === [], $name . ': no send or deletion.');
        settings_expect(str_starts_with($result['email_backup']['code'], 'EMAIL_SETTINGS_'), $name . ': explicit settings error required.');
    }

    settings_reset();
    settings_config(settings_row('new@example.com', false));
    settings_expect(storage_cleanup_email_settings()['enabled'] === false, 'Admin disable overrides enabled private defaults.');
    $readFailure = $failures['timeout'];
    settings_expect(!storage_cleanup_email_settings()['ready'], 'Read failure cannot re-enable Admin-disabled backups.');
    settings_execute();
    settings_expect($messages === [] && $deletes === 0, 'Disabled state followed by failure must remain non-destructive.');

    settings_reset();
    settings_config(settings_row('old@example.com'));
    settings_config(settings_row('new@example.com'));
    $approved = storage_cleanup_email_settings();
    $readFailure = $failures['server'];
    $result = settings_execute($approved);
    settings_expect(!$result['ok'] && $messages === [] && $deletes === 0, 'A to B followed by read failure cannot revert to A.');

    settings_reset();
    $result = settings_execute(storage_cleanup_email_settings());
    settings_expect($result['ok'] && $messages === ['new@example.com'] && $deletes === 1, 'Enabled B must back up before deletion.');
    $receiptPath = array_values(array_filter(array_keys($records), static fn(string $p): bool => str_starts_with($p, 'STORAGE_CLEANUP_EMAIL_BACKUPS/')))[0];
    $accepted = $records[$receiptPath];
    settings_expect($accepted['state'] === 'ACCEPTED' && !empty($accepted['config_hash']), 'Receipt must bind accepted transport to approved configuration.');
    $again = settings_execute();
    settings_expect($again['ok'] && $again['email_backup']['files_reused'] === 1 && count($messages) === 1, 'Valid retry reuses the receipt without another email.');

    foreach (['recipient_hash', 'config_hash', 'file_sha256', 'size_bytes'] as $field) {
        $records[$receiptPath] = $accepted;
        $records[$receiptPath][$field] = $field === 'size_bytes' ? -1 : str_repeat('0', 64);
        $before = count($messages);
        $result = settings_execute();
        settings_expect($result['ok'] && count($messages) === $before + 1, $field . ': stale receipt requires a fresh confirmed backup.');
    }
    $records[$receiptPath] = $accepted;
    unset($records[$receiptPath]['state'], $records[$receiptPath]['config_hash']);
    $before = count($messages);
    settings_expect(settings_execute()['ok'] && count($messages) === $before + 1, 'Legacy accepted receipt requires a fresh configuration-bound backup.');
    settings_config(settings_row('third@example.com'));
    $before = count($messages);
    settings_expect(settings_execute()['ok'] && count($messages) === $before + 1
        && end($messages) === 'third@example.com', 'An old-recipient receipt must not authorize deletion after a real recipient change.');

    settings_reset();
    settings_config(null);
    $missing = storage_cleanup_email_settings();
    settings_expect($missing['ok'] && $missing['code'] === 'EMAIL_SETTINGS_MISSING' && $missing['source'] === 'BOOTSTRAP', 'Confirmed null config has a distinct initialization status.');
    settings_expect($missing['recipient'] === 'old@example.com' && $missing['enabled'], 'Confirmed missing config follows the documented private bootstrap policy.');
    $result = settings_execute($missing);
    settings_expect($result['ok'] && $messages === ['old@example.com'], 'A verified bootstrap snapshot may back up and delete.');
    settings_reset();
    $readFailure = ['ok' => true, 'status' => 200, 'body' => '{}', 'headers' => ['etag' => '"empty"']];
    settings_expect(storage_cleanup_email_settings()['source'] === 'BOOTSTRAP', 'Confirmed object with both fields absent is an initialization state.');
    $readFailure['body'] = '{"storage_cleanup_email_enabled":false}';
    settings_expect(!storage_cleanup_email_settings()['ok'], 'Partial configuration must not blend Admin data and private defaults.');

    foreach (['disabled', 'recipient', 'version', 'failure'] as $change) {
        settings_reset();
        $approved = storage_cleanup_email_settings();
        if ($change === 'disabled') {
            settings_config(settings_row('new@example.com', false));
        } elseif ($change === 'recipient') {
            settings_config(settings_row('third@example.com'));
        } elseif ($change === 'version') {
            settings_config(settings_row());
        } else {
            $readFailure = $failures['timeout'];
        }
        settings_execute($approved);
        settings_expect($messages === [] && $deletes === 0, $change . ': readiness snapshot cannot authorize changed execution.');
    }

    settings_reset();
    $approved = storage_cleanup_email_settings();
    $onRead = static function (string $path): void {
        global $settingsReads;
        if ($path === 'APP_CONFIG' && $settingsReads === 3) {
            settings_config(settings_row('third@example.com'));
        }
    };
    settings_execute($approved);
    settings_expect($messages === [] && $deletes === 0, 'Change after preparation but before send must stop transport.');

    settings_reset();
    $onRead = static function (string $path): void {
        global $settingsReads;
        if ($path === 'APP_CONFIG' && $settingsReads === 4) {
            settings_config(settings_row('new@example.com', false));
        }
    };
    settings_execute();
    settings_expect($messages === [] && $deletes === 0, 'Disable after intent creation must stop before submission.');

    settings_reset();
    $onRead = static function (string $path): void {
        global $settingsReads;
        if ($path === 'APP_CONFIG' && $settingsReads === 7) {
            settings_config(settings_row('new@example.com', false));
        }
    };
    $lastBoundary = settings_execute();
    settings_expect(!$lastBoundary['ok'] && count($messages) === 1 && $deletes === 0, 'Last settings check immediately before unlink must stop deletion.');
    settings_expect((array_values($auditRows)[0]['email_backup']['code'] ?? '') === 'EMAIL_BACKUP_NOT_READY', 'Late settings failure must be present in the persisted run audit.');

    foreach (['disabled', 'recipient', 'failure', 'receipt_failure', 'fingerprint'] as $change) {
        settings_reset();
        $result = settings_execute(null, static function (array $backup) use ($change, $failures, $imagePath): void {
            global $readFailure, $onRead;
            settings_expect(!empty($backup['ok']), 'Backup must be accepted before the deletion-boundary change.');
            if ($change === 'disabled') {
                settings_config(settings_row('new@example.com', false));
            } elseif ($change === 'recipient') {
                settings_config(settings_row('third@example.com'));
            } elseif ($change === 'failure') {
                $readFailure = $failures['timeout'];
            } elseif ($change === 'receipt_failure') {
                $onRead = static function (string $path): void {
                    if (str_starts_with($path, 'STORAGE_CLEANUP_EMAIL_BACKUPS/')) {
                        throw new RuntimeException('receipt read failed');
                    }
                };
            } else {
                file_put_contents($imagePath, 'changed-file');
                touch($imagePath, 1577836800);
                clearstatcache(true, $imagePath);
            }
        });
        settings_expect(!$result['ok'] && count($messages) === 1 && $deletes === 0, $change . ': accepted email does not override the deletion gate.');
    }

    settings_reset();
    $onSend = static function (): bool {
        settings_config(settings_row('third@example.com'));
        return true;
    };
    $result = settings_execute();
    settings_expect(!$result['ok'] && $messages === ['new@example.com'] && $deletes === 0, 'Change during mail submission stops cleanup; submitted mail cannot be recalled.');
    settings_expect($result['email_backup']['messages_sent'] === 1 && $result['email_backup']['files_sent'] === 1, 'A late failure must still report the accepted mail submission accurately.');

    foreach (['false', 'throw', 'unknown', 'intent_write', 'receipt_write', 'receipt_read', 'receipt_race'] as $failure) {
        settings_reset();
        if ($failure === 'false') {
            $onSend = static fn(): bool => false;
        } elseif ($failure === 'throw') {
            $onSend = static fn() => throw new RuntimeException('ambiguous mail outcome');
        } elseif ($failure === 'unknown') {
            $onSend = static fn() => null;
        } elseif ($failure === 'intent_write' || $failure === 'receipt_write') {
            $onWrite = static fn(string $path, array $row): bool => $row['state'] !== ($failure === 'intent_write' ? 'PENDING' : 'ACCEPTED');
        } elseif ($failure === 'receipt_read') {
            $onRead = static function (string $path): void {
                if (str_starts_with($path, 'STORAGE_CLEANUP_EMAIL_BACKUPS/')) {
                    throw new RuntimeException('receipt unavailable');
                }
            };
        } else {
            $onWrite = static function (string $path, array $row): bool {
                global $versions;
                $versions[$path] = ($versions[$path] ?? 0) + 1;
                return true;
            };
        }
        $result = settings_execute();
        settings_expect(!$result['ok'] && $deletes === 0, $failure . ': no destructive continuation.');
        $sendCount = count($messages);
        if (in_array($failure, ['throw', 'unknown', 'receipt_write'], true)) {
            $onSend = $onWrite = null;
            $retry = settings_execute();
            settings_expect(!$retry['ok'] && count($messages) === $sendCount && $deletes === 0, $failure . ': pending outcome forbids blind resend or deletion.');
        }
        if ($failure === 'false') {
            $onSend = null;
            settings_expect(settings_execute()['ok'] && count($messages) === 2, 'Definite rejection can be retried safely.');
        }
        if (in_array($failure, ['intent_write', 'receipt_read', 'receipt_race'], true)) {
            settings_expect($messages === [], $failure . ': must fail before transport.');
        }
    }

    foreach (['PENDING', 'ACCEPTED'] as $lostResponseState) {
        settings_reset();
        $onWrite = static function (string $path, array $row) use ($lostResponseState): bool {
            global $records, $versions;
            if ($row['state'] !== $lostResponseState) {
                return true;
            }
            $records[$path] = $row;
            $versions[$path] = ($versions[$path] ?? 0) + 1;
            return false;
        };
        $result = settings_execute();
        settings_expect(!$result['ok'] && $deletes === 0, 'Lost write response must stop the current run.');
        $sentBefore = count($messages);
        $onWrite = null;
        $retry = settings_execute();
        settings_expect(count($messages) === $sentBefore, 'Lost write response must not cause duplicate submission.');
        settings_expect($lostResponseState === 'PENDING'
            ? (!$retry['ok'] && $deletes === 0)
            : ($retry['ok'] && $deletes === 1), 'Only durable accepted evidence may authorize retry deletion.');
    }

    settings_reset();
    $extraPaths = [];
    for ($i = 0; $i < 10; $i++) {
        $extra = $fixture . '/NI20200101000000' . sprintf('%08X', $i) . '.png';
        file_put_contents($extra, 'isolated-batch-file');
        touch($extra, 1577836800);
        $extraPaths[] = $extra;
    }
    $onSend = static function (): bool {
        global $messages;
        return count($messages) === 1;
    };
    $result = settings_execute();
    settings_expect(!$result['ok'] && count($messages) === 2 && $deletes === 0, 'Failure in the second email batch must block all deletion.');
    settings_expect($result['email_backup']['messages_sent'] === 1 && $result['email_backup']['files_sent'] === 10, 'Partial transport success must retain accurate accepted counts.');
    $onSend = null;
    $retry = settings_execute();
    settings_expect($retry['ok'] && count($messages) === 3 && $retry['email_backup']['files_reused'] === 10
        && $deletes === 11, 'Batch retry must reuse confirmed attachments and resend only the rejected batch.');
    foreach ($extraPaths as $extra) {
        unlink($extra);
    }

    settings_reset();
    $scan = storage_cleanup_scan(['notification_images'], ['now' => 1800000000, 'roots' => ['notification_images' => $fixture]]);
    $prepared = storage_cleanup_email_prepare_candidate($scan['items'][0], ['notification_images' => $fixture], 1024 * 1024);
    file_put_contents($imagePath, 'different-content');
    $tamperedMessage = storage_cleanup_email_message([$prepared], 'FIXTURE', 1, 1);
    settings_expect(!$tamperedMessage['ok'] && $tamperedMessage['code'] === 'EMAIL_BACKUP_FILE_CHANGED', 'MIME attachments must match the fingerprint recorded in the receipt.');

    settings_reset();
    $savedCandidate = null;
    $first = settings_execute(null, static function (array $backup) use (&$savedCandidate): void {
        $savedCandidate = $backup;
    });
    $check = ($savedCandidate['authorize_delete'])(['candidate_id' => str_repeat('0', 64)]);
    settings_expect(!$check['ok'], 'A valid backup cannot authorize an unrelated candidate.');
    $disabledTest = storage_cleanup_email_send_test(['settings' => static fn(): array => storage_cleanup_email_settings_failure('EMAIL_SETTINGS_READ_FAILED'),
        'send_message' => static fn() => throw new RuntimeException('must not send')]);
    settings_expect(!$disabledTest['ok'], 'Diagnostic email must also fail closed on settings failure.');

    fwrite(STDOUT, "PASS: {$assertions} cleanup settings and receipt safety assertions\n");
} finally {
    foreach (glob($fixture . '/*.png') ?: [] as $fixtureFile) {
        unlink($fixtureFile);
    }
    rmdir($fixture);
}
