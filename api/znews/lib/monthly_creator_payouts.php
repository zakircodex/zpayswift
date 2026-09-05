<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/monthly_revenue.php';
require_once dirname(__DIR__, 2) . '/lib/wallet.php';

function znews_monthly_fx_path(string $monthId, string $currency): string
{
    return 'ZNEWS_CREATOR_PAYOUT_FX/'
        . znews_firebase_key($monthId, 'month_id') . '/USD_'
        . znews_firebase_key(strtoupper($currency), 'currency');
}

function znews_monthly_payout_path(string $monthId, string $creatorUid): string
{
    return 'ZNEWS_CREATOR_MONTHLY_PAYOUTS/ADSTERRA/'
        . znews_firebase_key($monthId, 'month_id') . '/'
        . znews_firebase_key($creatorUid, 'creator_uid');
}

function znews_monthly_payout_adjustment_path(string $monthId, string $creatorUid): string
{
    return 'ZNEWS_CREATOR_PAYOUT_ADJUSTMENTS/ADSTERRA/'
        . znews_firebase_key($monthId, 'month_id') . '/'
        . znews_firebase_key($creatorUid, 'creator_uid');
}

function znews_monthly_payout_batch_path(string $batchId): string
{
    return 'ZNEWS_CREATOR_PAYOUT_BATCHES/' . znews_firebase_key($batchId, 'batch_id');
}

function znews_monthly_fx_public(array $row): array
{
    if ($row === []) {
        return [];
    }
    $rate = max(0, (int)($row['rate_micros'] ?? 0));
    return [
        'currency_pair' => strtoupper(trim((string)($row['currency_pair'] ?? ''))),
        'rate_micros' => $rate,
        'rate' => znews_adsterra_decimal($rate),
        'source_reference' => trim((string)($row['source_reference'] ?? '')),
        'rate_timestamp' => max(0, (int)($row['rate_timestamp'] ?? 0)),
        'status' => strtoupper(trim((string)($row['status'] ?? 'UNAVAILABLE'))),
        'locked_at' => max(0, (int)($row['locked_at'] ?? 0)),
    ];
}

function znews_monthly_fx_lock(
    string $monthId,
    string $currency,
    string $rateValue,
    string $sourceReference,
    int $rateTimestamp,
    string $adminUid
): array {
    $month = znews_monthly_performance_month($monthId);
    if (empty($month['ok']) || empty($month['completed'])) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_MONTH_OPEN', 'http_status' => 409];
    }
    $currency = strtoupper(trim($currency));
    if (!in_array($currency, ['BDT', 'MYR'], true)) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_CURRENCY_UNSUPPORTED', 'http_status' => 422];
    }
    $rateMicros = znews_adsterra_decimal_micros($rateValue);
    if ($rateMicros === null || $rateMicros <= 0 || $rateMicros > 1000 * 1000000) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_FX_INVALID', 'http_status' => 422];
    }
    $sourceReference = trim($sourceReference);
    if (strlen($sourceReference) < 3 || strlen($sourceReference) > 200) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_FX_SOURCE_REQUIRED', 'http_status' => 422];
    }
    $now = znews_now();
    if ($rateTimestamp <= 0 || $rateTimestamp > $now + 300) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_FX_TIMESTAMP_INVALID', 'http_status' => 422];
    }

    $path = znews_monthly_fx_path((string)$month['month_id'], $currency);
    $snapshot = fb_get_with_etag($path);
    if (empty($snapshot['ok']) || !is_string($snapshot['etag'] ?? null)) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_FX_LOCK_UNAVAILABLE', 'http_status' => 503];
    }
    $existing = $snapshot['value'] ?? null;
    if (is_array($existing)) {
        return [
            'ok' => true,
            'code' => 'ZNEWS_PAYOUT_FX_ALREADY_LOCKED',
            'idempotent_replay' => true,
            'fx' => znews_monthly_fx_public($existing),
        ];
    }
    if ($existing !== null) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_FX_LOCK_INVALID', 'http_status' => 409];
    }

    $row = [
        'schema_version' => 1,
        'provider' => 'ADSTERRA',
        'month_id' => (string)$month['month_id'],
        'base_currency' => 'USD',
        'payout_currency' => $currency,
        'currency_pair' => 'USD_' . $currency,
        'rate_micros' => $rateMicros,
        'source_reference' => $sourceReference,
        'rate_timestamp' => $rateTimestamp,
        'status' => 'LOCKED',
        'locked_at' => $now,
        'locked_by' => znews_firebase_key($adminUid, 'admin_uid'),
    ];
    $write = fb_put_if_match($path, $row, (string)$snapshot['etag']);
    if ((int)($write['status'] ?? 0) === 412) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_FX_LOCK_CONFLICT', 'http_status' => 409];
    }
    if (empty($write['ok'])) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_FX_LOCK_FAILED', 'http_status' => 503];
    }
    return [
        'ok' => true,
        'code' => 'ZNEWS_PAYOUT_FX_LOCKED',
        'idempotent_replay' => false,
        'fx' => znews_monthly_fx_public($row),
    ];
}

