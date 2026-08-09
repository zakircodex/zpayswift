<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$proxy = (string)file_get_contents($root . '/api/user/proxy.php');
$transferPage = (string)file_get_contents($root . '/api/user/transfer.php');
$transferJs = (string)file_get_contents($root . '/api/user/assets/pages/transfer-page.js');
$transferCss = (string)file_get_contents($root . '/api/user/assets/pages/transfer-page.css');
$profileJs = (string)file_get_contents($root . '/api/user/assets/pages/profile-page.js');
$supportPage = (string)file_get_contents($root . '/api/user/support.php');
$contactPage = (string)file_get_contents($root . '/api/user/contact-us.php');
$supportJs = (string)file_get_contents($root . '/api/user/assets/pages/support-page.js');
$supportCss = (string)file_get_contents($root . '/api/user/assets/pages/support-page.css');
$shellJs = (string)file_get_contents($root . '/api/user/assets/user-shell.js');
$profileUpdate = (string)file_get_contents($root . '/api/user/profile_update.php');
$transferCreate = (string)file_get_contents($root . '/api/transfer/create.php');
$mobileTransfer = (string)file_get_contents($root . '/api/lib/mobile_transfer.php');
$supportBackend = (string)file_get_contents($root . '/api/lib/support.php');
$htaccess = (string)file_get_contents($root . '/.htaccess');
$tests = 0;

