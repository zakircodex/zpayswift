<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/api/user/add-money.php');
$js = (string)file_get_contents($root . '/api/user/assets/pages/add-money-page.js');
$css = (string)file_get_contents($root . '/api/user/assets/pages/add-money-page.css');
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

expect_add_money(
    str_contains($page, 'id="addMoneySection"')
    && str_contains($page, 'id="addMoneyContent"')
    && !str_contains($page, 'add-money-intro-card')
    && !str_contains($page, 'addMoneyReloadBtn'),
    'Isolated Add Money page shell is missing or old intro card remains'
);
expect_add_money(
    str_contains($page, "'show_header' => false")
    && str_contains($page, "'show_drawer' => false")
    && str_contains($page, "'show_bottom_nav' => true")
    && str_contains($page, 'id="addMoneyBackButton"')
    && str_contains($page, 'href="/user/dashboard"')
    && str_contains($page, 'class="add-money-page-header"')
    && !str_contains($page, 'id="openSidebarBtn"'),
    'Add Money must use a Back header with the shared bottom navigation and no menu trigger'
);
expect_add_money(
    str_contains($js, 'function addMoneyCountryProfile')
    && str_contains($js, 'profile.pricing_country')
    && !str_contains($js, 'phone_country'),
    'Add Money methods are not resolved from pricing country'
);
expect_add_money(
    str_contains($js, 'accountCountry === country')
    && str_contains($js, 'accountCurrency === currency')
    && str_contains($js, 'data-add-money-account-id')
    && str_contains($js, 'selectAddMoneyAccount'),
    'Payment accounts are not defensively filtered/selectable'
);
expect_add_money(
    str_contains($js, 'add-money-copy-icon')
    && str_contains($js, 'copyAddMoneyAccountNumber')
    && str_contains($js, "document.execCommand('copy')"),
    'Account copy action or fallback is missing'
);
expect_add_money(
    str_contains($js, 'Holder:')
    && str_contains($js, 'Account:')
    && str_contains($js, 'name="payment_account_id"')
    && str_contains($js, "formData.set('payment_account_id'"),
    'Account details or selected account contract is incomplete'
);
expect_add_money(
    str_contains($js, 'addMoneyReceiptInput')
    && str_contains($js, 'addMoneyReceiptPreview')
    && str_contains($js, 'Replace')
    && str_contains($js, 'Remove')
    && str_contains($js, '5 * 1024 * 1024'),
    'Receipt preview/replace/remove/size guard is incomplete'
);
expect_add_money(
    str_contains($js, 'type="submit">Submit Receipt')
    && str_contains($js, "proxyFormPost('add_money_submit'")
    && str_contains($js, "form.dataset.submitting === '1'")
    && str_contains($js, 'idempotency_key'),
    'Submit Receipt flow or duplicate protection is missing'
);
expect_add_money(
    str_contains($js, '<a class="btn green" href="/user/contact-us">Contact</a>')
    && !str_contains($js, 'data-open-section'),
    'Add Money support still uses SPA routing'
);
expect_add_money(
    str_contains($proxy, "case 'add_money_settings':")
    && str_contains($proxy, "case 'add_money_submit':")
    && str_contains($proxy, 'user_proxy_require_csrf();')
    && str_contains($backend, 'add_money_country_for_user'),
    'Existing Add Money proxy/backend authority is not preserved'
);
expect_add_money(
    str_contains($backend, 'payment_account_id')
    && str_contains($backend, 'add_money_store_receipt'),
    'Secure receipt/payment-account backend contract is missing'
);
expect_add_money(
    str_contains($css, '#addMoneySection .add-money-account-card.selected')
    && str_contains($css, '#addMoneySection .add-money-receipt-preview')
    && str_contains($css, 'grid-template-columns: 44px minmax(0, 1fr) 40px')
    && str_contains($css, 'min-height: 90px')
    && str_contains($css, 'grid-template-columns: 48px minmax(0, 1fr)')
    && str_contains($css, '#addMoneySection .add-money-support-card')
    && str_contains($css, '#addMoneySection .add-money-page-header')
    && str_contains($css, '#addMoneySection .add-money-header-button')
    && str_contains($css, 'width: min(100%, 720px)')
    && str_contains($css, '@media'),
    'Responsive Add Money account/receipt styling is incomplete'
);
expect_add_money(
    !str_contains($page . $js, 'openSection(')
    && !str_contains($page, 'data-page-section'),
    'Add Money page still depends on SPA navigation'
);

echo "User Add Money UI tests passed ({$tests} assertions).\n";
