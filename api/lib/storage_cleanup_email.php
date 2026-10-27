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

function storage_cleanup_email_settings(?array $row = null): array
{
    if ($row === null) {
        $loaded = function_exists('fb_get') ? fb_get('APP_CONFIG') : [];
        $row = is_array($loaded) ? $loaded : [];
    }

    $defaultEnabled = defined('STORAGE_CLEANUP_EMAIL_BACKUP_ENABLED')
        && constant('STORAGE_CLEANUP_EMAIL_BACKUP_ENABLED') === true;
    $defaultRecipient = defined('STORAGE_CLEANUP_EMAIL_BACKUP_RECIPIENT')
        ? storage_cleanup_email_address(constant('STORAGE_CLEANUP_EMAIL_BACKUP_RECIPIENT'))
        : '';
    $enabled = array_key_exists('storage_cleanup_email_enabled', $row)
        ? storage_cleanup_email_bool($row['storage_cleanup_email_enabled'])
        : $defaultEnabled;
    $recipient = array_key_exists('storage_cleanup_email_recipient', $row)
        ? storage_cleanup_email_address($row['storage_cleanup_email_recipient'])
        : $defaultRecipient;

    return [
        'enabled' => $enabled,
        'recipient' => $recipient,
        'recipient_masked' => storage_cleanup_email_mask($recipient),
        'ready' => $enabled && $recipient !== '' && function_exists('mail'),
    ];
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
            is_array($row = fb_get(storage_cleanup_email_receipt_path($candidateId))) ? $row : [],
        'write_receipt' => static fn(string $candidateId, array $row): bool =>
            fb_put(storage_cleanup_email_receipt_path($candidateId), $row),
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

function storage_cleanup_email_receipt_matches(array $receipt, array $file, string $recipientHash): bool
{
    return !empty($receipt['accepted'])
        && hash_equals((string)($receipt['candidate_id'] ?? ''), (string)$file['candidate_id'])
        && hash_equals((string)($receipt['file_sha256'] ?? ''), (string)$file['sha256'])
        && hash_equals((string)($receipt['recipient_hash'] ?? ''), $recipientHash)
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
    array $dependencies = []
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
    $settings = ($deps['settings'])();
    $settings = is_array($settings) ? $settings : [];
    $recipient = storage_cleanup_email_address($settings['recipient'] ?? '');
    if (empty($settings['enabled']) || $recipient === '' || !is_callable($deps['send_message'])) {
        return ['ok' => false, 'code' => 'EMAIL_BACKUP_NOT_READY'];
    }

    $roots = ($deps['roots'])();
    $roots = is_array($roots) ? $roots : [];
    $maxBytes = storage_cleanup_email_max_raw_bytes();
    $recipientHash = storage_cleanup_email_recipient_hash($recipient);
    $prepared = [];
    $unsent = [];
    $reused = 0;

    foreach ($candidates as $candidate) {
        $file = storage_cleanup_email_prepare_candidate((array)$candidate, $roots, $maxBytes);
        if (empty($file['ok'])) {
            return ['ok' => false, 'code' => (string)($file['code'] ?? 'EMAIL_BACKUP_PREPARE_FAILED')];
        }
        $receipt = ($deps['read_receipt'])((string)$file['candidate_id']);
        $receipt = is_array($receipt) ? $receipt : [];
        if (storage_cleanup_email_receipt_matches($receipt, $file, $recipientHash)) {
            $reused++;
        } else {
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
    foreach ($groups as $index => $group) {
        $message = storage_cleanup_email_message($group, $runId, $index + 1, count($groups));
        if (empty($message['ok'])) {
            return ['ok' => false, 'code' => (string)($message['code'] ?? 'EMAIL_BACKUP_BUILD_FAILED')];
        }
        try {
            $accepted = (bool)($deps['send_message'])(
                $recipient,
                (string)$message['subject'],
                (string)$message['body'],
                (string)$message['headers']
            );
        } catch (Throwable $exception) {
            $accepted = false;
        }
        if (!$accepted) {
            return ['ok' => false, 'code' => 'EMAIL_BACKUP_SEND_FAILED'];
        }
        $sentMessages++;

        foreach ($group as $file) {
            $receipt = [
                'candidate_id' => (string)$file['candidate_id'],
                'category' => (string)$file['category'],
                'file_sha256' => (string)$file['sha256'],
                'size_bytes' => (int)$file['size_bytes'],
                'recipient_hash' => $recipientHash,
                'accepted' => true,
                'accepted_at' => $now,
                'run_id' => $runId,
            ];
            if (!($deps['write_receipt'])((string)$file['candidate_id'], $receipt)) {
                return ['ok' => false, 'code' => 'EMAIL_BACKUP_AUDIT_FAILED'];
            }
            $sentFiles++;
        }
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
    ];
}

function storage_cleanup_email_send_test(array $dependencies = []): array
{
    $deps = array_replace(storage_cleanup_email_default_dependencies(), $dependencies);
    $settings = ($deps['settings'])();
    $settings = is_array($settings) ? $settings : [];
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
