<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$dashboardJs = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');
$transactionsCss = (string)file_get_contents($root . '/api/admin/assets/admin-transactions.css');
$mfsPage = (string)file_get_contents($root . '/api/admin/mfs.php');
$mfsJs = (string)file_get_contents($root . '/api/admin/assets/mfs-panel.js');
$mfsCss = (string)file_get_contents($root . '/api/admin/assets/mfs-panel.css');

$assertions = 0;

function admin_transactions_ui_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

admin_transactions_ui_expect(str_contains($dashboard, "filemtime(__DIR__ . '/assets/admin-transactions.css')"), 'Transaction stylesheet must use deploy-safe cache versioning');
admin_transactions_ui_expect(str_contains($dashboard, 'class="admin-transaction-table topup-requests-table"'), 'Top-Up operational table hook is missing');
admin_transactions_ui_expect(str_contains($dashboard, 'class="admin-transaction-table bundle-requests-table"'), 'Bundle operational table hook is missing');
admin_transactions_ui_expect(str_contains($dashboard, 'class="tabs topup-status-tabs"'), 'Top-Up status tabs must remain available');

foreach (['topupSearch', 'reloadTopupBtn', 'topupTableBody', 'reloadBundleBtn', 'bundleTableBody'] as $id) {
    admin_transactions_ui_expect(str_contains($dashboard, 'id="' . $id . '"'), "Existing Admin transaction control is missing: {$id}");
}

foreach (['Request', 'User', 'Service', 'Wallet', 'Status', 'Created', 'Worker', 'Actions'] as $label) {
    admin_transactions_ui_expect(str_contains($dashboardJs, 'data-label="' . $label . '"'), "Responsive Top-Up label is missing: {$label}");
}

foreach (['Bundle', 'Financials'] as $label) {
    admin_transactions_ui_expect(str_contains($dashboardJs, 'data-label="' . $label . '"'), "Responsive Bundle label is missing: {$label}");
}

foreach (['directTopupNumber', 'directTopupOperator', 'directTopupAmount', 'directTopupNote', 'topupRequestId', 'topupMessage', 'bundleRequestId', 'bundleMessage'] as $id) {
    admin_transactions_ui_expect(str_contains($dashboardJs, 'id="' . $id . '"'), "Existing Top-Up/Bundle modal field is missing: {$id}");
}

foreach (["proxyGet('topups'", "proxyGet('topup_get'", "proxyGet('bundles'", "proxyPost('topup_create'", "'topup_success' : 'topup_failed'", "'bundle_success' : 'bundle_failed'"] as $contract) {
    admin_transactions_ui_expect(str_contains($dashboardJs, $contract), "Existing Admin transaction API contract changed: {$contract}");
}

admin_transactions_ui_expect(str_contains($dashboardJs, 'BDT ${money(topupServiceAmount(item))}'), 'Top-Up service amount must remain explicitly BDT');
admin_transactions_ui_expect(str_contains($dashboardJs, 'topupWalletDebitText(item)'), 'Stored wallet debit display is missing');
admin_transactions_ui_expect(str_contains($dashboardJs, 'bundleWalletDebitText(item)'), 'Stored Bundle wallet debit display is missing');
admin_transactions_ui_expect(str_contains($dashboardJs, 'Bundle Commission'), 'Bundle-specific commission label is missing');
admin_transactions_ui_expect(!str_contains($dashboardJs, 'Normal Top-Up Commission'), 'Bundle presentation must not introduce normal Top-Up commission');

$viewTopupStart = strpos($dashboardJs, 'async function viewTopup(requestId)');
$viewTopupEnd = strpos($dashboardJs, 'async function loadBundles', $viewTopupStart === false ? 0 : $viewTopupStart);
admin_transactions_ui_expect($viewTopupStart !== false && $viewTopupEnd !== false && $viewTopupEnd > $viewTopupStart, 'Unable to isolate Top-Up detail presentation');
$viewTopupBlock = substr($dashboardJs, (int)$viewTopupStart, (int)$viewTopupEnd - (int)$viewTopupStart);
admin_transactions_ui_expect(!str_contains($viewTopupBlock, 'Raw Request JSON'), 'Top-Up details must not render raw response JSON');

admin_transactions_ui_expect(str_contains($transactionsCss, '#topupSection') && str_contains($transactionsCss, '#bundleSection'), 'Main transaction CSS must remain section scoped');
admin_transactions_ui_expect(str_contains($transactionsCss, '@media(max-width:760px)'), 'Main transaction mobile card breakpoint is missing');
admin_transactions_ui_expect(str_contains($transactionsCss, 'content:attr(data-label)'), 'Main transaction mobile labels are missing');
admin_transactions_ui_expect(str_contains($transactionsCss, 'overflow-wrap:anywhere'), 'Long transaction IDs need safe wrapping');

