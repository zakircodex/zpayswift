<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

function fb_get(string $path)
{
    return null;
}

function normalize_operator($value): string
{
    $value = strtoupper(trim((string)$value));
    return match ($value) {
        'BANGLALINK' => 'BL',
        'TELETALK' => 'TT',
        default => $value,
    };
}

require_once dirname(__DIR__) . '/api/lib/topup_config.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assert_true(topup_effective_min_amount('BD', 20.0) === 20.0, 'BD effective minimum must be BDT 20');
assert_true(topup_effective_min_amount('BD', 500.0) === 20.0, 'stale configured BD minimum must normalize to BDT 20');
assert_true(topup_effective_min_amount('MY', 5.0) === 20.0, 'all Top-Up service amounts must enforce the BDT 20 minimum');

$bdOperator = topup_normalize_operator_row([
    'code' => 'GP',
    'country_code' => 'BD',
    'min_amount' => 20,
    'max_amount' => 1000,
    'quick_amounts' => [20, 100, 500, 1000],
]);
assert_true((float)$bdOperator['min_amount'] === 20.0, 'BD catalog must expose the effective BDT 20 minimum');
assert_true((array)$bdOperator['quick_amounts'] === [20, 100, 500, 1000], 'BD quick amounts at or above BDT 20 must remain available');

$belowMinimum = topup_amount_validation('BD', 'GP', 19.00);
assert_true(empty($belowMinimum['ok']) && ($belowMinimum['code'] ?? '') === 'TOPUP_AMOUNT_MIN', 'BD amount BDT 19 must be rejected');
assert_true(($belowMinimum['message'] ?? '') === 'Minimum top-up amount is 20 BDT.', 'minimum error message must remain canonical');
$decimalBelowMinimum = topup_amount_validation('BD', 'GP', 19.99);
assert_true(empty($decimalBelowMinimum['ok']) && ($decimalBelowMinimum['code'] ?? '') === 'TOPUP_AMOUNT_MIN', 'BD amount BDT 19.99 must be rejected');
$atMinimum = topup_amount_validation('BD', 'GP', 20.00);
assert_true(!empty($atMinimum['ok']), 'BD amount BDT 20 must be accepted inclusively');
$legacyPreset = topup_amount_validation('BD', 'GP', 500.00);
assert_true(!empty($legacyPreset['ok']), 'BD amount BDT 500 must remain accepted');
$myBelowMinimum = topup_amount_validation('MY', 'DIGI', 5.00);
assert_true(empty($myBelowMinimum['ok']) && ($myBelowMinimum['code'] ?? '') === 'TOPUP_AMOUNT_MIN', 'MY destination must not restore the retired MYR 5 minimum');
$myMinimum = topup_amount_validation('MY', 'DIGI', 20.00);
assert_true(!empty($myMinimum['ok']), 'MY destination amount is BDT and must accept BDT 20');

if (!defined('MYR_TO_BDT_RATE')) {
    define('MYR_TO_BDT_RATE', 31.0);
}
require_once dirname(__DIR__) . '/api/lib/topup.php';

$myFinancials = topup_calculate_payment_context(
    'TOPUP_MY_TEST',
    20.0,
    ['uid' => 'TOPUP_MY_TEST', 'role' => 'USER', 'pricing_country' => 'MY', 'wallet_currency' => 'MYR'],
    ['available_balance' => 100.0, 'wallet_currency' => 'MYR', 'currency' => 'MYR'],
    [],
    'BD'
);
assert_true(!empty($myFinancials['ok']), 'MY account BDT 20 top-up calculation must succeed');
assert_true((float)$myFinancials['wallet_debit_amount'] === 0.65, 'MY wallet debit must use backend BDT-to-MYR rate');
assert_true((float)$myFinancials['amount_bdt'] === 20.0, 'MY account worker amount must remain original BDT 20');
assert_true((string)$myFinancials['wallet_debit_currency'] === 'MYR', 'MY account wallet debit currency must remain MYR');
assert_true((string)$myFinancials['topup_currency'] === 'BDT', 'MY account Top-Up service currency must remain BDT');
assert_true((string)$myFinancials['calculation_version'] === 'TOPUP_BDT_SERVICE_V4', 'new Top-Up calculations must use the BDT service version');

$bdFinancials = topup_calculate_payment_context(
    'TOPUP_BD_TEST',
    20.0,
    ['uid' => 'TOPUP_BD_TEST', 'role' => 'USER', 'pricing_country' => 'BD', 'wallet_currency' => 'BDT'],
    ['available_balance' => 100.0, 'wallet_currency' => 'BDT', 'currency' => 'BDT'],
    [],
    'BD'
);
assert_true(!empty($bdFinancials['ok']), 'BD account BDT 20 top-up calculation must succeed');
assert_true((float)$bdFinancials['wallet_debit_amount'] === 20.0, 'BD account wallet debit must remain exact BDT 20');
assert_true((string)$bdFinancials['wallet_debit_currency'] === 'BDT', 'BD account wallet debit currency must remain BDT');

$workerSource = file_get_contents(dirname(__DIR__) . '/api/lib/worker.php');
assert_true(is_string($workerSource) && str_contains($workerSource, "'amount_bdt' => (float)(\$done['amount_bdt'] ?? \$amount)"), 'worker payload must preserve original BDT amount');

$liveTopupSources = [
    'Android submit' => dirname(__DIR__) . '/api/topup/submit.php',
    'Admin create' => dirname(__DIR__) . '/api/admin/topup/create.php',
    'Public API create' => dirname(__DIR__) . '/api/public_api/topup_create.php',
    'Subadmin panel create' => dirname(__DIR__) . '/api/lib/subadmin_api.php',
    'User web create' => dirname(__DIR__) . '/api/user/proxy.php',
];
foreach ($liveTopupSources as $label => $path) {
    $source = file_get_contents($path);
    assert_true(is_string($source) && str_contains($source, 'topup_validate_request('), $label . ' must use backend-authoritative top-up validation');
}

$publicSource = file_get_contents(dirname(__DIR__) . '/api/public_api/topup_create.php');
$subadminSource = file_get_contents(dirname(__DIR__) . '/api/lib/subadmin_api.php');
$userProxySource = file_get_contents(dirname(__DIR__) . '/api/user/proxy.php');
assert_true(is_string($publicSource) && str_contains($publicSource, "\$topupRoleLimits['min_amount']"), 'Public API role limits must inherit canonical top-up minimum');
assert_true(is_string($subadminSource) && str_contains($subadminSource, "\$topupRoleLimits['min_amount']"), 'Subadmin role limits must inherit canonical top-up minimum');
assert_true(is_string($userProxySource) && str_contains($userProxySource, "\$topupRoleLimits['min_amount']"), 'User web role limits must inherit canonical top-up minimum');

echo "topup business rule tests passed\n";
