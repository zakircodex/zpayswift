<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = (string)file_get_contents($root . '/api/wallet_add_balance.php');
$dashboardJs = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');
$dashboardCss = (string)file_get_contents($root . '/api/admin/assets/dashboard.css');

$assertions = 0;

function admin_wallet_ui_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

admin_wallet_ui_expect(
    str_contains($endpoint, '$historyResult = wallet_store_transfer_records($transfer);'),
    'Admin wallet history must finalize without rewriting ledger rows'
);
admin_wallet_ui_expect(
    !str_contains($endpoint, 'wallet_store_transfer_records($transfer, $ledgerRows)'),
    'Duplicate admin wallet ledger finalization must not return'
);
admin_wallet_ui_expect(
    !str_contains($endpoint, "'ledger_rows' => \$ledgerRows"),
    'Retry metadata must not retain obsolete duplicate ledger rows'
);
admin_wallet_ui_expect(
    str_contains($endpoint, 'wallet_apply_available_delta_with_operation('),
    'The idempotent wallet mutation path must remain active'
);
admin_wallet_ui_expect(
    str_contains($dashboardJs, 'function showAdminError(')
        && str_contains($dashboardJs, 'function showAdminNotice('),
    'Admin dashboard must provide an in-app notice component'
);
admin_wallet_ui_expect(
    preg_match('/\balert\s*\(/', $dashboardJs) !== 1,
    'Native browser alerts must not be used for admin errors'
);
admin_wallet_ui_expect(
    str_contains($dashboardJs, 'id="walletActionFeedback"')
        && str_contains($dashboardJs, "setInlineFeedback('walletActionFeedback'"),
    'Wallet action errors must render inside the wallet modal'
);
admin_wallet_ui_expect(
    str_contains($dashboardJs, "['TRANSFER_HISTORY_FAILED', 'FINANCIAL_OPERATION_FINALIZATION_FAILED']")
        && str_contains($dashboardJs, 'Press Submit again in this window'),
    'Retryable finalization errors need safe inline retry guidance'
);
admin_wallet_ui_expect(
    str_contains($dashboardCss, '.admin-notice-wrap')
        && str_contains($dashboardCss, '.action-feedback'),
    'Admin notice and inline feedback styles are required'
);

echo "Admin wallet add UI regression tests passed ({$assertions} assertions).\n";
