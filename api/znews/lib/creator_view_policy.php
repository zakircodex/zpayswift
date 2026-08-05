<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_guest_view_window_seconds(): int
{
    $value = defined('ZNEWS_GUEST_VIEW_WINDOW_SECONDS')
        ? (int)constant('ZNEWS_GUEST_VIEW_WINDOW_SECONDS')
        : 300;
    return max(180, min(600, $value));
}

function znews_guest_view_window_limit(): int
{
    $value = defined('ZNEWS_GUEST_VIEW_WINDOW_LIMIT')
        ? (int)constant('ZNEWS_GUEST_VIEW_WINDOW_LIMIT')
        : 3;
    return max(1, min(10, $value));
}

function znews_guest_view_window_path(string $fingerprint): string
{
    return 'ZNEWS_GUEST_VIEW_WINDOWS/' . znews_firebase_key($fingerprint, 'fingerprint');
}

function znews_guest_view_window_claim(string $fingerprint, int $now): array
{
    $fingerprint = znews_firebase_key($fingerprint, 'fingerprint');
    $window = znews_guest_view_window_seconds();
    $limit = znews_guest_view_window_limit();
    $cutoff = $now - $window;
    $path = znews_guest_view_window_path($fingerprint);

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $read = fb_get_with_etag($path);
        if (empty($read['ok']) || !is_string($read['etag'] ?? null)) {
            return [
                'ok' => false,
                'allowed' => false,
                'spam' => true,
                'count' => 0,
                'limit' => $limit,
                'window_seconds' => $window,
                'reason' => 'GUEST_VIEW_WINDOW_UNAVAILABLE',
            ];
        }

        $row = is_array($read['value'] ?? null) ? (array)$read['value'] : [];
        $timestamps = [];
        foreach ((array)($row['timestamps'] ?? []) as $timestamp) {
            $timestamp = (int)$timestamp;
            if ($timestamp > $cutoff && $timestamp <= $now) {
                $timestamps[] = $timestamp;
            }
        }
        $timestamps[] = $now;
        sort($timestamps, SORT_NUMERIC);
        if (count($timestamps) > 20) {
            $timestamps = array_slice($timestamps, -20);
        }

        $count = count($timestamps);
        $allowed = $count <= $limit;
        $nextAllowedAt = !$allowed && isset($timestamps[0])
            ? ((int)$timestamps[0] + $window)
            : 0;
        $next = [
            'fingerprint_hash' => $fingerprint,
            'timestamps' => array_values($timestamps),
            'count' => $count,
            'limit' => $limit,
            'window_seconds' => $window,
            'spam_count' => max(0, (int)($row['spam_count'] ?? 0)) + ($allowed ? 0 : 1),
            'last_seen_at' => $now,
            'next_allowed_at' => $nextAllowedAt,
            'expires_at' => $now + $window,
            'updated_at' => $now,
        ];

        $write = fb_put_if_match($path, $next, (string)$read['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            usleep(50000);
            continue;
        }
        if (empty($write['ok'])) {
            return [
                'ok' => false,
                'allowed' => false,
                'spam' => true,
                'count' => $count,
                'limit' => $limit,
                'window_seconds' => $window,
                'reason' => 'GUEST_VIEW_WINDOW_WRITE_FAILED',
            ];
        }

        return [
            'ok' => true,
            'allowed' => $allowed,
            'spam' => !$allowed,
            'count' => $count,
            'limit' => $limit,
            'window_seconds' => $window,
            'next_allowed_at' => $nextAllowedAt,
            'reason' => $allowed ? '' : 'GUEST_VIEW_WINDOW_LIMIT_EXCEEDED',
        ];
    }

    return [
        'ok' => false,
        'allowed' => false,
        'spam' => true,
        'count' => 0,
        'limit' => $limit,
        'window_seconds' => $window,
        'reason' => 'GUEST_VIEW_WINDOW_BUSY',
    ];
}