admin_transactions_ui_expect(str_contains($mfsPage, "filemtime(__DIR__ . '/assets/mfs-panel.css')"), 'MFS stylesheet must use deploy-safe cache versioning');
admin_transactions_ui_expect(str_contains($mfsPage, "filemtime(__DIR__ . '/assets/mfs-panel.js')"), 'MFS script must use deploy-safe cache versioning');
admin_transactions_ui_expect(str_contains($mfsPage, 'class="admin-mfs-table"'), 'MFS operational table hook is missing');
admin_transactions_ui_expect(str_contains($mfsPage, 'class="admin-mfs-details-grid" id="mfsViewDetails"'), 'MFS grouped details container is missing');

foreach (['mfsCreateUid', 'mfsCreateProvider', 'mfsCreateReceiver', 'mfsCreateAmountBdt', 'mfsCreateAmountRm', 'mfsCreateReference', 'mfsCreateNote', 'mfsRateMyrBdt', 'mfsSettingsSaveBtn', 'mfsSettingsReloadBtn'] as $id) {
    admin_transactions_ui_expect(str_contains($mfsPage, 'id="' . $id . '"'), "Existing MFS form/settings ID is missing: {$id}");
}

foreach (['USER Fee RM', 'RETAILER Fee RM', 'SUBADMIN Fee RM'] as $label) {
    admin_transactions_ui_expect(str_contains($mfsPage, $label), "MFS role fee label changed: {$label}");
}

foreach (["get('mfs_pending'", "get('mfs_processing'", "get('mfs_done'", "get('mfs_get'", "post('mfs_create'", "post('mfs_settings_save'", "post('mfs_success'"] as $contract) {
    admin_transactions_ui_expect(str_contains($mfsJs, $contract), "Existing MFS API action changed: {$contract}");
}

admin_transactions_ui_expect(str_contains($mfsJs, 'function mfsViewDetailsHtml(row)'), 'MFS allowlisted detail renderer is missing');
admin_transactions_ui_expect(str_contains($mfsJs, "el('mfsViewDetails').innerHTML=mfsViewDetailsHtml(row)"), 'MFS modal must use the grouped detail renderer');
admin_transactions_ui_expect(!str_contains($mfsJs, "el('mfsViewDetails').textContent=JSON.stringify"), 'MFS modal must not dump raw response JSON');
admin_transactions_ui_expect(str_contains($mfsJs, 'class="admin-mfs-card"'), 'MFS responsive request card is missing');
admin_transactions_ui_expect(str_contains($mfsJs, 'mfs-status-pill'), 'MFS canonical status badge hook is missing');
admin_transactions_ui_expect(str_contains($mfsJs, 'rowAmountMarkup(r)'), 'MFS amount/currency presentation is missing');

$mfsDetailStart = strpos($mfsJs, 'function mfsViewDetailsHtml(row)');
$mfsDetailEnd = strpos($mfsJs, 'function num(id)', $mfsDetailStart === false ? 0 : $mfsDetailStart);
admin_transactions_ui_expect($mfsDetailStart !== false && $mfsDetailEnd !== false && $mfsDetailEnd > $mfsDetailStart, 'Unable to isolate MFS detail renderer');
$mfsDetailBlock = strtolower(substr($mfsJs, (int)$mfsDetailStart, (int)$mfsDetailEnd - (int)$mfsDetailStart));
foreach (['password', 'pin_hash', 'otp', 'session_token', 'worker_key', 'admin_key', 'app_key', 'firebase', 'retailer_secret_pin'] as $privateField) {
    admin_transactions_ui_expect(!str_contains($mfsDetailBlock, $privateField), "Private field leaked into MFS details: {$privateField}");
}

admin_transactions_ui_expect(str_contains($mfsCss, '.admin-mfs-details-grid'), 'MFS grouped detail styling is missing');
admin_transactions_ui_expect(str_contains($mfsCss, '.mfs-status-pill'), 'MFS status badge styling is missing');
admin_transactions_ui_expect(str_contains($mfsCss, '@media(max-width:600px)') && str_contains($mfsCss, '@media(max-width:360px)'), 'MFS narrow responsive fallbacks are missing');
admin_transactions_ui_expect(str_contains($mfsCss, 'overflow-x:hidden'), 'MFS shell must prevent page-level horizontal overflow');
admin_transactions_ui_expect(str_contains($mfsCss, 'max-height:calc(100dvh - 32px)'), 'MFS modal must remain within the dynamic viewport');

echo "Admin transaction UI polish tests passed ({$assertions} assertions).\n";
