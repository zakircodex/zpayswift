<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function storage_cleanup_now(): int
{
    return function_exists('now_ts') ? (int)now_ts() : time();
}

function storage_cleanup_retention_days(): int
{
    $days = defined('STORAGE_CLEANUP_RETENTION_DAYS')
        ? (int)constant('STORAGE_CLEANUP_RETENTION_DAYS')
        : 90;

    return max(90, min(3650, $days));
}

function storage_cleanup_batch_limit(): int
{
    $limit = defined('STORAGE_CLEANUP_BATCH_LIMIT')
        ? (int)constant('STORAGE_CLEANUP_BATCH_LIMIT')
        : 100;

    return max(1, min(250, $limit));
}

function storage_cleanup_scan_limit(): int
{
    $limit = defined('STORAGE_CLEANUP_SCAN_LIMIT')
        ? (int)constant('STORAGE_CLEANUP_SCAN_LIMIT')
        : 5000;

    return max(100, min(20000, $limit));
}

function storage_cleanup_automatic_enabled(): bool
{
    if (!defined('STORAGE_CLEANUP_AUTOMATIC_ENABLED')) {
        return false;
    }

    return constant('STORAGE_CLEANUP_AUTOMATIC_ENABLED') === true;
}

function storage_cleanup_automatic_batch_limit(): int
{
    $limit = defined('STORAGE_CLEANUP_AUTOMATIC_BATCH_LIMIT')
        ? (int)constant('STORAGE_CLEANUP_AUTOMATIC_BATCH_LIMIT')
        : storage_cleanup_batch_limit();

    return max(1, min(storage_cleanup_batch_limit(), $limit));
}

function storage_cleanup_automatic_categories(): array
{
    $categories = defined('STORAGE_CLEANUP_AUTOMATIC_CATEGORIES')
        ? constant('STORAGE_CLEANUP_AUTOMATIC_CATEGORIES')
        : array_keys(storage_cleanup_categories());

    return is_array($categories) ? storage_cleanup_normalize_categories($categories) : [];
}

function storage_cleanup_trigger($value): string
{
    $trigger = strtoupper(trim((string)$value));
    return in_array($trigger, ['MANUAL', 'AUTOMATIC'], true) ? $trigger : 'MANUAL';
}

function storage_cleanup_confirmation_phrase(): string
{
    return 'DELETE 90 DAY FILES';
}

function storage_cleanup_categories(): array
{
    return [
        'add_money_receipts' => [
            'label' => 'Add Money receipts',
            'description' => 'Approved or rejected receipts whose final status is at least 90 days old.',
        ],
        'notification_images' => [
            'label' => 'Notification images',
            'description' => 'Images attached to notifications sent at least 90 days ago.',
        ],
        'support_attachments' => [
            'label' => 'Closed support attachments',
            'description' => 'Screenshots from tickets closed at least 90 days ago.',
        ],
        'old_app_releases' => [
            'label' => 'Old app releases',
            'description' => 'Old APK files while preserving the current release and one rollback copy.',
        ],
    ];
}

function storage_cleanup_normalize_categories(array $categories): array
{
    $allowed = storage_cleanup_categories();
    $selected = [];
    foreach ($categories as $category) {
        $category = strtolower(trim((string)$category));
        if ($category !== '' && isset($allowed[$category])) {
            $selected[$category] = $category;
        }
    }

    return array_values($selected);
}

function storage_cleanup_timestamp($value): int
{
    $timestamp = is_numeric($value) ? (int)$value : 0;
    if ($timestamp >= 1000000000000) {
        return (int)floor($timestamp / 1000);
    }
    return max(0, $timestamp);
}

function storage_cleanup_filename_timestamp(string $value): int
{
    if (preg_match('/^\d{14}$/D', $value) !== 1) {
        return 0;
    }
    $date = DateTimeImmutable::createFromFormat(
        '!YmdHis',
        $value,
        new DateTimeZone(date_default_timezone_get())
    );
    if (!$date instanceof DateTimeImmutable || $date->format('YmdHis') !== $value) {
        return 0;
    }
    return $date->getTimestamp();
}

function storage_cleanup_clean_path(string $path): string
{
    return str_replace('\\', '/', trim($path));
}

function storage_cleanup_path_inside_root(string $root, string $path): string
{
    if ($root === '' || $path === '' || is_link($path)) {
        return '';
    }
    $realRoot = realpath($root);
    $realPath = realpath($path);
    if ($realRoot === false || $realPath === false || !is_file($realPath) || is_link($realPath)) {
        return '';
    }

    $rootPrefix = rtrim(storage_cleanup_clean_path($realRoot), '/') . '/';
    $normalized = storage_cleanup_clean_path($realPath);
    return str_starts_with($normalized, $rootPrefix) ? $realPath : '';
}

