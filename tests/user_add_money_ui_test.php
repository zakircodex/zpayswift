<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/user/dashboard.php');
$js = (string)file_get_contents($root . '/api/user/assets/dashboard.js');
$css = (string)file_get_contents($root . '/api/user/assets/user-app.css');
$proxy = (string)file_get_contents($root . '/api/user/proxy.php');
$backend = (string)file_get_contents($root . '/api/lib/add_money.php');
$tests = 0;

function expect_add_money(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$start = strpos($js, 'function addMoneyMethodLabel');
$end = strpos($js, 'function bundleCardHtml', $start === false ? 0 : $start);
$addMoneyJs = ($start !== false && $end !== false) ? substr($js, $start, $end - $start) : '';

expect_add_money(str_contains($dashboard, 'id="addMoneySection" class="page-section add-money-page-section"'), 'Add Money page shell is missing');
expect_add_money(!str_contains($dashboard, 'add-money-intro-card') && !str_contains($dashboard, 'id="addMoneyOpenBtn"') && !str_contains($dashboard, 'id="addMoneyReloadBtn"'), 'old Add Money intro/tab card remains visible');
expect_add_money(str_contains($dashboard, '/api/user/assets/user-app.css?v=31') && str_contains($dashboard, '/api/user/assets/dashboard.js?v=36'), 'Add Money asset cache bust versions were not bumped');
expect_add_money(str_contains($addMoneyJs, 'function addMoneyCountryProfile') && str_contains($addMoneyJs, 'profile.pricing_country'), 'payment methods are not resolved from pricing country');
expect_add_money(!str_contains($addMoneyJs, 'phone_country'), 'Add Money UI uses phone_country for payment selection');
expect_add_money(str_contains($addMoneyJs, 'accountCountry === country') && str_contains($addMoneyJs, 'accountCurrency === currency'), 'payment accounts are not defensively country/currency filtered');
expect_add_money(str_contains($addMoneyJs, 'data-add-money-account-id') && str_contains($addMoneyJs, 'selectAddMoneyAccount'), 'payment account selection is not wired');
expect_add_money(str_contains($addMoneyJs, 'add-money-copy-icon') && !str_contains($addMoneyJs, 'add-money-copy-action'), 'copy action is not aligned with account number');
expect_add_money(str_contains($addMoneyJs, 'Holder:') && str_contains($addMoneyJs, 'Account:'), 'payment card account hierarchy is missing');
expect_add_money(str_contains($js, 'copyAddMoneyAccountNumber'), 'Add Money account copy fallback is missing');
expect_add_money(str_contains($addMoneyJs, 'name="payment_account_id"') && str_contains($addMoneyJs, "formData.set('payment_account_id'"), 'selected payment account ID is not sent to the existing API contract');
expect_add_money(str_contains($addMoneyJs, 'addMoneyReceiptInput') && str_contains($addMoneyJs, 'addMoneyReceiptPreview'), 'receipt preview flow is missing');
expect_add_money(str_contains($addMoneyJs, 'Replace') && str_contains($addMoneyJs, 'Remove'), 'receipt replace/remove controls are missing');
expect_add_money(str_contains($addMoneyJs, '5 * 1024 * 1024'), 'receipt size guard is missing');
expect_add_money(str_contains($addMoneyJs, 'type="submit">Submit Receipt'), 'Submit Receipt label is missing');
expect_add_money(str_contains($addMoneyJs, 'proxyFormPost(\'add_money_submit\''), 'receipt/form submit does not use the existing Add Money proxy');
expect_add_money(str_contains($addMoneyJs, "form.dataset.submitting === '1'") && str_contains($addMoneyJs, 'idempotency_key'), 'duplicate Add Money submit protection is missing');
expect_add_money(!str_contains($addMoneyJs, 'addMoneySubmitModal') && !str_contains($addMoneyJs, 'ensureAddMoneySubmitModal'), 'old Add Money modal system remains in the live Add Money flow');
expect_add_money(str_contains($proxy, "case 'add_money_settings':") && str_contains($proxy, "case 'add_money_submit':"), 'existing Add Money proxy actions are not preserved');
expect_add_money(str_contains($proxy, 'user_proxy_require_csrf();') && str_contains($backend, 'add_money_country_for_user'), 'CSRF and backend country authority are not preserved');
expect_add_money(str_contains($backend, 'payment_account_id') && str_contains($backend, 'add_money_store_receipt'), 'backend payment account and secure receipt contracts are not preserved');
expect_add_money(str_contains($css, '#addMoneySection .add-money-account-card.selected') && str_contains($css, '#addMoneySection .add-money-receipt-preview'), 'scoped Android-style Add Money CSS is missing');
expect_add_money(str_contains($css, '#addMoneySection .add-money-account-list') && str_contains($css, 'grid-template-columns: minmax(0, 1fr)'), 'responsive Add Money card layout is missing');
expect_add_money(str_contains($css, '#addMoneySection .add-money-account-info') && str_contains($css, '#addMoneySection .add-money-receipt-empty-icon svg'), 'Android-style account and receipt details are missing');
expect_add_money(str_contains($css, 'grid-template-columns: 52px minmax(0, 1fr) 44px') && str_contains($css, 'display: contents'), 'payment account logo/text/copy alignment does not match Android compact cards');
expect_add_money(str_contains($css, "data-active-section='addMoneySection'] .bottom-nav") && str_contains($css, "data-active-section='addMoneySection'] .main-panel"), 'Add Money bottom navigation spacing is not scoped off');
expect_add_money(str_contains($css, "data-active-section='addMoneySection'] .mobile-header") && str_contains($css, 'width: min(calc(100% - 28px), 720px)'), 'Add Money header width styling is missing');
expect_add_money(str_contains($css, "data-active-section='addMoneySection'] .mobile-top-card") && str_contains($css, 'width: 100%'), 'Add Money header card does not fill the page width');
expect_add_money(str_contains($css, 'padding: env(safe-area-inset-top) 0 0') && str_contains($css, '#addMoneySection .add-money-account-info > div'), 'mobile Add Money header padding or compact account rows are missing');

echo "User Add Money UI tests passed ({$tests} assertions).\n";
