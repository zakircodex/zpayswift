<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function birthday_cleanup_claim(int $now): string
{
    $path = birthday_path('CLEANUP_LEASES') . '/ACTIVE';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return '';
        }
        $current = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        if ((int)($current['expires_at'] ?? 0) > $now) {
            return '';
        }
        $token = bin2hex(random_bytes(16));
        $write = fb_put_if_match($path, [
            'token_hash' => hash('sha256', $token),
            'started_at' => $now,
            'expires_at' => $now + 900,
        ], (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(25000);
            continue;
        }
        return !empty($write['ok']) ? $token : '';
    }
    return '';
}

function birthday_cleanup_release(string $token, int $now): void
{
    if ($token === '') {
        return;
    }
    $path = birthday_path('CLEANUP_LEASES') . '/ACTIVE';
    $snapshot = fb_get_with_etag($path);
    $current = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
    if (empty($snapshot['ok'])
        || !is_string($snapshot['etag'] ?? null)
        || !hash_equals((string)($current['token_hash'] ?? ''), hash('sha256', $token))) {
        return;
    }
    $current['completed_at'] = $now;
    $current['expires_at'] = $now;
    fb_put_if_match($path, $current, (string)$snapshot['etag']);
}

function birthday_cleanup_run(bool $dryRun = false, int $limit = 100): array
{
    $limit = max(1, min(1000, $limit));
    $now = birthday_now();
    $leaseToken = $dryRun ? '' : birthday_cleanup_claim($now);
    if (!$dryRun && $leaseToken === '') {
        return ['ok' => true, 'busy' => true, 'message' => 'Another cleanup run is active.', 'completed_at' => $now];
    }
    $graceCutoff = $now - 7 * 86400;
    $result = [
        'ok' => true,
        'scanned' => 0,
        'scanned_universes' => 0,
        'scanned_drafts' => 0,
        'scanned_media' => 0,
        'scanned_transient' => 0,
        'marked_expired' => 0,
        'deleted_universes' => 0,
        'deleted_drafts' => 0,
        'deleted_media' => 0,
        'deleted_transient' => 0,
        'would_delete' => 0,
        'skipped_active' => 0,
        'failed' => 0,
    ];

    $universes = fb_get(birthday_path('UNIVERSES'));
    $universeActions = 0;
    foreach (is_array($universes) ? $universes : [] as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $result['scanned']++;
        $result['scanned_universes']++;
        $status = strtoupper((string)($row['status'] ?? 'ACTIVE'));
        $expiresAt = (int)($row['expires_at'] ?? 0);
        if ($status === 'ACTIVE' && $expiresAt > $now) {
            $result['skipped_active']++;
            continue;
        }
        if ($status === 'ACTIVE' && $expiresAt > 0 && $expiresAt <= $now) {
            if ($universeActions >= $limit) {
                continue;
            }
            $universeActions++;
            if (!$dryRun) {
                $snapshot = fb_get_with_etag(birthday_path('UNIVERSES', (string)$id));
                if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null) || !is_array($snapshot['value'] ?? null)) {
                    $result['failed']++;
                    continue;
                }
                $current = (array)$snapshot['value'];
                if (strtoupper((string)($current['status'] ?? '')) !== 'ACTIVE'
                    || (int)($current['expires_at'] ?? 0) > $now) {
                    continue;
                }
                $current['status'] = 'EXPIRED';
                $current['expired_at'] = $now;
                $current['updated_at'] = $now;
                $write = fb_put_if_match(birthday_path('UNIVERSES', (string)$id), $current, (string)$snapshot['etag']);
                if (empty($write['ok'])) {
                    $result['failed']++;
                    continue;
                }
            }
            $result['marked_expired']++;
            continue;
        }
        $terminalAt = $status === 'DELETED'
            ? (int)($row['deleted_at'] ?? $row['updated_at'] ?? 0)
            : (int)($row['expired_at'] ?? $expiresAt);
        if (!in_array($status, ['EXPIRED', 'DELETED'], true) || $terminalAt <= 0 || $terminalAt > $graceCutoff) {
            continue;
        }
        if ($universeActions >= $limit) {
            continue;
        }
        $universeActions++;
        $result['would_delete']++;
        if ($dryRun) {
            continue;
        }
        foreach (['photo_media_id', 'custom_audio_media_id'] as $mediaField) {
            $mediaId = trim((string)($row[$mediaField] ?? ''));
            if ($mediaId !== '') {
                $media = fb_get(birthday_path('MEDIA', $mediaId));
                if (is_array($media)) {
                    birthday_media_delete_files($media);
                    fb_delete(birthday_path('MEDIA', $mediaId));
                    $result['deleted_media']++;
                }
            }
        }
        birthday_release_reservation('SLUGS', (string)($row['slug'] ?? ''), (string)$id);
        birthday_release_reservation('STAR_IDS', (string)($row['star_id'] ?? ''), (string)$id);
        $ownerUid = trim((string)($row['owner_uid'] ?? ''));
        if ($ownerUid !== '') {
            fb_delete(birthday_path('OWNERS') . '/' . znews_firebase_key($ownerUid, 'uid') . '/' . znews_firebase_key((string)$id, 'universe_id'));
        }
        if (fb_delete(birthday_path('UNIVERSES', (string)$id))) {
            $result['deleted_universes']++;
        } else {
            $result['failed']++;
        }
    }

    $drafts = fb_get(birthday_path('DRAFTS'));
    $draftActions = 0;
    foreach (is_array($drafts) ? $drafts : [] as $id => $draft) {
        if (!is_array($draft)) {
            continue;
        }
        $result['scanned']++;
        $result['scanned_drafts']++;
        if ((int)($draft['expires_at'] ?? 0) > $now && strtoupper((string)($draft['status'] ?? 'DRAFT')) === 'DRAFT') {
            continue;
        }
        if ($draftActions >= $limit) {
            continue;
        }
        $draftActions++;
        if ($dryRun) {
            $result['would_delete']++;
            continue;
        }
        foreach (['photo_media_id', 'custom_audio_media_id'] as $mediaField) {
            $mediaId = trim((string)($draft[$mediaField] ?? ''));
            if ($mediaId !== '') {
                $media = fb_get(birthday_path('MEDIA', $mediaId));
                if (is_array($media) && strtoupper((string)($media['status'] ?? '')) !== 'ACTIVE') {
                    birthday_media_delete_files($media);
                    fb_delete(birthday_path('MEDIA', $mediaId));
                    $result['deleted_media']++;
                }
            }
        }
        if (fb_delete(birthday_path('DRAFTS', (string)$id))) {
            $result['deleted_drafts']++;
        } else {
            $result['failed']++;
        }
    }

    $mediaRows = fb_get(birthday_path('MEDIA'));
    $mediaActions = 0;
    foreach (is_array($mediaRows) ? $mediaRows : [] as $id => $media) {
        if (!is_array($media)) {
            continue;
        }
        $result['scanned']++;
        $result['scanned_media']++;
        $status = strtoupper((string)($media['status'] ?? ''));
        $staleDraft = $status === 'DRAFT'
            && (int)($media['expires_at'] ?? 0) > 0
            && (int)$media['expires_at'] <= $now;
        $staleReplacement = $status === 'REPLACED'
            && (int)($media['updated_at'] ?? $media['created_at'] ?? 0) > 0
            && (int)($media['updated_at'] ?? $media['created_at'] ?? 0) <= $now - 86400;
        if (!$staleDraft && !$staleReplacement) {
            continue;
        }

        $isReferenced = false;
        $referenceField = strtoupper((string)($media['kind'] ?? '')) === 'AUDIO'
            ? 'custom_audio_media_id'
            : 'photo_media_id';
        $draftId = trim((string)($media['draft_id'] ?? ''));
        if ($draftId !== '') {
            $draft = fb_get(birthday_path('DRAFTS', $draftId));
            $isReferenced = is_array($draft)
                && hash_equals((string)($draft[$referenceField] ?? ''), (string)$id)
                && strtoupper((string)($draft['status'] ?? 'DRAFT')) === 'DRAFT'
                && (int)($draft['expires_at'] ?? 0) > $now;
        }
        $universeId = trim((string)($media['universe_id'] ?? ''));
        if (!$isReferenced && $universeId !== '') {
            $universe = birthday_universe_by_id($universeId);
            $isReferenced = is_array($universe)
                && hash_equals((string)($universe[$referenceField] ?? ''), (string)$id)
                && strtoupper((string)($universe['status'] ?? '')) === 'ACTIVE'
                && (int)($universe['expires_at'] ?? 0) > $now;
        }
        if ($isReferenced || $mediaActions >= $limit) {
            continue;
        }
        $mediaActions++;
        $result['would_delete']++;
        if ($dryRun) {
            continue;
        }
        birthday_media_delete_files($media);
        if (fb_delete(birthday_path('MEDIA', (string)$id))) {
            $result['deleted_media']++;
        } else {
            $result['failed']++;
        }
    }

    $transientRoots = [birthday_path('IDEMPOTENCY'), birthday_path('RATE_LIMITS'), birthday_path('VIEW_DEDUP')];
    foreach ($transientRoots as $rootPath) {
        $root = fb_get($rootPath);
        $transientActions = 0;
        $walk = function (array $rows, string $path) use (&$walk, &$result, &$transientActions, $limit, $now, $dryRun): void {
            foreach ($rows as $key => $row) {
                if (!is_array($row) || preg_match('/^[^.#$\[\]\/]{1,200}$/u', (string)$key) !== 1) {
                    continue;
                }
                $currentPath = $path . '/' . (string)$key;
                if (array_key_exists('expires_at', $row)) {
                    $result['scanned']++;
                    $result['scanned_transient']++;
                    $expiresAt = (int)$row['expires_at'];
                    if ($expiresAt <= 0 || $expiresAt > $now || $transientActions >= $limit) {
                        continue;
                    }
                    $transientActions++;
                    $result['would_delete']++;
                    if ($dryRun) {
                        continue;
                    }
                    if (fb_delete($currentPath)) {
                        $result['deleted_transient']++;
                    } else {
                        $result['failed']++;
                    }
                    continue;
                }
                if ($transientActions < $limit) {
                    $walk($row, $currentPath);
                }
            }
        };
        if (is_array($root)) {
            $walk($root, $rootPath);
        }
    }

    $result['ok'] = $result['failed'] === 0;
    $result['completed_at'] = $now;
    if (!$dryRun) {
        fb_put(birthday_path('CLEANUP_LEASES') . '/LAST_RUN', $result);
        birthday_cleanup_release($leaseToken, birthday_now());
    }
    return $result;
}
