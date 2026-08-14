<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function user_registration_kyc_private_root(): string
{
    return rtrim(dirname(app_private_config_path()), '/\\') . '/storage/register_kyc';
}

function user_registration_kyc_token_root(string $registerToken): string
{
    return user_registration_kyc_private_root() . '/' . substr(hash('sha256', trim($registerToken)), 0, 32);
}

function user_registration_kyc_normalized_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function user_registration_kyc_valid_private_file(string $path, string $registerToken): bool
{
    $path = trim($path);
    if (
        $path === ''
        || trim($registerToken) === ''
        || str_contains($path, "\0")
        || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) === 1
        || preg_match('/(^|[\/\\\\])\.\.([\/\\\\]|$)/', $path) === 1
    ) {
        return false;
    }

    $realRoot = realpath(user_registration_kyc_token_root($registerToken));
    $realPath = realpath($path);
    if ($realRoot === false || $realPath === false || !is_file($realPath)) {
        return false;
    }

    $root = rtrim(user_registration_kyc_normalized_path($realRoot), '/') . '/';
    $file = user_registration_kyc_normalized_path($realPath);
    if (!str_starts_with($file, $root)) {
        return false;
    }

    $size = (int)filesize($realPath);
    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        return false;
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($realPath);
    return in_array($mime, ['image/jpeg', 'image/png'], true);
}

function user_registration_kyc_state(array $preAuth, string $registerToken): array
{
    $kyc = is_array($preAuth['KYC'] ?? null) ? (array)$preAuth['KYC'] : [];
    $documentPath = trim((string)($kyc['document_path_private'] ?? $preAuth['document_path_private'] ?? ''));
    $selfiePath = trim((string)($kyc['selfie_path_private'] ?? $preAuth['selfie_path_private'] ?? ''));
    $documentReady = user_registration_kyc_valid_private_file($documentPath, $registerToken);
    $selfieReady = user_registration_kyc_valid_private_file($selfiePath, $registerToken);

    return [
        'document_ready' => $documentReady,
        'selfie_ready' => $selfieReady,
        'kyc_ready' => $documentReady && $selfieReady,
        'document_path_private' => $documentReady ? $documentPath : '',
        'selfie_path_private' => $selfieReady ? $selfiePath : '',
        'kyc' => $kyc,
    ];
}

function user_registration_kyc_is_web_draft(array $preAuth): bool
{
    return !empty($preAuth['web_kyc_draft'])
        && strtoupper(trim((string)($preAuth['registration_source'] ?? ''))) === 'USER_WEB';
}

function user_registration_kyc_remove_private_file(string $path, string $registerToken): void
{
    if (user_registration_kyc_valid_private_file($path, $registerToken)) {
        @unlink($path);
    }
}

function user_registration_kyc_temp_ttl_seconds(): int
{
    $ttl = defined('REGISTRATION_KYC_TEMP_TTL_SECONDS')
        ? (int)constant('REGISTRATION_KYC_TEMP_TTL_SECONDS')
        : 60 * 60 * 72;

    return max(60 * 60, min(60 * 60 * 24 * 30, $ttl));
}

function user_registration_kyc_cleanup_batch_limit(): int
{
    $limit = defined('REGISTRATION_KYC_CLEANUP_BATCH_LIMIT')
        ? (int)constant('REGISTRATION_KYC_CLEANUP_BATCH_LIMIT')
        : 100;

    return max(1, min(1000, $limit));
}

function user_registration_kyc_cleanup_lease_seconds(): int
{
    return 300;
}

function user_registration_kyc_cleanup_token_valid(string $registerToken): bool
{
    return preg_match('/^[A-Za-z0-9_-]{16,160}$/D', trim($registerToken)) === 1;
}

