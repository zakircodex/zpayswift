<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/views_v2.php';

function znews_ad_cfg_int(string $name, int $default, int $min, int $max): int
{
    $value = defined($name) ? (int)constant($name) : $default;
    return max($min, min($max, $value));
}

function znews_ad_signature_tolerance(): int
{
    return znews_ad_cfg_int('ZNEWS_AD_SIGNATURE_TOLERANCE_SECONDS', 300, 30, 1800);
}

function znews_ad_event_max_age(): int
{
    return znews_ad_cfg_int('ZNEWS_AD_EVENT_MAX_AGE_SECONDS', 86400, 300, 604800);
}

function znews_ad_max_per_view(): int
{
    return znews_ad_cfg_int('ZNEWS_AD_MAX_IMPRESSIONS_PER_VIEW', 3, 1, 20);
}

function znews_ad_event_lease(): int
{
    return znews_ad_cfg_int('ZNEWS_AD_EVENT_LEASE_SECONDS', 120, 30, 600);
}

function znews_ad_network_name($value): string
{
    $network = strtoupper(trim((string)$value));
    if ($network === '' || strlen($network) > 40 || preg_match('/^[A-Z0-9_]+$/', $network) !== 1) {
        api_response(false, 'ZNEWS_AD_NETWORK_INVALID', 'Invalid ad network.', [], 422);
    }
    return $network;
}

function znews_ad_network_secrets(): array
{
    $value = defined('ZNEWS_AD_NETWORK_SECRETS')
        ? constant('ZNEWS_AD_NETWORK_SECRETS')
        : [];

    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($value)) {
        $value = [];
    }

    if (defined('ZNEWS_AD_INGESTION_SECRET')) {
        $fallback = trim((string)constant('ZNEWS_AD_INGESTION_SECRET'));
        if ($fallback !== '' && !isset($value['DEFAULT'])) {
            $value['DEFAULT'] = $fallback;
        }
    }

    $result = [];
    foreach ($value as $network => $secret) {
        $network = strtoupper(trim((string)$network));
        $secret = trim((string)$secret);
        if ($network === ''
            || preg_match('/^[A-Z0-9_]{2,40}$/', $network) !== 1
            || strlen($secret) < 32) {
            continue;
        }
        $result[$network] = $secret;
    }
    return $result;
}

function znews_ad_network_secret(string $network): string
{
    $network = znews_ad_network_name($network);
    $secrets = znews_ad_network_secrets();
    $secret = trim((string)($secrets[$network] ?? ''));
    if ($secret === '') {
        api_response(false, 'ZNEWS_AD_NETWORK_NOT_CONFIGURED', 'Ad network is not configured.', [], 503);
    }
    return $secret;
}

function znews_ad_clean_value($value, string $field, int $maxLength = 160): string
{
    $clean = trim((string)$value);
    if ($clean === '' || strlen($clean) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $clean) === 1) {
        api_response(false, 'ZNEWS_AD_FIELD_INVALID', 'Invalid ' . $field . '.', [], 422);
    }
    return $clean;
}

function znews_ad_currency($value): string
{
    $currency = strtoupper(trim((string)$value));
    if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
        api_response(false, 'ZNEWS_AD_CURRENCY_INVALID', 'Invalid currency.', [], 422);
    }
    return $currency;
}

function znews_ad_revenue_micros($value): int
{
    $revenue = filter_var($value, FILTER_VALIDATE_INT);
    if ($revenue === false || $revenue < 0 || $revenue > 1000000000000) {
        api_response(false, 'ZNEWS_AD_REVENUE_INVALID', 'Invalid reported revenue.', [], 422);
    }
    return (int)$revenue;
}

function znews_ad_occurred_at($value): int
{
    $timestamp = filter_var($value, FILTER_VALIDATE_INT);
    if ($timestamp === false || $timestamp <= 0) {
        api_response(false, 'ZNEWS_AD_OCCURRED_AT_INVALID', 'Invalid occurred_at.', [], 422);
    }
    $now = znews_now();
    if ($timestamp > $now + znews_ad_signature_tolerance()
        || $timestamp < $now - znews_ad_event_max_age()) {
        api_response(false, 'ZNEWS_AD_EVENT_TIME_INVALID', 'Ad event timestamp is outside the allowed window.', [], 422);
    }
    return (int)$timestamp;
}