function znews_monthly_fx_get(string $monthId, string $currency): array
{
    $row = fb_get(znews_monthly_fx_path($monthId, $currency));
    return is_array($row) && strtoupper((string)($row['status'] ?? '')) === 'LOCKED'
        ? $row
        : [];
}

function znews_monthly_creator_allocations(array $items, int $creatorPoolMicros): array
{
    $eligibleRows = [];
    $totalEligible = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $uid = trim((string)($item['creator_uid'] ?? ''));
        $eligible = max(0, (int)($item['settlement_eligible_views'] ?? 0));
        if ($uid === '' || $eligible <= 0) {
            continue;
        }
        if ($totalEligible > PHP_INT_MAX - $eligible) {
            return [];
        }
        $eligibleRows[$uid] = ['item' => $item, 'eligible' => $eligible];
        $totalEligible += $eligible;
    }
    if ($creatorPoolMicros <= 0 || $totalEligible <= 0) {
        return [];
    }

    $allocations = [];
    $assigned = 0;
    $remainders = [];
    foreach ($eligibleRows as $uid => $entry) {
        $share = znews_monthly_mul_div($creatorPoolMicros, (int)$entry['eligible'], $totalEligible);
        if (!is_array($share)) {
            return [];
        }
        $amount = (int)$share['quotient'];
        $remainder = (int)$share['remainder'];
        $assigned += $amount;
        $ratio = znews_monthly_mul_div((int)$entry['eligible'], 1000000, $totalEligible);
        $allocations[$uid] = [
            'creator_uid' => $uid,
            'settlement_eligible_views' => (int)$entry['eligible'],
            'all_settlement_eligible_views' => $totalEligible,
            'creator_share_ratio_ppm' => (int)($ratio['quotient'] ?? 0),
            'creator_share_usd_micros' => $amount,
            'creator_share_usd' => znews_adsterra_decimal($amount),
        ];
        $remainders[] = ['uid' => $uid, 'remainder' => $remainder];
    }
    usort($remainders, static function (array $a, array $b): int {
        $result = ((int)$b['remainder']) <=> ((int)$a['remainder']);
        return $result !== 0 ? $result : strcmp((string)$a['uid'], (string)$b['uid']);
    });
    $remaining = max(0, $creatorPoolMicros - $assigned);
    for ($i = 0; $i < $remaining && isset($remainders[$i]); $i++) {
        $uid = (string)$remainders[$i]['uid'];
        $allocations[$uid]['creator_share_usd_micros']++;
        $allocations[$uid]['creator_share_usd'] = znews_adsterra_decimal(
            (int)$allocations[$uid]['creator_share_usd_micros']
        );
    }
    return $allocations;
}

function znews_monthly_native_amount_micros(int $usdMicros, int $rateMicros): int
{
    if ($usdMicros <= 0 || $rateMicros <= 0) {
        return 0;
    }
    $conversion = znews_monthly_mul_div($usdMicros, $rateMicros, 1000000);
    if (!is_array($conversion)) {
        return 0;
    }
    $nativeMicros = (int)$conversion['quotient'];
    if ((int)$conversion['remainder'] >= 500000) {
        if ($nativeMicros === PHP_INT_MAX) {
            return 0;
        }
        $nativeMicros++;
    }
    if ($nativeMicros > PHP_INT_MAX - 5000) {
        return 0;
    }
    return intdiv($nativeMicros + 5000, 10000) * 10000;
}