function storage_cleanup_relative_path(string $root, string $path): string
{
    $realRoot = realpath($root);
    $realPath = storage_cleanup_path_inside_root($root, $path);
    if ($realRoot === false || $realPath === '') {
        return '';
    }
    $prefix = rtrim(storage_cleanup_clean_path($realRoot), '/') . '/';
    return ltrim(substr(storage_cleanup_clean_path($realPath), strlen($prefix)), '/');
}

function storage_cleanup_files(string $root, array $extensions, int $limit): array
{
    $realRoot = realpath($root);
    if ($realRoot === false || !is_dir($realRoot) || is_link($realRoot)) {
        return ['available' => false, 'items' => [], 'scanned' => 0, 'scan_limited' => false];
    }

    $allowed = array_fill_keys(array_map('strtolower', $extensions), true);
    $items = [];
    $scanned = 0;
    $scanLimited = false;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            $scanned++;
            if ($scanned > $limit) {
                $scanLimited = true;
                break;
            }
            $path = storage_cleanup_path_inside_root($realRoot, $file->getPathname());
            if ($path === '') {
                continue;
            }
            $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
            if (!isset($allowed[$extension])) {
                continue;
            }
            $relative = storage_cleanup_relative_path($realRoot, $path);
            if ($relative === '' || str_starts_with(basename($relative), '.')) {
                continue;
            }
            $items[] = [
                'path' => $path,
                'relative_path' => $relative,
                'name' => basename($path),
                'extension' => $extension,
                'size_bytes' => max(0, (int)$file->getSize()),
                'modified_at' => max(0, (int)$file->getMTime()),
            ];
        }
    } catch (UnexpectedValueException $exception) {
        return ['available' => false, 'items' => [], 'scanned' => $scanned, 'scan_limited' => false];
    }

    usort($items, static fn(array $a, array $b): int =>
        ((int)$a['modified_at'] <=> (int)$b['modified_at'])
        ?: strcmp((string)$a['relative_path'], (string)$b['relative_path']));

    return [
        'available' => true,
        'items' => $items,
        'scanned' => min($scanned, $limit),
        'scan_limited' => $scanLimited,
    ];
}

function storage_cleanup_default_roots(): array
{
    $projectRoot = dirname(dirname(__DIR__));
    return [
        'add_money_receipts' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'add_money',
        'notification_images' => function_exists('notification_private_storage_root')
            ? notification_private_storage_root()
            : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage_private' . DIRECTORY_SEPARATOR . 'notifications',
        'support_attachments' => function_exists('support_private_storage_root')
            ? support_private_storage_root()
            : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage_private' . DIRECTORY_SEPARATOR . 'support',
        'old_app_releases' => $projectRoot . DIRECTORY_SEPARATOR . 'downloads',
    ];
}

function storage_cleanup_lock_path(): string
{
    if (function_exists('app_private_config_path')) {
        $base = dirname(app_private_config_path());
    } else {
        $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage_private';
    }
    return rtrim($base, '/\\')
        . DIRECTORY_SEPARATOR . 'storage_cleanup'
        . DIRECTORY_SEPARATOR . 'run.lock';
}

function storage_cleanup_acquire_lock()
{
    $path = storage_cleanup_lock_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        return false;
    }
    $handle = @fopen($path, 'c+');
    if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return false;
    }
    return $handle;
}

function storage_cleanup_release_lock($handle): void
{
    if (!is_resource($handle)) {
        return;
    }
    @flock($handle, LOCK_UN);
    fclose($handle);
}

function storage_cleanup_default_dependencies(): array
{
    return [
        'list_add_money_requests' => static fn(): array =>
            is_array($rows = fb_get('ADD_MONEY_REQUESTS')) ? $rows : [],
        'read_add_money_request' => static fn(string $requestId): array =>
            is_array($row = fb_get('ADD_MONEY_REQUESTS/' . $requestId)) ? $row : [],
        'list_support_tickets' => static fn(): array =>
            is_array($rows = fb_get('SUPPORT_TICKETS')) ? $rows : [],
        'list_support_attachments' => static fn(): array =>
            is_array($rows = fb_get('SUPPORT_ATTACHMENTS')) ? $rows : [],
        'read_support_ticket' => static fn(string $ticketId): array =>
            is_array($row = fb_get('SUPPORT_TICKETS/' . $ticketId)) ? $row : [],
        'read_support_attachments' => static fn(string $ticketId): array =>
            is_array($rows = fb_get('SUPPORT_ATTACHMENTS/' . $ticketId)) ? $rows : [],
        'patch_add_money_request' => static fn(string $requestId, array $patch): bool =>
            function_exists('add_money_patch_request') && add_money_patch_request($requestId, $patch),
        'delete_add_money_token' => static fn(string $token): bool =>
            $token === '' || fb_delete('ADD_MONEY_RECEIPT_TOKENS/' . $token),
        'patch_support_attachment' => static fn(string $ticketId, string $attachmentId, array $patch): bool =>
            fb_patch('SUPPORT_ATTACHMENTS/' . $ticketId . '/' . $attachmentId, $patch),
        'write_audit' => static fn(string $path, array $row): bool => fb_put($path, $row),
        'patch_audit' => static fn(string $path, array $row): bool => fb_patch($path, $row),
        'move_file' => static fn(string $from, string $to): bool =>
            is_file($from) && !is_link($from) && !file_exists($to) && @rename($from, $to),
        'delete_file' => static fn(string $path): bool => is_file($path) && !is_link($path) && @unlink($path),
    ];
}