function znews_ad_normalize_payload(array $body, string $headerNetwork): array
{
    $eventType = strtoupper(trim((string)($body['event_type'] ?? 'IMPRESSION')));
    if ($eventType !== 'IMPRESSION') {
        api_response(false, 'ZNEWS_AD_EVENT_TYPE_INVALID', 'Only impression events are accepted.', [], 422);
    }

    $network = znews_ad_network_name($body['network'] ?? $headerNetwork);
    if (!hash_equals($headerNetwork, $network)) {
        api_response(false, 'ZNEWS_AD_NETWORK_MISMATCH', 'Ad network does not match the signed header.', [], 422);
    }

    $eventId = znews_ad_clean_value(
        $body['event_id'] ?? $body['network_event_id'] ?? '',
        'event_id',
        200
    );
    $viewId = znews_firebase_key($body['view_id'] ?? '', 'view_id');
    $postId = znews_firebase_key($body['post_id'] ?? '', 'post_id');
    $adUnitId = znews_ad_clean_value($body['ad_unit_id'] ?? '', 'ad_unit_id', 160);
    $currency = znews_ad_currency($body['currency'] ?? '');
    $revenueMicros = znews_ad_revenue_micros($body['revenue_micros'] ?? 0);
    $occurredAt = znews_ad_occurred_at($body['occurred_at'] ?? 0);
    $providerReference = trim((string)($body['provider_reference'] ?? ''));
    if (strlen($providerReference) > 160 || preg_match('/[\x00-\x1F\x7F]/', $providerReference) === 1) {
        api_response(false, 'ZNEWS_AD_PROVIDER_REFERENCE_INVALID', 'Invalid provider_reference.', [], 422);
    }

    return [
        'event_type' => 'IMPRESSION',
        'network' => $network,
        'event_id' => $eventId,
        'view_id' => $viewId,
        'post_id' => $postId,
        'ad_unit_id' => $adUnitId,
        'currency' => $currency,
        'revenue_micros' => $revenueMicros,
        'occurred_at' => $occurredAt,
        'provider_reference' => $providerReference,
    ];
}

function znews_ad_impression_id(string $network, string $eventId): string
{
    return 'ZAI' . strtoupper(substr(hash('sha256', $network . '|' . $eventId), 0, 29));
}

function znews_ad_impression_path(string $impressionId): string
{
    return 'ZNEWS_AD_IMPRESSIONS/' . znews_firebase_key($impressionId, 'impression_id');
}

function znews_ad_event_path(string $network, string $eventId): string
{
    return 'ZNEWS_AD_EVENTS/' . znews_ad_network_name($network) . '/' . hash('sha256', $eventId);
}

function znews_ad_nonce_path(string $network, string $nonce): string
{
    return 'ZNEWS_AD_NONCES/' . znews_ad_network_name($network) . '/' . hash('sha256', $nonce);
}

function znews_ad_post_index_path(string $postId, string $impressionId): string
{
    return 'ZNEWS_POST_AD_IMPRESSIONS/'
        . znews_firebase_key($postId, 'post_id')
        . '/'
        . znews_firebase_key($impressionId, 'impression_id');
}

function znews_ad_view_index_path(string $viewId, string $impressionId): string
{
    return 'ZNEWS_VIEW_AD_IMPRESSIONS/'
        . znews_firebase_key($viewId, 'view_id')
        . '/'
        . znews_firebase_key($impressionId, 'impression_id');
}

function znews_ad_review_queue_path(string $impressionId): string
{
    return 'ZNEWS_AD_REVIEW_QUEUE/' . znews_firebase_key($impressionId, 'impression_id');
}

function znews_ad_view_slot_path(string $viewId, string $network, string $adUnitId): string
{
    return 'ZNEWS_VIEW_AD_SLOTS/'
        . znews_firebase_key($viewId, 'view_id')
        . '/'
        . hash('sha256', $network . '|' . $adUnitId);
}

