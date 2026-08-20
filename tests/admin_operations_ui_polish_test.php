<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$js = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');
$css = (string)file_get_contents($root . '/api/admin/assets/admin-operations.css');

$assertions = 0;

function admin_operations_ui_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

admin_operations_ui_expect(str_contains($dashboard, "filemtime(__DIR__ . '/assets/admin-operations.css')"), 'Operations stylesheet must use deploy-safe cache versioning');
admin_operations_ui_expect(str_contains($dashboard, 'class="card add-money-admin-card"'), 'Add Money card presentation hook is missing');
admin_operations_ui_expect(str_contains($dashboard, 'class="toolbar add-money-filter-row add-money-toolbar"'), 'Add Money filter toolbar hook is missing');
admin_operations_ui_expect(str_contains($dashboard, 'class="add-money-requests-table"'), 'Add Money table presentation hook is missing');
admin_operations_ui_expect(str_contains($dashboard, '<th>Submitted</th>'), 'Submitted time must remain visible as an operational field');
admin_operations_ui_expect(str_contains($dashboard, 'colspan="9"'), 'Add Money state row must span the full table');

foreach (['addMoneySettingsBtn', 'reloadAddMoneyBtn', 'addMoneyStatusFilter', 'addMoneyCountryFilter', 'addMoneyMethodFilter', 'addMoneyTableBody'] as $id) {
    admin_operations_ui_expect(str_contains($dashboard, 'id="' . $id . '"'), "Existing Add Money control is missing: {$id}");
}

foreach (['Request', 'User', 'Market', 'Method', 'Amount', 'Submitted', 'Proof', 'Status', 'Actions'] as $label) {
    admin_operations_ui_expect(str_contains($js, 'data-label="' . $label . '"'), "Responsive Add Money label is missing: {$label}");
}

admin_operations_ui_expect(str_contains($js, 'function openAddMoneyReceipt(requestId)'), 'Receipt preview controller is missing');
admin_operations_ui_expect(str_contains($js, 'row.receipt_mime'), 'Receipt preview must use the canonical MIME metadata');
admin_operations_ui_expect(str_contains($js, 'add-money-receipt-image'), 'Image receipt preview is missing');
admin_operations_ui_expect(str_contains($js, 'add-money-pdf-preview'), 'PDF receipt presentation is missing');
admin_operations_ui_expect(str_contains($js, 'rel="noopener noreferrer"') && str_contains($js, 'referrerpolicy="no-referrer"'), 'Receipt open action must preserve safe browser isolation');
admin_operations_ui_expect(str_contains($js, 'addMoneyAmount(row)'), 'Add Money amount must keep the canonical display helper');
admin_operations_ui_expect(str_contains($js, "proxyPost(isApprove ? 'add_money_approve' : 'add_money_reject'"), 'Add Money action proxy contract changed');
admin_operations_ui_expect(str_contains($js, "proxyGet('add_money_requests', addMoneyFilters()"), 'Add Money list proxy contract changed');

$receiptStart = strpos($js, 'function openAddMoneyReceipt(requestId)');
$receiptEnd = strpos($js, 'async function submitAddMoneyAction', $receiptStart === false ? 0 : $receiptStart);
admin_operations_ui_expect($receiptStart !== false && $receiptEnd !== false && $receiptEnd > $receiptStart, 'Unable to isolate receipt presentation controller');
$receiptBlock = substr($js, (int)$receiptStart, (int)$receiptEnd - (int)$receiptStart);
foreach (['receipt_path', 'receipt_token', 'receipt_hash', 'firebase', 'app_key', 'worker_key'] as $privateField) {
    admin_operations_ui_expect(!str_contains(strtolower($receiptBlock), $privateField), "Private receipt field leaked into the modal: {$privateField}");
}

admin_operations_ui_expect(str_contains($js, 'account-review-summary'), 'Account Review summary presentation is missing');
foreach (['Identity', 'Market', 'Review Reason'] as $group) {
    admin_operations_ui_expect(str_contains($js, '>' . $group . '</span>'), "Account Review summary field is missing: {$group}");
}
admin_operations_ui_expect(str_contains($js, 'GPS ${esc(data.gps_country') && str_contains($js, 'IP ${esc(data.ip_country'), 'Account Review GPS/IP context is missing');
admin_operations_ui_expect(str_contains($js, "proxyPost('user_approve', { uid }") && str_contains($js, "proxyPost('user_reject', { uid }"), 'Account Review CAS callers changed');

admin_operations_ui_expect(str_contains($css, '#addMoneySection .add-money-toolbar'), 'Add Money CSS must remain section-scoped');
admin_operations_ui_expect(str_contains($css, '#addMoneySection .add-money-requests-table thead th'), 'Desktop Add Money table header styling is missing');
admin_operations_ui_expect(str_contains($css, '@media(max-width:760px)'), 'Mobile Add Money layout breakpoint is missing');
admin_operations_ui_expect(str_contains($css, 'content:attr(data-label)'), 'Mobile Add Money card labels are missing');
admin_operations_ui_expect(str_contains($css, '.add-money-receipt-image') && str_contains($css, 'object-fit:contain'), 'Receipt image viewport containment is missing');
admin_operations_ui_expect(str_contains($css, '.account-review-summary-grid'), 'Account Review grouped layout is missing');
admin_operations_ui_expect(str_contains($css, '@media(max-width:360px)'), 'Narrow mobile fallback is missing');

echo "Admin operations UI polish tests passed ({$assertions} assertions).\n";
