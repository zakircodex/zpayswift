<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
define('WORKER_CLAIM_LEASE_SECONDS', 60);

$store = [];
$versions = [];
$testNow = 1700000000;
$injectClaimPath = '';

function test_parts(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
}

function test_get(string $path)
{
    global $store;
    $node = $store;
    foreach (test_parts($path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

function test_set(string $path, $value): void
{
    global $store, $versions;
    $node =& $store;
    foreach (test_parts($path) as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
    $node = $value;
    $versions[$path] = (int)($versions[$path] ?? 0) + 1;
}

function test_delete(string $path): void
{
    global $store, $versions;
    $parts = test_parts($path);
    $last = array_pop($parts);
    $node =& $store;
    foreach ($parts as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            return;
        }
        $node =& $node[$part];
    }
    if ($last !== null) {
        unset($node[$last]);
    }
    $versions[$path] = (int)($versions[$path] ?? 0) + 1;
}

function fb_get(string $path)
{
    return test_get($path);
}

function fb_put(string $path, $data): bool
{
    test_set($path, $data);
    return true;
}

function fb_patch(string $path, array $patch): bool
{
    $current = test_get($path);
    test_set($path, array_merge(is_array($current) ? $current : [], $patch));
    return true;
}

function fb_delete(string $path): bool
{
    test_delete($path);
    return true;
}

function fb_get_with_etag(string $path): array
{
    global $versions;
    return [
        'ok' => true,
        'status' => 200,
        'etag' => 'E' . (string)($versions[$path] ?? 0),
        'value' => test_get($path),
    ];
}

function fb_put_if_match(string $path, $data, string $etag): array
{
    global $versions, $injectClaimPath, $testNow;
    if ($injectClaimPath === $path) {
        $injectClaimPath = '';
        $competitor = is_array($data) ? $data : [];
        $competitor['assigned_device_id'] = 'DEVICE_WINNER';
        $competitor['worker_claim_owner_hash'] = hash('sha256', 'winner');
        $competitor['worker_claim_lease_expires_at'] = $testNow + 60;
        test_set($path, $competitor);
        return ['ok' => false, 'status' => 412];
    }
    if ($etag !== 'E' . (string)($versions[$path] ?? 0)) {
        return ['ok' => false, 'status' => 412];
    }
    test_set($path, $data);
    return ['ok' => true, 'status' => 200];
}

function fb_delete_if_match(string $path, string $etag): array
{
    global $versions;
    if ($etag !== 'E' . (string)($versions[$path] ?? 0)) {
        return ['ok' => false, 'status' => 412];
    }
    test_delete($path);
    return ['ok' => true, 'status' => 200];
}

function now_ts(): int
{
    global $testNow;
    return $testNow;
}

function normalize_operator($value): string
{
    return strtoupper(trim((string)$value));
}

function get_operator_runtime(string $operator): array
{
    return ['dial_template' => '*123*{NUMBER}*{AMOUNT}#', 'masked_template' => '*123*...#'];
}

function get_operator_private_config(string $operator): array
{
    return ['retailer_secret_pin' => 'fixture-pin'];
}

function update_request_status(string $requestId, string $status, string $message, array $extra = []): bool
{
    return true;
}

require_once dirname(__DIR__) . '/api/lib/worker.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$pending = [
    'request_id' => 'REQ_MAIN',
    'uid' => 'USER_1',
    'status' => 'PENDING',
    'operator' => 'GP',
    'amount' => 500,
    'topup_amount_bdt' => 500,
    'wallet_debit_amount' => 16.13,
    'wallet_debit_currency' => 'MYR',
];
test_set('TOPUP_REQUESTS/PENDING/REQ_MAIN', $pending);
$mainClaim = worker_claim_pending_request('REQ_MAIN', 'DEVICE_A', 'SIM1', 'MAIN');
assert_true(is_array($mainClaim), 'main worker should claim a normal pending request');
assert_true(test_get('TOPUP_REQUESTS/PENDING/REQ_MAIN') === null, 'claimed pending row should be owner-checked deleted');
assert_true(is_array(test_get('TOPUP_REQUESTS/CLAIMED/REQ_MAIN')), 'claimed row should be persisted');
$mainPayload = worker_claim_payload($mainClaim);
assert_true((float)($mainPayload['amount'] ?? 0) === 500.0, 'worker payload must receive the original BDT service amount, not the MYR wallet debit');
assert_true(worker_claim_pending_request('REQ_MAIN', 'DEVICE_B', 'SIM1', 'MAIN') === null, 'duplicate worker claim must not win');

$builder = array_merge($pending, [
    'request_id' => 'REQ_BUILDER',
    'source' => 'Z_BUILDER_TEST',
    'test_mode' => true,
    'z_builder_owner_id' => 'OWNER_1',
]);
test_set('TOPUP_REQUESTS/PENDING/REQ_BUILDER', $builder);
assert_true(worker_claim_pending_request('REQ_BUILDER', 'DEVICE_A', 'SIM1', 'MAIN') === null, 'main worker must not claim Z Builder rows');
assert_true(worker_claim_pending_request('REQ_BUILDER', 'DEVICE_A', 'SIM1', 'Z_BUILDER', 'OWNER_2') === null, 'wrong Z Builder owner must not claim row');
assert_true(is_array(worker_claim_pending_request('REQ_BUILDER', 'DEVICE_A', 'SIM1', 'Z_BUILDER', 'OWNER_1')), 'matching Z Builder owner should claim isolated row');

$race = array_merge($pending, ['request_id' => 'REQ_RACE']);
test_set('TOPUP_REQUESTS/PENDING/REQ_RACE', $race);
$injectClaimPath = 'TOPUP_REQUESTS/PENDING/REQ_RACE';
$raceLoser = worker_claim_pending_request('REQ_RACE', 'DEVICE_LOSER', 'SIM1', 'MAIN');
assert_true($raceLoser === null, 'CAS loser must not claim a concurrently won request');
assert_true((string)(test_get('TOPUP_REQUESTS/PENDING/REQ_RACE')['assigned_device_id'] ?? '') === 'DEVICE_WINNER', 'CAS winner must remain authoritative');

$device = [
    'online' => true,
    'worker_enabled' => true,
    'accessibility_enabled' => true,
    'sim_slots' => ['SIM1' => ['operator' => 'GP', 'active' => true]],
];
test_set('TOPUP_REQUESTS/CLAIMED/REQ_STALE', [
    'request_id' => 'REQ_STALE',
    'uid' => 'USER_2',
    'status' => 'CLAIMED',
    'operator' => 'GP',
    'assigned_device_id' => 'DEVICE_OLD',
    'worker_claim_owner_hash' => hash('sha256', 'old'),
    'worker_claim_lease_expires_at' => $testNow - 1,
    'worker_claim_attempt_count' => 1,
]);
$takeover = worker_reclaim_stale_request('DEVICE_NEW', $device, 'MAIN');
assert_true(is_array($takeover), 'expired worker claim should be reclaimed');
assert_true((string)($takeover['assigned_device_id'] ?? '') === 'DEVICE_NEW', 'stale takeover should replace worker owner');
assert_true((int)($takeover['worker_claim_attempt_count'] ?? 0) === 2, 'stale takeover should increment attempt count');

test_set('TOPUP_REQUESTS/CLAIMED/REQ_PROCESS', [
    'request_id' => 'REQ_PROCESS',
    'status' => 'CLAIMED',
    'operator' => 'GP',
    'assigned_device_id' => 'DEVICE_A',
    'worker_claim_owner_hash' => hash('sha256', 'process-owner'),
    'worker_claim_lease_expires_at' => $testNow + 60,
]);
assert_true(!worker_mark_processing('REQ_PROCESS', 'DEVICE_B', 'SIM1', '*masked#'), 'wrong worker must not transition claimed request');
assert_true(worker_mark_processing('REQ_PROCESS', 'DEVICE_A', 'SIM1', '*masked#'), 'assigned worker should transition request once');
assert_true(worker_mark_processing('REQ_PROCESS', 'DEVICE_A', 'SIM1', '*masked#'), 'same worker retry should be idempotent');
assert_true(!worker_mark_processing('REQ_PROCESS', 'DEVICE_B', 'SIM1', '*masked#'), 'different worker must not replay processing transition');

echo "worker claim CAS tests passed\n";
