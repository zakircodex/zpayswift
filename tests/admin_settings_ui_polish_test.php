<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$js = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');
$css = (string)file_get_contents($root . '/api/admin/assets/admin-settings.css');
$proxy = (string)file_get_contents($root . '/api/admin/proxy.php');
$operatorGet = (string)file_get_contents($root . '/api/admin/operators/get.php');
$operatorList = (string)file_get_contents($root . '/api/admin/operators/list.php');
$assertions = 0;

function admin_settings_ui_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

admin_settings_ui_expect(
    str_contains($dashboard, "filemtime(__DIR__ . '/assets/admin-settings.css')"),
    'Settings presentation stylesheet is not deploy-safe linked'
);
admin_settings_ui_expect(str_contains($dashboard, 'class="section operators-admin-section" id="operatorsSection"'), 'Operators section scope is missing');
admin_settings_ui_expect(str_contains($dashboard, 'class="operator-country-grid" id="topupCountriesTableBody"'), 'Country renderer hook changed or responsive grid is missing');
admin_settings_ui_expect(str_contains($dashboard, 'class="operators-grid" id="operatorsTableBody"'), 'Operator renderer hook changed or responsive grid is missing');
admin_settings_ui_expect(str_contains($dashboard, 'id="reloadOperatorsBtn"'), 'Reload Operators action changed');

foreach ([
    "GP: 'GP'",
    "ROBI: 'ROBI'",
    "AIRTEL: 'AIRTEL'",
    "BL: 'BANGLALINK'",
    "TT: 'TELETALK'",
] as $operatorLabel) {
    admin_settings_ui_expect(str_contains($js, $operatorLabel), "Operator presentation mapping missing: {$operatorLabel}");
}

foreach ([
    'operator-card',
    'operator-card-head',
    'operator-card-body',
    'operator-card-foot',
    'operator-detail',
    'operator-edit-btn',
] as $className) {
    admin_settings_ui_expect(str_contains($js, $className), "Operator card renderer class missing: {$className}");
}

admin_settings_ui_expect(str_contains($js, "onclick=\"editOperator('"), 'Existing Operator edit action changed');
admin_settings_ui_expect(str_contains($js, "onclick=\"editTopupCountry('"), 'Existing country edit action changed');
admin_settings_ui_expect(!str_contains($js, 'item.masked_template || item.dial_template'), 'Operator list can fall back to an unmasked runtime template');

$operatorEditorStart = strpos($js, 'async function editOperator');
$operatorEditorEnd = strpos($js, 'async function saveOperator');
$operatorEditor = ($operatorEditorStart !== false && $operatorEditorEnd !== false)
    ? substr($js, $operatorEditorStart, $operatorEditorEnd - $operatorEditorStart)
    : '';
admin_settings_ui_expect($operatorEditor !== '', 'Operator editor source could not be isolated');
admin_settings_ui_expect(!str_contains($operatorEditor, 'retailer_secret_pin_masked'), 'Existing private credential indicator is rendered in the Operator editor');
admin_settings_ui_expect(!str_contains($operatorEditor, 'Current PIN'), 'Operator editor reveals a private credential representation');
admin_settings_ui_expect(str_contains($operatorEditor, 'id="opRetailerPin" type="password" autocomplete="new-password" value=""'), 'Private credential replacement input is not blank/password-safe');
admin_settings_ui_expect(str_contains($operatorEditor, "setModalPresentationScope('operator-settings')"), 'Operator modal presentation scope is missing');

foreach ([
    'opOperator',
    'opName',
    'opCountryCode',
    'opServiceType',
    'opActive',
    'opRequiresPin',
    'opMinAmount',
    'opMaxAmount',
    'opQuickAmounts',
    'opPrefixes',
    'opSortOrder',
    'opDialTemplate',
    'opMaskedTemplate',
    'opRetailerPin',
] as $fieldId) {
    admin_settings_ui_expect(str_contains($js, 'id="' . $fieldId . '"'), "Existing Operator field ID changed: {$fieldId}");
}

admin_settings_ui_expect(str_contains($js, "proxyPost('operator_save', body"), 'Operator save proxy action changed');
admin_settings_ui_expect(str_contains($proxy, "case 'operator_save':") && str_contains($proxy, "operators/save.php"), 'Operator proxy route changed');

$settingsStart = strpos($js, 'async function openAppConfigModal');
$settingsEnd = strpos($js, 'function openDirectTopupModal');
$settingsUi = ($settingsStart !== false && $settingsEnd !== false)
    ? substr($js, $settingsStart, $settingsEnd - $settingsStart)
    : '';
admin_settings_ui_expect($settingsUi !== '', 'System Settings source could not be isolated');

foreach (['Services', 'Service Limits', 'Legal Links', 'Maintenance'] as $groupTitle) {
    admin_settings_ui_expect(str_contains($settingsUi, $groupTitle), "System Settings group missing: {$groupTitle}");
}

foreach ([
    'cfgTopupEnabled',
    'cfgBundleEnabled',
    'cfgMaintenanceMode',
    'cfgMinTopupAmount',
    'cfgMaxTopupAmount',
    'cfgMinBundleAmount',
    'cfgMaxBundleAmount',
    'cfgPrivacyPolicyUrl',
    'cfgTermsConditionsUrl',
] as $fieldId) {
    admin_settings_ui_expect(str_contains($settingsUi, 'id="' . $fieldId . '"'), "Existing System Settings field ID changed: {$fieldId}");
}

admin_settings_ui_expect(str_contains($settingsUi, "proxyPost('app_config_save', body"), 'System Settings save action changed');
admin_settings_ui_expect(str_contains($settingsUi, "setModalPresentationScope('system-settings')"), 'System Settings modal presentation scope is missing');
admin_settings_ui_expect(!preg_match('/\b(commission|rate_myr_bdt|myr_to_bdt_rate)\b/i', $settingsUi), 'Unrelated rate or commission settings were added to System Settings');

$getResponse = substr($operatorGet, (int)strrpos($operatorGet, 'api_response('));
$listResponse = substr($operatorList, (int)strrpos($operatorList, 'api_response('));
admin_settings_ui_expect(!str_contains($getResponse, "'retailer_secret_pin' =>"), 'Operator get response exposes the private credential');
admin_settings_ui_expect(!str_contains($listResponse, "'retailer_secret_pin' =>"), 'Operator list response exposes the private credential');

foreach (['#operatorsSection', '[data-modal-scope="operator-settings"]', '[data-modal-scope="system-settings"]'] as $scope) {
    admin_settings_ui_expect(str_contains($css, $scope), "Scoped presentation rule missing: {$scope}");
}
admin_settings_ui_expect(str_contains($css, '@media(max-width:1100px)') && str_contains($css, '@media(max-width:700px)') && str_contains($css, '@media(max-width:520px)') && str_contains($css, '@media(max-width:360px)'), 'Required responsive breakpoints are missing');
admin_settings_ui_expect(str_contains($css, 'overflow-wrap:anywhere'), 'Long Operator templates/identifiers do not have a safe wrapping rule');

echo "Admin Operators and System Settings UI tests passed ({$assertions} assertions).\n";
