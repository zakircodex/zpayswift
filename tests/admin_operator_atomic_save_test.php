<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$operatorAssertions = 0;
$operatorDb = [];
$operatorPatchCalls = [];
$operatorFailPatch = false;

function operator_expect(bool $condition, string $message): void
{
    global $operatorAssertions;
    $operatorAssertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function fb_get(string $path, array $query = []): mixed
{
    global $operatorDb;
    return $operatorDb[$path] ?? null;
}

function fb_patch(string $path, array $data): bool
{
    global $operatorDb, $operatorPatchCalls, $operatorFailPatch;
    $operatorPatchCalls[] = ['path' => $path, 'writes' => $data];
    if ($operatorFailPatch) {
        return false;
    }

    $next = $operatorDb;
    foreach ($data as $writePath => $value) {
        $next[$writePath] = $value;
    }
    $operatorDb = $next;
    return true;
}

require_once $root . '/api/lib/helpers.php';
require_once $root . '/api/lib/operators.php';

function operator_records(string $inputCode, int $revision, bool $active = true, array $extra = []): array
{
    $code = normalize_operator($inputCode);
    $catalog = array_merge([
        'code' => $code,
        'name' => $code . ' Mobile',
        'country_code' => 'BD',
        'service_type' => 'PREPAID',
        'active' => $active,
        'min_amount' => 20.0,
        'max_amount' => 1000.0,
        'quick_amounts' => [20, 100, 500],
        'prefixes' => ['017'],
        'sort_order' => 10,
        'catalog_metadata' => 'preserved',
    ], (array)($extra['catalog'] ?? []));

    $config = [
        'countries' => [[
            'code' => 'BD',
            'name' => 'Bangladesh',
            'currency' => 'BDT',
            'operators' => [$catalog],
        ]],
        'top_level_metadata' => 'preserved',
        'updated_at' => $revision,
        'updated_by' => 'ADMIN001',
        'updated_by_role' => 'ADMIN',
    ];

    $runtime = array_merge($catalog, [
        'dial_template' => '*123*{NUMBER}*{AMOUNT}*{PIN}#',
        'masked_template' => '*123*{NUMBER}*{AMOUNT}*****#',
        'requires_secret_pin' => true,
        'runtime_metadata' => 'preserved',
        'updated_at' => $revision,
    ], (array)($extra['runtime'] ?? []));

    $private = array_merge([
        'retailer_secret_pin' => 'fixture-pin',
        'private_runtime_metadata' => 'preserved',
        'updated_at' => $revision,
    ], (array)($extra['private'] ?? []));

    return compact('code', 'config', 'runtime', 'private');
}

function operator_reset(array $records): void
{
    global $operatorDb, $operatorPatchCalls, $operatorFailPatch;
    $code = (string)$records['code'];
    $operatorDb = [
        'TOPUP_CONFIG' => ['existing' => true, 'updated_at' => 1],
        'OPERATOR_RUNTIME/' . $code => ['code' => $code, 'old' => true, 'updated_at' => 1],
        'OPERATOR_PRIVATE/' . $code => ['retailer_secret_pin' => 'old-pin', 'old' => true, 'updated_at' => 1],
    ];
    $operatorPatchCalls = [];
    $operatorFailPatch = false;
}

foreach ([
    'GP' => 'GP',
    'ROBI' => 'ROBI',
    'AIRTEL' => 'AIRTEL',
    'BANGLALINK' => 'BL',
    'TELETALK' => 'TT',
] as $inputCode => $canonicalCode) {
    $records = operator_records($inputCode, 100);
    operator_reset($records);
    $saved = operator_config_save_atomic($inputCode, $records['config'], $records['runtime'], $records['private']);

    operator_expect($saved, "{$inputCode} atomic save failed");
    operator_expect(count($operatorPatchCalls) === 1 && $operatorPatchCalls[0]['path'] === '', "{$inputCode} used more than one database mutation");
    operator_expect($records['code'] === $canonicalCode, "{$inputCode} canonical Worker code changed");
    operator_expect(($operatorDb['TOPUP_CONFIG']['updated_at'] ?? 0) === 100, "{$inputCode} catalog revision missing");
    operator_expect(($operatorDb['OPERATOR_RUNTIME/' . $canonicalCode]['updated_at'] ?? 0) === 100, "{$inputCode} runtime revision missing");
    operator_expect(($operatorDb['OPERATOR_PRIVATE/' . $canonicalCode]['updated_at'] ?? 0) === 100, "{$inputCode} private revision missing");
}

$disabled = operator_records('GP', 200, false);
operator_reset($disabled);
operator_expect(operator_config_save_atomic('GP', $disabled['config'], $disabled['runtime'], $disabled['private']), 'disabled operator save failed');
operator_expect(($operatorDb['TOPUP_CONFIG']['countries'][0]['operators'][0]['active'] ?? true) === false, 'disabled catalog state changed');
operator_expect(($operatorDb['OPERATOR_RUNTIME/GP']['active'] ?? true) === false, 'disabled Worker runtime state changed');

$invalid = operator_records('GP', 300);
operator_reset($invalid);
operator_expect(!operator_config_save_atomic('INVALID', $invalid['config'], $invalid['runtime'], $invalid['private']), 'invalid operator was accepted');
operator_expect(count($operatorPatchCalls) === 0, 'invalid operator mutated Firebase');

$malformed = operator_records('GP', 400);
$malformed['runtime']['max_amount'] = 999.0;
operator_reset($malformed);
operator_expect(!operator_config_save_atomic('GP', $malformed['config'], $malformed['runtime'], $malformed['private']), 'mismatched in-memory config was accepted');
operator_expect(count($operatorPatchCalls) === 0, 'malformed config mutated Firebase');

$failure = operator_records('ROBI', 500);
operator_reset($failure);
$beforeFailure = $operatorDb;
$operatorFailPatch = true;
operator_expect(!operator_config_save_atomic('ROBI', $failure['config'], $failure['runtime'], $failure['private']), 'atomic Firebase failure reported success');
operator_expect(count($operatorPatchCalls) === 1, 'failed save did not remain one atomic mutation');
operator_expect($operatorDb === $beforeFailure, 'failed atomic save partially changed one or more paths');

$preserved = operator_records('AIRTEL', 600);
operator_reset($preserved);
operator_expect(operator_config_save_atomic('AIRTEL', $preserved['config'], $preserved['runtime'], $preserved['private']), 'preservation fixture save failed');
operator_expect(($operatorDb['TOPUP_CONFIG']['top_level_metadata'] ?? '') === 'preserved', 'unsent catalog metadata was removed');
operator_expect(($operatorDb['OPERATOR_RUNTIME/AIRTEL']['runtime_metadata'] ?? '') === 'preserved', 'unsent runtime metadata was removed');
operator_expect(($operatorDb['OPERATOR_PRIVATE/AIRTEL']['private_runtime_metadata'] ?? '') === 'preserved', 'unsent private metadata was removed');

$first = operator_records('GP', 700, true, ['runtime' => ['save_marker' => 'first'], 'private' => ['save_marker' => 'first']]);
$second = operator_records('GP', 701, false, ['runtime' => ['save_marker' => 'second'], 'private' => ['save_marker' => 'second']]);
operator_reset($first);
operator_expect(operator_config_save_atomic('GP', $first['config'], $first['runtime'], $first['private']), 'first complete concurrent-save fixture failed');
operator_expect(operator_config_save_atomic('GP', $second['config'], $second['runtime'], $second['private']), 'second complete concurrent-save fixture failed');
operator_expect(($operatorDb['TOPUP_CONFIG']['updated_at'] ?? 0) === 701, 'last complete save catalog revision is stale');
operator_expect(($operatorDb['OPERATOR_RUNTIME/GP']['updated_at'] ?? 0) === 701, 'last complete save runtime revision is stale');
operator_expect(($operatorDb['OPERATOR_PRIVATE/GP']['updated_at'] ?? 0) === 701, 'last complete save private revision is stale');
operator_expect(($operatorDb['OPERATOR_RUNTIME/GP']['active'] ?? true) === false, 'complete last save left mixed active state');
operator_expect(($operatorDb['OPERATOR_RUNTIME/GP']['save_marker'] ?? '') === 'second' && ($operatorDb['OPERATOR_PRIVATE/GP']['save_marker'] ?? '') === 'second', 'complete last save mixed runtime/private records');

$saveSource = file_get_contents($root . '/api/admin/operators/save.php') ?: '';
$operatorsSource = file_get_contents($root . '/api/lib/operators.php') ?: '';
$listSource = file_get_contents($root . '/api/admin/operators/list.php') ?: '';
$getSource = file_get_contents($root . '/api/admin/operators/get.php') ?: '';
$proxySource = file_get_contents($root . '/api/admin/proxy.php') ?: '';
$workerSource = file_get_contents($root . '/api/lib/worker.php') ?: '';

operator_expect(str_contains($operatorsSource, "fb_patch('', \$writes)"), 'operator helper does not use Firebase root atomic PATCH');
operator_expect(!str_contains($saveSource, "fb_put('TOPUP_CONFIG'") && !str_contains($saveSource, "fb_put('OPERATOR_RUNTIME/'") && !str_contains($saveSource, "fb_put('OPERATOR_PRIVATE/'"), 'sequential three-write implementation remains reachable');
operator_expect(substr_count($saveSource, 'operator_config_save_atomic(') === 1, 'operator endpoint does not perform exactly one canonical atomic save');
operator_expect(str_contains($saveSource, '$private = array_merge($existingPrivate'), 'unsent private fields are not merged before save');
operator_expect(strpos($saveSource, 'operator_config_save_atomic(') < strpos($saveSource, "admin_action_log('SAVE_OPERATOR'"), 'success audit log runs before database success');
operator_expect(strpos($saveSource, 'operator_config_save_atomic(') < strpos($saveSource, "system_log('ADMIN_SAVE_OPERATOR'"), 'success system log runs before database success');
operator_expect(str_contains($saveSource, "'Failed to save operator settings'"), 'database failure is not mapped to a safe generic error');
operator_expect(str_contains($proxySource, "case 'operator_save':") && str_contains($proxySource, "operators/save.php"), 'Admin proxy action/route changed');
operator_expect(str_contains($workerSource, "'dial_template'") && str_contains($workerSource, "'retailer_secret_pin'") && str_contains($workerSource, "'assigned_slot'"), 'Worker-facing field names changed');

$getResponse = substr($getSource, (int)strrpos($getSource, 'api_response('));
$listResponse = substr($listSource, (int)strrpos($listSource, 'api_response('));
operator_expect(!str_contains($getResponse, "'retailer_secret_pin' =>") && !str_contains($listResponse, "'retailer_secret_pin' =>"), 'private retailer PIN entered an Admin frontend response');
operator_expect(str_contains($getResponse, 'retailer_secret_pin_set') && str_contains($getResponse, 'retailer_secret_pin_masked'), 'safe private PIN indicators were removed');

echo "Admin Operator atomic save tests passed ({$operatorAssertions} assertions).\n";