function znews_monthly_payout_public(array $row): array
{
    $usd = max(0, (int)($row['creator_share_usd_micros'] ?? 0));
    $wallet = max(0, (int)($row['wallet_amount_micros'] ?? 0));
    return [
        'payout_id' => trim((string)($row['payout_id'] ?? '')),
        'batch_id' => trim((string)($row['batch_id'] ?? '')),
        'provider' => 'ADSTERRA',
        'month_id' => trim((string)($row['month_id'] ?? '')),
        'creator_uid' => trim((string)($row['creator_uid'] ?? '')),
        'creator_name' => trim((string)($row['creator_name'] ?? 'Z-Pay creator')),
        'settlement_eligible_views' => max(0, (int)($row['settlement_eligible_views'] ?? 0)),
        'creator_share_ratio_ppm' => max(0, (int)($row['creator_share_ratio_ppm'] ?? 0)),
        'creator_share_usd_micros' => $usd,
        'creator_share_usd' => znews_adsterra_decimal($usd),
        'fx_pair' => trim((string)($row['fx_pair'] ?? '')),
        'fx_rate_micros' => max(0, (int)($row['fx_rate_micros'] ?? 0)),
        'fx_rate' => znews_adsterra_decimal(max(0, (int)($row['fx_rate_micros'] ?? 0))),
        'wallet_currency' => strtoupper(trim((string)($row['wallet_currency'] ?? ''))),
        'wallet_amount_micros' => $wallet,
        'wallet_amount' => znews_adsterra_decimal($wallet, 2),
        'wallet_ledger_reference' => trim((string)($row['wallet_ledger_reference'] ?? '')),
        'status' => strtoupper(trim((string)($row['status'] ?? 'PENDING'))),
        'created_at' => max(0, (int)($row['created_at'] ?? 0)),
        'completed_at' => max(0, (int)($row['completed_at'] ?? 0)),
    ];
}

function znews_monthly_payout_enrich_performance(array $performance): array
{
    if (empty($performance['ok']) || !is_array($performance['month'] ?? null)) {
        return $performance;
    }
    $monthId = (string)$performance['month']['month_id'];
    $sync = fb_get(znews_monthly_revenue_sync_path($monthId));
    $lock = fb_get(znews_monthly_revenue_lock_path($monthId));
    $performance['revenue'] = [
        'status' => is_array($lock)
            ? 'LOCKED'
            : (is_array($sync) ? strtoupper((string)($sync['status'] ?? 'ESTIMATED')) : 'PENDING'),
        'sync' => is_array($sync) ? znews_monthly_revenue_public_row($sync) : [],
        'lock' => is_array($lock) ? znews_monthly_revenue_public_row($lock) : [],
    ];
    $performance['fx'] = [
        'USD_BDT' => znews_monthly_fx_public(znews_monthly_fx_get($monthId, 'BDT')),
        'USD_MYR' => znews_monthly_fx_public(znews_monthly_fx_get($monthId, 'MYR')),
    ];
    if (!is_array($lock)) {
        foreach ($performance['items'] as &$row) {
            $row['revenue_status'] = is_array($sync) ? 'ESTIMATED' : 'PENDING';
        }
        unset($row);
        return $performance;
    }

    $formula = znews_monthly_revenue_formula((int)($lock['gross_settled_usd_micros'] ?? 0));
    $allocations = znews_monthly_creator_allocations(
        (array)($performance['items'] ?? []),
        (int)$formula['creator_pool_usd_micros']
    );
    foreach ($performance['items'] as &$row) {
        $uid = (string)($row['creator_uid'] ?? '');
        $allocation = $allocations[$uid] ?? [];
        $row['revenue_status'] = 'LOCKED';
        $row['creator_share_usd_micros'] = max(0, (int)($allocation['creator_share_usd_micros'] ?? 0));
        $row['creator_share_usd'] = znews_adsterra_decimal((int)$row['creator_share_usd_micros']);
        $currency = strtoupper(trim((string)($row['wallet_currency_snapshot'] ?? '')));
        $fx = in_array($currency, ['BDT', 'MYR'], true) ? znews_monthly_fx_get($monthId, $currency) : [];
        $row['fx_status'] = $fx === [] ? 'UNLOCKED' : 'LOCKED';
        $row['fx_rate'] = $fx === [] ? '' : znews_adsterra_decimal((int)$fx['rate_micros']);
        $row['estimated_wallet_amount'] = $fx === [] ? '' : znews_adsterra_decimal(
            znews_monthly_native_amount_micros((int)$row['creator_share_usd_micros'], (int)$fx['rate_micros']),
            2
        );
    }
    unset($row);
    $performance['summary'] = array_merge((array)($performance['summary'] ?? []), $formula, [
        'revenue_locked' => true,
    ]);
    return $performance;
}

