<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/settlements_balances.php';

function znews_settlement_failure(string $impressionId, string $settlementId, string $code): void
{
    @fb_patch(znews_ad_impression_path($impressionId), [
        'settlement_status' => 'SETTLING',
        'settlement_id' => $settlementId,
        'settlement_reconciliation_required' => true,
        'settlement_reconciliation_code' => $code,
        'settlement_lease_expires_at' => 0,
        'updated_at' => znews_now(),
    ]);
    @fb_patch(znews_settlement_path($settlementId), [
        'status' => 'RECONCILIATION_REQUIRED',
        'reconciliation_required' => true,
        'reconciliation_code' => $code,
        'updated_at' => znews_now(),
    ]);
}

function znews_settlement_record_payload(
    array $impression,
    string $settlementId,
    array $allocation,
    array $admin
): array {
    $now = znews_now();
    $user = is_array($admin['user'] ?? null) ? (array)$admin['user'] : [];
    $adminUid = znews_firebase_key((string)($user['uid'] ?? ''), 'admin_uid');

    return [
        'schema_version' => 1,
        'settlement_id' => $settlementId,
        'source_type' => 'AD_IMPRESSION',
        'impression_id' => (string)$impression['impression_id'],
        'post_id' => (string)$impression['post_id'],
        'view_id' => (string)$impression['view_id'],
        'creator_uid' => (string)$impression['creator_uid'],
        'network' => (string)$impression['network'],
        'currency' => (string)$impression['currency'],
        'gross_revenue_micros' => (int)$allocation['gross_micros'],
        'creator_share_bps' => (int)$allocation['creator_share_bps'],
        'platform_share_bps' => (int)$allocation['platform_share_bps'],
        'creator_amount_micros' => (int)$allocation['creator_micros'],
        'platform_amount_micros' => (int)$allocation['platform_micros'],
        'status' => 'SETTLING',
        'znews_balance_status' => 'PENDING',
        'main_wallet_transfer_status' => 'NOT_TRANSFERRED',
        'initiated_by_admin_uid' => $adminUid,
        'initiated_by_admin_name' => trim((string)($user['name'] ?? 'Z-Pay Admin')),
        'reconciliation_required' => true,
        'reconciliation_code' => 'BALANCE_AND_LEDGER_SYNC',
        'created_at' => $now,
        'updated_at' => $now,
        'settled_at' => 0,
    ];
}