function znews_ad_view_count_path(string $viewId): string
{
    return 'ZNEWS_VIEW_AD_COUNTS/' . znews_firebase_key($viewId, 'view_id');
}

function znews_ad_analytics_path(string $postId): string
{
    return 'ZNEWS_AD_ANALYTICS/' . znews_firebase_key($postId, 'post_id');
}

function znews_ad_require_view_and_post(array $payload): array
{
    $viewId = znews_firebase_key((string)$payload['view_id'], 'view_id');
    $postId = znews_firebase_key((string)$payload['post_id'], 'post_id');
    $view = fb_get(znews_view_path($viewId));
    $post = fb_get(znews_path_post($postId));

    if (!is_array($view)) {
        return [
            'status' => 'REJECTED',
            'risk_score' => 100,
            'reasons' => ['VIEW_NOT_FOUND'],
            'view' => [],
            'post' => is_array($post) ? $post : [],
        ];
    }
    if (!is_array($post)) {
        return [
            'status' => 'REJECTED',
            'risk_score' => 100,
            'reasons' => ['POST_NOT_FOUND'],
            'view' => $view,
            'post' => [],
        ];
    }

    $reasons = [];
    $risk = max(0, min(100, (int)($view['risk_score'] ?? 0)));
    $status = strtoupper(trim((string)($view['status'] ?? '')));
    $result = strtoupper(trim((string)($view['result'] ?? '')));
    $viewPostId = trim((string)($view['post_id'] ?? ''));
    $viewerUid = trim((string)($view['viewer_uid'] ?? ''));
    $creatorUid = trim((string)($post['creator_uid'] ?? ''));
    $selfView = !empty($view['self_view'])
        || ($viewerUid !== '' && $creatorUid !== '' && hash_equals($creatorUid, $viewerUid));

    if ($viewPostId === '' || !hash_equals($viewPostId, $postId)) {
        $risk = 100;
        $reasons[] = 'VIEW_POST_MISMATCH';
    }
    if (strtoupper(trim((string)($post['status'] ?? ''))) !== 'ACTIVE'
        || strtoupper(trim((string)($post['visibility'] ?? ''))) !== 'PUBLIC'
        || (int)($post['deleted_at'] ?? 0) > 0) {
        $risk = max(90, $risk);
        $reasons[] = 'POST_NOT_PUBLIC';
    }
    if (!empty($view['duplicate'])) {
        $risk = max(90, $risk);
        $reasons[] = 'VIEW_DUPLICATE';
    }
    if (!empty($view['bot_detected'])) {
        $risk = 100;
        $reasons[] = 'VIEW_BOT_DETECTED';
    }
    if ($selfView) {
        $risk = 100;
        $reasons[] = 'SELF_VIEW';
        return [
            'status' => 'REJECTED',
            'risk_score' => 100,
            'reasons' => array_values(array_unique($reasons)),
            'self_view' => true,
            'view' => $view,
            'post' => $post,
        ];
    }

    $occurredAt = (int)$payload['occurred_at'];
    $startedAt = (int)($view['started_at'] ?? $view['created_at'] ?? 0);
    $completedAt = (int)($view['completed_at'] ?? 0);
    if ($startedAt <= 0
        || $occurredAt < $startedAt - 60
        || ($completedAt > 0 && $occurredAt > $completedAt + znews_ad_signature_tolerance())) {
        $risk = max(80, $risk);
        $reasons[] = 'IMPRESSION_OUTSIDE_VIEW_WINDOW';
    }

    if ($status !== 'COMPLETED') {
        $reasons[] = 'VIEW_NOT_COMPLETED';
        return [
            'status' => 'PENDING_VIEW',
            'risk_score' => min(100, $risk),
            'reasons' => array_values(array_unique($reasons)),
            'view' => $view,
            'post' => $post,
        ];
    }

    if ($result !== 'VALID'
        || $risk >= znews_view_risk_threshold()
        || in_array('VIEW_POST_MISMATCH', $reasons, true)
        || in_array('POST_NOT_PUBLIC', $reasons, true)
        || in_array('IMPRESSION_OUTSIDE_VIEW_WINDOW', $reasons, true)) {
        if ($result !== 'VALID') {
            $reasons[] = 'VIEW_NOT_VALID';
        }
        return [
            'status' => 'REJECTED',
            'risk_score' => min(100, max(70, $risk)),
            'reasons' => array_values(array_unique($reasons)),
            'view' => $view,
            'post' => $post,
        ];
    }

    return [
        'status' => 'VERIFIED',
        'risk_score' => min(100, $risk),
        'reasons' => array_values(array_unique($reasons)),
        'view' => $view,
        'post' => $post,
    ];
}