function znews_monthly_unresolved_adjustment(string $monthId, string $creatorUid): bool
{
    $row = fb_get(znews_monthly_payout_adjustment_path($monthId, $creatorUid));
    if (!is_array($row)) {
        return false;
    }
    return in_array(strtoupper(trim((string)($row['status'] ?? 'OPEN'))), [
        'OPEN', 'PENDING', 'UNDER_REVIEW', 'RECONCILIATION_REQUIRED',
    ], true);
}

function znews_monthly_payout_preflight(
    string $monthId,
    array $creatorUids,
    bool $allowCompletedReplay = false
): array
{
    $base = znews_creator_payout_batch_preflight($creatorUids);
    if (empty($base['ok'])) {
        return $base;
    }
    $month = znews_monthly_performance_month($monthId);
    if (empty($month['ok'])) {
        return ['ok' => false, 'code' => 'ZNEWS_MONTHLY_PERIOD_INVALID', 'http_status' => 422];
    }
    if (empty($month['completed'])) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_MONTH_OPEN', 'http_status' => 409];
    }
    $monthId = (string)$month['month_id'];
    $revenueLock = fb_get(znews_monthly_revenue_lock_path($monthId));
    if (!is_array($revenueLock) || strtoupper((string)($revenueLock['status'] ?? '')) !== 'LOCKED') {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_REVENUE_UNLOCKED', 'http_status' => 409];
    }

    $performance = znews_monthly_performance_preview($monthId);
    if (empty($performance['ok'])) {
        return $performance;
    }
    $summary = (array)($performance['summary'] ?? []);
    if (empty($summary['all_periods_generated']) || empty($summary['all_creator_reviews_complete'])) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_REVIEWS_INCOMPLETE', 'http_status' => 409];
    }

    $formula = znews_monthly_revenue_formula((int)($revenueLock['gross_settled_usd_micros'] ?? 0));
    $allocations = znews_monthly_creator_allocations(
        (array)($performance['items'] ?? []),
        (int)$formula['creator_pool_usd_micros']
    );
    $liveAccounts = [];
    foreach ((array)$base['creators'] as $creator) {
        $liveAccounts[(string)$creator['creator_uid']] = $creator;
    }

    $ready = [];
    $rejected = [];
    foreach (array_keys($liveAccounts) as $uid) {
        $account = (array)$liveAccounts[$uid];
        $allocation = $allocations[$uid] ?? null;
        if (!is_array($allocation) || (int)$allocation['creator_share_usd_micros'] <= 0) {
            $rejected[] = ['creator_uid' => $uid, 'code' => 'ZNEWS_PAYOUT_AMOUNT_ZERO', 'message' => 'Creator payout amount is zero.'];
            continue;
        }
        $existing = fb_get(znews_monthly_payout_path($monthId, $uid));
        if (is_array($existing) && strtoupper((string)($existing['status'] ?? '')) === 'COMPLETED') {
            if ($allowCompletedReplay) {
                $ready[] = array_merge($account, [
                    'provider' => 'ADSTERRA',
                    'month_id' => $monthId,
                    'payout_id' => (string)($existing['payout_id'] ?? ''),
                    'status' => 'COMPLETED',
                    'idempotent_replay' => true,
                ]);
                continue;
            }
            $rejected[] = ['creator_uid' => $uid, 'code' => 'ZNEWS_CREATOR_MONTH_ALREADY_PAID', 'message' => 'Creator was already paid for this month.', 'payout' => znews_monthly_payout_public($existing)];
            continue;
        }
        if (znews_monthly_unresolved_adjustment($monthId, $uid)) {
            $rejected[] = ['creator_uid' => $uid, 'code' => 'ZNEWS_PAYOUT_ADJUSTMENT_UNRESOLVED', 'message' => 'Creator has an unresolved payout adjustment.'];
            continue;
        }
        $currency = (string)$account['wallet_currency'];
        $fx = znews_monthly_fx_get($monthId, $currency);
        if ($fx === []) {
            $rejected[] = ['creator_uid' => $uid, 'code' => 'ZNEWS_PAYOUT_FX_UNLOCKED', 'message' => 'Required payout FX rate is not locked.', 'wallet_currency' => $currency];
            continue;
        }
        $walletAmount = znews_monthly_native_amount_micros(
            (int)$allocation['creator_share_usd_micros'],
            (int)$fx['rate_micros']
        );
        if ($walletAmount <= 0) {
            $rejected[] = ['creator_uid' => $uid, 'code' => 'ZNEWS_PAYOUT_AMOUNT_ZERO', 'message' => 'Native payout amount is zero.'];
            continue;
        }
        $payoutId = 'ZPY' . strtoupper(substr(hash('sha256', 'ADSTERRA|' . $monthId . '|' . $uid), 0, 29));
        $ready[] = array_merge($account, $allocation, [
            'payout_id' => $payoutId,
            'provider' => 'ADSTERRA',
            'month_id' => $monthId,
            'gross_settled_usd_micros' => (int)$formula['gross_settled_usd_micros'],
            'creator_pool_usd_micros' => (int)$formula['creator_pool_usd_micros'],
            'fx_pair' => (string)$fx['currency_pair'],
            'fx_rate_micros' => (int)$fx['rate_micros'],
            'fx_rate' => znews_adsterra_decimal((int)$fx['rate_micros']),
            'wallet_amount_micros' => $walletAmount,
            'wallet_amount' => znews_adsterra_decimal($walletAmount, 2),
            'status' => 'READY',
        ]);
    }

    if ($rejected !== []) {
        return [
            'ok' => false,
            'code' => 'ZNEWS_PAYOUT_BATCH_NOT_READY',
            'message' => 'One or more creators are not ready for payout.',
            'http_status' => 409,
            'month' => $month,
            'revenue' => znews_monthly_revenue_public_row($revenueLock),
            'ready' => $ready,
            'rejected' => $rejected,
        ];
    }
    return [
        'ok' => true,
        'code' => 'ZNEWS_PAYOUT_BATCH_READY',
        'message' => 'Creator payout batch is ready.',
        'batch_limit' => znews_creator_payout_batch_limit(),
        'month' => $month,
        'revenue' => znews_monthly_revenue_public_row($revenueLock),
        'creators' => $ready,
    ];
}

