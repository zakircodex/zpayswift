<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function storage_cleanup_email_bool($value, bool $default = false): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
            return false;
        }
    }
    return $default;
}

function storage_cleanup_email_address($value): string
{
    $raw = (string)$value;
    if (preg_match('/[\r\n\x00]/', $raw) === 1) {
        return '';
    }
    $email = strtolower(trim($raw));
    if ($email === '' || strlen($email) > 254) {
        return '';
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
}

function storage_cleanup_email_mask(string $email): string
{
    $email = storage_cleanup_email_address($email);
    if ($email === '') {
        return '';
    }
    [$local, $domain] = explode('@', $email, 2);
    $visible = substr($local, 0, min(2, strlen($local)));
    return $visible . str_repeat('*', max(2, strlen($local) - strlen($visible))) . '@' . $domain;
}

function storage_cleanup_email_read_record(string $path): array
{
    try {
        if (!function_exists('fb_request')) {
            return ['ok' => false, 'state' => 'READ_FAILED'];
        }
        $response = fb_request('GET', $path, null, [], ['X-Firebase-ETag: true'], true);
        if (($response['ok'] ?? false) !== true || (int)($response['status'] ?? 0) !== 200) {
            return ['ok' => false, 'state' => 'READ_FAILED'];
        }
        if (!is_string($response['body'] ?? null)) {
            return ['ok' => false, 'state' => 'MALFORMED'];
        }
        $decoded = json_decode($response['body'], false, 512, JSON_THROW_ON_ERROR);
        if ($decoded !== null && !$decoded instanceof stdClass) {
            return ['ok' => false, 'state' => 'MALFORMED'];
        }
        $etag = $response['headers']['etag'] ?? null;
        if (!is_string($etag) || trim($etag) === '') {
            return ['ok' => false, 'state' => 'VERSION_UNAVAILABLE'];
        }
        return [
            'ok' => true,
            'state' => $decoded === null ? 'MISSING' : 'VALID',
            'row' => $decoded === null ? [] : json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR),
            'etag' => $etag,
        ];
    } catch (JsonException $exception) {
        return ['ok' => false, 'state' => 'MALFORMED'];
    } catch (Throwable $exception) {
        return ['ok' => false, 'state' => 'READ_FAILED'];
    }
}

function storage_cleanup_email_settings_failure(string $code): array
{
    return ['ok' => false, 'code' => $code, 'enabled' => false, 'recipient' => '',
        'recipient_masked' => '', 'ready' => false];
}

function storage_cleanup_email_settings_from_config(array $read): array
{
    if (($read['ok'] ?? false) !== true) {
        return storage_cleanup_email_settings_failure('EMAIL_SETTINGS_' . ($read['state'] ?? 'READ_FAILED'));
    }
    $row = $read['row'] ?? null;
    if (!is_array($row) || !in_array($read['state'] ?? '', ['VALID', 'MISSING'], true)
        || !is_string($read['etag'] ?? null) || $read['etag'] === ''
    ) {
        return storage_cleanup_email_settings_failure('EMAIL_SETTINGS_MALFORMED');
    }
    $hasEnabled = array_key_exists('storage_cleanup_email_enabled', $row);
    $hasRecipient = array_key_exists('storage_cleanup_email_recipient', $row);
    $hasRevision = array_key_exists('storage_cleanup_email_revision', $row);
    if ($hasEnabled !== $hasRecipient || ($hasRevision && !$hasEnabled)) {
        return storage_cleanup_email_settings_failure('EMAIL_SETTINGS_MALFORMED');
    }

    $defaultEnabled = defined('STORAGE_CLEANUP_EMAIL_BACKUP_ENABLED')
        && constant('STORAGE_CLEANUP_EMAIL_BACKUP_ENABLED') === true;
    $defaultRecipient = defined('STORAGE_CLEANUP_EMAIL_BACKUP_RECIPIENT')
        ? storage_cleanup_email_address(constant('STORAGE_CLEANUP_EMAIL_BACKUP_RECIPIENT'))
        : '';
    $rawEnabled = $hasEnabled ? $row['storage_cleanup_email_enabled'] : $defaultEnabled;
    $rawRecipient = $hasRecipient ? $row['storage_cleanup_email_recipient'] : $defaultRecipient;
    $revision = $row['storage_cleanup_email_revision'] ?? 'legacy';
    if (!is_bool($rawEnabled) || !is_string($rawRecipient) || !is_string($revision)
        || ($hasRevision && preg_match('/^[a-f0-9]{32}$/D', $revision) !== 1)
    ) {
        return storage_cleanup_email_settings_failure('EMAIL_SETTINGS_MALFORMED');
    }
    $enabled = $rawEnabled;
    $recipient = storage_cleanup_email_address($rawRecipient);
    if (($rawRecipient !== '' && $recipient === '') || ($enabled && $recipient === '')) {
        return storage_cleanup_email_settings_failure('EMAIL_SETTINGS_MALFORMED');
    }
    $source = $hasEnabled ? 'ADMIN' : 'BOOTSTRAP';

    return [
        'ok' => true,
        'code' => 'EMAIL_SETTINGS_' . $read['state'],
        'source' => $source,
        'read_version' => $read['etag'],
        'config_hash' => hash('sha256', json_encode([$source, $enabled, $recipient, $revision], JSON_THROW_ON_ERROR)),
        'enabled' => $enabled,
        'recipient' => $recipient,
        'recipient_masked' => storage_cleanup_email_mask($recipient),
        'ready' => $enabled && $recipient !== '' && function_exists('mail'),
    ];
}

