<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/views.php';

function znews_view_analytics_apply_once(
    string $postId,
    string $eventId,
    array $deltas
): array {
    $postId = znews_firebase_key($postId, 'post_id');
    $eventKey = hash('sha256', $eventId);
    $allowed = [
        'total_opens',
        'valid_views',
        'invalid_views',
        'suspicious_views',
        'completed_reads',
        'unique_viewers',
        'total_read_seconds',
    ];
    foreach ($deltas as $field => $delta) {
        if (!in_array((string)$field, $allowed, true) || !is_int($delta)) {
            return ['ok' => false, 'code' => 'ZNEWS_ANALYTICS_DELTA_INVALID'];
        }
    }

    $path = znews_view_analytics_path($postId);
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'code' => 'ZNEWS_ANALYTICS_READ_FAILED'];
        }
        $row = is_array($snapshot['value'] ?? null)
            ? (array)$snapshot['value']
            : znews_view_analytics_defaults($postId);
        $events = is_array($row['applied_events'] ?? null)
            ? (array)$row['applied_events']
            : [];
        if (isset($events[$eventKey])) {
            return [
                'ok' => true,
                'idempotent_replay' => true,
                'analytics' => znews_view_analytics_format($row),
            ];
        }

        foreach ($allowed as $field) {
            $row[$field] = max(0, (int)($row[$field] ?? 0));
        }
        foreach ($deltas as $field => $delta) {
            $row[$field] = max(0, (int)$row[$field] + $delta);
        }
        $events[$eventKey] = znews_now();
        $row['applied_events'] = $events;
        $row['post_id'] = $postId;
        $row['updated_at'] = znews_now();

        $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(60000);
            continue;
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_ANALYTICS_WRITE_FAILED'];
        }
        return [
            'ok' => true,
            'idempotent_replay' => false,
            'analytics' => znews_view_analytics_format($row),
        ];
    }

    return ['ok' => false, 'code' => 'ZNEWS_ANALYTICS_BUSY'];
}

function znews_view_open_sync(array $row): array
{
    $viewId = znews_firebase_key((string)($row['view_id'] ?? ''), 'view_id');
    $postId = znews_firebase_key((string)($row['post_id'] ?? ''), 'post_id');
    $blocked = strtoupper(trim((string)($row['status'] ?? ''))) === 'BLOCKED';
    $deltas = ['total_opens' => 1];
    if ($blocked) {
        $deltas['invalid_views'] = 1;
        $deltas['suspicious_views'] = 1;
    }

    $analytics = znews_view_analytics_apply_once(
        $postId,
        'OPEN|' . $viewId,
        $deltas
    );
    $indexOk = fb_put(znews_view_post_path($postId, $viewId), [
        'view_id' => $viewId,
        'post_id' => $postId,
        'status' => strtoupper(trim((string)($row['status'] ?? 'STARTED'))),
        'result' => strtoupper(trim((string)($row['result'] ?? 'PENDING'))),
        'created_at' => (int)($row['created_at'] ?? znews_now()),
        'updated_at' => znews_now(),
    ]);

    return [
        'ok' => !empty($analytics['ok']) && $indexOk,
        'analytics' => is_array($analytics['analytics'] ?? null)
            ? (array)$analytics['analytics']
            : [],
    ];
}