function znews_monthly_payout_record(array $ready, string $batchId, string $adminUid, string $status): array
{
    $now = znews_now();
    return [
        'schema_version' => 1,
        'payout_id' => (string)$ready['payout_id'],
        'batch_id' => $batchId,
        'provider' => 'ADSTERRA',
        'month_id' => (string)$ready['month_id'],
        'creator_uid' => (string)$ready['creator_uid'],
        'zpay_uid' => (string)$ready['zpay_uid'],
        'creator_name' => (string)$ready['name'],
        'gross_settled_usd_micros' => (int)$ready['gross_settled_usd_micros'],
        'creator_pool_usd_micros' => (int)$ready['creator_pool_usd_micros'],
        'settlement_eligible_views' => (int)$ready['settlement_eligible_views'],
        'all_settlement_eligible_views' => (int)$ready['all_settlement_eligible_views'],
        'creator_share_ratio_ppm' => (int)$ready['creator_share_ratio_ppm'],
        'creator_share_usd_micros' => (int)$ready['creator_share_usd_micros'],
        'fx_pair' => (string)$ready['fx_pair'],
        'fx_rate_micros' => (int)$ready['fx_rate_micros'],
        'wallet_currency' => (string)$ready['wallet_currency'],
        'wallet_amount_micros' => (int)$ready['wallet_amount_micros'],
        'wallet_ledger_reference' => '',
        'status' => $status,
        'created_at' => $now,
        'updated_at' => $now,
        'completed_at' => 0,
        'admin_uid' => znews_firebase_key($adminUid, 'admin_uid'),
    ];
}

