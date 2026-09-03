<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/public_projection.php';
require_once __DIR__ . '/categories.php';

function znews_feed_cfg(string $name, int $default, int $min, int $max): int
{
    $value = defined($name) ? (int)constant($name) : $default;
    return max($min, min($max, $value));
}

function znews_feed_candidate_limit(): int { return znews_feed_cfg('ZNEWS_FEED_CANDIDATE_LIMIT', 1000, 50, 5000); }
function znews_feed_session_ttl(): int { return znews_feed_cfg('ZNEWS_FEED_SESSION_TTL_SECONDS', 7200, 900, 21600); }
function znews_feed_creator_cap(): int { return znews_feed_cfg('ZNEWS_FEED_CREATOR_CAP_PER_BLOCK', 2, 1, 5); }
function znews_feed_creator_block_size(): int { return znews_feed_cfg('ZNEWS_FEED_CREATOR_BLOCK_SIZE', 20, 10, 50); }
function znews_feed_impression_batch_max(): int { return znews_feed_cfg('ZNEWS_FEED_IMPRESSION_BATCH_MAX', 12, 1, 30); }

function znews_feed_candidate_window(int $pageSize): int
{
    $pageSize = max(1, min(30, $pageSize));
    return min(znews_feed_candidate_limit(), $pageSize * 5);
}

function znews_feed_session_candidate_page_size(int $responsePageSize): int
{
    // Keep the established Web ranking pool while allowing smaller response batches.
    return max(12, min(30, $responsePageSize));
}

function znews_feed_session_path(string $sessionId): string
{
    return 'ZNEWS_FEED_SESSIONS/' . znews_firebase_key($sessionId, 'feed_session_id');
}

function znews_feed_session_impression_path(string $sessionId, string $postId): string
{
    return 'ZNEWS_FEED_SESSION_IMPRESSIONS/'
        . znews_firebase_key($sessionId, 'feed_session_id')
        . '/'
        . znews_firebase_key($postId, 'post_id');
}

function znews_feed_exposure_path(string $postId): string
{
    return 'ZNEWS_FEED_EXPOSURE/' . znews_firebase_key($postId, 'post_id');
}

function znews_feed_unique_path(string $postId, string $fingerprintHash): string
{
    return 'ZNEWS_FEED_EXPOSURE_UNIQUE/'
        . znews_firebase_key($postId, 'post_id')
        . '/'
        . znews_firebase_key($fingerprintHash, 'viewer_hash');
}

function znews_feed_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function znews_feed_base64url_decode(string $value): ?string
{
    if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value) === 1) {
        return null;
    }
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return is_string($decoded) ? $decoded : null;
}

function znews_feed_cursor_encode(string $sessionId, int $offset, int $expiresAt): string
{
    $payload = json_encode([
        'sid' => $sessionId,
        'offset' => max(0, $offset),
        'exp' => max(0, $expiresAt),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return '';
    }
    $body = znews_feed_base64url_encode($payload);
    $signature = substr(znews_view_hmac('feed-cursor|' . $body), 0, 40);
    return $body . '.' . $signature;
}

function znews_feed_cursor_decode($value): array
{
    $cursor = trim((string)$value);
    if ($cursor === '') {
        return [];
    }
    if (strlen($cursor) > 1024 || substr_count($cursor, '.') !== 1) {
        api_response(false, 'ZNEWS_FEED_CURSOR_INVALID', 'Invalid feed cursor.', [], 422);
    }
    [$body, $signature] = explode('.', $cursor, 2);
    $expected = substr(znews_view_hmac('feed-cursor|' . $body), 0, 40);
    if ($signature === '' || !hash_equals($expected, $signature)) {
        api_response(false, 'ZNEWS_FEED_CURSOR_INVALID', 'Invalid feed cursor.', [], 422);
    }
    $json = znews_feed_base64url_decode($body);
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data)) {
        api_response(false, 'ZNEWS_FEED_CURSOR_INVALID', 'Invalid feed cursor.', [], 422);
    }
    $sessionId = trim((string)($data['sid'] ?? ''));
    $offset = filter_var($data['offset'] ?? null, FILTER_VALIDATE_INT);
    $expiresAt = filter_var($data['exp'] ?? null, FILTER_VALIDATE_INT);
    if (preg_match('/^ZFS[A-F0-9]{32}$/', $sessionId) !== 1
        || $offset === false || $offset < 0
        || $expiresAt === false || $expiresAt < znews_now()) {
        api_response(false, 'ZNEWS_FEED_CURSOR_EXPIRED', 'This feed session has expired.', [], 409);
    }
    return [
        'session_id' => $sessionId,
        'offset' => (int)$offset,
        'expires_at' => (int)$expiresAt,
    ];
}

