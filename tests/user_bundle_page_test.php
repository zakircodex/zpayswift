<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/lib/favorites.php';
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

foreach (['bundleStepOperator', 'bundleStepOffers', 'bundleStepNumber', 'bundleStepPin', 'bundleStepPreview', 'bundleActionModal'] as $id) {
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
    && str_contains($js, "const STEP_ORDER = ['operator', 'offers', 'number', 'pin', 'preview']")
    && str_contains($js, "window.history.go(-distance)"),
    'Bundle must support step Back and collapse completed flow history after Done.'
);

bundle_page_expect(
    str_contains($page, 'id="bundleOperatorGrid"')
    && str_contains($js, "{ code: 'GP', label: 'Grameenphone' }")
    && str_contains($js, "{ code: 'ROBI', label: 'Robi' }")
    && str_contains($js, "{ code: 'AIRTEL', label: 'Airtel' }")
    && str_contains($js, "{ code: 'BANGLALINK', label: 'Banglalink' }")
    && str_contains($js, "{ code: 'TELETALK', label: 'Teletalk' }")
    && !str_contains($js, 'operators[0]'),
    'Bundle must start with the five Android operators and must not auto-select GP.'
);

bundle_page_expect(
    str_contains($js, "shell.get('bundle_offers_panel', { operator: operator.code }")
    && str_contains($js, 'serial !== state.offerLoadSerial')
    && str_contains($proxy, "\$operator = trim((string)(\$_GET['operator'] ?? ''))")
    && str_contains($proxy, 'user_proxy_bundle_offers_for_user($uid, $operator)'),
    'Bundle offer requests must be operator-scoped and ignore stale operator responses.'
);

bundle_page_expect(
    str_contains($js, 'function offerValidity(offer)')
    && str_contains($js, 'if (days > 120) return')
    && !str_contains($js, 'offer?.validity_value || offer?.duration_value')
    && str_contains($bundle, "'validity_value' => \$validityValue")
    && str_contains($proxy, "'validity_text' => \$validityText"),
    'Bundle validity must use canonical package-validity fields and never offer expiry duration as the badge.'
);

bundle_page_expect(
    str_contains($css, '.user-bundle-page .bundle-operator-grid')
    && str_contains($css, 'grid-template-columns: repeat(2, minmax(0, 1fr))')
    && str_contains($css, 'border-radius: 46% 46% 28px 28px / 32px 32px 28px 28px'),
    'Bundle must use the Android operator grid and the finished Transfer arched hold control.'
);

bundle_page_expect(
    str_contains($proxy, "case 'bundle_favorites':")
    && str_contains($proxy, "case 'bundle_favorite_add':")
    && str_contains($proxy, 'favorite_create_for_user($uid, user_proxy_read_json_body())')
    && str_contains($proxy, "'favorites/list.php'")
    && str_contains($proxy, "'favorites/update.php'")
    && str_contains($proxy, "'favorites/delete.php'"),
    'Bundle favourites must reuse the authenticated favorite helper and existing list/update/delete endpoints.'
);

bundle_page_expect(
    str_contains($js, "postWithFreshCsrf('bundle_favorite_add', {")
    && str_contains($js, 'number: fullNumber')
    && str_contains($js, "service_type: 'bundle'")
    && str_contains($js, 'if (state.favoriteSaving || isBundleFavoriteSaved())')
    && str_contains($js, "label: alreadySaved ? 'Saved' : 'Favorite'")
    && str_contains($js, "{ label: 'Done', handler: finishSuccess }")
    && str_contains($js, "error?.code || '').toUpperCase() === 'FAVORITE_ALREADY_EXISTS'"),
    'Bundle success must save the original full number once while preserving Done and duplicate handling.'
);

bundle_page_expect(
    str_contains($js, 'async function postWithFreshCsrf(action, payload)')
    && str_contains($js, 'await shell.refreshSession()')
    && str_contains($js, "code === 'FORBIDDEN'")
    && str_contains($js, "message.includes('csrf')"),
    'Bundle favorite save must refresh and retry once when the page CSRF token is stale.'
);

$favoritePayload = favorite_validate_create_payload([
    'name' => 'Grameenphone Bundle',
    'number' => '01309096677',
    'country' => 'BD',
    'country_code' => 'BD',
    'operator' => 'GP',
    'operator_name' => 'Grameenphone',
    'service_type' => 'bundle',
]);
bundle_page_expect(
    !empty($favoritePayload['ok'])
    && ($favoritePayload['favorite']['number'] ?? '') === '01309096677'
    && ($favoritePayload['favorite']['operator'] ?? '') === 'GP'
    && ($favoritePayload['favorite']['service_type'] ?? '') === 'bundle',
    'The full Bundle receiver payload must pass the existing favorite contract unchanged.'
);

bundle_page_expect(
    str_contains($js, "shell.toast(safeMessage(error, 'Favorite number could not be saved.'), 'error')")
    && str_contains($js, "button.textContent = 'Favorite'"),
    'Favorite-save failure must remain a local toast and leave the successful Bundle result available.'
);

bundle_page_expect(
    str_contains($css, '.bundle-modal-actions.bundle-success-actions')
    && str_contains($css, 'grid-template-columns: repeat(2, minmax(0, 1fr))')
    && str_contains($css, '.bundle-success-actions .bundle-modal-action'),
    'Bundle success Favorite and Done actions must share one responsive equal-width row.'
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