function znews_creator_view_gate(string $viewerUid): array
{
    $viewerUid = trim($viewerUid);
    if ($viewerUid !== '') {
        return [
            'viewer_class' => 'CREATOR',
            'ad_eligible' => false,
            'revenue_share_eligible' => false,
            'spam' => false,
            'count' => 0,
            'limit' => znews_guest_view_window_limit(),
            'window_seconds' => znews_guest_view_window_seconds(),
            'reason' => 'AUTHENTICATED_CREATOR_NO_ADS',
        ];
    }

    $context = znews_view_context();
    $claim = znews_guest_view_window_claim((string)$context['fingerprint'], znews_now());
    $allowed = !empty($claim['ok']) && !empty($claim['allowed']);

    return array_merge($claim, [
        'viewer_class' => 'GUEST',
        'ad_eligible' => $allowed,
        'revenue_share_eligible' => $allowed,
        'spam' => !$allowed,
    ]);
}

function znews_creator_view_policy_apply(array $result, array $gate): array
{
    $session = is_array($result['session'] ?? null) ? (array)$result['session'] : [];
    $viewId = trim((string)($session['view_id'] ?? ''));
    $postId = trim((string)($session['post_id'] ?? ''));
    $policy = [
        'viewer_class' => (string)($gate['viewer_class'] ?? 'GUEST'),
        'ad_eligible' => !empty($gate['ad_eligible']),
        'revenue_share_eligible' => !empty($gate['revenue_share_eligible']),
        'guest_window_count' => max(0, (int)($gate['count'] ?? 0)),
        'guest_window_limit' => max(1, (int)($gate['limit'] ?? znews_guest_view_window_limit())),
        'guest_window_seconds' => max(1, (int)($gate['window_seconds'] ?? znews_guest_view_window_seconds())),
        'guest_spam' => !empty($gate['spam']),
        'ad_block_reason' => trim((string)($gate['reason'] ?? '')),
        'next_ad_allowed_at' => max(0, (int)($gate['next_allowed_at'] ?? 0)),
    ];

    if ($viewId !== '') {
        @fb_patch(znews_view_path($viewId), array_merge($policy, [
            'updated_at' => znews_now(),
        ]));
    }

    $result['session'] = array_merge($session, $policy);

    if (empty($gate['spam']) || $viewId === '' || $postId === '') {
        return $result;
    }

    $now = znews_now();
    @fb_patch(znews_view_path($viewId), [
        'status' => 'BLOCKED',
        'result' => 'INVALID',
        'risk_score' => 100,
        'risk_reasons' => ['GUEST_VIEW_WINDOW_LIMIT_EXCEEDED'],
        'ad_eligible' => false,
        'revenue_share_eligible' => false,
        'guest_spam' => true,
        'completed_at' => $now,
        'updated_at' => $now,
    ]);

    if (function_exists('znews_view_analytics_apply_once')) {
        znews_view_analytics_apply_once($postId, 'GUEST_SPAM|' . $viewId, [
            'invalid_views' => 1,
            'suspicious_views' => 1,
        ]);
    }
    @fb_put(znews_view_post_path($postId, $viewId), [
        'view_id' => $viewId,
        'post_id' => $postId,
        'status' => 'BLOCKED',
        'result' => 'INVALID',
        'created_at' => max(0, (int)($session['created_at'] ?? $now)),
        'completed_at' => $now,
        'updated_at' => $now,
    ]);

    $riskRow = fb_get(znews_view_path($viewId));
    if (is_array($riskRow) && function_exists('znews_view_risk_store')) {
        znews_view_risk_store($riskRow);
    }

    $result['ok'] = false;
    $result['code'] = 'ZNEWS_GUEST_VIEW_SPAM_BLOCKED';
    $result['message'] = 'Repeated guest views were marked invalid. Ads are temporarily disabled for this visitor.';
    $result['http_status'] = 429;
    $result['session'] = array_merge($result['session'], [
        'status' => 'BLOCKED',
        'result' => 'INVALID',
        'ad_eligible' => false,
        'revenue_share_eligible' => false,
    ]);

    return $result;
}