function znews_feed_viewer_context(): array
{
    $context = znews_view_context();
    $fingerprint = trim((string)($context['fingerprint'] ?? ''));
    $visitorHash = trim((string)($context['visitor_hash'] ?? ''));
    return [
        'fingerprint_hash' => znews_view_hmac('feed-session|' . $fingerprint),
        'unique_hash' => znews_view_hmac('feed-unique|' . $visitorHash),
    ];
}

function znews_feed_tie(string $seed, string $postId): string
{
    return znews_view_hmac('feed-tie|' . $seed . '|' . $postId);
}

function znews_feed_public_candidates(
    int $snapshotAt,
    string $seed,
    int $pageSize,
    ?array &$projectionMap = null,
    string $category = ''
): array
{
    $category = znews_normalize_category($category, true);
    $query = $category === ''
        ? [
            'orderBy' => json_encode('created_at'),
            'endAt' => json_encode($snapshotAt),
            'limitToLast' => znews_feed_candidate_window($pageSize),
        ]
        : [
            'orderBy' => json_encode('category_created_at'),
            'startAt' => json_encode(znews_category_query_start($category)),
            'endAt' => json_encode(znews_category_query_end($category, $snapshotAt)),
            'limitToLast' => znews_feed_candidate_window($pageSize),
        ];
    $index = fb_get('ZNEWS_PUBLIC_FEED', $query);
    if (!is_array($index)) {
        return [];
    }

    $rows = [];
    $projectionMap = [];
    foreach ($index as $postKey => $row) {
        $row = is_array($row) ? $row : [];
        $postId = trim((string)($row['post_id'] ?? $postKey));
        if ($postId === '') {
            continue;
        }
        $publicItem = znews_public_projection_item($row);
        if ($publicItem === null) {
            continue;
        }
        $createdAt = max(0, (int)($row['created_at'] ?? 0));
        if ($createdAt > $snapshotAt) {
            continue;
        }
        if ($category !== '' && !hash_equals($category, strtoupper(trim((string)($row['category'] ?? ''))))) {
            continue;
        }
        $rows[] = [
            'post_id' => $postId,
            'creator_uid' => trim((string)($row['creator_uid'] ?? '')),
            'created_at' => $createdAt,
            'ranking_metrics' => znews_ranking_metrics_from_index_row($row),
        ];
        $projectionMap[$postId] = $publicItem;
    }
    znews_sort_index_rows_desc($rows);

    $candidates = [];
    foreach ($rows as $row) {
        $postId = znews_firebase_key((string)$row['post_id'], 'post_id');
        $creatorUid = trim((string)($row['creator_uid'] ?? ''));
        if ($creatorUid === '') {
            $creatorUid = 'UNKNOWN_' . substr(hash('sha256', $postId), 0, 16);
        }
        $metrics = is_array($row['ranking_metrics'] ?? null)
            ? (array)$row['ranking_metrics']
            : znews_ranking_metrics_defaults();
        $createdAt = max(0, (int)($row['created_at'] ?? 0));
        $ageDays = max(0.0, ($snapshotAt - $createdAt) / 86400);
        $impressions = max(0, (int)($metrics['impressions'] ?? 0));
        $uniqueImpressions = max(0, (int)($metrics['unique_impressions'] ?? 0));
        $validViews = max(0, (int)($metrics['valid_views'] ?? 0));
        $uniqueViews = max(0, (int)($metrics['unique_views'] ?? 0));
        $totalOpens = max(0, (int)($metrics['total_opens'] ?? 0));
        $exposureWeight = ($uniqueImpressions * 12) + ($impressions * 2)
            + ($uniqueViews * 8) + ($validViews * 3) + $totalOpens;
        $ageDivisor = max(1.0, min(30.0, $ageDays + 1.0));

        $candidates[] = [
            'post_id' => $postId,
            'creator_uid' => $creatorUid,
            'created_at' => $createdAt,
            'fair_score' => $exposureWeight / $ageDivisor,
            'impressions' => $impressions,
            'unique_impressions' => $uniqueImpressions,
            'last_shown_at' => max(0, (int)($metrics['last_shown_at'] ?? 0)),
            'tie' => znews_feed_tie($seed, $postId),
        ];
    }

    return $candidates;
}

