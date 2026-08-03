<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/settlements_service.php';

function znews_auto_settlement_network_allowed(string $network): bool
{
    return znews_ad_network_name($network) === 'INMOBI';
}

function znews_auto_settlement_actor(): array
{
    return [
        'user' => [
            'uid' => 'SYSTEM_ZSKY24_AUTO',
            'name' => 'Z Sky 24 automatic settlement',
        ],
    ];
}

function znews_auto_settle_impression(string $impressionId): array
{
    $impressionId = znews_firebase_key($impressionId, 'impression_id');
    $row = fb_get(znews_ad_impression_path($impressionId));
    if (!is_array($row)) {
        return ['ok' => false, 'code' => 'ZNEWS_AD_IMPRESSION_NOT_FOUND', 'http_status' => 404];
    }
    if (!znews_auto_settlement_network_allowed((string)($row['network'] ?? ''))) {
        return ['ok' => true, 'code' => 'ZNEWS_AUTO_SETTLEMENT_NETWORK_NOT_PAYABLE', 'skipped' => true];
    }
    if (strtoupper(trim((string)($row['status'] ?? ''))) !== 'VERIFIED'
        || strtoupper(trim((string)($row['verification_status'] ?? ''))) !== 'VERIFIED') {
        return ['ok' => true, 'code' => 'ZNEWS_AUTO_SETTLEMENT_NOT_VERIFIED', 'skipped' => true];
    }
    if (!empty($row['self_view']) || in_array('SELF_VIEW', array_map('strval', (array)($row['risk_reasons'] ?? [])), true)) {
        return ['ok' => true, 'code' => 'ZNEWS_AUTO_SETTLEMENT_SELF_VIEW_REJECTED', 'skipped' => true];
    }

    return znews_settle_impression(
        znews_auto_settlement_actor(),
        $impressionId,
        (int)($row['updated_at'] ?? 0)
    );
}

function znews_auto_settle_view_impressions(string $viewId): array
{
    $viewId = znews_firebase_key($viewId, 'view_id');
    $index = fb_get('ZNEWS_VIEW_AD_IMPRESSIONS/' . $viewId);
    if (!is_array($index)) {
        return ['ok' => true, 'code' => 'ZNEWS_AUTO_SETTLEMENT_NO_IMPRESSIONS', 'processed' => 0, 'credited' => 0];
    }

    $processed = 0;
    $credited = 0;
    $retryRequired = false;
    foreach (array_keys($index) as $impressionIdRaw) {
        if ($processed >= znews_ad_max_per_view()) {
            break;
        }
        $impressionId = znews_firebase_key((string)$impressionIdRaw, 'impression_id');
        $row = fb_get(znews_ad_impression_path($impressionId));
        if (!is_array($row)) {
            continue;
        }
        $status = strtoupper(trim((string)($row['status'] ?? '')));
        if (in_array($status, ['PENDING_VIEW', 'REVIEW'], true)) {
            $recheck = znews_ad_recheck_impression($impressionId, null);
            if (empty($recheck['ok'])) {
                $retryRequired = true;
                $processed++;
                continue;
            }
        }
        $settled = znews_auto_settle_impression($impressionId);
        if (empty($settled['ok'])) {
            $retryRequired = true;
        } elseif (empty($settled['skipped'])) {
            $credited++;
        }
        $processed++;
    }

    return [
        'ok' => !$retryRequired,
        'code' => $retryRequired ? 'ZNEWS_AUTO_SETTLEMENT_RETRY_REQUIRED' : 'ZNEWS_AUTO_SETTLEMENT_VIEW_PROCESSED',
        'processed' => $processed,
        'credited' => $credited,
        'retry_required' => $retryRequired,
    ];
}
