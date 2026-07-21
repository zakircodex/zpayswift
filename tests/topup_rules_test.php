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

assert_true(topup_effective_min_amount('BD', 20.0) === 500.0, 'BD effective minimum must be BDT 500');
assert_true(topup_effective_min_amount('BD', 700.0) === 700.0, 'higher configured BD minimum must remain effective');
assert_true(topup_effective_min_amount('MY', 5.0) === 5.0, 'MY configured minimum must remain unchanged');

$bdOperator = topup_normalize_operator_row([
    'code' => 'GP',
    'country_code' => 'BD',
    'min_amount' => 20,
    'max_amount' => 1000,
    'quick_amounts' => [20, 100, 500, 1000],
]);
assert_true((float)$bdOperator['min_amount'] === 500.0, 'BD catalog must expose the effective BDT 500 minimum');
assert_true((array)$bdOperator['quick_amounts'] === [500, 1000], 'BD quick amounts below BDT 500 must be removed');

$belowMinimum = topup_amount_validation('BD', 'GP', 499.99);
assert_true(empty($belowMinimum['ok']) && ($belowMinimum['code'] ?? '') === 'TOPUP_AMOUNT_MIN', 'BD amount below BDT 500 must be rejected');
$atMinimum = topup_amount_validation('BD', 'GP', 500.00);
assert_true(!empty($atMinimum['ok']), 'BD amount BDT 500 must be accepted inclusively');
$myMinimum = topup_amount_validation('MY', 'DIGI', 5.00);
assert_true(!empty($myMinimum['ok']), 'MY configured minimum must remain accepted');

echo "topup business rule tests passed\n";