function znews_feed_find_candidate(
    array $pool,
    array $used,
    string $lastCreator,
    array $blockCounts,
    int $creatorCap,
    bool $enforceCap,
    bool $enforceAdjacent
): ?array {
    foreach ($pool as $candidate) {
        $postId = (string)($candidate['post_id'] ?? '');
        $creatorUid = (string)($candidate['creator_uid'] ?? '');
        if ($postId === '' || isset($used[$postId])) {
            continue;
        }
        if ($enforceAdjacent && $lastCreator !== '' && hash_equals($lastCreator, $creatorUid)) {
            continue;
        }
        if ($enforceCap && (int)($blockCounts[$creatorUid] ?? 0) >= $creatorCap) {
            continue;
        }
        return $candidate;
    }
    return null;
}

function znews_feed_rank_candidates(array $candidates, string $seed): array
{
    if (!$candidates) {
        return [];
    }

    $fresh = $candidates;
    usort($fresh, static function (array $a, array $b): int {
        $time = ((int)($b['created_at'] ?? 0)) <=> ((int)($a['created_at'] ?? 0));
        return $time !== 0 ? $time : strcmp((string)($a['tie'] ?? ''), (string)($b['tie'] ?? ''));
    });

    $fair = $candidates;
    usort($fair, static function (array $a, array $b): int {
        $score = ((float)($a['fair_score'] ?? 0.0)) <=> ((float)($b['fair_score'] ?? 0.0));
        if ($score !== 0) {
            return $score;
        }
        $lastShown = ((int)($a['last_shown_at'] ?? 0)) <=> ((int)($b['last_shown_at'] ?? 0));
        if ($lastShown !== 0) {
            return $lastShown;
        }
        $unique = ((int)($a['unique_impressions'] ?? 0)) <=> ((int)($b['unique_impressions'] ?? 0));
        return $unique !== 0 ? $unique : strcmp((string)($a['tie'] ?? ''), (string)($b['tie'] ?? ''));
    });

    // Seven fresh slots and three underexposed slots per ten positions.
    $pattern = ['F', 'F', 'E', 'F', 'F', 'E', 'F', 'F', 'E', 'F'];
    $used = [];
    $selected = [];
    $lastCreator = '';
    $blockCounts = [];
    $creatorCap = znews_feed_creator_cap();
    $blockSize = znews_feed_creator_block_size();
    $total = count($candidates);

    while (count($selected) < $total) {
        $position = count($selected);
        if ($position % $blockSize === 0) {
            $blockCounts = [];
        }
        $type = $pattern[$position % count($pattern)];
        $primary = $type === 'E' ? $fair : $fresh;
        $secondary = $type === 'E' ? $fresh : $fair;

        $candidate = znews_feed_find_candidate($primary, $used, $lastCreator, $blockCounts, $creatorCap, true, true)
            ?? znews_feed_find_candidate($secondary, $used, $lastCreator, $blockCounts, $creatorCap, true, true)
            ?? znews_feed_find_candidate($fresh, $used, $lastCreator, $blockCounts, $creatorCap, true, true)
            ?? znews_feed_find_candidate($primary, $used, $lastCreator, $blockCounts, $creatorCap, false, true)
            ?? znews_feed_find_candidate($secondary, $used, $lastCreator, $blockCounts, $creatorCap, false, true)
            ?? znews_feed_find_candidate($fresh, $used, $lastCreator, $blockCounts, $creatorCap, false, false);

        if (!is_array($candidate)) {
            break;
        }
        $postId = (string)$candidate['post_id'];
        $creatorUid = (string)$candidate['creator_uid'];
        $selected[] = $postId;
        $used[$postId] = true;
        $blockCounts[$creatorUid] = (int)($blockCounts[$creatorUid] ?? 0) + 1;
        $lastCreator = $creatorUid;
    }

    return $selected;
}

function znews_feed_session_order(array $session): array
{
    $order = is_array($session['order'] ?? null) ? (array)$session['order'] : [];
    if (!$order) {
        return [];
    }
    ksort($order, SORT_STRING);
    return array_values(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $order
    ), static fn(string $value): bool => $value !== ''));
}