function storage_cleanup_current_apk_name(string $projectRoot): string
{
    $downloadScript = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'download.php';
    $source = is_file($downloadScript) && is_readable($downloadScript)
        ? @file_get_contents($downloadScript)
        : false;
    if (!is_string($source)) {
        return '';
    }
    if (preg_match("~/downloads/([^'\"]+\\.apk)~i", $source, $matches) !== 1) {
        return '';
    }
    return basename((string)$matches[1]);
}

function storage_cleanup_candidate(
    string $category,
    array $file,
    int $eligibleAt,
    string $status = '',
    array $metadata = []
): array {
    $relative = (string)($file['relative_path'] ?? '');
    return [
        'candidate_id' => hash('sha256', $category . "\0" . $relative),
        'category' => $category,
        'name' => (string)($file['name'] ?? basename($relative)),
        'relative_path' => $relative,
        'path' => (string)($file['path'] ?? ''),
        'size_bytes' => max(0, (int)($file['size_bytes'] ?? 0)),
        'modified_at' => max(0, (int)($file['modified_at'] ?? 0)),
        'eligible_at' => max(0, $eligibleAt),
        'status' => strtoupper(trim($status)),
        'metadata' => $metadata,
    ];
}

function storage_cleanup_add_money_candidates(
    string $root,
    int $cutoff,
    int $scanLimit,
    array $dependencies
): array {
    $scan = storage_cleanup_files($root, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], $scanLimit);
    $candidates = [];
    $protected = 0;
    $ambiguous = 0;
    $requestRows = [];
    if ((array)$scan['items'] !== []) {
        try {
            $requestRows = ($dependencies['list_add_money_requests'])();
        } catch (Throwable $exception) {
            $requestRows = [];
        }
        $requestRows = is_array($requestRows) ? $requestRows : [];
    }
    foreach ((array)$scan['items'] as $file) {
        if ((int)$file['modified_at'] > $cutoff) {
            $protected++;
            continue;
        }
        $requestId = (string)pathinfo((string)$file['name'], PATHINFO_FILENAME);
        if (preg_match('/^AM[0-9A-Z_-]{12,80}$/D', $requestId) !== 1) {
            $ambiguous++;
            continue;
        }
        $row = is_array($requestRows[$requestId] ?? null) ? (array)$requestRows[$requestId] : [];
        if (!is_array($row) || $row === []) {
            $ambiguous++;
            continue;
        }
        $status = strtoupper(trim((string)($row['status'] ?? '')));
        if (!in_array($status, ['APPROVED', 'REJECTED'], true)) {
            $protected++;
            continue;
        }
        $terminalAt = max(array_map('storage_cleanup_timestamp', [
            $row['approved_at'] ?? 0,
            $row['rejected_at'] ?? 0,
            $row['status_updated_at'] ?? 0,
            $row['updated_at'] ?? 0,
            $row['created_at'] ?? 0,
        ]));
        if ($terminalAt <= 0 || $terminalAt > $cutoff) {
            $protected++;
            continue;
        }
        $storedPath = ltrim(storage_cleanup_clean_path((string)($row['receipt_path'] ?? '')), '/');
        $expected = 'storage/add_money/' . ltrim(storage_cleanup_clean_path((string)$file['relative_path']), '/');
        if ($storedPath === '' || !hash_equals($expected, $storedPath)) {
            $ambiguous++;
            continue;
        }
        $candidates[] = storage_cleanup_candidate(
            'add_money_receipts',
            $file,
            max($terminalAt, (int)$file['modified_at']),
            $status,
            [
                'request_id' => $requestId,
                'uid' => trim((string)($row['uid'] ?? '')),
                'receipt_token' => trim((string)($row['receipt_token'] ?? '')),
                'receipt_mime' => trim((string)($row['receipt_mime'] ?? '')),
                'terminal_at' => $terminalAt,
            ]
        );
    }
    return [
        'available' => !empty($scan['available']),
        'items' => $candidates,
        'scanned' => (int)$scan['scanned'],
        'scan_limited' => !empty($scan['scan_limited']),
        'protected' => $protected,
        'ambiguous' => $ambiguous,
    ];
}

