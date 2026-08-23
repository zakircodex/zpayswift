<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$assertions = 0;

function tier_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/api/lib/mfs_fee_tiers.php';

$defaults = mfs_default_my_fee_tiers();
$cases = [
    ['USER', 500.00, 5.00, 'TIER1'],
    ['USER', 50000.00, 5.00, 'TIER1'],
    ['USER', 50000.01, 7.00, 'TIER2'],
    ['USER', 50001.00, 7.00, 'TIER2'],
    ['USER', 70000.00, 7.00, 'TIER2'],
    ['USER', 70000.01, 10.00, 'TIER3'],
    ['USER', 70001.00, 10.00, 'TIER3'],
    ['USER', 100000.00, 10.00, 'TIER3'],
    ['RETAILER', 500.00, 2.00, 'TIER1'],
    ['RETAILER', 50000.00, 2.00, 'TIER1'],
    ['RETAILER', 50000.01, 3.00, 'TIER2'],
    ['RETAILER', 70000.00, 3.00, 'TIER2'],
    ['RETAILER', 70000.01, 4.00, 'TIER3'],
    ['RETAILER', 100000.00, 4.00, 'TIER3'],
    ['SUBADMIN', 500.00, 2.00, 'TIER1'],
    ['SUBADMIN', 50000.00, 2.00, 'TIER1'],
    ['SUBADMIN', 50000.01, 3.00, 'TIER2'],
    ['SUBADMIN', 70000.00, 3.00, 'TIER2'],
    ['SUBADMIN', 70000.01, 4.00, 'TIER3'],
    ['SUBADMIN', 100000.00, 4.00, 'TIER3'],
    ['ADMIN', 500.00, 0.00, 'TIER1'],
    ['ADMIN', 70000.00, 0.00, 'TIER2'],
    ['ADMIN', 100000.00, 0.00, 'TIER3'],
];

foreach ($cases as [$role, $amount, $fee, $tier]) {
    $resolved = mfs_resolve_my_fee_tier((float)$amount, (string)$role, $defaults);
    tier_expect(!empty($resolved['ok']), "{$role} {$amount} was rejected");
    tier_expect((float)$resolved['fee_rm'] === (float)$fee, "{$role} {$amount} resolved the wrong fee");
    tier_expect((string)$resolved['tier_id'] === $tier, "{$role} {$amount} resolved the wrong tier");
    tier_expect((string)$resolved['role'] === $role, "{$role} was not preserved as canonical role");
}

$overMaximum = mfs_resolve_my_fee_tier(100000.01, 'USER', $defaults);
tier_expect(empty($overMaximum['ok']) && ($overMaximum['code'] ?? '') === 'MFS_AMOUNT_OUT_OF_RANGE', 'Amount above BDT 100,000 was not rejected');
$underMinimum = mfs_resolve_my_fee_tier(499.99, 'USER', $defaults);
tier_expect(empty($underMinimum['ok']), 'Amount below BDT 500 was not rejected');

$legacyFlat = [
    'BKASH' => ['USER' => 99.00, 'RETAILER' => 88.00, 'SUBADMIN' => 77.00],
    'NAGAD' => ['USER' => 66.00, 'RETAILER' => 55.00, 'SUBADMIN' => 44.00],
];
$legacyFallback = mfs_resolve_my_fee_tier(70000.01, 'USER', $legacyFlat);
tier_expect((float)$legacyFallback['fee_rm'] === 10.00, 'Legacy provider-flat config overrode the canonical default tiers');