function znews_feed_create_session(array $viewer, int $pageSize, string $category = ''): array
{
    $now = znews_now();
    $sessionId = 'ZFS' . strtoupper(bin2hex(random_bytes(16)));
    $expiresAt = $now + znews_feed_session_ttl();
    $candidatePageSize = znews_feed_session_candidate_page_size($pageSize);
    $projectionMap = [];
    $category = znews_normalize_category($category, true);
    $candidates = znews_feed_public_candidates($now, $sessionId, $candidatePageSize, $projectionMap, $category);
    $ranked = znews_feed_rank_candidates($candidates, $sessionId);
    $order = [];
    foreach ($ranked as $index => $postId) {
        $order[sprintf('%06d', $index)] = $postId;
    }
    $session = [
        'schema_version' => 1,
        'session_id' => $sessionId,
        'fingerprint_hash' => (string)$viewer['fingerprint_hash'],
        'ranking_mode' => 'FRESH_FAIR_V1',
        'fresh_ratio' => 70,
        'fair_ratio' => 30,
        'creator_cap' => znews_feed_creator_cap(),
        'creator_block_size' => znews_feed_creator_block_size(),
        'snapshot_at' => $now,
        'created_at' => $now,
        'expires_at' => $expiresAt,
        'total' => count($ranked),
        'candidate_window' => znews_feed_candidate_window($candidatePageSize),
        'category' => $category,
        'order' => $order,
    ];
    if (!fb_patch('', [znews_feed_session_path($sessionId) => $session])) {
        api_response(false, 'ZNEWS_FEED_SESSION_CREATE_FAILED', 'Feed could not be prepared.', [], 503);
    }
    $session['_projection_map'] = $projectionMap;
    return $session;
}

function znews_feed_load_session(string $sessionId, array $viewer, ?string $category = null): array
{
    $sessionId = znews_firebase_key($sessionId, 'feed_session_id');
    $session = fb_get(znews_feed_session_path($sessionId));
    if (!is_array($session)) {
        api_response(false, 'ZNEWS_FEED_SESSION_NOT_FOUND', 'This feed session is no longer available.', [], 409);
    }
    $expectedFingerprint = trim((string)($session['fingerprint_hash'] ?? ''));
    if ($expectedFingerprint === '' || !hash_equals($expectedFingerprint, (string)$viewer['fingerprint_hash'])) {
        api_response(false, 'ZNEWS_FEED_SESSION_FORBIDDEN', 'This feed session belongs to another visitor.', [], 403);
    }
    if ((int)($session['expires_at'] ?? 0) < znews_now()) {
        api_response(false, 'ZNEWS_FEED_CURSOR_EXPIRED', 'This feed session has expired.', [], 409);
    }
    if ($category !== null) {
        $sessionCategory = znews_normalize_category($session['category'] ?? '', true);
        $requestedCategory = znews_normalize_category($category, true);
        if (!hash_equals($sessionCategory, $requestedCategory)) {
            api_response(false, 'ZNEWS_FEED_CATEGORY_CURSOR_MISMATCH', 'This cursor belongs to another feed category.', [], 409);
        }
    }
    return $session;
}

function znews_feed_session_projection_map(array $session): array
{
    if (is_array($session['_projection_map'] ?? null)) {
        return (array)$session['_projection_map'];
    }

    $snapshotAt = max(0, (int)($session['snapshot_at'] ?? 0));
    $candidateWindow = max(1, min(
        znews_feed_candidate_limit(),
        (int)($session['candidate_window'] ?? $session['total'] ?? 1)
    ));
    $category = znews_normalize_category($session['category'] ?? '', true);
    $query = $category === ''
        ? [
            'orderBy' => json_encode('created_at'),
            'endAt' => json_encode($snapshotAt),
            'limitToLast' => $candidateWindow,
        ]
        : [
            'orderBy' => json_encode('category_created_at'),
            'startAt' => json_encode(znews_category_query_start($category)),
            'endAt' => json_encode(znews_category_query_end($category, $snapshotAt)),
            'limitToLast' => $candidateWindow,
        ];
    $index = fb_get('ZNEWS_PUBLIC_FEED', $query);
    if (!is_array($index)) {
        return [];
    }

    $allowed = array_fill_keys(znews_feed_session_order($session), true);
    $projectionMap = [];
    foreach ($index as $postKey => $row) {
        $row = is_array($row) ? $row : [];
        $postId = trim((string)($row['post_id'] ?? $postKey));
        if ($postId === '' || !isset($allowed[$postId])) {
            continue;
        }
        $publicItem = znews_public_projection_item($row);
        if ($publicItem !== null) {
            $projectionMap[$postId] = $publicItem;
        }
    }
    return $projectionMap;
}