function storage_cleanup_notification_candidates(string $root, int $cutoff, int $scanLimit): array
{
    $scan = storage_cleanup_files($root, ['jpg', 'jpeg', 'png', 'webp'], $scanLimit);
    $candidates = [];
    $protected = 0;
    $ambiguous = 0;
    foreach ((array)$scan['items'] as $file) {
        if ((int)$file['modified_at'] > $cutoff) {
            $protected++;
            continue;
        }
        if (preg_match('/^NI(\d{14})[A-F0-9]{8}\.(?:jpe?g|png|webp)$/Di', (string)$file['name'], $matches) !== 1) {
            $ambiguous++;
            continue;
        }
        $generatedAt = storage_cleanup_filename_timestamp((string)($matches[1] ?? ''));
        if ($generatedAt <= 0) {
            $ambiguous++;
            continue;
        }
        if ($generatedAt > $cutoff) {
            $protected++;
            continue;
        }
        $candidates[] = storage_cleanup_candidate(
            'notification_images',
            $file,
            max($generatedAt, (int)$file['modified_at']),
            'EXPIRED',
            ['generated_at' => $generatedAt]
        );
    }
    return [
        'available' => !empty($scan['available']),
        'items' => $candidates,
        'scanned' => (int)$scan['scanned'],
        'scan_limited' => !empty($scan['scan_limited']),
        'protected' => $protected,
        'ambiguous' => $ambiguous,
    ];
}

function storage_cleanup_support_candidates(
    string $root,
    int $cutoff,
    int $scanLimit,
    array $dependencies
): array {
    $scan = storage_cleanup_files($root, ['jpg', 'jpeg', 'png', 'webp'], $scanLimit);
    $candidates = [];
    $protected = 0;
    $ambiguous = 0;
    $ticketRows = [];
    $attachmentRows = [];
    if ((array)$scan['items'] !== []) {
        try {
            $ticketRows = ($dependencies['list_support_tickets'])();
            $attachmentRows = ($dependencies['list_support_attachments'])();
        } catch (Throwable $exception) {
            $ticketRows = [];
            $attachmentRows = [];
        }
        $ticketRows = is_array($ticketRows) ? $ticketRows : [];
        $attachmentRows = is_array($attachmentRows) ? $attachmentRows : [];
    }
    foreach ((array)$scan['items'] as $file) {
        if ((int)$file['modified_at'] > $cutoff) {
            $protected++;
            continue;
        }
        $parts = explode('/', storage_cleanup_clean_path((string)$file['relative_path']));
        if (count($parts) !== 3) {
            $ambiguous++;
            continue;
        }
        [$month, $ticketId, $fileName] = $parts;
        if (
            preg_match('/^\d{4}-\d{2}$/D', $month) !== 1
            || preg_match('/^SP\d{14}[A-F0-9]{8}$/D', $ticketId) !== 1
            || preg_match('/^ATT\d{14}[A-F0-9]{6}\.(?:jpe?g|png|webp)$/Di', $fileName) !== 1
        ) {
            $ambiguous++;
            continue;
        }
        $ticket = is_array($ticketRows[$ticketId] ?? null) ? (array)$ticketRows[$ticketId] : [];
        $status = strtoupper(trim((string)($ticket['status'] ?? '')));
        $closedAt = max(array_map('storage_cleanup_timestamp', [
            $ticket['closed_at'] ?? 0,
            $ticket['status_updated_at'] ?? 0,
            $ticket['updated_at'] ?? 0,
        ]));
        if ($status !== 'CLOSED' || $closedAt <= 0 || $closedAt > $cutoff) {
            $protected++;
            continue;
        }
        $attachmentId = (string)pathinfo($fileName, PATHINFO_FILENAME);
        $rows = is_array($attachmentRows[$ticketId] ?? null) ? (array)$attachmentRows[$ticketId] : [];
        $attachment = is_array($rows[$attachmentId] ?? null) ? (array)$rows[$attachmentId] : [];
        $relative = storage_cleanup_clean_path((string)($attachment['relative_path'] ?? ''));
        $createdAt = storage_cleanup_timestamp($attachment['created_at'] ?? 0);
        if (
            $attachment === []
            || $relative === ''
            || !hash_equals(storage_cleanup_clean_path((string)$file['relative_path']), $relative)
            || $createdAt <= 0
            || $createdAt > $cutoff
            || !empty($attachment['archived'])
        ) {
            $ambiguous++;
            continue;
        }
        $candidates[] = storage_cleanup_candidate(
            'support_attachments',
            $file,
            max($closedAt, $createdAt, (int)$file['modified_at']),
            'CLOSED',
            [
                'ticket_id' => $ticketId,
                'attachment_id' => $attachmentId,
                'closed_at' => $closedAt,
            ]
        );
    }
    return [
        'available' => !empty($scan['available']),
        'items' => $candidates,
        'scanned' => (int)$scan['scanned'],
        'scan_limited' => !empty($scan['scan_limited']),
        'protected' => $protected,
        'ambiguous' => $ambiguous,
    ];
}