function znews_settle_impression(array $admin, string $impressionId, int $expectedUpdatedAt): array
{
    $impressionId = znews_firebase_key($impressionId, 'impression_id');
    $path = znews_ad_impression_path($impressionId);
    $snapshot = fb_get_with_etag($path);
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_IMPRESSION_READ_FAILED', 'http_status' => 503];
    }

    $impression = $snapshot['value'] ?? null;
    if (!is_array($impression)) {
        return ['ok' => false, 'code' => 'ZNEWS_AD_IMPRESSION_NOT_FOUND', 'http_status' => 404];
    }

    $currentUpdatedAt = (int)($impression['updated_at'] ?? 0);
    if ($expectedUpdatedAt <= 0 || $expectedUpdatedAt !== $currentUpdatedAt) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_SETTLEMENT_VERSION_CONFLICT',
            'http_status' => 409,
            'current_updated_at' => $currentUpdatedAt,
        ];
    }

    $status = strtoupper(trim((string)($impression['status'] ?? '')));
    $verification = strtoupper(trim((string)($impression['verification_status'] ?? '')));
    $settlementStatus = strtoupper(trim((string)($impression['settlement_status'] ?? 'NOT_SETTLED')));
    $settlementId = trim((string)($impression['settlement_id'] ?? ''));
    if ($settlementId === '') {
        $settlementId = znews_settlement_id($impressionId);
    }

    if ($settlementStatus === 'SETTLED') {
        $saved = fb_get(znews_settlement_path($settlementId));
        return [
            'ok' => true,
            'code' => 'ZNEWS_SETTLEMENT_ALREADY_COMPLETED',
            'http_status' => 200,
            'idempotent_replay' => true,
            'settlement' => is_array($saved) ? znews_settlement_public($saved) : [],
        ];
    }
    if ($status !== 'VERIFIED' || $verification !== 'VERIFIED') {
        return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_IMPRESSION_NOT_VERIFIED', 'http_status' => 409];
    }
    if (!empty($impression['reconciliation_required'])) {
        return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_IMPRESSION_RECONCILIATION_REQUIRED', 'http_status' => 409];
    }

    $creatorUid = trim((string)($impression['creator_uid'] ?? ''));
    if ($creatorUid === '') {
        return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_CREATOR_MISSING', 'http_status' => 409];
    }
    $creatorUid = znews_firebase_key($creatorUid, 'creator_uid');
    $currency = znews_ad_currency($impression['currency'] ?? '');
    $grossMicros = max(0, (int)($impression['reported_revenue_micros'] ?? 0));
    if ($grossMicros <= 0) {
        return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_ZERO_REVENUE', 'http_status' => 409];
    }

    $allocation = znews_settlement_allocation($grossMicros);
    $now = znews_now();
    $user = is_array($admin['user'] ?? null) ? (array)$admin['user'] : [];
    $adminUid = znews_firebase_key((string)($user['uid'] ?? ''), 'admin_uid');

    if ($settlementStatus === 'NOT_SETTLED') {
        $claimed = $impression;
        $claimed['settlement_status'] = 'SETTLING';
        $claimed['settlement_id'] = $settlementId;
        $claimed['settlement_lease_expires_at'] = $now + znews_settlement_lease_seconds();
        $claimed['settlement_reconciliation_required'] = true;
        $claimed['settlement_reconciliation_code'] = 'BALANCE_AND_LEDGER_SYNC';
        $claimed['creator_share_bps'] = (int)$allocation['creator_share_bps'];
        $claimed['platform_share_bps'] = (int)$allocation['platform_share_bps'];
        $claimed['creator_amount_micros'] = (int)$allocation['creator_micros'];
        $claimed['platform_amount_micros'] = (int)$allocation['platform_micros'];
        $claimed['znews_balance_status'] = 'PENDING';
        $claimed['main_wallet_credit_status'] = 'NOT_CREDITED';
        $claimed['transfer_status'] = 'NOT_REQUESTED';
        $claimed['settlement_started_by_admin_uid'] = $adminUid;
        $claimed['settlement_started_at'] = $now;
        $claimed['updated_at'] = $now;

        $write = fb_put_if_match($path, $claimed, (string)$snapshot['etag']);
        if ((int)($write['status'] ?? 0) === 412) {
            return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_VERSION_CONFLICT', 'http_status' => 409];
        }
        if (empty($write['ok'])) {
            return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_CLAIM_FAILED', 'http_status' => 503];
        }
        $impression = $claimed;
    } elseif ($settlementStatus === 'SETTLING') {
        $savedId = trim((string)($impression['settlement_id'] ?? ''));
        if ($savedId === '' || !hash_equals($savedId, $settlementId)) {
            return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_ID_CONFLICT', 'http_status' => 409];
        }
        if ((int)($impression['settlement_lease_expires_at'] ?? 0) > $now) {
            return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_IN_PROGRESS', 'http_status' => 409];
        }
        $allocation = [
            'gross_micros' => $grossMicros,
            'creator_share_bps' => (int)($impression['creator_share_bps'] ?? 0),
            'platform_share_bps' => (int)($impression['platform_share_bps'] ?? 0),
            'creator_micros' => (int)($impression['creator_amount_micros'] ?? -1),
            'platform_micros' => (int)($impression['platform_amount_micros'] ?? -1),
        ];
        $expectedAllocation = znews_settlement_allocation($grossMicros);
        if ($allocation !== $expectedAllocation) {
            return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_ALLOCATION_CONFLICT', 'http_status' => 409];
        }
        @fb_patch($path, [
            'settlement_lease_expires_at' => $now + znews_settlement_lease_seconds(),
            'settlement_started_by_admin_uid' => $adminUid,
            'updated_at' => $now,
        ]);
    } else {
        return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_STATE_INVALID', 'http_status' => 409];
    }

    $record = znews_settlement_record_payload($impression, $settlementId, $allocation, $admin);
    $existingRecord = fb_get(znews_settlement_path($settlementId));
    if (is_array($existingRecord)) {
        $record['created_at'] = (int)($existingRecord['created_at'] ?? $record['created_at']);
    }
    @fb_put(znews_settlement_path($settlementId), $record);

    $creatorBalance = znews_settlement_apply_creator_balance(
        $creatorUid, $currency, $settlementId, (int)$allocation['creator_micros']
    );
    if (empty($creatorBalance['ok'])) {
        $code = (string)($creatorBalance['code'] ?? 'ZNEWS_SETTLEMENT_CREATOR_BALANCE_FAILED');
        znews_settlement_failure($impressionId, $settlementId, $code);
        return ['ok' => false, 'code' => $code, 'http_status' => 503];
    }

    $platformBalance = znews_settlement_apply_platform_balance(
        $currency, $settlementId, (int)$allocation['platform_micros']
    );
    if (empty($platformBalance['ok'])) {
        $code = (string)($platformBalance['code'] ?? 'ZNEWS_SETTLEMENT_PLATFORM_BALANCE_FAILED');
        znews_settlement_failure($impressionId, $settlementId, $code);
        return ['ok' => false, 'code' => $code, 'http_status' => 503];
    }

    $settledAt = znews_now();
    $record['status'] = 'SETTLED';
    $record['znews_balance_status'] = 'CREDITED';
    $record['main_wallet_transfer_status'] = 'NOT_TRANSFERRED';
    $record['reconciliation_required'] = false;
    $record['reconciliation_code'] = null;
    $record['settled_at'] = $settledAt;
    $record['updated_at'] = $settledAt;

    $creatorLedger = [
        'entry_id' => $settlementId,
        'settlement_id' => $settlementId,
        'impression_id' => $impressionId,
        'post_id' => (string)$impression['post_id'],
        'type' => 'AD_REVENUE_SHARE',
        'direction' => 'CREDIT',
        'currency' => $currency,
        'amount_micros' => (int)$allocation['creator_micros'],
        'amount' => znews_settlement_decimal((int)$allocation['creator_micros']),
        'status' => 'POSTED',
        'main_wallet_transfer_status' => 'NOT_TRANSFERRED',
        'created_at' => $settledAt,
    ];
    $platformLedger = [
        'entry_id' => $settlementId,
        'settlement_id' => $settlementId,
        'impression_id' => $impressionId,
        'post_id' => (string)$impression['post_id'],
        'type' => 'AD_PLATFORM_SHARE',
        'direction' => 'CREDIT',
        'currency' => $currency,
        'amount_micros' => (int)$allocation['platform_micros'],
        'amount' => znews_settlement_decimal((int)$allocation['platform_micros']),
        'status' => 'POSTED',
        'created_at' => $settledAt,
    ];
    $index = [
        'settlement_id' => $settlementId,
        'impression_id' => $impressionId,
        'post_id' => (string)$impression['post_id'],
        'currency' => $currency,
        'creator_amount_micros' => (int)$allocation['creator_micros'],
        'status' => 'SETTLED',
        'created_at' => (int)$record['created_at'],
        'settled_at' => $settledAt,
        'updated_at' => $settledAt,
    ];

    $ledgerOk = fb_patch('', [
        znews_settlement_path($settlementId) => $record,
        znews_settlement_item_path($settlementId, $impressionId) => [
            'impression_id' => $impressionId,
            'gross_revenue_micros' => $grossMicros,
            'creator_amount_micros' => (int)$allocation['creator_micros'],
            'platform_amount_micros' => (int)$allocation['platform_micros'],
            'currency' => $currency,
            'status' => 'SETTLED',
            'created_at' => $settledAt,
        ],
        znews_settlement_impression_index_path($impressionId) => $index,
        znews_settlement_creator_index_path($creatorUid, $settlementId) => $index,
        znews_settlement_creator_ledger_path($creatorUid, $currency, $settlementId) => $creatorLedger,
        znews_settlement_platform_ledger_path($currency, $settlementId) => $platformLedger,
    ]);
    if (!$ledgerOk) {
        znews_settlement_failure($impressionId, $settlementId, 'ZNEWS_SETTLEMENT_LEDGER_WRITE_FAILED');
        return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_LEDGER_WRITE_FAILED', 'http_status' => 503];
    }

    $finalSnapshot = fb_get_with_etag($path);
    if (empty($finalSnapshot['ok']) || !is_string($finalSnapshot['etag'] ?? null)
        || !is_array($finalSnapshot['value'] ?? null)) {
        znews_settlement_failure($impressionId, $settlementId, 'ZNEWS_SETTLEMENT_FINALIZE_READ_FAILED');
        return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_FINALIZE_READ_FAILED', 'http_status' => 503];
    }

    $finalImpression = (array)$finalSnapshot['value'];
    if (strtoupper(trim((string)($finalImpression['settlement_status'] ?? ''))) === 'SETTLED') {
        return [
            'ok' => true,
            'code' => 'ZNEWS_SETTLEMENT_ALREADY_COMPLETED',
            'http_status' => 200,
            'idempotent_replay' => true,
            'settlement' => znews_settlement_public($record),
        ];
    }
    $finalSavedId = trim((string)($finalImpression['settlement_id'] ?? ''));
    if ($finalSavedId === '' || !hash_equals($finalSavedId, $settlementId)) {
        znews_settlement_failure($impressionId, $settlementId, 'ZNEWS_SETTLEMENT_FINALIZE_CONFLICT');
        return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_FINALIZE_CONFLICT', 'http_status' => 409];
    }

    $finalImpression['settlement_status'] = 'SETTLED';
    $finalImpression['znews_balance_status'] = 'CREDITED';
    $finalImpression['main_wallet_credit_status'] = 'NOT_CREDITED';
    $finalImpression['credit_status'] = 'NOT_CREDITED';
    $finalImpression['transfer_status'] = 'NOT_REQUESTED';
    $finalImpression['earning_eligible'] = true;
    $finalImpression['settlement_reconciliation_required'] = false;
    $finalImpression['settlement_reconciliation_code'] = null;
    $finalImpression['settlement_lease_expires_at'] = 0;
    $finalImpression['settled_at'] = $settledAt;
    $finalImpression['updated_at'] = znews_now();

    $finalWrite = fb_put_if_match($path, $finalImpression, (string)$finalSnapshot['etag']);
    if ((int)($finalWrite['status'] ?? 0) === 412) {
        znews_settlement_failure($impressionId, $settlementId, 'ZNEWS_SETTLEMENT_FINALIZE_CONFLICT');
        return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_FINALIZE_CONFLICT', 'http_status' => 409];
    }
    if (empty($finalWrite['ok'])) {
        znews_settlement_failure($impressionId, $settlementId, 'ZNEWS_SETTLEMENT_FINALIZE_FAILED');
        return ['ok' => false, 'code' => 'ZNEWS_SETTLEMENT_FINALIZE_FAILED', 'http_status' => 503];
    }

    if (function_exists('system_log')) {
        system_log('ZNEWS_REVENUE_SETTLED', $settlementId, 'Z News ad revenue settled', [
            'impression_id' => $impressionId,
            'creator_uid' => $creatorUid,
            'currency' => $currency,
            'gross_micros' => $grossMicros,
            'creator_micros' => (int)$allocation['creator_micros'],
            'platform_micros' => (int)$allocation['platform_micros'],
        ]);
    }

    return [
        'ok' => true,
        'code' => 'ZNEWS_SETTLEMENT_COMPLETED',
        'http_status' => 200,
        'idempotent_replay' => false,
        'settlement' => znews_settlement_public($record),
    ];
}
