<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$mfsSettingsAssertions = 0;
$mfsSettingsDb = [];
$mfsSettingsWrites = [];
$mfsFcmSends = 0;
$mfsRateLogSequence = 0;
$mfsNow = 1700000000;

function mfs_settings_expect(bool $condition, string $message): void
{
    global $mfsSettingsAssertions;
    $mfsSettingsAssertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function mfs_settings_path_parts(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
}

function mfs_settings_get_path(string $path)
{
    global $mfsSettingsDb;
    $value = $mfsSettingsDb;
    foreach (mfs_settings_path_parts($path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

function mfs_settings_put_path(string $path, $value): void
{
    global $mfsSettingsDb;
    $parts = mfs_settings_path_parts($path);
    $node =& $mfsSettingsDb;
    foreach ($parts as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
    $node = $value;
}

function fb_get(string $path, array $query = [])
{
    return mfs_settings_get_path($path);
}

function fb_put(string $path, $value): bool
{
    global $mfsSettingsWrites;
    $mfsSettingsWrites[] = ['method' => 'PUT', 'path' => $path, 'data' => $value];
    mfs_settings_put_path($path, $value);
    return true;
}

function fb_patch(string $path, array $data): bool
{
    global $mfsSettingsWrites;
    $mfsSettingsWrites[] = ['method' => 'PATCH', 'path' => $path, 'data' => $data];
    $current = fb_get($path);
    $current = is_array($current) ? $current : [];
    mfs_settings_put_path($path, array_merge($current, $data));
    return true;
}

function now_ts(): int
{
    global $mfsNow;
    return $mfsNow;
}

function make_log_id(): string
{
    global $mfsRateLogSequence;
    $mfsRateLogSequence++;
    return 'RATE_LOG_' . $mfsRateLogSequence;
}

function fcm_send_to_user(string $uid, string $title, string $body, array $data = [], string $idempotencyKey = ''): array
{
    global $mfsFcmSends;
    $mfsFcmSends++;
    return ['ok' => true, 'sent' => 0, 'failed' => 0, 'code' => 'TEST_FCM'];
}

function mfs_config(bool $refresh = false): array
{
    return [
        'rate_myr_bdt' => (float)(fb_get('MFS_SETTINGS/rate_myr_bdt') ?? 0),
        'fees' => (array)(fb_get('MFS_SETTINGS/fees') ?? []),
    ];
}

function mfs_public_settings(): array
{
    return [
        'rate_myr_bdt' => zpay_myr_to_bdt_rate(true),
        'fees' => (array)(fb_get('MFS_SETTINGS/fees') ?? []),
    ];
}

require_once dirname(__DIR__) . '/api/lib/rates.php';
require_once dirname(__DIR__) . '/api/lib/mfs_admin_settings.php';

$initialFees = [
    'MY' => [
        'BKASH' => ['type' => 'fixed', 'fixed' => 5.0, 'fee_rm' => 5.0, 'USER' => 5.0, 'RETAILER' => 2.0, 'SUBADMIN' => 2.0, 'ADMIN' => 0.0],
        'NAGAD' => ['type' => 'fixed', 'fixed' => 6.0, 'fee_rm' => 6.0, 'USER' => 6.0, 'RETAILER' => 2.5, 'SUBADMIN' => 2.25, 'ADMIN' => 0.0],
    ],
    'BD' => [
        'BKASH' => ['type' => 'fixed', 'fixed' => 5.0, 'percent' => 0.0, 'min_fee' => 1.0, 'max_fee' => 10.0],
        'NAGAD' => ['type' => 'percent', 'fixed' => 0.0, 'percent' => 1.5, 'min_fee' => 2.0, 'max_fee' => 20.0],
    ],
];

$mfsSettingsDb = [
    'MFS_SETTINGS' => [
        'rate_myr_bdt' => 31.0,
        'myr_to_bdt_rate' => 31.0,
        'rates' => ['myr_to_bdt' => 31.0],
        'fees' => $initialFees,
        'updated_at' => 1600000000,
        'updated_by_uid' => 'ADMIN_OLD',
        'updated_by_role' => 'ADMIN_PANEL',
    ],
    'MFS_CONFIG' => ['myr_to_bdt_rate' => 31.0, 'rates' => ['myr_to_bdt' => 31.0]],
    'APP_CONFIG' => ['MYR_TO_BDT_RATE' => 31.0, 'RINGGIT_RATE' => 31.0],
];

$rateState = mfs_admin_rate_state();
mfs_settings_expect((float)$rateState['rate_myr_bdt'] === 31.0, 'Rate GET did not return the canonical current rate');

$feesBeforeRateSave = fb_get('MFS_SETTINGS/fees');
$rateResult = mfs_admin_save_rate(32.25, 'ADMIN_TEST');
mfs_settings_expect(!empty($rateResult['ok']), 'Rate Save failed');
mfs_settings_expect((float)fb_get('MFS_SETTINGS/rate_myr_bdt') === 32.25, 'Primary MFS rate was not updated');
mfs_settings_expect((float)fb_get('MFS_SETTINGS/myr_to_bdt_rate') === 32.25, 'MFS compatibility rate was not updated');
mfs_settings_expect((float)fb_get('MFS_SETTINGS/rates/myr_to_bdt') === 32.25, 'MFS nested rate mirror was not updated');
mfs_settings_expect((float)fb_get('MFS_CONFIG/myr_to_bdt_rate') === 32.25, 'MFS_CONFIG rate mirror was not updated');
mfs_settings_expect((float)fb_get('APP_CONFIG/MYR_TO_BDT_RATE') === 32.25, 'APP_CONFIG rate mirror was not updated');
mfs_settings_expect(fb_get('MFS_SETTINGS/fees') === $feesBeforeRateSave, 'Rate Save changed one or more fee fields');
mfs_settings_expect(count((array)fb_get('RATE_CHANGE_LOG')) === 1, 'Canonical rate change log was not preserved');
mfs_settings_expect(count((array)fb_get('ADMIN_NOTICE_BROADCASTS')) === 1, 'Canonical rate notification broadcast was not preserved');
mfs_settings_expect($mfsFcmSends === 0, 'Rate test unexpectedly attempted a real recipient push');
mfs_settings_expect((float)mfs_public_settings()['rate_myr_bdt'] === 32.25, 'MFS preview/config reader did not see the newly saved rate');

$feePayload = [
    'fees' => [
        'MY' => [
            'BKASH' => ['USER' => 7.0, 'RETAILER' => 3.0, 'SUBADMIN' => 2.75, 'ADMIN' => 0],
            'NAGAD' => ['USER' => 8.0, 'RETAILER' => 3.5, 'SUBADMIN' => 3.0, 'ADMIN' => 0],
        ],
        'BD' => [
            'BKASH' => ['type' => 'fixed', 'fixed' => 6.0, 'percent' => 0.0, 'min_fee' => 1.0, 'max_fee' => 12.0],
            'NAGAD' => ['type' => 'percent', 'fixed' => 0.0, 'percent' => 1.75, 'min_fee' => 2.0, 'max_fee' => 22.0],
        ],
    ],
];
$ratePaths = [
    'MFS_SETTINGS/rate_myr_bdt',
    'MFS_SETTINGS/myr_to_bdt_rate',
    'MFS_SETTINGS/rates/myr_to_bdt',
    'MFS_CONFIG/myr_to_bdt_rate',
    'MFS_CONFIG/rates/myr_to_bdt',
    'APP_CONFIG/MYR_TO_BDT_RATE',
    'APP_CONFIG/RINGGIT_RATE',
];
$rateSnapshot = [];
foreach ($ratePaths as $path) {
    $rateSnapshot[$path] = fb_get($path);
}
$writesBeforeFeeSave = count($mfsSettingsWrites);
$notificationsBeforeFeeSave = count((array)fb_get('ADMIN_NOTICE_BROADCASTS'));
$logsBeforeFeeSave = count((array)fb_get('RATE_CHANGE_LOG'));
$feeResult = mfs_admin_save_fees($feePayload);
mfs_settings_expect(!empty($feeResult['ok']), 'Fee Save failed');
foreach ($rateSnapshot as $path => $value) {
    mfs_settings_expect(fb_get($path) === $value, "Fee Save changed rate path: {$path}");
}
mfs_settings_expect(count((array)fb_get('ADMIN_NOTICE_BROADCASTS')) === $notificationsBeforeFeeSave, 'Fee Save emitted a rate notification');
mfs_settings_expect(count((array)fb_get('RATE_CHANGE_LOG')) === $logsBeforeFeeSave, 'Fee Save wrote a rate change log');
$feeWrites = array_slice($mfsSettingsWrites, $writesBeforeFeeSave);
mfs_settings_expect(count($feeWrites) === 1, 'Fee Save must perform one fee-only database patch');
mfs_settings_expect($feeWrites[0]['method'] === 'PATCH' && $feeWrites[0]['path'] === 'MFS_SETTINGS', 'Fee Save used an unexpected Firebase path');
mfs_settings_expect(array_keys((array)$feeWrites[0]['data']) === ['fees'], 'Fee Save database payload included non-fee fields');

$savedFees = (array)fb_get('MFS_SETTINGS/fees');
mfs_settings_expect((float)$savedFees['MY']['BKASH']['USER'] === 7.0, 'MY bKash USER fee changed semantics');
mfs_settings_expect((float)$savedFees['MY']['BKASH']['RETAILER'] === 3.0, 'MY bKash RETAILER fee changed semantics');
mfs_settings_expect((float)$savedFees['MY']['BKASH']['SUBADMIN'] === 2.75, 'MY bKash SUBADMIN fee changed semantics');
mfs_settings_expect((float)$savedFees['MY']['NAGAD']['USER'] === 8.0 && (float)$savedFees['MY']['NAGAD']['RETAILER'] === 3.5, 'MY Nagad role fees changed semantics');
mfs_settings_expect($savedFees['BD']['BKASH']['type'] === 'fixed' && (float)$savedFees['BD']['BKASH']['fixed'] === 6.0, 'BD bKash fee rule changed semantics');
mfs_settings_expect($savedFees['BD']['NAGAD']['type'] === 'percent' && (float)$savedFees['BD']['NAGAD']['percent'] === 1.75, 'BD Nagad fee rule changed semantics');

$beforeInvalidRate = $mfsSettingsDb;
$invalidRate = mfs_admin_save_rate(10.0, 'ADMIN_TEST');
mfs_settings_expect(empty($invalidRate['ok']) && ($invalidRate['code'] ?? '') === 'INVALID_RATE_RANGE', 'Invalid rate was not rejected');
mfs_settings_expect($mfsSettingsDb === $beforeInvalidRate, 'Invalid rate mutated Firebase');

$beforeInvalidFee = $mfsSettingsDb;
$invalidFee = mfs_admin_save_fees(['fees' => ['MY' => ['BKASH' => ['USER' => -1]]]]);
mfs_settings_expect(empty($invalidFee['ok']) && ($invalidFee['code'] ?? '') === 'INVALID_FEE', 'Negative fee was not rejected');
mfs_settings_expect($mfsSettingsDb === $beforeInvalidFee, 'Invalid fee mutated Firebase');

$root = dirname(__DIR__);
$proxy = (string)file_get_contents($root . '/api/admin/proxy.php');
$helper = (string)file_get_contents($root . '/api/lib/mfs_admin_settings.php');
$page = (string)file_get_contents($root . '/api/admin/mfs.php');
$js = (string)file_get_contents($root . '/api/admin/assets/mfs-panel.js');
$css = (string)file_get_contents($root . '/api/admin/assets/mfs-panel.css');
$mfs = (string)file_get_contents($root . '/api/lib/mfs.php');
$topup = (string)file_get_contents($root . '/api/lib/topup.php');
$telegramRate = (string)file_get_contents($root . '/api/telegram/rate_webhook.php');
$telegramNotification = (string)file_get_contents($root . '/api/telegram/notification_webhook.php');

foreach (["case 'mfs_rate_get':", "case 'mfs_rate_save':", "case 'mfs_fees_get':", "case 'mfs_fees_save':"] as $action) {
    mfs_settings_expect(str_contains($proxy, $action), "Separated proxy action is missing: {$action}");
}
mfs_settings_expect(str_contains($proxy, 'proxy_require_csrf();') && str_contains($proxy, 'proxy_require_admin_login(true);'), 'Admin proxy auth/CSRF contract changed');
mfs_settings_expect(str_contains($helper, "zpay_save_myr_to_bdt_rate(\$rate, trim(\$changedBy), 'ADMIN_PANEL')"), 'Admin Rate Save does not use the canonical helper');
mfs_settings_expect(str_contains($helper, "fb_patch('MFS_SETTINGS', ['fees' => (array)\$built['fees']])"), 'Fee Save is not scoped to the existing fees child');
mfs_settings_expect(str_contains($telegramRate, 'zpay_save_myr_to_bdt_rate(') && str_contains($telegramNotification, 'zpay_save_myr_to_bdt_rate('), 'Telegram rate helper compatibility changed');
mfs_settings_expect(str_contains($mfs, '$rate = mfs_myr_to_bdt_rate();'), 'MFS financial preview no longer reads the canonical live rate');
mfs_settings_expect(str_contains($topup, "foreach (['MFS_SETTINGS', 'MFS_CONFIG'] as \$node)"), 'Top-Up rate mirror compatibility changed');

foreach (['mfsRateForm', 'mfsRateMyrBdt', 'mfsRateSaveBtn', 'mfsRateReloadBtn', 'mfsLiveRateValue', 'mfsRateUpdatedAt', 'mfsSettingsForm', 'mfsSettingsSaveBtn', 'mfsSettingsReloadBtn'] as $id) {
    mfs_settings_expect(str_contains($page, 'id="' . $id . '"'), "Separated MFS settings control is missing: {$id}");
}
foreach (["get('mfs_rate_get'", "post('mfs_rate_save'", "get('mfs_fees_get'", "post('mfs_fees_save'"] as $call) {
    mfs_settings_expect(str_contains($js, $call), "Separated MFS settings call is missing: {$call}");
}
$feesPayloadStart = strpos($js, 'function feesPayload()');
$feesPayloadEnd = strpos($js, 'async function saveRate', $feesPayloadStart === false ? 0 : $feesPayloadStart);
mfs_settings_expect($feesPayloadStart !== false && $feesPayloadEnd !== false, 'Unable to isolate Fee Save payload');
$feesPayloadSource = substr($js, (int)$feesPayloadStart, (int)$feesPayloadEnd - (int)$feesPayloadStart);
mfs_settings_expect(!str_contains($feesPayloadSource, 'rate_myr_bdt') && !str_contains($feesPayloadSource, 'mfsRateMyrBdt'), 'Fee Save frontend payload still contains the rate');
mfs_settings_expect(str_contains($js, 'rateMutating:false') && str_contains($js, 'feesMutating:false'), 'Rate and fee loading states are not independent');
mfs_settings_expect(str_contains($css, 'align-items:end') && str_contains($css, '.admin-mfs-rate-actions'), 'Desktop rate action alignment is missing');
mfs_settings_expect(str_contains($css, '@media(max-width:600px)') && str_contains($css, 'grid-template-columns:repeat(2,minmax(0,1fr))'), 'Mobile settings actions are not responsive');
mfs_settings_expect(str_contains($css, '@media(max-width:360px)') && str_contains($css, '.admin-mfs-rate-actions'), 'Narrow settings action fallback is missing');

$settingsMarkupStart = strpos($page, 'id="mfsSettingsSection"');
$settingsMarkupEnd = strpos($page, 'id="mfsManageSection"', $settingsMarkupStart === false ? 0 : $settingsMarkupStart);
$settingsMarkup = strtolower(substr($page, (int)$settingsMarkupStart, (int)$settingsMarkupEnd - (int)$settingsMarkupStart));
foreach (['worker_key', 'admin_key', 'app_key', 'firebase', 'telegram token', 'password', 'pin'] as $secretField) {
    mfs_settings_expect(!str_contains($settingsMarkup, $secretField), "Secret/private field rendered in MFS settings: {$secretField}");
}

echo "MFS Admin rate/fee separation tests passed ({$mfsSettingsAssertions} assertions).\n";