function user_registration_kyc_cleanup_paths(array $preAuth): array
{
    $kyc = is_array($preAuth['KYC'] ?? null) ? (array)$preAuth['KYC'] : [];
    $paths = [
        $kyc['document_path_private'] ?? '',
        $kyc['selfie_path_private'] ?? '',
        $preAuth['document_path_private'] ?? '',
        $preAuth['selfie_path_private'] ?? '',
    ];

    $clean = [];
    foreach ($paths as $path) {
        $path = trim((string)$path);
        if ($path !== '') {
            $clean[user_registration_kyc_normalized_path($path)] = $path;
        }
    }

    return array_values($clean);
}

function user_registration_kyc_cleanup_path_in_token_root(string $path, string $registerToken): bool
{
    $path = trim($path);
    if (
        $path === ''
        || trim($registerToken) === ''
        || str_contains($path, "\0")
        || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) === 1
        || preg_match('/(^|[\/\\\\])\.\.([\/\\\\]|$)/', $path) === 1
    ) {
        return false;
    }

    $realRoot = realpath(user_registration_kyc_token_root($registerToken));
    if ($realRoot === false || !is_dir($realRoot)) {
        return false;
    }

    $root = rtrim(user_registration_kyc_normalized_path($realRoot), '/') . '/';
    $realPath = realpath($path);
    if ($realPath !== false) {
        return str_starts_with(user_registration_kyc_normalized_path($realPath), $root);
    }

    $parent = realpath(dirname($path));
    if ($parent === false) {
        return false;
    }

    $candidate = rtrim(user_registration_kyc_normalized_path($parent), '/')
        . '/'
        . basename($path);

    return str_starts_with($candidate, $root);
}

function user_registration_kyc_cleanup_token_file_paths(string $registerToken): array
{
    $realRoot = realpath(user_registration_kyc_token_root($registerToken));
    if ($realRoot === false || !is_dir($realRoot)) {
        return [];
    }

    $items = scandir($realRoot);
    if ($items === false) {
        return [];
    }

    $paths = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $realRoot . DIRECTORY_SEPARATOR . $item;
        if (is_file($path) && user_registration_kyc_cleanup_path_in_token_root($path, $registerToken)) {
            $paths[] = $path;
        }
    }

    return $paths;
}

function user_registration_kyc_cleanup_row_state(array $preAuth, int $now, int $ttl): string
{
    $status = strtoupper(trim((string)($preAuth['status'] ?? '')));
    if ($status === 'COMPLETED' || (int)($preAuth['completed_at'] ?? 0) > 0) {
        return 'FINALIZED';
    }

    $cleanup = is_array($preAuth['kyc_cleanup'] ?? null) ? (array)$preAuth['kyc_cleanup'] : [];
    $cleanupStatus = strtoupper(trim((string)($cleanup['status'] ?? '')));
    $leaseExpiresAt = (int)($cleanup['lease_expires_at'] ?? 0);
    if ($cleanupStatus === 'CLAIMED' && $leaseExpiresAt > $now) {
        return 'ACTIVE_CLAIM';
    }

    $expiresAt = (int)($preAuth['expires_at'] ?? 0);
    if ($expiresAt <= 0) {
        return 'AMBIGUOUS';
    }

    if ($expiresAt + max(0, $ttl) > $now) {
        return 'FRESH';
    }

    return 'ELIGIBLE';
}

function user_registration_kyc_cleanup_identity_hashes(array $preAuth): array
{
    $kyc = is_array($preAuth['KYC'] ?? null) ? (array)$preAuth['KYC'] : [];
    $hashes = array_merge(
        [
            $preAuth['identity_number_hash'] ?? '',
            $preAuth['document_number_hash'] ?? '',
            $kyc['identity_number_hash'] ?? '',
        ],
        is_array($preAuth['identity_hash_variants'] ?? null)
            ? (array)$preAuth['identity_hash_variants']
            : []
    );

    $valid = [];
    foreach ($hashes as $hash) {
        $hash = strtolower(trim((string)$hash));
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) === 1) {
            $valid[$hash] = $hash;
        }
    }

    return array_values($valid);
}