function znews_monthly_execute_one(array $ready, string $batchId, string $adminUid): array
{
    $monthId = (string)$ready['month_id'];
    $uid = (string)$ready['creator_uid'];
    $path = znews_monthly_payout_path($monthId, $uid);
    $existing = fb_get($path);
    if (is_array($existing) && strtoupper((string)($existing['status'] ?? '')) === 'COMPLETED') {
        return ['ok' => true, 'idempotent_replay' => true, 'payout' => znews_monthly_payout_public($existing)];
    }

    $row = znews_monthly_payout_record($ready, $batchId, $adminUid, 'PROCESSING');
    if (!fb_put($path, array_merge(is_array($existing) ? $existing : [], $row))) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_RECORD_FAILED', 'creator_uid' => $uid];
    }

    $walletAmount = (float)znews_adsterra_decimal((int)$ready['wallet_amount_micros'], 2);
    $financial = wallet_financial_operation_begin(
        (string)$ready['payout_id'],
        'ZNEWS_CREATOR_MONTHLY_PAYOUT',
        'CREATOR_PAYOUT',
        (string)$ready['zpay_uid'],
        $walletAmount,
        (string)$ready['wallet_currency'],
        [
            'provider' => 'ADSTERRA',
            'month_id' => $monthId,
            'creator_uid' => $uid,
            'batch_id' => $batchId,
        ]
    );
    if (empty($financial['ok'])) {
        fb_patch($path, ['status' => 'FAILED_RETRYABLE', 'last_error_code' => (string)($financial['code'] ?? 'FINANCIAL_OPERATION_FAILED'), 'updated_at' => znews_now()]);
        return ['ok' => false, 'code' => (string)($financial['code'] ?? 'ZNEWS_PAYOUT_WALLET_CLAIM_FAILED'), 'creator_uid' => $uid];
    }

    $claim = is_array($financial['claim'] ?? null) ? (array)$financial['claim'] : [];
    if (!empty($financial['duplicate']) && !empty($financial['completed'])) {
        $operation = (array)($financial['operation'] ?? []);
        $ledgerId = trim((string)($operation['ledger_id'] ?? ''));
        $completed = array_merge($row, [
            'wallet_ledger_reference' => $ledgerId,
            'status' => 'COMPLETED',
            'completed_at' => (int)($operation['completed_at'] ?? znews_now()),
            'updated_at' => znews_now(),
        ]);
        fb_put($path, $completed);
        return ['ok' => true, 'idempotent_replay' => true, 'payout' => znews_monthly_payout_public($completed)];
    }

    $ledgerId = wallet_financial_operation_side_ledger_id(
        (string)$ready['payout_id'],
        'ZNEWS_CREATOR_MONTHLY_PAYOUT',
        'creator_credited'
    );
    $credit = wallet_credit_available(
        (string)$ready['zpay_uid'],
        $walletAmount,
        (string)$ready['payout_id'],
        'ZNEWS_CREATOR_MONTHLY_PAYOUT',
        'Z Sky 24 monthly creator payout',
        [
            'ledger_id' => $ledgerId,
            'currency' => (string)$ready['wallet_currency'],
            'provider' => 'ADSTERRA',
            'month_id' => $monthId,
            'creator_share_usd_micros' => (int)$ready['creator_share_usd_micros'],
            'fx_pair' => (string)$ready['fx_pair'],
            'fx_rate_micros' => (int)$ready['fx_rate_micros'],
            'batch_id' => $batchId,
        ],
        ['financial_operation' => $claim]
    );
    if (empty($credit['ok'])) {
        wallet_financial_operation_mark_failed(
            $claim,
            (string)($credit['code'] ?? 'ZNEWS_PAYOUT_WALLET_FAILED'),
            (string)($credit['message'] ?? 'Creator payout wallet credit failed')
        );
        fb_patch($path, ['status' => 'FAILED_RETRYABLE', 'last_error_code' => (string)($credit['code'] ?? 'ZNEWS_PAYOUT_WALLET_FAILED'), 'updated_at' => znews_now()]);
        return ['ok' => false, 'code' => (string)($credit['code'] ?? 'ZNEWS_PAYOUT_WALLET_FAILED'), 'creator_uid' => $uid];
    }
    $ledgerId = trim((string)($credit['ledger_id'] ?? $ledgerId));
    if ($ledgerId === '') {
        wallet_financial_operation_mark_reconciliation_required($claim, 'LEDGER_EVIDENCE_MISSING', 'Creator payout ledger evidence is missing');
        fb_patch($path, ['status' => 'RECONCILIATION_REQUIRED', 'updated_at' => znews_now()]);
        return ['ok' => false, 'code' => 'LEDGER_EVIDENCE_MISSING', 'creator_uid' => $uid];
    }
    if (!wallet_financial_operation_mark_completed($claim, ['ledger_id' => $ledgerId])) {
        fb_patch($path, ['status' => 'RECONCILIATION_REQUIRED', 'wallet_ledger_reference' => $ledgerId, 'updated_at' => znews_now()]);
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_FINALIZE_FAILED', 'creator_uid' => $uid];
    }

    $completed = array_merge($row, [
        'wallet_ledger_reference' => $ledgerId,
        'status' => 'COMPLETED',
        'completed_at' => znews_now(),
        'updated_at' => znews_now(),
    ]);
    if (!fb_put($path, $completed)) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_RECONCILIATION_REQUIRED', 'creator_uid' => $uid];
    }
    return ['ok' => true, 'idempotent_replay' => false, 'payout' => znews_monthly_payout_public($completed)];
}