function znews_view_start_v2(string $postId, string $idempotencyKey, string $viewerUid = ''): array
{
    $postId = znews_firebase_key($postId, 'post_id');
    $post = znews_view_require_public_post($postId);
    $viewerUid = trim($viewerUid);
    if ($viewerUid !== '') {
        $viewerUid = znews_firebase_key($viewerUid, 'viewer_uid');
    }
    $creatorUid = trim((string)($post['creator_uid'] ?? ''));
    $selfView = $viewerUid !== '' && $creatorUid !== '' && hash_equals($creatorUid, $viewerUid);
    $idempotencyKey = znews_idempotency_key($idempotencyKey);
    $ctx = znews_view_context();
    $fingerprint = (string)$ctx['fingerprint'];
    $viewId = 'ZNV' . strtoupper(substr(hash(
        'sha256',
        $fingerprint . '|' . $postId . '|' . $idempotencyKey
    ), 0, 29));
    $path = znews_view_path($viewId);
    $snapshot = fb_get_with_etag($path);
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        return ['ok' => false, 'code' => 'ZNEWS_VIEW_START_READ_FAILED', 'message' => 'View session could not be started.', 'http_status' => 503];
    }

    $existing = $snapshot['value'] ?? null;
    if (is_array($existing)) {
        $saved = trim((string)($existing['fingerprint_hash'] ?? ''));
        if ($saved === '' || !hash_equals($saved, $fingerprint)) {
            return ['ok' => false, 'code' => 'ZNEWS_VIEW_IDEMPOTENCY_CONFLICT', 'message' => 'View request conflicts with an existing session.', 'http_status' => 409];
        }
        $sync = znews_view_open_sync($existing);
        if (!empty($sync['ok'])) {
            @fb_patch($path, [
                'reconciliation_required' => false,
                'reconciliation_code' => null,
                'updated_at' => znews_now(),
            ]);
        }
        $blocked = strtoupper(trim((string)($existing['status'] ?? ''))) === 'BLOCKED';
        $token = znews_view_token($viewId, $fingerprint, (int)($existing['created_at'] ?? 0));
        return [
            'ok' => !$blocked && !empty($sync['ok']),
            'code' => $blocked
                ? 'ZNEWS_VIEW_BLOCKED'
                : (!empty($sync['ok']) ? 'ZNEWS_VIEW_ALREADY_STARTED' : 'ZNEWS_VIEW_RECONCILIATION_REQUIRED'),
            'message' => $blocked
                ? 'View session was blocked.'
                : (!empty($sync['ok']) ? 'View session was already started.' : 'View started but analytics require reconciliation.'),
            'http_status' => $blocked ? 429 : (!empty($sync['ok']) ? 200 : 503),
            'idempotent_replay' => true,
            'session' => znews_view_public_session($existing, $blocked ? '' : $token),
            'reconciliation_required' => empty($sync['ok']),
        ];
    }
    if ($existing !== null) {
        return ['ok' => false, 'code' => 'ZNEWS_VIEW_INVALID_RECORD', 'message' => 'View session could not be started.', 'http_status' => 409];
    }

    $now = znews_now();
    $rate = znews_view_rate_check($fingerprint, $now);
    $dedup = znews_view_dedup_claim($fingerprint, $postId, $viewId, $now);
    $duplicate = empty($dedup['ok']) || !empty($dedup['duplicate']);
    $reasons = [];
    $risk = 0;
    if (!empty($ctx['bot'])) { $risk = 100; $reasons[] = 'BOT_USER_AGENT'; }
    if (!empty($ctx['ua_missing'])) { $risk += 20; $reasons[] = 'USER_AGENT_MISSING'; }
    if (!empty($ctx['new_cookie'])) { $risk += 5; $reasons[] = 'NEW_VISITOR_COOKIE'; }
    if ($duplicate) { $risk += 40; $reasons[] = 'DEDUPLICATE_WINDOW_MATCH'; }
    if (empty($dedup['ok'])) { $risk += 30; $reasons[] = 'DEDUP_CHECK_UNAVAILABLE'; }
    if (empty($rate['minute_allowed'])) { $risk += 80; $reasons[] = 'MINUTE_RATE_EXCEEDED'; }
    if (empty($rate['hour_allowed'])) { $risk += 80; $reasons[] = 'HOURLY_RATE_EXCEEDED'; }
    $risk = min(100, $risk);
    $blocked = !empty($ctx['bot'])
        || empty($rate['minute_allowed'])
        || empty($rate['hour_allowed']);
    $token = znews_view_token($viewId, $fingerprint, $now);

    $row = [
        'schema_version' => 2,
        'view_id' => $viewId,
        'post_id' => $postId,
        'status' => $blocked ? 'BLOCKED' : 'STARTED',
        'result' => $blocked ? 'INVALID' : 'PENDING',
        'viewer_type' => (string)$ctx['viewer_type'],
        'viewer_uid' => $viewerUid,
        'self_view' => $selfView,
        'fingerprint_hash' => $fingerprint,
        'session_hash' => (string)$ctx['session_hash'],
        'visitor_hash' => (string)$ctx['visitor_hash'],
        'ip_hash' => (string)$ctx['ip_hash'],
        'ua_hash' => (string)$ctx['ua_hash'],
        'token_hash' => hash('sha256', $token),
        'duplicate' => $duplicate,
        'duplicate_of_view_id' => trim((string)($dedup['existing_view_id'] ?? '')),
        'bot_detected' => !empty($ctx['bot']),
        'risk_score' => $risk,
        'risk_reasons' => array_values(array_unique($reasons)),
        'heartbeat_count' => 0,
        'active_seconds' => 0,
        'started_at' => $now,
        'last_heartbeat_at' => $now,
        'completed_at' => 0,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $now + znews_view_session_max(),
        'reconciliation_required' => false,
        'earning_eligible' => false,
        'source' => 'ZNEWS_WEB',
    ];

    $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
    if ((int)($write['status'] ?? 0) === 412) {
        return ['ok' => false, 'code' => 'ZNEWS_VIEW_START_CONFLICT', 'message' => 'View session was started by another request.', 'http_status' => 409];
    }
    if (empty($write['ok'])) {
        return ['ok' => false, 'code' => 'ZNEWS_VIEW_START_FAILED', 'message' => 'View session could not be started.', 'http_status' => 503];
    }

    $sync = znews_view_open_sync($row);
    if (empty($sync['ok'])) {
        @fb_patch($path, [
            'reconciliation_required' => true,
            'reconciliation_code' => 'START_INDEX_SYNC',
            'updated_at' => znews_now(),
        ]);
    }
    if ($blocked || $duplicate || $risk >= znews_view_risk_threshold()) {
        znews_view_risk_store($row);
    }
    if ($blocked) {
        return ['ok' => false, 'code' => 'ZNEWS_VIEW_BLOCKED', 'message' => 'View session was blocked.', 'http_status' => 429, 'session' => znews_view_public_session($row), 'reconciliation_required' => empty($sync['ok'])];
    }
    return [
        'ok' => !empty($sync['ok']),
        'code' => !empty($sync['ok']) ? 'ZNEWS_VIEW_STARTED' : 'ZNEWS_VIEW_RECONCILIATION_REQUIRED',
        'message' => !empty($sync['ok']) ? 'View session started.' : 'View started but analytics require reconciliation.',
        'http_status' => !empty($sync['ok']) ? 201 : 503,
        'idempotent_replay' => false,
        'session' => znews_view_public_session($row, $token),
        'reconciliation_required' => empty($sync['ok']),
    ];
}