$custom = [
    'TIER1' => ['USER' => 6, 'RETAILER' => 2.5, 'SUBADMIN' => 2.25, 'ADMIN' => 0],
    'TIER2' => ['USER' => 8, 'RETAILER' => 3.5, 'SUBADMIN' => 3.25, 'ADMIN' => 0],
    'TIER3' => ['USER' => 11, 'RETAILER' => 4.5, 'SUBADMIN' => 4.25, 'ADMIN' => 0],
];
tier_expect((float)mfs_resolve_my_fee_tier(50000.01, 'USER', $custom)['fee_rm'] === 8.00, 'Custom Tier 2 USER fee was ignored');
tier_expect((float)mfs_resolve_my_fee_tier(70000.01, 'RETAILER', $custom)['fee_rm'] === 4.50, 'Custom Tier 3 RETAILER fee was ignored');
tier_expect((float)mfs_resolve_my_fee_tier(70000.01, 'SUBADMIN', $custom)['fee_rm'] === 4.25, 'Custom Tier 3 SUBADMIN fee was ignored');
tier_expect((float)mfs_resolve_my_fee_tier(70000.01, 'ADMIN', $custom)['fee_rm'] === 0.00, 'ADMIN was charged by custom config');

$root = dirname(__DIR__);
$mfs = (string)file_get_contents($root . '/api/lib/mfs.php');
$preview = (string)file_get_contents($root . '/api/mfs/preview.php');
$userProxy = (string)file_get_contents($root . '/api/user/proxy.php');
$adminCreate = (string)file_get_contents($root . '/api/admin/mfs/create.php');
$subadmin = (string)file_get_contents($root . '/api/subadmin/proxy.php');
$adminPage = (string)file_get_contents($root . '/api/admin/mfs.php');
$adminJs = (string)file_get_contents($root . '/api/admin/assets/mfs-panel.js');
$userJs = (string)file_get_contents($root . '/api/user/assets/pages/mfs-page.js');
$subadminJs = (string)file_get_contents($root . '/api/subadmin/assets/subadmin.js');
$configExample = (string)file_get_contents($root . '/api/config.example.php');

tier_expect(str_contains($mfs, 'mfs_resolve_my_fee_tier($amountBdt, $role'), 'Canonical MFS calculator does not use the shared tier helper');
tier_expect(str_contains($preview, 'mfs_resolve_my_fee_tier($amountBdt, $userRole'), 'Public MFS preview does not use the shared tier helper');
tier_expect(str_contains($userProxy, 'mfs_resolve_my_fee_tier($amountBdt, $role'), 'Compatibility MFS preview does not use the shared tier helper');
tier_expect(!str_contains($preview, 'function mfs_preview_my_fee_rm'), 'Standalone preview still contains duplicate flat MY fee logic');
tier_expect(str_contains($mfs, '$role = mfs_user_role($user);'), 'Canonical target account role is not authoritative');
tier_expect(str_contains($adminCreate, "mfs_create_request(\$uid, \$body, 'ADMIN_PANEL'"), 'Admin create no longer calculates against the target UID');
tier_expect(str_contains($subadmin, "mfs_create_request(\$uid, \$body, 'SUBADMIN_PANEL'"), 'Subadmin create no longer uses the canonical MFS helper');
tier_expect(str_contains($mfs, "\$user['pricing_country']") && !str_contains($mfs, "mfs_infer_country_from_phone((string)(\$user['phone']"), 'Phone country can select the canonical MFS market');
tier_expect(str_contains($mfs, "'maximum' => 100000.00"), 'Canonical BDT 100,000 maximum is missing');
tier_expect(str_contains($configExample, "define('MFS_MAX_AMOUNT_BDT', 100000.00);"), 'Example config still documents the old maximum');

foreach ([$adminPage, $adminJs, $userJs, $subadminJs, $userProxy, $preview] as $source) {
    tier_expect(!str_contains($source, '> 50000') && !str_contains($source, '>50000'), 'A hidden BDT 50,000 validator remains');
}

foreach (['mfsMyTier1UserFee', 'mfsMyTier2RetailerFee', 'mfsMyTier3SubadminFee', 'mfsTierFeesSaveBtn', 'mfsTierFeesReloadBtn'] as $id) {
    tier_expect(str_contains($adminPage, 'id="' . $id . '"'), "Admin tier control is missing: {$id}");
}
tier_expect(str_contains($adminJs, "post('mfs_my_fee_tiers_save'"), 'Admin tier form does not use the dedicated save action');
tier_expect(!str_contains($adminJs, 'Role fee USER RM'), 'Admin create preview still calculates Malaysia role fees in JavaScript');

echo "MFS tiered remittance fee tests passed ({$assertions} assertions).\n";