function znews_monthly_payout_execute(
    string $monthId,
    array $creatorUids,
    string $idempotencyKey,
    string $adminUid
): array {
    $preflight = znews_monthly_payout_preflight($monthId, $creatorUids, true);
    if (empty($preflight['ok'])) {
        return $preflight;
    }
    $sorted = array_values(array_unique(array_map('strval', $creatorUids)));
    sort($sorted, SORT_STRING);
    $batchId = 'ZPB' . strtoupper(substr(hash(
        'sha256',
        implode('|', ['ADSTERRA', $monthId, implode(',', $sorted), znews_idempotency_key($idempotencyKey)])
    ), 0, 29));
    $batchPath = znews_monthly_payout_batch_path($batchId);
    $existingBatch = fb_get($batchPath);
    if (is_array($existingBatch) && strtoupper((string)($existingBatch['status'] ?? '')) === 'COMPLETED') {
        return ['ok' => true, 'code' => 'ZNEWS_PAYOUT_BATCH_ALREADY_COMPLETED', 'idempotent_replay' => true, 'batch' => $existingBatch];
    }

    $now = znews_now();
    $batch = [
        'schema_version' => 1,
        'batch_id' => $batchId,
        'provider' => 'ADSTERRA',
        'month_id' => $monthId,
        'creator_uids' => $sorted,
        'status' => 'PROCESSING',
        'created_at' => (int)($existingBatch['created_at'] ?? $now),
        'updated_at' => $now,
        'completed_at' => 0,
        'admin_uid' => znews_firebase_key($adminUid, 'admin_uid'),
        'items' => [],
    ];
    if (!fb_put($batchPath, $batch)) {
        return ['ok' => false, 'code' => 'ZNEWS_PAYOUT_BATCH_CREATE_FAILED', 'http_status' => 503];
    }

    $failed = 0;
    foreach ((array)$preflight['creators'] as $ready) {
        $result = znews_monthly_execute_one((array)$ready, $batchId, $adminUid);
        $uid = (string)($ready['creator_uid'] ?? '');
        $batch['items'][$uid] = $result;
        if (empty($result['ok'])) {
            $failed++;
        }
    }
    $batch['status'] = $failed === 0 ? 'COMPLETED' : 'PARTIAL_FAILED';
    $batch['completed_count'] = count($batch['items']) - $failed;
    $batch['failed_count'] = $failed;
    $batch['updated_at'] = znews_now();
    $batch['completed_at'] = $failed === 0 ? znews_now() : 0;
    fb_put($batchPath, $batch);

    return [
        'ok' => $failed === 0,
        'code' => $failed === 0 ? 'ZNEWS_PAYOUT_BATCH_COMPLETED' : 'ZNEWS_PAYOUT_BATCH_PARTIAL_FAILED',
        'message' => $failed === 0 ? 'Creator payout batch completed.' : 'Some creator payouts failed and may be retried safely.',
        'http_status' => $failed === 0 ? 200 : 503,
        'idempotent_replay' => false,
        'batch' => $batch,
    ];
}