function znews_view_unique_owner(string $postId, string $fingerprint, string $viewId): array
{
    $path = znews_view_unique_path($postId, $fingerprint);
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $snapshot = fb_get_with_etag($path);
        if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
            return ['ok' => false, 'first_view' => false];
        }
        $existing = $snapshot['value'] ?? null;
        if (is_array($existing)) {
            $owner = trim((string)($existing['view_id'] ?? ''));
            return [
                'ok' => $owner !== '',
                'first_view' => $owner !== '' && hash_equals($owner, $viewId),
            ];
        }
        if ($existing !== null) {
            return ['ok' => false, 'first_view' => false];
        }
        $write = fb_put_if_match($path, [
            'view_id' => $viewId,
            'created_at' => znews_now(),
        ], (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        return [
            'ok' => !empty($write['ok']),
            'first_view' => !empty($write['ok']),
        ];
    }
    return ['ok' => false, 'first_view' => false];
}

function znews_view_complete_sync(array $row): array
{
    $viewId = znews_firebase_key((string)($row['view_id'] ?? ''), 'view_id');
    $postId = znews_firebase_key((string)($row['post_id'] ?? ''), 'post_id');
    $valid = strtoupper(trim((string)($row['result'] ?? ''))) === 'VALID';
    $active = max(0, (int)($row['active_seconds'] ?? 0));
    $deltas = $valid
        ? [
            'valid_views' => 1,
            'completed_reads' => 1,
            'total_read_seconds' => min($active, znews_view_session_max()),
        ]
        : [
            'invalid_views' => 1,
            'suspicious_views' => strtoupper(trim((string)($row['result'] ?? ''))) === 'SUSPICIOUS' ? 1 : 0,
        ];

    if ($valid) {
        $unique = znews_view_unique_owner(
            $postId,
            znews_firebase_key((string)($row['fingerprint_hash'] ?? ''), 'fingerprint'),
            $viewId
        );
        if (empty($unique['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_UNIQUE_VIEW_SYNC_FAILED'];
        }
        if (!empty($unique['first_view'])) {
            $deltas['unique_viewers'] = 1;
        }
    }

    $analytics = znews_view_analytics_apply_once(
        $postId,
        'COMPLETE|' . $viewId,
        $deltas
    );
    if (empty($analytics['ok'])) {
        return ['ok' => false, 'code' => (string)($analytics['code'] ?? 'ZNEWS_ANALYTICS_SYNC_FAILED')];
    }

    $indexOk = fb_put(znews_view_post_path($postId, $viewId), [
        'view_id' => $viewId,
        'post_id' => $postId,
        'status' => 'COMPLETED',
        'result' => strtoupper(trim((string)($row['result'] ?? 'INVALID'))),
        'active_seconds' => $active,
        'created_at' => (int)($row['created_at'] ?? znews_now()),
        'completed_at' => (int)($row['completed_at'] ?? znews_now()),
        'updated_at' => znews_now(),
    ]);
    return [
        'ok' => $indexOk,
        'code' => $indexOk ? 'OK' : 'ZNEWS_VIEW_INDEX_SYNC_FAILED',
        'analytics' => (array)$analytics['analytics'],
    ];
}

function znews_view_complete_v2(string $viewId, string $token): array
{
    $loaded = znews_view_load($viewId);
    $row = (array)$loaded['row'];
    if (!znews_view_token_valid($row, $token)) {
        return ['ok' => false, 'code' => 'ZNEWS_VIEW_TOKEN_INVALID', 'message' => 'Invalid view session token.', 'http_status' => 403];
    }

    if (strtoupper(trim((string)($row['status'] ?? ''))) === 'COMPLETED') {
        $sync = znews_view_complete_sync($row);
        if (!empty($sync['ok'])) {
            @fb_patch(znews_view_path($viewId), [
                'reconciliation_required' => false,
                'reconciliation_code' => null,
                'updated_at' => znews_now(),
            ]);
        }
        $result = strtoupper(trim((string)($row['result'] ?? 'INVALID')));
        return [
            'ok' => !empty($sync['ok']),
            'code' => !empty($sync['ok']) ? 'ZNEWS_VIEW_ALREADY_COMPLETED' : 'ZNEWS_VIEW_RECONCILIATION_REQUIRED',
            'message' => !empty($sync['ok']) ? 'View session was already completed.' : 'View completed but analytics require reconciliation.',
            'http_status' => !empty($sync['ok']) ? 200 : 503,
            'idempotent_replay' => true,
            'result' => $result,
            'valid_view' => $result === 'VALID',
            'earning_eligible' => false,
            'analytics' => is_array($sync['analytics'] ?? null) ? (array)$sync['analytics'] : [],
            'reconciliation_required' => empty($sync['ok']),
        ];
    }

    $now = znews_now();
    $elapsed = max(0, $now - (int)($row['started_at'] ?? $row['created_at'] ?? $now));
    $active = max(0, (int)($row['active_seconds'] ?? 0));
    $beats = max(0, (int)($row['heartbeat_count'] ?? 0));
    $risk = max(0, min(100, (int)($row['risk_score'] ?? 0)));
    $reasons = (array)($row['risk_reasons'] ?? []);
    if ($elapsed < znews_view_min_read()) { $risk += 20; $reasons[] = 'READ_TIME_TOO_SHORT'; }
    if ($active < znews_view_min_active()) { $risk += 20; $reasons[] = 'ACTIVE_TIME_TOO_SHORT'; }
    if ($beats < znews_view_min_heartbeats()) { $risk += 25; $reasons[] = 'HEARTBEAT_REQUIRED'; }
    if (!empty($row['duplicate'])) { $reasons[] = 'DUPLICATE_VIEW'; }
    if (!empty($row['bot_detected'])) { $risk = 100; $reasons[] = 'BOT_DETECTED'; }
    if (strtoupper(trim((string)($row['status'] ?? ''))) === 'BLOCKED') { $risk = max(90, $risk); $reasons[] = 'SESSION_BLOCKED'; }
    $risk = min(100, $risk);

    $valid = strtoupper(trim((string)($row['status'] ?? ''))) !== 'BLOCKED'
        && empty($row['duplicate'])
        && empty($row['bot_detected'])
        && $elapsed >= znews_view_min_read()
        && $active >= znews_view_min_active()
        && $beats >= znews_view_min_heartbeats()
        && $risk < znews_view_risk_threshold();
    $result = $valid
        ? 'VALID'
        : ($risk >= znews_view_risk_threshold() ? 'SUSPICIOUS' : 'INVALID');

    $row['status'] = 'COMPLETED';
    $row['result'] = $result;
    $row['risk_score'] = $risk;
    $row['risk_reasons'] = array_values(array_unique(array_map('strval', $reasons)));
    $row['elapsed_seconds'] = $elapsed;
    $row['completed_at'] = $now;
    $row['updated_at'] = $now;
    $row['earning_eligible'] = false;
    $row['reconciliation_required'] = true;
    $row['reconciliation_code'] = 'COMPLETE_INDEX_SYNC';

    $write = fb_put_if_match(
        znews_view_path($viewId),
        $row,
        (string)$loaded['etag']
    );
    if ((int)($write['status'] ?? 0) === 412) {
        return ['ok' => false, 'code' => 'ZNEWS_VIEW_COMPLETE_CONFLICT', 'message' => 'View session changed. Retry completion.', 'http_status' => 409];
    }
    if (empty($write['ok'])) {
        return ['ok' => false, 'code' => 'ZNEWS_VIEW_COMPLETE_FAILED', 'message' => 'View session could not be completed.', 'http_status' => 503];
    }

    $sync = znews_view_complete_sync($row);
    if (!empty($sync['ok'])) {
        @fb_patch(znews_view_path($viewId), [
            'reconciliation_required' => false,
            'reconciliation_code' => null,
            'updated_at' => znews_now(),
        ]);
    }
    if (!$valid) {
        znews_view_risk_store($row);
    }

    return [
        'ok' => !empty($sync['ok']),
        'code' => !empty($sync['ok']) ? 'ZNEWS_VIEW_COMPLETED' : 'ZNEWS_VIEW_RECONCILIATION_REQUIRED',
        'message' => !empty($sync['ok']) ? 'View session completed.' : 'View completed but analytics require reconciliation.',
        'http_status' => !empty($sync['ok']) ? 200 : 503,
        'result' => $result,
        'valid_view' => $valid,
        'earning_eligible' => false,
        'active_seconds' => $active,
        'elapsed_seconds' => $elapsed,
        'analytics' => is_array($sync['analytics'] ?? null) ? (array)$sync['analytics'] : [],
        'reconciliation_required' => empty($sync['ok']),
    ];
}