function storage_cleanup_email_settings(): array
{
    return storage_cleanup_email_settings_from_config(storage_cleanup_email_read_record('APP_CONFIG'));
}

function storage_cleanup_email_check_settings(array $approved, array $deps): array
{
    try {
        $current = ($deps['settings'])();
    } catch (Throwable $exception) {
        return ['ok' => false, 'code' => 'EMAIL_SETTINGS_READ_FAILED'];
    }
    if (!is_array($current) || ($current['ok'] ?? false) !== true) {
        return ['ok' => false, 'code' => is_array($current)
            ? (string)($current['code'] ?? 'EMAIL_SETTINGS_READ_FAILED') : 'EMAIL_SETTINGS_READ_FAILED'];
    }
    if (($current['enabled'] ?? false) !== true || empty($current['ready'])) {
        return ['ok' => false, 'code' => 'EMAIL_BACKUP_NOT_READY'];
    }
    foreach (['config_hash', 'read_version'] as $key) {
        if (!is_string($approved[$key] ?? null) || $approved[$key] === ''
            || !hash_equals($approved[$key], (string)($current[$key] ?? ''))
        ) {
            return ['ok' => false, 'code' => 'EMAIL_SETTINGS_CHANGED'];
        }
    }
    return ['ok' => true];
}

function storage_cleanup_email_from(): string
{
    $configured = defined('STORAGE_CLEANUP_EMAIL_FROM')
        ? storage_cleanup_email_address(constant('STORAGE_CLEANUP_EMAIL_FROM'))
        : '';
    return $configured !== '' ? $configured : 'no-reply@zpayswift.com';
}

function storage_cleanup_email_max_raw_bytes(): int
{
    $configured = defined('STORAGE_CLEANUP_EMAIL_MAX_RAW_BYTES')
        ? (int)constant('STORAGE_CLEANUP_EMAIL_MAX_RAW_BYTES')
        : 12 * 1024 * 1024;
    return max(1024 * 1024, min(18 * 1024 * 1024, $configured));
}

function storage_cleanup_email_max_attachments(): int
{
    $configured = defined('STORAGE_CLEANUP_EMAIL_MAX_ATTACHMENTS')
        ? (int)constant('STORAGE_CLEANUP_EMAIL_MAX_ATTACHMENTS')
        : 10;
    return max(1, min(10, $configured));
}

function storage_cleanup_email_recipient_hash(string $email): string
{
    return hash('sha256', storage_cleanup_email_address($email));
}

function storage_cleanup_email_receipt_path(string $candidateId): string
{
    return 'STORAGE_CLEANUP_EMAIL_BACKUPS/' . strtolower($candidateId);
}

function storage_cleanup_email_default_dependencies(): array
{
    return [
        'settings' => static fn(): array => storage_cleanup_email_settings(),
        'roots' => static fn(): array => storage_cleanup_default_roots(),
        'read_receipt' => static fn(string $candidateId): array =>
            storage_cleanup_email_read_record(storage_cleanup_email_receipt_path($candidateId)),
        'write_receipt' => static fn(string $candidateId, array $row, string $etag): bool =>
            (fb_put_if_match(storage_cleanup_email_receipt_path($candidateId), $row, $etag)['ok'] ?? false) === true,
        'send_message' => static fn(string $to, string $subject, string $body, string $headers): bool =>
            @mail($to, $subject, $body, $headers),
        'now' => static fn(): int => function_exists('now_ts') ? (int)now_ts() : time(),
    ];
}