function storage_cleanup_apk_candidates(string $root, int $cutoff, int $scanLimit, string $currentApk): array
{
    $scan = storage_cleanup_files($root, ['apk'], $scanLimit);
    $files = (array)$scan['items'];
    usort($files, static fn(array $a, array $b): int =>
        ((int)$b['modified_at'] <=> (int)$a['modified_at'])
        ?: strcmp((string)$b['name'], (string)$a['name']));

    $currentFound = false;
    foreach ($files as $file) {
        if ($currentApk !== '' && hash_equals(strtolower($currentApk), strtolower((string)$file['name']))) {
            $currentFound = true;
            break;
        }
    }
    if ($currentApk === '' || !$currentFound) {
        return [
            'available' => !empty($scan['available']),
            'items' => [],
            'scanned' => (int)$scan['scanned'],
            'scan_limited' => !empty($scan['scan_limited']),
            'protected' => count($files),
            'ambiguous' => count($files),
            'current_apk' => $currentApk,
            'rollback_apk' => '',
        ];
    }

    $rollback = '';
    foreach ($files as $file) {
        $name = (string)($file['name'] ?? '');
        if (preg_match('/^Z-Pay-Swift-[0-9A-Za-z._-]+\.apk$/Di', $name) !== 1) {
            continue;
        }
        if (hash_equals(strtolower($currentApk), strtolower($name))) {
            continue;
        }
        $rollback = $name;
        break;
    }

    $candidates = [];
    $protected = 0;
    $ambiguous = 0;
    foreach ($files as $file) {
        $name = (string)$file['name'];
        if (preg_match('/^Z-Pay-Swift-[0-9A-Za-z._-]+\.apk$/Di', $name) !== 1) {
            $ambiguous++;
            continue;
        }
        if (
            (int)$file['modified_at'] > $cutoff
            || ($currentApk !== '' && hash_equals(strtolower($currentApk), strtolower($name)))
            || ($rollback !== '' && hash_equals(strtolower($rollback), strtolower($name)))
        ) {
            $protected++;
            continue;
        }
        $candidates[] = storage_cleanup_candidate(
            'old_app_releases',
            $file,
            (int)$file['modified_at'],
            'SUPERSEDED',
            ['current_apk' => $currentApk, 'rollback_apk' => $rollback]
        );
    }
    return [
        'available' => !empty($scan['available']),
        'items' => $candidates,
        'scanned' => (int)$scan['scanned'],
        'scan_limited' => !empty($scan['scan_limited']),
        'protected' => $protected,
        'ambiguous' => $ambiguous,
        'current_apk' => $currentApk,
        'rollback_apk' => $rollback,
    ];
}

function storage_cleanup_scan(array $categories, array $options = [], array $dependencies = []): array
{
    $selected = storage_cleanup_normalize_categories($categories);
    $definitions = storage_cleanup_categories();
    $deps = array_replace(storage_cleanup_default_dependencies(), $dependencies);
    $now = isset($options['now']) ? (int)$options['now'] : storage_cleanup_now();
    $retentionDays = storage_cleanup_retention_days();
    $cutoff = $now - ($retentionDays * 86400);
    $scanLimit = isset($options['scan_limit'])
        ? max(1, min(20000, (int)$options['scan_limit']))
        : storage_cleanup_scan_limit();
    $roots = array_replace(storage_cleanup_default_roots(), (array)($options['roots'] ?? []));
    $projectRoot = (string)($options['project_root'] ?? dirname(dirname(__DIR__)));
    $currentApk = isset($options['current_apk'])
        ? basename((string)$options['current_apk'])
        : storage_cleanup_current_apk_name($projectRoot);

    $summary = [
        'ok' => true,
        'retention_days' => $retentionDays,
        'cutoff_at' => $cutoff,
        'generated_at' => $now,
        'total_files' => 0,
        'total_bytes' => 0,
        'scan_limited' => false,
        'categories' => [],
        'items' => [],
    ];

    foreach ($selected as $category) {
        $root = (string)($roots[$category] ?? '');
        $result = match ($category) {
            'add_money_receipts' => storage_cleanup_add_money_candidates($root, $cutoff, $scanLimit, $deps),
            'notification_images' => storage_cleanup_notification_candidates($root, $cutoff, $scanLimit),
            'support_attachments' => storage_cleanup_support_candidates($root, $cutoff, $scanLimit, $deps),
            'old_app_releases' => storage_cleanup_apk_candidates($root, $cutoff, $scanLimit, $currentApk),
            default => ['available' => false, 'items' => [], 'scanned' => 0, 'scan_limited' => false, 'protected' => 0, 'ambiguous' => 0],
        };
        $items = array_values((array)($result['items'] ?? []));
        $bytes = array_sum(array_map(static fn(array $item): int => (int)($item['size_bytes'] ?? 0), $items));
        $categorySummary = [
            'key' => $category,
            'label' => (string)($definitions[$category]['label'] ?? $category),
            'description' => (string)($definitions[$category]['description'] ?? ''),
            'available' => !empty($result['available']),
            'eligible_files' => count($items),
            'eligible_bytes' => $bytes,
            'scanned_files' => max(0, (int)($result['scanned'] ?? 0)),
            'protected_files' => max(0, (int)($result['protected'] ?? 0)),
            'ambiguous_files' => max(0, (int)($result['ambiguous'] ?? 0)),
            'scan_limited' => !empty($result['scan_limited']),
        ];
        if ($category === 'old_app_releases') {
            $categorySummary['current_apk'] = (string)($result['current_apk'] ?? '');
            $categorySummary['rollback_apk'] = (string)($result['rollback_apk'] ?? '');
        }
        $summary['categories'][$category] = $categorySummary;
        $summary['total_files'] += count($items);
        $summary['total_bytes'] += $bytes;
        $summary['scan_limited'] = $summary['scan_limited'] || !empty($result['scan_limited']);
        array_push($summary['items'], ...$items);
    }

    usort($summary['items'], static fn(array $a, array $b): int =>
        ((int)$a['eligible_at'] <=> (int)$b['eligible_at'])
        ?: strcmp((string)$a['candidate_id'], (string)$b['candidate_id']));

    return $summary;
}

