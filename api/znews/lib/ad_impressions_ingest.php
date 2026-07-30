<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/ad_impressions_signature.php';
require_once __DIR__ . '/ad_impressions_analytics.php';

function znews_ad_ingest(): array
{
    $signed = znews_ad_signed_request();
    $payload = znews_ad_normalize_payload((array)$signed['body'], (string)$signed['network']);
    $network = (string)$payload['network'];
    $eventId = (string)$payload['event_id'];
    $impressionId = znews_ad_impression_id($network, $eventId);

    $claim = znews_ad_event_claim(
        $network,
        $eventId,
        $impressionId,
        (string)$signed['payload_hash']
    );
    if (empty($claim['ok'])) {
        return [
            'ok' => false,
            'code' => (string)($claim['code'] ?? 'ZNEWS_AD_EVENT_CLAIM_FAILED'),
            'message' => 'Ad impression event could not be claimed.',
            'http_status' => (int)($claim['http_status'] ?? 503),
        ];
    }
    if (!empty($claim['idempotent_replay'])) {
        $result = is_array($claim['result'] ?? null) ? (array)$claim['result'] : [];
        $saved = fb_get(znews_ad_impression_path($impressionId));
        $recheck = null;
        if (is_array($saved)
            && (in_array(strtoupper(trim((string)($saved['status'] ?? ''))), ['PENDING_VIEW', 'REVIEW'], true)
                || !empty($saved['reconciliation_required']))) {
            $recheck = znews_ad_recheck_impression($impressionId, null);
            if (!empty($recheck['ok'])) {
                $result = [
                    'impression' => (array)($recheck['impression'] ?? []),
                    'reconciliation_required' => false,
                ];
                @fb_patch((string)($claim['path'] ?? ''), [
                    'result' => $result,
                    'updated_at' => znews_now(),
                ]);
            }
        }
        $reconcile = is_array($recheck) && empty($recheck['ok']);
        return [
            'ok' => !$reconcile,
            'code' => $reconcile
                ? 'ZNEWS_AD_IMPRESSION_RECONCILIATION_REQUIRED'
                : 'ZNEWS_AD_IMPRESSION_ALREADY_INGESTED',
            'message' => $reconcile
                ? 'Ad impression exists but still requires reconciliation.'
                : 'Ad impression was already ingested.',
            'http_status' => $reconcile ? 503 : 200,
            'idempotent_replay' => true,
            'impression' => is_array($result['impression'] ?? null)
                ? (array)$result['impression']
                : [],
            'reconciliation_required' => $reconcile,
        ];
    }

    $nonce = znews_ad_nonce_claim(
        $network,
        (string)$signed['nonce'],
        $impressionId,
        (string)$signed['payload_hash']
    );
    if (empty($nonce['ok'])) {
        znews_ad_event_fail($claim, (string)($nonce['code'] ?? 'ZNEWS_AD_NONCE_FAILED'));
        return [
            'ok' => false,
            'code' => (string)($nonce['code'] ?? 'ZNEWS_AD_NONCE_FAILED'),
            'message' => 'Ad impression nonce could not be accepted.',
            'http_status' => str_contains((string)($nonce['code'] ?? ''), 'REPLAY') ? 409 : 503,
        ];
    }

    $evaluation = znews_ad_require_view_and_post($payload);
    $status = strtoupper(trim((string)$evaluation['status']));
    $risk = max(0, min(100, (int)$evaluation['risk_score']));
    $reasons = array_values(array_unique(array_map('strval', (array)$evaluation['reasons'])));
    $post = is_array($evaluation['post'] ?? null) ? (array)$evaluation['post'] : [];
    $creatorUid = trim((string)($post['creator_uid'] ?? ''));
    if ($creatorUid === '') {
        $status = 'REVIEW';
        $risk = max(70, $risk);
        $reasons[] = 'CREATOR_UID_MISSING';
    }

    if ($status === 'VERIFIED') {
        $slot = znews_ad_claim_slot(
            (string)$payload['view_id'],
            $network,
            (string)$payload['ad_unit_id'],
            $impressionId
        );
        if (empty($slot['ok'])) {
            $status = 'REVIEW';
            $risk = max(70, $risk);
            $reasons[] = (string)($slot['code'] ?? 'AD_SLOT_CLAIM_FAILED');
        } elseif (!empty($slot['duplicate'])) {
            $status = 'REJECTED';
            $risk = max(90, $risk);
            $reasons[] = 'DUPLICATE_AD_SLOT';
        }
    }

    if ($status === 'VERIFIED') {
        $limit = znews_ad_apply_view_limit((string)$payload['view_id'], $impressionId);
        if (empty($limit['ok'])) {
            $status = 'REVIEW';
            $risk = max(70, $risk);
            $reasons[] = (string)($limit['code'] ?? 'AD_VIEW_LIMIT_CHECK_FAILED');
        } elseif (empty($limit['allowed'])) {
            $status = 'REJECTED';
            $risk = max(90, $risk);
            $reasons[] = 'AD_VIEW_LIMIT_REACHED';
        }
    }

    $now = znews_now();
    $verificationStatus = match ($status) {
        'VERIFIED' => 'VERIFIED',
        'PENDING_VIEW' => 'PENDING',
        'REVIEW' => 'REVIEW_REQUIRED',
        default => 'REJECTED',
    };
    $row = [
        'schema_version' => 1,
        'impression_id' => $impressionId,
        'event_type' => 'IMPRESSION',
        'network' => $network,
        'provider_event_hash' => hash('sha256', $eventId),
        'provider_reference' => (string)$payload['provider_reference'],
        'post_id' => (string)$payload['post_id'],
        'view_id' => (string)$payload['view_id'],
        'creator_uid' => $creatorUid,
        'ad_unit_id' => (string)$payload['ad_unit_id'],
        'currency' => (string)$payload['currency'],
        'reported_revenue_micros' => (int)$payload['revenue_micros'],
        'occurred_at' => (int)$payload['occurred_at'],
        'received_at' => $now,
        'status' => $status,
        'verification_status' => $verificationStatus,
        'risk_score' => min(100, $risk),
        'risk_reasons' => array_values(array_unique($reasons)),
        'signature_verified' => true,
        'signature_timestamp' => (int)$signed['timestamp'],
        'nonce_hash' => (string)$signed['nonce_hash'],
        'payload_hash' => (string)$signed['payload_hash'],
        'settlement_status' => 'NOT_SETTLED',
        'credit_status' => 'NOT_CREDITED',
        'earning_eligible' => false,
        'reconciliation_required' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $indexPayload = [
        'impression_id' => $impressionId,
        'post_id' => (string)$payload['post_id'],
        'view_id' => (string)$payload['view_id'],
        'network' => $network,
        'status' => $status,
        'currency' => (string)$payload['currency'],
        'reported_revenue_micros' => (int)$payload['revenue_micros'],
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $writes = [
        znews_ad_impression_path($impressionId) => $row,
        znews_ad_post_index_path((string)$payload['post_id'], $impressionId) => $indexPayload,
        znews_ad_view_index_path((string)$payload['view_id'], $impressionId) => $indexPayload,
    ];
    if (in_array($status, ['PENDING_VIEW', 'REVIEW'], true)) {
        $writes[znews_ad_review_queue_path($impressionId)] = [
            'impression_id' => $impressionId,
            'post_id' => (string)$payload['post_id'],
            'view_id' => (string)$payload['view_id'],
            'network' => $network,
            'status' => $status,
            'risk_score' => min(100, $risk),
            'risk_reasons' => array_values(array_unique($reasons)),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    $stored = fb_patch('', $writes);
    if (!$stored) {
        znews_ad_event_fail($claim, 'ZNEWS_AD_IMPRESSION_WRITE_FAILED');
        return [
            'ok' => false,
            'code' => 'ZNEWS_AD_IMPRESSION_WRITE_FAILED',
            'message' => 'Ad impression could not be stored.',
            'http_status' => 503,
        ];
    }

    $analytics = znews_ad_analytics_transition(
        (string)$payload['post_id'],
        $impressionId,
        $status,
        (string)$payload['currency'],
        (int)$payload['revenue_micros']
    );
    $reconcile = empty($analytics['ok']);
    if ($reconcile) {
        @fb_patch(znews_ad_impression_path($impressionId), [
            'reconciliation_required' => true,
            'reconciliation_code' => (string)($analytics['code'] ?? 'ZNEWS_AD_ANALYTICS_SYNC_FAILED'),
            'updated_at' => znews_now(),
        ]);
    }

    $public = znews_ad_public_result($row);
    $result = [
        'impression' => $public,
        'reconciliation_required' => $reconcile,
    ];
    if (!znews_ad_event_finish($claim, $result)) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_AD_EVENT_FINALIZE_FAILED',
            'message' => 'Ad impression was stored but the event could not be finalized.',
            'http_status' => 503,
            'impression' => $public,
            'reconciliation_required' => true,
        ];
    }

    if (function_exists('system_log')) {
        system_log('ZNEWS_AD_IMPRESSION_INGESTED', $impressionId, 'Z Sky 24 ad impression ingested', [
            'network' => $network,
            'post_id' => (string)$payload['post_id'],
            'view_id' => (string)$payload['view_id'],
            'status' => $status,
        ]);
    }

    return [
        'ok' => !$reconcile,
        'code' => $reconcile
            ? 'ZNEWS_AD_IMPRESSION_RECONCILIATION_REQUIRED'
            : 'ZNEWS_AD_IMPRESSION_INGESTED',
        'message' => $reconcile
            ? 'Ad impression was stored but analytics require reconciliation.'
            : 'Ad impression ingested.',
        'http_status' => $reconcile ? 503 : 201,
        'idempotent_replay' => false,
        'impression' => $public,
        'reconciliation_required' => $reconcile,
    ];
}