function user_registration_kyc_cleanup_identity_index_paths(string $hash, string $type): array
{
    $paths = [
        'USER_IDENTITY_INDEX/' . $hash,
        'USER_INDEX/IDENTITY/' . $hash,
    ];
    if ($type === 'NID') {
        $paths[] = 'USER_INDEX/NID/' . $hash;
    } elseif ($type === 'PASSPORT') {
        $paths[] = 'USER_INDEX/PASSPORT/' . $hash;
    }

    return $paths;
}

function user_registration_kyc_cleanup_index_uid($value): string
{
    if (is_string($value)) {
        return trim($value);
    }
    if (is_array($value)) {
        return trim((string)($value['uid'] ?? $value['value'] ?? ''));
    }

    return '';
}

function user_registration_kyc_cleanup_index_owner(array $preAuth, array $dependencies): array
{
    $type = strtoupper(trim((string)(
        $preAuth['identity_type']
        ?? $preAuth['document_type']
        ?? $preAuth['KYC']['document_type']
        ?? $preAuth['KYC']['type']
        ?? ''
    )));
    $hashes = user_registration_kyc_cleanup_identity_hashes($preAuth);
    if (!in_array($type, ['NID', 'PASSPORT'], true) || !$hashes) {
        return ['ok' => false, 'uid' => ''];
    }

    foreach ($hashes as $hash) {
        foreach (user_registration_kyc_cleanup_identity_index_paths($hash, $type) as $path) {
            $result = ($dependencies['read_identity_index'])($path);
            if (!is_array($result) || empty($result['ok'])) {
                return ['ok' => false, 'uid' => ''];
            }
            $uid = user_registration_kyc_cleanup_index_uid($result['value'] ?? null);
            if ($uid !== '') {
                return ['ok' => true, 'uid' => $uid];
            }
        }
    }

    return ['ok' => true, 'uid' => ''];
}

function user_registration_kyc_cleanup_remove_token_files(string $registerToken): array
{
    $root = user_registration_kyc_token_root($registerToken);
    $realRoot = realpath($root);
    if ($realRoot === false || !is_dir($realRoot)) {
        return ['ok' => true, 'deleted' => 0, 'missing' => 1, 'failed' => 0];
    }

    $deleted = 0;
    $failed = 0;
    $items = scandir($realRoot);
    if ($items === false) {
        return ['ok' => false, 'deleted' => 0, 'missing' => 0, 'failed' => 1];
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $realRoot . DIRECTORY_SEPARATOR . $item;
        if (!is_file($path) || !user_registration_kyc_cleanup_path_in_token_root($path, $registerToken)) {
            $failed++;
            continue;
        }

        if (@unlink($path)) {
            $deleted++;
        } else {
            $failed++;
        }
    }

    if ($failed === 0) {
        @rmdir($realRoot);
    }

    return [
        'ok' => $failed === 0,
        'deleted' => $deleted,
        'missing' => 0,
        'failed' => $failed,
    ];
}

function user_registration_kyc_cleanup_default_dependencies(): array
{
    return [
        'list_preauth' => static fn(): array => fb_get_with_etag('AUTH_USER_REGISTER_PREAUTH'),
        'snapshot' => static fn(string $token): array => fb_get_with_etag('AUTH_USER_REGISTER_PREAUTH/' . $token),
        'claim' => static fn(string $token, array $row, string $etag): array =>
            fb_put_if_match('AUTH_USER_REGISTER_PREAUTH/' . $token, $row, $etag),
        'read_user' => static fn(string $uid): array => fb_get_with_etag('USERS/' . $uid),
        'read_identity_index' => static fn(string $path): array => fb_get_with_etag($path),
        'delete_record' => static fn(string $token, string $etag): array =>
            fb_delete_if_match('AUTH_USER_REGISTER_PREAUTH/' . $token, $etag),
        'delete_files' => static fn(string $token): array =>
            user_registration_kyc_cleanup_remove_token_files($token),
        'before_delete' => static function (string $token): void {
        },
    ];
}