function storage_cleanup_public_preview(array $scan, int $sampleLimit = 30): array
{
    $items = [];
    foreach (array_slice((array)($scan['items'] ?? []), 0, max(0, $sampleLimit)) as $item) {
        $metadata = is_array($item['metadata'] ?? null) ? (array)$item['metadata'] : [];
        $items[] = [
            'candidate_id' => (string)($item['candidate_id'] ?? ''),
            'category' => (string)($item['category'] ?? ''),
            'name' => (string)($item['name'] ?? ''),
            'size_bytes' => max(0, (int)($item['size_bytes'] ?? 0)),
            'modified_at' => max(0, (int)($item['modified_at'] ?? 0)),
            'eligible_at' => max(0, (int)($item['eligible_at'] ?? 0)),
            'status' => (string)($item['status'] ?? ''),
            'reference' => (string)(
                $metadata['request_id']
                ?? $metadata['ticket_id']
                ?? $item['name']
                ?? ''
            ),
        ];
    }
    unset($scan['items']);
    $scan['sample_items'] = $items;
    $scan['sample_limited'] = (int)($scan['total_files'] ?? 0) > count($items);
    $scan['batch_limit'] = storage_cleanup_batch_limit();
    $scan['confirmation_phrase'] = storage_cleanup_confirmation_phrase();
    $scan['automatic'] = [
        'enabled' => storage_cleanup_automatic_enabled(),
        'batch_limit' => storage_cleanup_automatic_batch_limit(),
        'categories' => storage_cleanup_automatic_categories(),
    ];
    return $scan;
}