function storage_cleanup_email_mime_type(string $extension): string
{
    return match (strtolower($extension)) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        default => '',
    };
}

function storage_cleanup_email_safe_name(string $name, string $extension): string
{
    $base = (string)pathinfo($name, PATHINFO_FILENAME);
    $base = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $base), '-.');
    if ($base === '') {
        $base = 'cleanup-file';
    }
    return substr($base, 0, 100) . '.' . strtolower($extension);
}

function storage_cleanup_email_prepare_candidate(array $candidate, array $roots, int $maxBytes): array
{
    $category = strtolower(trim((string)($candidate['category'] ?? '')));
    $candidateId = strtolower(trim((string)($candidate['candidate_id'] ?? '')));
    if (preg_match('/^[a-f0-9]{64}$/D', $candidateId) !== 1) {
        return ['ok' => false, 'code' => 'EMAIL_BACKUP_INVALID_CANDIDATE'];
    }
    if ($category === 'old_app_releases') {
        return ['ok' => false, 'code' => 'EMAIL_BACKUP_APK_BLOCKED'];
    }
    $root = (string)($roots[$category] ?? '');
    $path = storage_cleanup_path_inside_root($root, (string)($candidate['path'] ?? ''));
    if ($path === '') {
        return ['ok' => false, 'code' => 'EMAIL_BACKUP_FILE_UNAVAILABLE'];
    }
    $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $mime = storage_cleanup_email_mime_type($extension);
    $size = max(0, (int)@filesize($path));
    if ($mime === '' || $size > $maxBytes) {
        return ['ok' => false, 'code' => $mime === '' ? 'EMAIL_BACKUP_TYPE_BLOCKED' : 'EMAIL_BACKUP_FILE_TOO_LARGE'];
    }
    $sha256 = @hash_file('sha256', $path);
    if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
        return ['ok' => false, 'code' => 'EMAIL_BACKUP_HASH_FAILED'];
    }

    return [
        'ok' => true,
        'candidate_id' => $candidateId,
        'category' => $category,
        'path' => $path,
        'name' => storage_cleanup_email_safe_name((string)($candidate['name'] ?? basename($path)), $extension),
        'mime' => $mime,
        'size_bytes' => $size,
        'sha256' => $sha256,
        'reference' => trim((string)(
            $candidate['metadata']['request_id']
            ?? $candidate['metadata']['ticket_id']
            ?? $candidate['name']
            ?? ''
        )),
    ];
}

function storage_cleanup_email_receipt_matches(array $receipt, array $file, string $recipientHash, string $configHash): bool
{
    return ($receipt['accepted'] ?? false) === true
        && ($receipt['state'] ?? '') === 'ACCEPTED'
        && hash_equals((string)($receipt['candidate_id'] ?? ''), (string)$file['candidate_id'])
        && hash_equals((string)($receipt['file_sha256'] ?? ''), (string)$file['sha256'])
        && hash_equals((string)($receipt['recipient_hash'] ?? ''), $recipientHash)
        && hash_equals((string)($receipt['config_hash'] ?? ''), $configHash)
        && (int)($receipt['size_bytes'] ?? -1) === (int)$file['size_bytes'];
}

function storage_cleanup_email_groups(array $files, int $maxBytes, int $maxAttachments): array
{
    $groups = [];
    $current = [];
    $currentBytes = 0;
    foreach ($files as $file) {
        $size = max(0, (int)($file['size_bytes'] ?? 0));
        if ($current !== [] && (count($current) >= $maxAttachments || $currentBytes + $size > $maxBytes)) {
            $groups[] = $current;
            $current = [];
            $currentBytes = 0;
        }
        $current[] = $file;
        $currentBytes += $size;
    }
    if ($current !== []) {
        $groups[] = $current;
    }
    return $groups;
}