function znews_ad_claim_slot(
    string $viewId,
    string $network,
    string $adUnitId,
    string $impressionId
): array {
    $path = znews_ad_view_slot_path($viewId, $network, $adUnitId);
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'duplicate' => false, 'code' => 'ZNEWS_AD_SLOT_READ_FAILED'];
        }
        $existing = $snapshot['value'] ?? null;
        if (is_array($existing)) {
            $owner = trim((string)($existing['impression_id'] ?? ''));
            return [
                'ok' => $owner !== '',
                'duplicate' => $owner !== '' && !hash_equals($owner, $impressionId),
                'idempotent_replay' => $owner !== '' && hash_equals($owner, $impressionId),
            ];
        }
        if ($existing !== null) {
            return ['ok' => false, 'duplicate' => true, 'code' => 'ZNEWS_AD_SLOT_INVALID'];
        }
        $write = fb_put_if_match($path, [
            'impression_id' => $impressionId,
            'view_id' => $viewId,
            'network' => $network,
            'ad_unit_hash' => hash('sha256', $adUnitId),
            'created_at' => znews_now(),
        ], (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        return [
            'ok' => !empty($write['ok']),
            'duplicate' => false,
            'idempotent_replay' => false,
            'code' => !empty($write['ok']) ? 'OK' : 'ZNEWS_AD_SLOT_WRITE_FAILED',
        ];
    }
    return ['ok' => false, 'duplicate' => false, 'code' => 'ZNEWS_AD_SLOT_BUSY'];
}

function znews_ad_apply_view_limit(string $viewId, string $impressionId): array
{
    $path = znews_ad_view_count_path($viewId);
    $eventKey = hash('sha256', $impressionId);
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'allowed' => false, 'code' => 'ZNEWS_AD_VIEW_COUNT_READ_FAILED'];
        }
        $row = is_array($snapshot['value'] ?? null) ? (array)$snapshot['value'] : [];
        $applied = is_array($row['applied_events'] ?? null) ? (array)$row['applied_events'] : [];
        if (isset($applied[$eventKey])) {
            return [
                'ok' => true,
                'allowed' => true,
                'idempotent_replay' => true,
                'count' => max(0, (int)($row['verified_count'] ?? 0)),
            ];
        }
        $count = max(0, (int)($row['verified_count'] ?? 0));
        if ($count >= znews_ad_max_per_view()) {
            return [
                'ok' => true,
                'allowed' => false,
                'idempotent_replay' => false,
                'count' => $count,
                'code' => 'ZNEWS_AD_VIEW_LIMIT_REACHED',
            ];
        }
        $applied[$eventKey] = znews_now();
        $row['view_id'] = $viewId;
        $row['verified_count'] = $count + 1;
        $row['applied_events'] = $applied;
        $row['updated_at'] = znews_now();
        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(60000);
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'allowed' => false, 'code' => 'ZNEWS_AD_VIEW_COUNT_WRITE_FAILED'];
        }
        return [
            'ok' => true,
            'allowed' => true,
            'idempotent_replay' => false,
            'count' => $count + 1,
        ];
    }
    return ['ok' => false, 'allowed' => false, 'code' => 'ZNEWS_AD_VIEW_COUNT_BUSY'];
}

