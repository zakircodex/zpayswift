<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$GLOBALS['topup_contract_reads'] = [];

function fb_get(string $path)
{
    $GLOBALS['topup_contract_reads'][] = $path;
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

function now_ts(): int
{
    return 1700000000;
}

require_once dirname(__DIR__) . '/api/lib/topup_config.php';

function topup_contract_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

topup_contract_expect(topup_effective_min_amount('BD', 500) === 20.0, 'BD stale minimum must normalize to BDT 20');
topup_contract_expect(topup_effective_min_amount('MY', 5) === 20.0, 'MY destination must use the BDT 20 service minimum');

$normalizedMy = topup_normalize_country_row([
    'code' => 'MY',
    'currency' => 'MYR',
    'operators' => [[
        'code' => 'DIGI',
        'name' => 'Digi',
        'active' => true,
        'min_amount' => 5,
        'max_amount' => 200,
    ]],
], topup_default_config()['countries'][1], false);
topup_contract_expect(($normalizedMy['currency'] ?? '') === 'BDT', 'stored destination currency cannot change the BDT service denomination');
topup_contract_expect((float)($normalizedMy['operators'][1]['min_amount'] ?? 0) === 20.0, 'stored MYR minimum cannot lower BDT service minimum');

if (!defined('MYR_TO_BDT_RATE')) {
    define('MYR_TO_BDT_RATE', 31.0);
}
require_once dirname(__DIR__) . '/api/lib/topup.php';

topup_contract_expect(topup_calculation_version() === 'TOPUP_BDT_SERVICE_V4', 'calculation version must identify BDT service semantics');
topup_contract_expect(topup_destination_currency('BD') === 'BDT', 'BD destination service currency must be BDT');
topup_contract_expect(topup_destination_currency('MY') === 'BDT', 'MY destination cannot create an MYR service amount');

$bdReadsBefore = count($GLOBALS['topup_contract_reads']);
$bdUser = topup_calculate_payment_context(
    'BD_USER',
    20,
    ['uid' => 'BD_USER', 'role' => 'USER', 'pricing_country' => 'BD', 'phone_country' => 'MY'],
    ['available_balance' => 100, 'wallet_currency' => 'BDT'],
    ['commission_per_1000' => 0],
    'MY'
);
topup_contract_expect(!empty($bdUser['ok']), 'BD USER calculation must succeed');
topup_contract_expect(count($GLOBALS['topup_contract_reads']) === $bdReadsBefore, 'BD wallet calculation must not read an MY rate');
topup_contract_expect((float)$bdUser['service_amount_bdt'] === 20.0, 'BD service amount must remain BDT 20');
topup_contract_expect((float)$bdUser['wallet_debit_amount'] === 20.0 && $bdUser['wallet_debit_currency'] === 'BDT', 'BD wallet debit must remain BDT 20');
topup_contract_expect(empty($bdUser['rate_applicable']) && $bdUser['rate_snapshot'] === null, 'BD wallet must not expose a rate');
topup_contract_expect((float)$bdUser['commission_amount'] === 0.0, 'USER default commission must remain zero');

$bdUserFiveHundred = topup_calculate_payment_context(
    'BD_USER_500',
    500,
    ['uid' => 'BD_USER_500', 'role' => 'USER', 'pricing_country' => 'BD', 'phone_country' => 'MY'],
    ['available_balance' => 1000, 'wallet_currency' => 'BDT'],
    ['commission_per_1000' => 0],
    'MY'
);
topup_contract_expect((float)$bdUserFiveHundred['service_amount_bdt'] === 500.0, 'BD USER service amount must remain BDT 500');
topup_contract_expect((float)$bdUserFiveHundred['wallet_debit_amount'] === 500.0 && $bdUserFiveHundred['rate_snapshot'] === null, 'BD USER BDT 500 must debit BDT 500 without a rate');

$bdRetailer = topup_calculate_payment_context(
    'BD_RETAILER',
    500,
    ['uid' => 'BD_RETAILER', 'role' => 'RETAILER', 'pricing_country' => 'BD'],
    ['available_balance' => 1000, 'wallet_currency' => 'BDT'],
    ['commission_per_1000' => 18],
    'BD'
);
topup_contract_expect((float)$bdRetailer['commission_amount'] === 9.0, 'BD RETAILER commission must remain BDT 9 per configured rule');
topup_contract_expect((float)$bdRetailer['wallet_debit_amount'] === 491.0, 'BD commission remains a discount before debit');

$myUser = topup_calculate_payment_context(
    'MY_USER',
    500,
    ['uid' => 'MY_USER', 'role' => 'USER', 'pricing_country' => 'MY', 'phone_country' => 'BD'],
    ['available_balance' => 100, 'wallet_currency' => 'MYR'],
    ['commission_per_1000' => 0],
    'MY'
);
topup_contract_expect(!empty($myUser['ok']), 'MY USER calculation must succeed');
topup_contract_expect((float)$myUser['service_amount_bdt'] === 500.0 && (float)$myUser['topup_amount_bdt'] === 500.0, 'MY USER service and worker amount must remain BDT 500');
topup_contract_expect($myUser['topup_currency'] === 'BDT' && $myUser['wallet_debit_currency'] === 'MYR', 'service and wallet currencies must remain distinct');
topup_contract_expect((float)$myUser['rate_snapshot'] === 31.0 && !empty($myUser['rate_applicable']), 'MY wallet must use canonical backend rate');
topup_contract_expect((float)$myUser['topup_amount_myr'] === 16.13 && (float)$myUser['wallet_debit_amount'] === 16.13, 'MY USER debit must be BDT 500 divided by rate');

$myUserMinimum = topup_calculate_payment_context(
    'MY_USER_20',
    20,
    ['uid' => 'MY_USER_20', 'role' => 'USER', 'pricing_country' => 'MY', 'phone_country' => 'BD'],
    ['available_balance' => 100, 'wallet_currency' => 'MYR'],
    ['commission_per_1000' => 0],
    'BD'
);
topup_contract_expect((float)$myUserMinimum['service_amount_bdt'] === 20.0, 'MY USER minimum service amount must remain BDT 20');
topup_contract_expect((float)$myUserMinimum['wallet_debit_amount'] === 0.65 && (float)$myUserMinimum['rate_snapshot'] === 31.0, 'MY USER BDT 20 must debit canonical rounded MYR amount');

foreach (['RETAILER', 'SUBADMIN'] as $role) {
    $financials = topup_calculate_payment_context(
        'MY_' . $role,
        500,
        ['uid' => 'MY_' . $role, 'role' => $role, 'pricing_country' => 'MY', 'phone_country' => 'BD'],
        ['available_balance' => 100, 'wallet_currency' => 'MYR'],
        ['commission_per_1000' => 18],
        'BD'
    );
    topup_contract_expect((float)$financials['commission_amount'] === 9.0, $role . ' commission snapshot must remain BDT 9');
    topup_contract_expect((float)$financials['wallet_debit_bdt'] === 491.0, $role . ' discounted BDT payable must remain 491');
    topup_contract_expect((float)$financials['wallet_debit_amount'] === 15.84, $role . ' MYR wallet debit must convert discounted BDT payable');
    topup_contract_expect((float)$financials['topup_amount_bdt'] === 500.0, $role . ' worker amount must remain original BDT 500');
}

$admin = topup_calculate_payment_context(
    'MY_ADMIN',
    500,
    ['uid' => 'MY_ADMIN', 'role' => 'ADMIN', 'pricing_country' => 'MY'],
    ['available_balance' => 100, 'wallet_currency' => 'MYR'],
    ['commission_per_1000' => 0],
    'BD'
);
topup_contract_expect((float)$admin['commission_amount'] === 0.0, 'ADMIN zero-commission semantics must remain unchanged');

$snapshot = topup_pending_request_row('REQ_V4', 'MY_USER', '60123456789', '01712345678', 'GP', 500, $myUser, ['country_code' => 'BD']);
topup_contract_expect((float)$snapshot['amount'] === 500.0 && (float)$snapshot['service_amount_bdt'] === 500.0, 'request snapshot must preserve original BDT service amount');
topup_contract_expect((float)$snapshot['wallet_debit_amount'] === 16.13 && $snapshot['wallet_debit_currency'] === 'MYR', 'request snapshot must preserve exact MYR debit');
topup_contract_expect((float)$snapshot['rate_snapshot'] === 31.0 && $snapshot['calculation_version'] === 'TOPUP_BDT_SERVICE_V4', 'request snapshot must preserve rate and calculation version');

$workerSource = (string)file_get_contents(dirname(__DIR__) . '/api/lib/worker.php');
topup_contract_expect(str_contains($workerSource, "\$claimed['topup_amount_bdt'] ?? \$claimed['amount_bdt'] ?? \$claimed['amount']"), 'Worker payload must prefer canonical BDT service amount');

$submitSource = (string)file_get_contents(dirname(__DIR__) . '/api/topup/submit.php');
topup_contract_expect(!str_contains($submitSource, "\$body['rate']") && !str_contains($submitSource, "\$body['commission']") && !str_contains($submitSource, "\$body['wallet_debit']"), 'submit must not trust client financial values');
topup_contract_expect(str_contains($submitSource, "\$preview['financials']") && str_contains($submitSource, "\$preview['wallet_debit_amount']"), 'submit must consume the server-owned preview financial snapshot');

$previewSource = (string)file_get_contents(dirname(__DIR__) . '/api/topup/preview.php');
topup_contract_expect(str_contains($previewSource, 'topup_calculate_payment_context(') && str_contains($previewSource, "'financials' => \$financials"), 'preview must persist the shared canonical financial calculation');

$adminSource = (string)file_get_contents(dirname(__DIR__) . '/api/admin/topup/create.php');
$subadminSource = (string)file_get_contents(dirname(__DIR__) . '/api/lib/subadmin_api.php');
$publicSource = (string)file_get_contents(dirname(__DIR__) . '/api/public_api/topup_create.php');
topup_contract_expect(str_contains($adminSource, 'topup_commission_breakdown('), 'Admin Direct Top-Up must retain the shared canonical calculator');
topup_contract_expect(str_contains($subadminSource, 'topup_calculate_payment_context('), 'Subadmin Top-Up must retain the shared canonical calculator');
topup_contract_expect(str_contains($publicSource, 'topup_calculate_payment_context('), 'Public API Top-Up must retain the shared canonical calculator');

$topupSource = (string)file_get_contents(dirname(__DIR__) . '/api/lib/topup.php');
topup_contract_expect(str_contains($topupSource, "\$row['wallet_hold_amount']") && str_contains($topupSource, "\$done['refund_amount'] = (float)(\$resolvedHold['amount']"), 'failed Top-Up must refund the immutable held wallet amount');
topup_contract_expect(str_contains($topupSource, "\$done['refund_currency'] = (string)(\$resolvedHold['wallet_currency']"), 'failed Top-Up must preserve the immutable wallet refund currency');

echo "topup BDT service contract tests passed\n";
