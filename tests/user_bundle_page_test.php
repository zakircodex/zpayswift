<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/api/user/bundle.php');
$css = (string)file_get_contents($root . '/api/user/assets/pages/bundle-page.css');
$js = (string)file_get_contents($root . '/api/user/assets/pages/bundle-page.js');
$proxy = (string)file_get_contents($root . '/api/user/proxy.php');
$bundle = (string)file_get_contents($root . '/api/lib/bundle.php');
$bundleSubmit = (string)file_get_contents($root . '/api/bundle/submit.php');
$assertions = 0;

function bundle_page_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach (['bundleStepOffers', 'bundleStepNumber', 'bundleStepPin', 'bundleStepPreview', 'bundleActionModal'] as $id) {
    bundle_page_expect(str_contains($page, 'id="' . $id . '"'), "Bundle page must contain {$id}.");
}

bundle_page_expect(
    str_contains($page, "'show_global_loader' => false")
    && str_contains($page, "'show_header' => false"),
    'Bundle must own its header and page-local modal without the global loader.'
);

bundle_page_expect(
    str_contains($css, 'body.user-bundle-page {')
    && str_contains($css, 'height: 100dvh')
    && str_contains($css, '.user-bundle-page .bundle-scroll-body')
    && str_contains($css, 'overflow-y: auto'),
    'Bundle must use a 100dvh shell with a body-only scroll container.'
);

bundle_page_expect(
    str_contains($css, 'width: min(calc(100% - 24px), 560px)')
    && str_contains($css, '.user-bundle-page .bottom-nav-inner'),
    'Bundle header, body and shared bottom navigation must use the centered app width.'
);

bundle_page_expect(
    str_contains($js, "shell.post('bundle_preview'")
    && str_contains($js, 'check_only: true')
    && str_contains($js, "shell.post('validate_pin'")
    && str_contains($js, "shell.post('bundle_submit'"),
    'Bundle Web flow must use canonical number check, PIN, preview and submit actions.'
);

bundle_page_expect(
    str_contains($js, 'preview_token: state.preview.preview_token')
    && str_contains($js, 'idempotency_key: state.idempotencyKey')
    && !str_contains($js, 'wallet_debit_amount:')
    && !str_contains($js, 'bundle_commission:'),
    'Bundle submit must send the preview identity and never client-authoritative financial fields.'
);

bundle_page_expect(
    str_contains($js, 'const HOLD_DURATION_MS = 1200;')
    && str_contains($js, "hold?.addEventListener('pointerdown'")
    && str_contains($js, "hold?.addEventListener('contextmenu'")
    && str_contains($js, 'if (state.submitting || state.completed'),
    'Bundle hold confirmation must match Android duration and prevent duplicate submit.'
);

bundle_page_expect(
    str_contains($js, "window.addEventListener('popstate'")
    && str_contains($js, "const STEP_ORDER = ['offers', 'number', 'pin', 'preview']")
    && str_contains($js, "window.history.go(-distance)"),
    'Bundle must support step Back and collapse completed flow history after Done.'
);

bundle_page_expect(
    str_contains($proxy, "case 'bundle_favorites':")
    && str_contains($proxy, "'favorites/list.php'")
    && str_contains($proxy, "'favorites/update.php'")
    && str_contains($proxy, "'favorites/delete.php'"),
    'Bundle favourites must reuse the existing authenticated favorite-number endpoints.'
);

bundle_page_expect(
    str_contains($bundle, "topup_suggest_operator_by_number('BD', \$bundleNumber)")
    && str_contains($bundle, "'BUNDLE_OPERATOR_MISMATCH'")
    && str_contains($bundle, "in_array(\$normalizedOperator, \$normalizedNumberOperators, true)"),
    'Backend must reject a number that does not match the authoritative bundle operator.'
);

bundle_page_expect(
    str_contains($js, "walletCurrency === 'MYR'")
    && str_contains($js, "['Rate', `RM 1 =")
    && str_contains($js, "['Wallet Debit'")
    && str_contains($js, "['Balance After'"),
    'Preview must conditionally show MYR rate and render authoritative debit/balance values.'
);

bundle_page_expect(
    str_contains($js, "status === 'WAITING_ADMIN' ? 'Pending'")
    && str_contains($js, "openLoading('Submitting bundle request...')"),
    'Bundle result must map WAITING_ADMIN to Pending and use a page-local submit loader.'
);

bundle_page_expect(
    str_contains($bundleSubmit, "'BUNDLE_SUBMIT_IDEMPOTENCY/'")
    && str_contains($bundleSubmit, 'wallet_financial_operation_begin(')
    && str_contains($bundleSubmit, 'wallet_financial_operation_ledger_id(')
    && str_contains($bundleSubmit, 'wallet_financial_operation_mark_completed('),
    'Bundle submit must preserve the existing idempotent wallet-operation and deterministic-ledger framework.'
);

bundle_page_expect(
    str_contains($bundle, "'wallet_hold_amount' => \$walletHoldAmount")
    && str_contains($bundle, "'wallet_debit_currency' => \$walletCurrency")
    && !str_contains($bundle, 'topup_commission'),
    'Bundle recovery must retain original debit/currency evidence without applying normal Top-Up commission.'
);

echo "User Bundle page tests passed ({$assertions} assertions).\n";