function storage_cleanup_run_id(): string
{
    return 'SCR' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function storage_cleanup_audit_path(string $runId): string
{
    return 'STORAGE_CLEANUP_RUNS/' . date('Y-m') . '/' . $runId;
}

function storage_cleanup_quarantine_path(string $path, string $runId, string $candidateId): string
{
    $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $suffix = $extension !== '' ? '.' . $extension : '';
    return dirname($path)
        . DIRECTORY_SEPARATOR
        . '.storage-cleanup-'
        . preg_replace('/[^A-Z0-9]+/i', '', $runId)
        . '-'
        . substr(preg_replace('/[^a-f0-9]+/i', '', $candidateId), 0, 16)
        . $suffix;
}

function storage_cleanup_archive_add_money(array $candidate, string $actorUid, int $now, array $deps): bool
{
    $metadata = (array)($candidate['metadata'] ?? []);
    $requestId = trim((string)($metadata['request_id'] ?? ''));
    if ($requestId === '') {
        return false;
    }
    $row = ($deps['read_add_money_request'])($requestId);
    $status = strtoupper(trim((string)($row['status'] ?? '')));
    $path = ltrim(storage_cleanup_clean_path((string)($row['receipt_path'] ?? '')), '/');
    $expected = 'storage/add_money/' . ltrim(storage_cleanup_clean_path((string)$candidate['relative_path']), '/');
    if (!in_array($status, ['APPROVED', 'REJECTED'], true) || $path === '' || !hash_equals($expected, $path)) {
        return false;
    }
    $token = trim((string)($row['receipt_token'] ?? ''));
    $patch = [
        'receipt_url' => '',
        'receipt_token' => '',
        'receipt_path' => '',
        'receipt_token_status' => 'ARCHIVED',
        'receipt_archived' => true,
        'receipt_archived_at' => $now,
        'receipt_archived_by' => $actorUid,
        'receipt_archive_reason' => 'RETENTION_90_DAYS',
        'receipt_archived_size' => max(0, (int)($candidate['size_bytes'] ?? 0)),
    ];
    if (!($deps['patch_add_money_request'])($requestId, $patch)) {
        return false;
    }
    ($deps['delete_add_money_token'])($token);
    return true;
}

function storage_cleanup_archive_support(array $candidate, string $actorUid, int $now, array $deps): bool
{
    $metadata = (array)($candidate['metadata'] ?? []);
    $ticketId = trim((string)($metadata['ticket_id'] ?? ''));
    $attachmentId = trim((string)($metadata['attachment_id'] ?? ''));
    if ($ticketId === '' || $attachmentId === '') {
        return false;
    }
    return (bool)($deps['patch_support_attachment'])($ticketId, $attachmentId, [
        'archived' => true,
        'archived_at' => $now,
        'archived_by' => $actorUid,
        'archive_reason' => 'RETENTION_90_DAYS',
        'archived_size' => max(0, (int)($candidate['size_bytes'] ?? 0)),
    ]);
}

function storage_cleanup_candidate_still_eligible(
    array $candidate,
    int $cutoff,
    array $deps
): bool {
    $category = (string)($candidate['category'] ?? '');
    $metadata = is_array($candidate['metadata'] ?? null) ? (array)$candidate['metadata'] : [];
    if ($category === 'add_money_receipts') {
        $requestId = trim((string)($metadata['request_id'] ?? ''));
        $row = $requestId !== '' ? ($deps['read_add_money_request'])($requestId) : [];
        $status = strtoupper(trim((string)($row['status'] ?? '')));
        $terminalAt = max(array_map('storage_cleanup_timestamp', [
            $row['approved_at'] ?? 0,
            $row['rejected_at'] ?? 0,
            $row['status_updated_at'] ?? 0,
            $row['updated_at'] ?? 0,
            $row['created_at'] ?? 0,
        ]));
        $path = ltrim(storage_cleanup_clean_path((string)($row['receipt_path'] ?? '')), '/');
        $expected = 'storage/add_money/' . ltrim(storage_cleanup_clean_path((string)($candidate['relative_path'] ?? '')), '/');
        return in_array($status, ['APPROVED', 'REJECTED'], true)
            && $terminalAt > 0
            && $terminalAt <= $cutoff
            && $path !== ''
            && hash_equals($expected, $path);
    }
    if ($category === 'support_attachments') {
        $ticketId = trim((string)($metadata['ticket_id'] ?? ''));
        $attachmentId = trim((string)($metadata['attachment_id'] ?? ''));
        $ticket = $ticketId !== '' ? ($deps['read_support_ticket'])($ticketId) : [];
        $status = strtoupper(trim((string)($ticket['status'] ?? '')));
        $closedAt = max(array_map('storage_cleanup_timestamp', [
            $ticket['closed_at'] ?? 0,
            $ticket['status_updated_at'] ?? 0,
            $ticket['updated_at'] ?? 0,
        ]));
        $rows = $ticketId !== '' ? ($deps['read_support_attachments'])($ticketId) : [];
        $attachment = is_array($rows[$attachmentId] ?? null) ? (array)$rows[$attachmentId] : [];
        return $status === 'CLOSED'
            && $closedAt > 0
            && $closedAt <= $cutoff
            && $attachment !== []
            && empty($attachment['archived'])
            && hash_equals(
                storage_cleanup_clean_path((string)($candidate['relative_path'] ?? '')),
                storage_cleanup_clean_path((string)($attachment['relative_path'] ?? ''))
            );
    }
    if ($category === 'notification_images') {
        $generatedAt = storage_cleanup_timestamp($metadata['generated_at'] ?? 0);
        return $generatedAt > 0
            && $generatedAt <= $cutoff
            && (int)($candidate['modified_at'] ?? 0) > 0
            && (int)($candidate['modified_at'] ?? 0) <= $cutoff;
    }
    return $category === 'old_app_releases'
        && (int)($candidate['modified_at'] ?? 0) > 0
        && (int)($candidate['modified_at'] ?? 0) <= $cutoff;
}

function storage_cleanup_execute(
    array $categories,
    string $actorUid,
    array $options = [],
    array $dependencies = []
): array {
    $selected = storage_cleanup_normalize_categories($categories);
    $deps = array_replace(storage_cleanup_default_dependencies(), $dependencies);
    $now = isset($options['now']) ? (int)$options['now'] : storage_cleanup_now();
    $trigger = storage_cleanup_trigger($options['trigger'] ?? 'MANUAL');
    $limit = isset($options['limit'])
        ? max(1, min(storage_cleanup_batch_limit(), (int)$options['limit']))
        : storage_cleanup_batch_limit();
    $scan = storage_cleanup_scan($selected, $options + ['now' => $now], $deps);
    $candidates = array_slice((array)$scan['items'], 0, $limit);
    $runId = storage_cleanup_run_id();
    $auditPath = storage_cleanup_audit_path($runId);
    $audit = [
        'run_id' => $runId,
        'status' => 'RUNNING',
        'trigger' => $trigger,
        'actor_uid' => trim($actorUid),
        'categories' => $selected,
        'retention_days' => storage_cleanup_retention_days(),
        'cutoff_at' => (int)$scan['cutoff_at'],
        'eligible_files' => (int)$scan['total_files'],
        'eligible_bytes' => (int)$scan['total_bytes'],
        'batch_limit' => $limit,
        'started_at' => $now,
    ];
    if (!($deps['write_audit'])($auditPath, $audit)) {
        return [
            'ok' => false,
            'code' => 'STORAGE_CLEANUP_AUDIT_UNAVAILABLE',
            'message' => 'Cleanup audit log could not be started. No files were deleted.',
        ];
    }

    $result = [
        'ok' => true,
        'code' => 'STORAGE_CLEANUP_COMPLETED',
        'run_id' => $runId,
        'trigger' => $trigger,
        'retention_days' => storage_cleanup_retention_days(),
        'eligible_files' => (int)$scan['total_files'],
        'eligible_bytes' => (int)$scan['total_bytes'],
        'attempted_files' => count($candidates),
        'deleted_files' => 0,
        'deleted_bytes' => 0,
        'failed_files' => 0,
        'metadata_failures' => 0,
        'orphaned_files' => 0,
        'remaining_files' => max(0, (int)$scan['total_files'] - count($candidates)),
        'categories' => [],
    ];
    $deletedRefs = [];
    $roots = array_replace(storage_cleanup_default_roots(), (array)($options['roots'] ?? []));

    foreach ($candidates as $candidate) {
        $category = (string)($candidate['category'] ?? '');
        $path = (string)($candidate['path'] ?? '');
        $safePath = storage_cleanup_path_inside_root((string)($roots[$category] ?? ''), $path);
        if ($safePath === '' || !storage_cleanup_candidate_still_eligible($candidate, (int)$scan['cutoff_at'], $deps)) {
            $result['failed_files']++;
            continue;
        }

        $deletePath = $safePath;
        $metadataReady = true;
        $metadataBacked = in_array($category, ['add_money_receipts', 'support_attachments'], true);
        if ($metadataBacked) {
            $quarantinePath = storage_cleanup_quarantine_path(
                $safePath,
                $runId,
                (string)($candidate['candidate_id'] ?? '')
            );
            if (!($deps['move_file'])($safePath, $quarantinePath)) {
                $result['failed_files']++;
                continue;
            }
            $deletePath = storage_cleanup_path_inside_root((string)($roots[$category] ?? ''), $quarantinePath);
            if ($deletePath === '') {
                ($deps['move_file'])($quarantinePath, $safePath);
                $result['failed_files']++;
                continue;
            }

            try {
                if ($category === 'add_money_receipts') {
                    $metadataReady = storage_cleanup_archive_add_money($candidate, $actorUid, $now, $deps);
                } else {
                    $metadataReady = storage_cleanup_archive_support($candidate, $actorUid, $now, $deps);
                }
            } catch (Throwable $exception) {
                $metadataReady = false;
            }
            if (!$metadataReady) {
                $restored = ($deps['move_file'])($quarantinePath, $safePath);
                $result['failed_files']++;
                $result['metadata_failures']++;
                if (!$restored) {
                    $result['orphaned_files']++;
                }
                continue;
            }
        }

        if (!($deps['delete_file'])($deletePath)) {
            $result['failed_files']++;
            if ($metadataBacked) {
                $result['orphaned_files']++;
            }
            continue;
        }

        $size = max(0, (int)($candidate['size_bytes'] ?? 0));
        $result['deleted_files']++;
        $result['deleted_bytes'] += $size;
        if (!isset($result['categories'][$category])) {
            $result['categories'][$category] = ['deleted_files' => 0, 'deleted_bytes' => 0];
        }
        $result['categories'][$category]['deleted_files']++;
        $result['categories'][$category]['deleted_bytes'] += $size;
        $deletedRefs[] = [
            'candidate_id' => (string)($candidate['candidate_id'] ?? ''),
            'category' => $category,
            'reference' => (string)(
                $candidate['metadata']['request_id']
                ?? $candidate['metadata']['ticket_id']
                ?? $candidate['name']
                ?? ''
            ),
            'size_bytes' => $size,
        ];
    }

    $result['remaining_files'] = max(0, (int)$scan['total_files'] - (int)$result['deleted_files']);
    $result['ok'] = $result['failed_files'] === 0;
    $result['code'] = $result['ok'] ? 'STORAGE_CLEANUP_COMPLETED' : 'STORAGE_CLEANUP_PARTIAL';
    ($deps['patch_audit'])($auditPath, [
        'status' => $result['ok'] ? 'COMPLETED' : 'PARTIAL',
        'attempted_files' => $result['attempted_files'],
        'deleted_files' => $result['deleted_files'],
        'deleted_bytes' => $result['deleted_bytes'],
        'failed_files' => $result['failed_files'],
        'metadata_failures' => $result['metadata_failures'],
        'orphaned_files' => $result['orphaned_files'],
        'remaining_files' => $result['remaining_files'],
        'deleted_items' => array_slice($deletedRefs, 0, 100),
        'completed_at' => storage_cleanup_now(),
    ]);

    if (function_exists('system_log')) {
        system_log('STORAGE_CLEANUP', $runId, 'Storage cleanup completed', [
            'actor_uid' => trim($actorUid),
            'trigger' => $trigger,
            'categories' => $selected,
            'deleted_files' => $result['deleted_files'],
            'deleted_bytes' => $result['deleted_bytes'],
            'failed_files' => $result['failed_files'],
        ]);
    }

    return $result;
}