function user_registration_kyc_cleanup_run(array $options = [], array $dependencies = []): array
{
    $deps = array_replace(user_registration_kyc_cleanup_default_dependencies(), $dependencies);
    $now = isset($options['now']) ? (int)$options['now'] : time();
    $ttl = isset($options['ttl'])
        ? max(0, (int)$options['ttl'])
        : user_registration_kyc_temp_ttl_seconds();
    $limit = isset($options['limit'])
        ? max(1, min(1000, (int)$options['limit']))
        : user_registration_kyc_cleanup_batch_limit();
    $dryRun = !empty($options['dry_run']);
    $summary = [
        'ok' => true,
        'dry_run' => $dryRun,
        'scanned' => 0,
        'eligible' => 0,
        'claimed' => 0,
        'deleted_records' => 0,
        'deleted_files' => 0,
        'would_delete' => 0,
        'missing' => 0,
        'skipped_active' => 0,
        'skipped_finalized' => 0,
        'skipped_ambiguous' => 0,
        'failed' => 0,
    ];
    $listResult = ($deps['list_preauth'])();
    if (!is_array($listResult) || empty($listResult['ok'])) {
        $summary['ok'] = false;
        $summary['failed']++;
        return $summary;
    }
    $rows = $listResult['value'] ?? null;
    if (!is_array($rows)) {
        return $summary;
    }

    uasort($rows, static fn($a, $b): int =>
        (int)(is_array($a) ? ($a['expires_at'] ?? PHP_INT_MAX) : PHP_INT_MAX)
        <=> (int)(is_array($b) ? ($b['expires_at'] ?? PHP_INT_MAX) : PHP_INT_MAX));

    $handled = 0;
    foreach ($rows as $registerToken => $listedRow) {
        $summary['scanned']++;
        $registerToken = trim((string)$registerToken);
        if (!is_array($listedRow) || !user_registration_kyc_cleanup_token_valid($registerToken)) {
            $summary['skipped_ambiguous']++;
            continue;
        }

        $listedState = user_registration_kyc_cleanup_row_state($listedRow, $now, $ttl);
        if ($listedState === 'FINALIZED') {
            $summary['skipped_finalized']++;
            continue;
        }
        if ($listedState === 'FRESH' || $listedState === 'ACTIVE_CLAIM') {
            $summary['skipped_active']++;
            continue;
        }
        if ($listedState !== 'ELIGIBLE') {
            $summary['skipped_ambiguous']++;
            continue;
        }
        if ($handled >= $limit) {
            continue;
        }

        $paths = user_registration_kyc_cleanup_paths($listedRow);
        if (!$paths) {
            $paths = user_registration_kyc_cleanup_token_file_paths($registerToken);
        }
        if (!$paths) {
            $summary['skipped_ambiguous']++;
            continue;
        }
        $uid = trim((string)($listedRow['uid'] ?? ''));
        $userResult = $uid !== '' ? ($deps['read_user'])($uid) : ['ok' => true, 'value' => null];
        if (!is_array($userResult) || empty($userResult['ok'])) {
            $summary['failed']++;
            continue;
        }
        $user = $userResult['value'] ?? null;
        if (is_array($user)) {
            $summary['skipped_finalized']++;
            continue;
        }
        $indexOwner = user_registration_kyc_cleanup_index_owner($listedRow, $deps);
        if (empty($indexOwner['ok'])) {
            $summary['failed']++;
            continue;
        }
        $indexUid = trim((string)($indexOwner['uid'] ?? ''));
        if ($indexUid !== '') {
            $indexedUserResult = ($deps['read_user'])($indexUid);
            if (!is_array($indexedUserResult) || empty($indexedUserResult['ok'])) {
                $summary['failed']++;
                continue;
            }
            if (is_array($indexedUserResult['value'] ?? null)) {
                $summary['skipped_finalized']++;
                continue;
            }
        }

        $summary['eligible']++;
        $handled++;
        if ($dryRun) {
            $summary['would_delete']++;
            continue;
        }

        $snapshot = ($deps['snapshot'])($registerToken);
        $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        $etag = trim((string)($snapshot['etag'] ?? ''));
        if (empty($snapshot['ok']) || !$row || $etag === '') {
            $summary['failed']++;
            continue;
        }
        if (user_registration_kyc_cleanup_row_state($row, $now, $ttl) !== 'ELIGIBLE') {
            $summary['skipped_active']++;
            continue;
        }

        $claimId = bin2hex(random_bytes(12));
        $previousStatus = strtoupper(trim((string)($row['status'] ?? '')));
        $row['status'] = 'KYC_CLEANUP_CLAIMED';
        $row['kyc_cleanup'] = [
            'status' => 'CLAIMED',
            'claim_id' => $claimId,
            'previous_status' => $previousStatus,
            'claimed_at' => $now,
            'lease_expires_at' => $now + user_registration_kyc_cleanup_lease_seconds(),
            'updated_at' => $now,
        ];
        $claim = ($deps['claim'])($registerToken, $row, $etag);
        if (empty($claim['ok'])) {
            $summary['skipped_active']++;
            continue;
        }
        $summary['claimed']++;

        ($deps['before_delete'])($registerToken);

        $claimedSnapshot = ($deps['snapshot'])($registerToken);
        $claimedRow = is_array($claimedSnapshot['value'] ?? null) ? (array)$claimedSnapshot['value'] : [];
        $claimedEtag = trim((string)($claimedSnapshot['etag'] ?? ''));
        $claimedCleanup = is_array($claimedRow['kyc_cleanup'] ?? null)
            ? (array)$claimedRow['kyc_cleanup']
            : [];
        if (
            empty($claimedSnapshot['ok'])
            || $claimedEtag === ''
            || !hash_equals($claimId, (string)($claimedCleanup['claim_id'] ?? ''))
            || strtoupper(trim((string)($claimedCleanup['status'] ?? ''))) !== 'CLAIMED'
        ) {
            $summary['failed']++;
            continue;
        }

        $paths = user_registration_kyc_cleanup_paths($claimedRow);
        $uid = trim((string)($claimedRow['uid'] ?? ''));
        $userResult = $uid !== '' ? ($deps['read_user'])($uid) : ['ok' => true, 'value' => null];
        if (!is_array($userResult) || empty($userResult['ok'])) {
            $summary['failed']++;
            continue;
        }
        $user = $userResult['value'] ?? null;
        $permanent = is_array($user);
        if (!$permanent) {
            $indexOwner = user_registration_kyc_cleanup_index_owner($claimedRow, $deps);
            if (empty($indexOwner['ok'])) {
                $summary['failed']++;
                continue;
            }
            $indexUid = trim((string)($indexOwner['uid'] ?? ''));
            if ($indexUid !== '') {
                $indexedUserResult = ($deps['read_user'])($indexUid);
                if (!is_array($indexedUserResult) || empty($indexedUserResult['ok'])) {
                    $summary['failed']++;
                    continue;
                }
                $permanent = is_array($indexedUserResult['value'] ?? null);
            }
        }
        if ($permanent) {
            $summary['skipped_finalized']++;
            continue;
        }

        $fileResult = ($deps['delete_files'])($registerToken);
        $summary['deleted_files'] += max(0, (int)($fileResult['deleted'] ?? 0));
        $summary['missing'] += max(0, (int)($fileResult['missing'] ?? 0));
        if (empty($fileResult['ok'])) {
            $summary['failed'] += max(1, (int)($fileResult['failed'] ?? 1));
            continue;
        }

        $deleted = ($deps['delete_record'])($registerToken, $claimedEtag);
        if (empty($deleted['ok'])) {
            $summary['failed']++;
            continue;
        }
        $summary['deleted_records']++;
    }

    $summary['ok'] = $summary['failed'] === 0;
    return $summary;
}