function storage_cleanup_email_message(array $files, string $runId, int $part, int $parts): array
{
    $boundary = '=_ZPayCleanup_' . bin2hex(random_bytes(12));
    $eol = "\r\n";
    $lines = [
        'Z-Pay Swift automatic storage cleanup backup',
        'Run ID: ' . $runId,
        'Part: ' . $part . ' of ' . $parts,
        'Files: ' . count($files),
        '',
        'Each attached file was selected under the protected 90-day retention policy.',
        'The server will not delete this batch unless every required backup email is accepted.',
        '',
        'Manifest:',
    ];
    foreach ($files as $file) {
        $lines[] = '- ' . (string)$file['category'] . ' | ' . (string)$file['reference']
            . ' | ' . (string)$file['name'] . ' | ' . (int)$file['size_bytes'] . ' bytes';
    }

    $body = '--' . $boundary . $eol
        . 'Content-Type: text/plain; charset=UTF-8' . $eol
        . 'Content-Transfer-Encoding: 8bit' . $eol . $eol
        . implode($eol, $lines) . $eol;

    foreach ($files as $file) {
        $content = @file_get_contents((string)$file['path']);
        if (!is_string($content)) {
            return ['ok' => false, 'code' => 'EMAIL_BACKUP_READ_FAILED'];
        }
        if (strlen($content) !== (int)$file['size_bytes']
            || !hash_equals((string)$file['sha256'], hash('sha256', $content))
        ) {
            return ['ok' => false, 'code' => 'EMAIL_BACKUP_FILE_CHANGED'];
        }
        $name = (string)$file['name'];
        $body .= '--' . $boundary . $eol
            . 'Content-Type: ' . (string)$file['mime'] . '; name="' . $name . '"' . $eol
            . 'Content-Disposition: attachment; filename="' . $name . '"' . $eol
            . 'Content-Transfer-Encoding: base64' . $eol . $eol
            . chunk_split(base64_encode($content), 76, $eol);
        unset($content);
    }
    $body .= '--' . $boundary . '--' . $eol;

    $from = storage_cleanup_email_from();
    $headers = implode($eol, [
        'MIME-Version: 1.0',
        'From: Z-Pay Swift <' . $from . '>',
        'Reply-To: ' . $from,
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        'X-Auto-Response-Suppress: All',
        'Auto-Submitted: auto-generated',
    ]);

    return [
        'ok' => true,
        'subject' => 'Z-Pay Swift cleanup backup ' . $runId . ' (' . $part . '/' . $parts . ')',
        'body' => $body,
        'headers' => $headers,
    ];
}