function znews_ad_public_result(array $row): array
{
    return [
        'impression_id' => trim((string)($row['impression_id'] ?? '')),
        'network' => strtoupper(trim((string)($row['network'] ?? ''))),
        'status' => strtoupper(trim((string)($row['status'] ?? 'REVIEW'))),
        'verification_status' => strtoupper(trim((string)($row['verification_status'] ?? 'PENDING'))),
        'settlement_status' => strtoupper(trim((string)($row['settlement_status'] ?? 'NOT_SETTLED'))),
        'earning_eligible' => false,
        'received_at' => max(0, (int)($row['received_at'] ?? 0)),
    ];
}

function znews_ad_admin_details(string $impressionId): array
{
    $impressionId = znews_firebase_key($impressionId, 'impression_id');
    $row = fb_get(znews_ad_impression_path($impressionId));
    if (!is_array($row)) {
        api_response(false, 'ZNEWS_AD_IMPRESSION_NOT_FOUND', 'Ad impression not found.', [], 404);
    }
    unset($row['payload_hash'], $row['nonce_hash'], $row['provider_event_hash']);
    return ['impression' => $row];
}

function znews_ad_queue_cursor_encode(int $createdAt, string $impressionId): string
{
    $json = json_encode([
        'created_at' => max(0, $createdAt),
        'impression_id' => $impressionId,
    ], JSON_UNESCAPED_SLASHES);
    return is_string($json) ? rtrim(strtr(base64_encode($json), '+/', '-_'), '=') : '';
}

function znews_ad_queue_cursor_decode($value): array
{
    $cursor = trim((string)$value);
    if ($cursor === '') {
        return [];
    }
    if (strlen($cursor) > 512 || preg_match('/[^A-Za-z0-9_-]/', $cursor) === 1) {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }
    $cursor .= str_repeat('=', (4 - strlen($cursor) % 4) % 4);
    $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
    $row = is_string($decoded) ? json_decode($decoded, true) : null;
    if (!is_array($row)) {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }
    $createdAt = filter_var($row['created_at'] ?? null, FILTER_VALIDATE_INT);
    $impressionId = trim((string)($row['impression_id'] ?? ''));
    if ($createdAt === false || $createdAt < 0 || $impressionId === '') {
        api_response(false, 'ZNEWS_INVALID_CURSOR', 'Invalid cursor.', [], 422);
    }
    return [
        'created_at' => (int)$createdAt,
        'impression_id' => znews_firebase_key($impressionId, 'impression_id'),
    ];
}

function znews_ad_review_queue(int $limit, array $cursor = []): array
{
    $rows = fb_get('ZNEWS_AD_REVIEW_QUEUE');
    $items = [];
    if (is_array($rows)) {
        foreach ($rows as $impressionId => $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['impression_id'] = (string)($row['impression_id'] ?? $impressionId);
            $items[] = $row;
        }
    }
    usort($items, static function (array $a, array $b): int {
        $time = ((int)($b['created_at'] ?? 0)) <=> ((int)($a['created_at'] ?? 0));
        return $time !== 0
            ? $time
            : strcmp((string)($b['impression_id'] ?? ''), (string)($a['impression_id'] ?? ''));
    });

    $result = [];
    foreach ($items as $item) {
        $time = (int)($item['created_at'] ?? 0);
        $id = (string)($item['impression_id'] ?? '');
        if ($cursor) {
            $after = $time < (int)$cursor['created_at']
                || ($time === (int)$cursor['created_at']
                    && strcmp($id, (string)$cursor['impression_id']) < 0);
            if (!$after) {
                continue;
            }
        }
        $result[] = $item;
        if (count($result) > $limit) {
            break;
        }
    }
    $hasMore = count($result) > $limit;
    if ($hasMore) {
        array_pop($result);
    }
    $next = '';
    if ($hasMore && $result) {
        $last = $result[count($result) - 1];
        $next = znews_ad_queue_cursor_encode(
            (int)($last['created_at'] ?? 0),
            (string)($last['impression_id'] ?? '')
        );
    }
    return ['items' => array_values($result), 'next_cursor' => $next, 'has_more' => $hasMore];
}