function expect_true(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach (['profile_get', 'profile_update', 'profile_photo_upload', 'profile_change_password', 'profile_change_pin'] as $action) {
    expect_true(str_contains($proxy, "case '{$action}':"), "missing profile proxy action {$action}");
}
expect_true(
    str_contains($proxy, "array_key_exists('name', \$body)")
    && str_contains($proxy, "array_key_exists('email', \$body)")
    && !preg_match("/\$profileBody\s*\[\s*['\"](?:role|pricing_country|wallet_currency|status)['\"]\s*\]/", $proxy)
    && str_contains($profileUpdate, 'FIELD_NOT_ALLOWED'),
    'Profile editable-field authority is not preserved'
);

foreach (['transfer_recipient', 'transfer_preview', 'transfer_create', 'transfer_history', 'transfer_favorites', 'transfer_favorite_add', 'transfer_favorite_remove'] as $action) {
    expect_true(str_contains($proxy, "case '{$action}':"), "missing transfer proxy action {$action}");
}
expect_true(
    strpos($proxy, 'user_proxy_validate_transaction_pin(', strpos($proxy, "case 'transfer_preview':")) !== false
    && str_contains($proxy, '$checkOnly = !empty($body[\'check_only\'])')
    && str_contains($proxy, "'check_only' => \$checkOnly"),
    'Transfer PIN/check-only validation contract is missing'
);
expect_true(
    str_contains($proxy, "'preview_token' => trim")
    && str_contains($transferCreate, 'zpay_transfer_claim_preview_token'),
    'Transfer execution is not bound to preview tokens'
);
expect_true(
    str_contains($transferCreate, 'zpay_transfer_schedule_post_response_tasks')
    && str_contains($transferCreate, "!empty(\$claim['resume'])")
    && str_contains($mobileTransfer, 'function zpay_transfer_run_post_response_tasks')
    && str_contains($mobileTransfer, "function_exists('fastcgi_finish_request')")
    && str_contains($mobileTransfer, 'zpay_transfer_operation_financially_committed'),
    'Transfer committed response or authoritative replay handling is incomplete'
);
expect_true(
    str_contains($proxy, "'canonical_only' => true")
    && str_contains($proxy, "'max_attempts' => 1")
    && str_contains($proxy, "'connect_timeout' => 15")
    && str_contains($proxy, "'timeout' => 60")
    && str_contains($transferJs, 'function submitTransferWithRecovery')
    && substr_count($transferJs, "shell.post('transfer_create', payload") === 2
    && str_contains($transferJs, "openTransferLoading('Confirming transfer status...')"),
    'Transfer proxy isolation or one-shot same-token response recovery is missing'
);
expect_true(
    str_contains($transferPage, 'transfer-page-header')
    && str_contains($transferPage, 'Receiver Account')
    && str_contains($transferPage, 'transferFavoriteList')
    && str_contains($transferPage, 'transferReviewRows')
    && str_contains($transferPage, 'transferReferenceInput')
    && !str_contains($transferPage, 'transferReceiverResult'),
    'Isolated Transfer page markup is incomplete'
);
expect_true(
    str_contains($transferPage, 'data-tracking-base=')
    && str_contains($transferPage, "app_api_url('transfer/receipt.php')")
    && str_contains($transferJs, "toast('Tracking link copied', 'ok')")
    && str_contains($transferJs, "url.origin !== base.origin")
    && str_contains($transferJs, "queryKeys[0] !== 't'")
    && !str_contains($transferJs, '`Z-Pay Transfer ${transferId'),
    'Transfer Open/Copy does not enforce the canonical public tracking URL'
);
expect_true(
    str_contains($transferJs, 'submitting: false')
    && str_contains($transferJs, 'Tap and hold to confirm transfer')
    && str_contains($transferJs, 'invalidateTransferReceiver')
    && str_contains($transferJs, 'verifiedInput')
    && str_contains($transferJs, 'loadTransferFavorites')
    && str_contains($transferJs, 'openTransferLoading')
    && str_contains($transferJs, 'openTransferError')
    && str_contains($transferJs, 'showTransferSuccess')
    && str_contains($transferJs, 'check_only: true'),
    'Transfer modal/hold/favourite/stale-state flow is incomplete'
);
expect_true(
    str_contains($proxy, 'USER_TRANSFER_FAVORITES/')
    && str_contains($proxy, 'transfer/check_recipient.php')
    && str_contains($proxy, 'fb_delete($path)'),
    'Authenticated favourite storage/validation is missing'
);
expect_true(
    str_contains($transferCss, '#transferSection .transfer-hold-button')
    && str_contains($transferCss, '.transfer-favorite-item')
    && str_contains($transferCss, '.zpay-transfer-modal')
    && str_contains($transferCss, '-webkit-touch-callout: none')
    && str_contains($transferCss, 'overflow-y: auto'),
    'Transfer Android-style isolated CSS is incomplete'
);

foreach (['support_config', 'support_list', 'support_details', 'support_create', 'support_reply', 'support_attachment'] as $action) {
    expect_true(str_contains($proxy, "case '{$action}':"), "missing support proxy action {$action}");
}
expect_true(
    str_contains($supportBackend, 'function support_user_can_access')
    && str_contains($supportBackend, "return \$uid !== '' && \$uid === (string)(\$ticket['uid'] ?? '');"),
    'Support ownership check is missing'
);
expect_true(
    str_contains($supportJs, "makeIdempotencyKey('SUPPORT-CREATE')")
    && str_contains($supportJs, "makeIdempotencyKey('SUPPORT-REPLY')")
    && str_contains($supportJs, 'option.dataset.relatedType')
    && str_contains($supportJs, 'selectedOptions?.[0]?.dataset.relatedType'),
    'Support idempotency or related request type is missing'
);
expect_true(
    str_contains($proxy, "'support/attachment.php?'")
    && str_contains($proxy, "header('Cache-Control: private, no-store"),
    'Private support attachment bridge is missing or cacheable'
);
expect_true(
    str_contains($contactPage, "header('Cache-Control: no-store')")
    && str_contains($contactPage, "header('Location: /user/support', true, 302)")
    && str_contains($supportPage, 'supportMainView')
    && str_contains($supportPage, 'supportStartChatButton'),
    'Contact Us canonical redirect or Support workspace page is incomplete'
);
expect_true(
    str_contains($supportCss, '#supportSection .support-ticket-scroll')
    && str_contains($supportCss, '#supportSection .support-category-grid')
    && str_contains($supportCss, '#supportSection .support-status-pill')
    && str_contains($supportCss, '#supportSection .support-chat-header')
    && str_contains($supportCss, '#supportSection .support-composer textarea')
    && str_contains($supportCss, 'height: 100dvh')
    && str_contains($supportCss, '#supportSection .support-action-modal'),
    'Support fixed/scroll conversation CSS is incomplete'
);
expect_true(
    str_contains($supportJs, 'text.textContent = String(message.message')
    && str_contains($supportJs, "'SESSION_EXPIRED'")
    && str_contains($supportJs, 'async function refreshCsrfToken')
    && str_contains($supportJs, 'function isCsrfError'),
    'Support XSS/session/CSRF handling is incomplete'
);
expect_true(
    str_contains($profileJs, 'function escapeHtml')
    || str_contains($shellJs, 'function escapeHtml'),
    'Shared/profile XSS-safe rendering helper is missing'
);

foreach (['dashboard', 'add-money', 'transfer', 'topup', 'bundle', 'bkash', 'nagad', 'history', 'services', 'notifications', 'profile', 'contact-us', 'support'] as $route) {
    expect_true(
        preg_match('#RewriteRule \^user/' . preg_quote($route, '#') . '/\?\$ /api/user/#i', $htaccess) === 1,
        "missing isolated route /user/{$route}"
    );
}
expect_true(
    str_contains($shellJs, "headers['X-CSRF-Token'] = state.csrf")
    && str_contains($shellJs, "'SESSION_EXPIRED'")
    && str_contains($shellJs, 'window.location.replace(loginUrl)')
    && !str_contains($shellJs, 'openSection('),
    'Shared shell does not preserve CSRF/session behavior or still routes as SPA'
);
expect_true(
    str_contains($proxy, 'function user_proxy_internal_api_attempts')
    && str_contains($proxy, 'CURLOPT_RESOLVE')
    && str_contains($proxy, 'http://127.0.0.1')
    && str_contains($proxy, "\$json['ok'] ?? \$json['success'] ?? false"),
    'Safe same-host proxy fallback/envelope compatibility is missing'
);

echo "User Panel feature tests passed ({$tests} assertions).\n";