function znews_fair_feed_page(int $limit, $cursorValue = '', string $category = ''): array
{
    $limit = max(1, min(30, $limit));
    $category = znews_normalize_category($category, true);
    $viewer = znews_feed_viewer_context();
    $cursor = znews_feed_cursor_decode($cursorValue);
    if ($cursor) {
        $session = znews_feed_load_session((string)$cursor['session_id'], $viewer, $category);
        $offset = (int)$cursor['offset'];
    } else {
        $session = znews_feed_create_session($viewer, $limit, $category);
        $offset = 0;
    }

    $order = znews_feed_session_order($session);
    $projectionMap = znews_feed_session_projection_map($session);
    $items = [];
    $nextOffset = $offset;

    while ($nextOffset < count($order) && count($items) < $limit) {
        $postIdRaw = $order[$nextOffset];
        $nextOffset++;
        $postId = znews_firebase_key($postIdRaw, 'post_id');
        if (!is_array($projectionMap[$postId] ?? null)) {
            continue;
        }
        $items[] = (array)$projectionMap[$postId];
    }

    $hasMore = $nextOffset < count($order);
    $nextCursor = $hasMore
        ? znews_feed_cursor_encode((string)$session['session_id'], $nextOffset, (int)$session['expires_at'])
        : '';

    return [
        'feed_session_id' => (string)$session['session_id'],
        'ranking_mode' => 'FRESH_FAIR_V1',
        'fresh_ratio' => 70,
        'fair_ratio' => 30,
        'category' => $category,
        'items' => $items,
        'next_cursor' => $nextCursor,
        'has_more' => $hasMore,
    ];
}

function znews_feed_claim_once(string $path, array $payload): bool
{
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return false;
        }
        if ($snapshot['value'] !== null) {
            return false;
        }
        $write = fb_put_if_match($path, $payload, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        if (empty($write['ok'])) {
            return false;
        }
        return true;
    }
    return false;
}

function znews_feed_increment_exposure(string $postId, bool $unique, int $now): bool
{
    $path = znews_feed_exposure_path($postId);
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return false;
        }
        $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        $row['post_id'] = $postId;
        $row['impressions'] = max(0, (int)($row['impressions'] ?? 0)) + 1;
        $row['unique_viewers'] = max(0, (int)($row['unique_viewers'] ?? 0)) + ($unique ? 1 : 0);
        $row['last_shown_at'] = $now;
        $row['updated_at'] = $now;
        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        if (empty($write['ok'])) {
            return false;
        }
        znews_ranking_metrics_mirror_exposure($postId, $row);
        return true;
    }
    return false;
}

function znews_feed_record_impressions(string $sessionIdRaw, array $postIdsRaw): array
{
    $viewer = znews_feed_viewer_context();
    $sessionId = znews_firebase_key($sessionIdRaw, 'feed_session_id');
    if (preg_match('/^ZFS[A-F0-9]{32}$/', $sessionId) !== 1) {
        api_response(false, 'ZNEWS_FEED_SESSION_INVALID', 'Invalid feed session.', [], 422);
    }
    $session = znews_feed_load_session($sessionId, $viewer);
    $allowed = array_fill_keys(znews_feed_session_order($session), true);
    $postIds = [];
    foreach ($postIdsRaw as $value) {
        $postId = trim((string)$value);
        if ($postId === '' || isset($postIds[$postId]) || !isset($allowed[$postId])) {
            continue;
        }
        $postIds[$postId] = true;
        if (count($postIds) >= znews_feed_impression_batch_max()) {
            break;
        }
    }

    $now = znews_now();
    $recorded = [];
    foreach (array_keys($postIds) as $postId) {
        $sessionClaim = znews_feed_claim_once(
            znews_feed_session_impression_path($sessionId, $postId),
            ['post_id' => $postId, 'session_id' => $sessionId, 'created_at' => $now]
        );
        if (!$sessionClaim) {
            continue;
        }
        $unique = znews_feed_claim_once(
            znews_feed_unique_path($postId, (string)$viewer['unique_hash']),
            ['post_id' => $postId, 'viewer_hash' => (string)$viewer['unique_hash'], 'created_at' => $now]
        );
        if (znews_feed_increment_exposure($postId, $unique, $now)) {
            $recorded[] = $postId;
        }
    }

    return [
        'recorded_post_ids' => $recorded,
        'recorded_count' => count($recorded),
    ];
}