function storage_cleanup_email_backup_candidates(
    array $candidates,
    string $runId,
    array $dependencies = [],
    ?array $approvedSettings = null
): array {
    if ($candidates === []) {
        return [
            'ok' => true,
            'code' => 'EMAIL_BACKUP_NOT_NEEDED',
            'messages_sent' => 0,
            'files_sent' => 0,
            'files_reused' => 0,
            'fingerprints' => [],
        ];
    }

    $deps = array_replace(storage_cleanup_email_default_dependencies(), $dependencies);
    $settings = $approvedSettings ?? ($deps['settings'])();
    if (!is_array($settings) || ($settings['ok'] ?? false) !== true) {
        return ['ok' => false, 'code' => is_array($settings)
            ? (string)($settings['code'] ?? 'EMAIL_SETTINGS_READ_FAILED') : 'EMAIL_SETTINGS_READ_FAILED'];
    }
    $check = storage_cleanup_email_check_settings($settings, $deps);
    if (empty($check['ok'])) {
        return $check;
    }
    $recipient = storage_cleanup_email_address($settings['recipient'] ?? '');
    if (empty($settings['enabled']) || $recipient === '' || !is_callable($deps['send_message'])) {
        return ['ok' => false, 'code' => 'EMAIL_BACKUP_NOT_READY'];
    }

    $roots = ($deps['roots'])();
    $roots = is_array($roots) ? $roots : [];
    $maxBytes = storage_cleanup_email_max_raw_bytes();
    $recipientHash = storage_cleanup_email_recipient_hash($recipient);
    $configHash = (string)$settings['config_hash'];
    $prepared = [];
    $unsent = [];
    $reused = 0;

    foreach ($candidates as $candidate) {
        $file = storage_cleanup_email_prepare_candidate((array)$candidate, $roots, $maxBytes);
        if (empty($file['ok'])) {
            return ['ok' => false, 'code' => (string)($file['code'] ?? 'EMAIL_BACKUP_PREPARE_FAILED')];
        }
        $read = ($deps['read_receipt'])((string)$file['candidate_id']);
        if (!is_array($read) || ($read['ok'] ?? false) !== true || empty($read['etag'])) {
            return ['ok' => false, 'code' => 'EMAIL_BACKUP_RECEIPT_READ_FAILED'];
        }
        $receipt = $read['row'];
        if (storage_cleanup_email_receipt_matches($receipt, $file, $recipientHash, $configHash)) {
            $reused++;
        } else {
            // A durable pending intent may already have reached the mail transport.
            $state = $receipt['state'] ?? '';
            $definitePreviousOutcome = ($state === 'FAILED' && ($receipt['accepted'] ?? null) === false)
                || (in_array($state, ['', 'ACCEPTED'], true) && ($receipt['accepted'] ?? false) === true);
            if ($receipt !== [] && !$definitePreviousOutcome) {
                return ['ok' => false, 'code' => 'EMAIL_BACKUP_OUTCOME_UNKNOWN'];
            }
            $file['receipt_etag'] = $read['etag'];
            $unsent[] = $file;
        }
        $prepared[] = $file;
    }

    $groups = storage_cleanup_email_groups(
        $unsent,
        $maxBytes,
        storage_cleanup_email_max_attachments()
    );
    $sentMessages = 0;
    $sentFiles = 0;
    $now = (int)($deps['now'])();
    $failure = static function (string $code) use (&$sentMessages, &$sentFiles, $reused, $recipient): array {
        return ['ok' => false, 'code' => $code, 'messages_sent' => $sentMessages,
            'files_sent' => $sentFiles, 'files_reused' => $reused,
            'recipient_masked' => storage_cleanup_email_mask($recipient)];
    };
    foreach ($groups as $index => $group) {
        $message = storage_cleanup_email_message($group, $runId, $index + 1, count($groups));
        if (empty($message['ok'])) {
            return $failure((string)($message['code'] ?? 'EMAIL_BACKUP_BUILD_FAILED'));
        }
        $check = storage_cleanup_email_check_settings($settings, $deps);
        if (empty($check['ok'])) {
            return $failure((string)$check['code']);
        }
        $attemptId = bin2hex(random_bytes(16));
        $intents = [];
        foreach ($group as $file) {
            $id = (string)$file['candidate_id'];
            $intent = [
                'candidate_id' => $id,
                'category' => (string)$file['category'],
                'file_sha256' => (string)$file['sha256'],
                'size_bytes' => (int)$file['size_bytes'],
                'recipient_hash' => $recipientHash,
                'config_hash' => $configHash,
                'accepted' => false,
                'state' => 'PENDING',
                'attempt_id' => $attemptId,
                'started_at' => $now,
                'run_id' => $runId,
            ];
            if (!(($deps['write_receipt'])($id, $intent, (string)$file['receipt_etag']))) {
                return $failure('EMAIL_BACKUP_AUDIT_FAILED');
            }
            $claimed = ($deps['read_receipt'])($id);
            $claimedRow = $claimed['row'] ?? null;
            $verifiedIntent = is_array($claimedRow) && count($claimedRow) === count($intent);
            foreach ($intent as $field => $value) {
                $verifiedIntent = $verifiedIntent && array_key_exists($field, $claimedRow) && $claimedRow[$field] === $value;
            }
            if (($claimed['ok'] ?? false) !== true || empty($claimed['etag'])
                || !$verifiedIntent
            ) {
                return $failure('EMAIL_BACKUP_AUDIT_FAILED');
            }
            $intents[$id] = ['row' => $intent, 'etag' => $claimed['etag']];
        }
        $check = storage_cleanup_email_check_settings($settings, $deps);
        if (empty($check['ok'])) {
            // No transport call occurred. Persist a definite non-send where possible.
            foreach ($intents as $id => $intent) {
                $row = $intent['row'];
                $row['state'] = 'FAILED';
                ($deps['write_receipt'])($id, $row, $intent['etag']);
            }
            return $failure((string)$check['code']);
        }
        try {
            $accepted = ($deps['send_message'])(
                $recipient,
                (string)$message['subject'],
                (string)$message['body'],
                (string)$message['headers']
            );
        } catch (Throwable $exception) {
            $accepted = null;
        }
        if ($accepted !== true && $accepted !== false) {
            return $failure('EMAIL_BACKUP_OUTCOME_UNKNOWN');
        }
        if ($accepted === false) {
            foreach ($intents as $id => $intent) {
                $row = $intent['row'];
                $row['state'] = 'FAILED';
                ($deps['write_receipt'])($id, $row, $intent['etag']);
            }
            return $failure('EMAIL_BACKUP_SEND_FAILED');
        }
        $sentMessages++;
        $sentFiles += count($group);

        foreach ($group as $file) {
            $id = (string)$file['candidate_id'];
            $receipt = $intents[$id]['row'];
            $receipt['state'] = 'ACCEPTED';
            $receipt['accepted'] = true;
            $receipt['accepted_at'] = $now;
            if (!($deps['write_receipt'])($id, $receipt, $intents[$id]['etag'])) {
                return $failure('EMAIL_BACKUP_AUDIT_FAILED');
            }
        }
    }

    $check = storage_cleanup_email_check_settings($settings, $deps);
    if (empty($check['ok'])) {
        return $failure((string)$check['code']);
    }

    $fingerprints = [];
    foreach ($prepared as $file) {
        $fingerprints[(string)$file['candidate_id']] = [
            'sha256' => (string)$file['sha256'],
            'size_bytes' => (int)$file['size_bytes'],
        ];
    }

    return [
        'ok' => true,
        'code' => 'EMAIL_BACKUP_ACCEPTED',
        'messages_sent' => $sentMessages,
        'files_sent' => $sentFiles,
        'files_reused' => $reused,
        'total_files' => count($prepared),
        'recipient_masked' => storage_cleanup_email_mask($recipient),
        'fingerprints' => $fingerprints,
        'authorize_delete' => static function (array $candidate) use ($settings, $deps, $prepared, $recipientHash, $configHash): array {
            $check = storage_cleanup_email_check_settings($settings, $deps);
            if (empty($check['ok'])) {
                return $check;
            }
            foreach ($prepared as $file) {
                if ($file['candidate_id'] !== ($candidate['candidate_id'] ?? '')) {
                    continue;
                }
                $read = ($deps['read_receipt'])((string)$file['candidate_id']);
                if (($read['ok'] ?? false) === true
                    && storage_cleanup_email_receipt_matches($read['row'], $file, $recipientHash, $configHash)
                ) {
                    return ['ok' => true];
                }
                break;
            }
            return ['ok' => false, 'code' => 'EMAIL_BACKUP_RECEIPT_INVALID'];
        },
    ];
}

function storage_cleanup_email_send_test(array $dependencies = [], ?array $approvedSettings = null): array
{
    $deps = array_replace(storage_cleanup_email_default_dependencies(), $dependencies);
    $settings = $approvedSettings ?? ($deps['settings'])();
    if (!is_array($settings) || ($settings['ok'] ?? false) !== true) {
        return ['ok' => false, 'code' => is_array($settings)
            ? (string)($settings['code'] ?? 'EMAIL_SETTINGS_READ_FAILED') : 'EMAIL_SETTINGS_READ_FAILED'];
    }
    $check = storage_cleanup_email_check_settings($settings, $deps);
    if (empty($check['ok'])) {
        return $check;
    }
    $recipient = storage_cleanup_email_address($settings['recipient'] ?? '');
    if (empty($settings['enabled']) || $recipient === '') {
        return ['ok' => false, 'code' => 'EMAIL_BACKUP_NOT_READY'];
    }
    $from = storage_cleanup_email_from();
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'From: Z-Pay Swift <' . $from . '>',
        'Reply-To: ' . $from,
        'Content-Type: text/plain; charset=UTF-8',
        'X-Auto-Response-Suppress: All',
        'Auto-Submitted: auto-generated',
    ]);
    try {
        $accepted = is_callable($deps['send_message']) && (bool)($deps['send_message'])(
            $recipient,
            'Z-Pay Swift storage cleanup email test',
            "Automatic cleanup email backup is configured.\nNo files were attached or deleted by this test.\n",
            $headers
        );
    } catch (Throwable $exception) {
        $accepted = false;
    }
    return [
        'ok' => $accepted,
        'code' => $accepted ? 'EMAIL_TEST_ACCEPTED' : 'EMAIL_TEST_FAILED',
        'recipient_masked' => storage_cleanup_email_mask($recipient),
    ];
}
