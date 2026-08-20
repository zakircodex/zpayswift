<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$js = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');
$css = (string)file_get_contents($root . '/api/admin/assets/admin-users.css');

$assertions = 0;

function admin_users_ui_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

admin_users_ui_expect(str_contains($dashboard, "filemtime(__DIR__ . '/assets/admin-users.css')"), 'Users stylesheet must use deploy-safe cache versioning');
admin_users_ui_expect(str_contains($dashboard, 'class="toolbar users-toolbar"'), 'Users toolbar presentation hook is missing');
admin_users_ui_expect(str_contains($dashboard, 'class="table-wrap users-table-wrap"'), 'Users table container hook is missing');
admin_users_ui_expect(str_contains($dashboard, 'class="users-table"'), 'Users table hook is missing');
admin_users_ui_expect(str_contains($dashboard, '<th>Created</th>'), 'Created timestamp column must remain visible');
admin_users_ui_expect(str_contains($dashboard, 'colspan="9"'), 'Users loading/empty row must span the full table');

foreach (['usersSearch', 'createUserBtn', 'walletHistoryBtn', 'reloadUsersBtn', 'usersPrevBtn', 'usersNextBtn', 'usersTableBody'] as $id) {
    admin_users_ui_expect(str_contains($dashboard, 'id="' . $id . '"'), "Existing Users control is missing: {$id}");
}

foreach (['Phone', 'Pricing', 'Wallet'] as $label) {
    admin_users_ui_expect(str_contains($js, '<span>' . $label . '</span>'), "Market distinction is missing: {$label}");
}

admin_users_ui_expect(str_contains($js, 'walletNativeCurrency(item)'), 'Wallet currency must use the existing canonical display helper');
admin_users_ui_expect(str_contains($js, 'user-primary-uid'), 'UID presentation hook is missing');
admin_users_ui_expect(str_contains($js, 'user-status-badge status-'), 'Status badge presentation hook is missing');
admin_users_ui_expect(str_contains($js, 'user-role-badge role-'), 'Role badge presentation hook is missing');
admin_users_ui_expect(str_contains($js, 'user-row-actions'), 'Responsive user action layout is missing');

foreach (['Account', 'Market', 'Security / Review', 'Wallet / Commission'] as $group) {
    admin_users_ui_expect(str_contains($js, '>' . $group . '</h4>'), "User detail group is missing: {$group}");
}

$viewStart = strpos($js, 'async function viewUser(uid)');
$viewEnd = strpos($js, 'function canManageApiKeys(role)', $viewStart === false ? 0 : $viewStart);
admin_users_ui_expect($viewStart !== false && $viewEnd !== false && $viewEnd > $viewStart, 'Unable to isolate User Detail renderer');
$viewUserBlock = substr($js, (int)$viewStart, (int)$viewEnd - (int)$viewStart);
foreach (['password_hash', 'pin_hash', 'session_token', 'firebase_path', 'kyc_private_path'] as $privateField) {
    admin_users_ui_expect(!str_contains(strtolower($viewUserBlock), $privateField), "Private field leaked into User Detail: {$privateField}");
}

admin_users_ui_expect(str_contains($js, 'user-wallet-currency-field'), 'Wallet currency presentation is missing');
admin_users_ui_expect(str_contains($js, 'No exchange conversion is applied.'), 'Wallet no-conversion contract text must remain visible');
admin_users_ui_expect(str_contains($js, "proxyPost('user_approve', { uid }") && str_contains($js, "proxyPost('user_reject', { uid }"), 'Account Review actions must keep their existing proxy bindings');
admin_users_ui_expect(str_contains($js, "proxyGet('users',") && str_contains($js, 'page,') && str_contains($js, 'limit: state.usersPagination.limit'), 'Users pagination/query contract changed');

admin_users_ui_expect(str_contains($css, '#usersSection .users-toolbar'), 'Users toolbar styles must be section-scoped');
admin_users_ui_expect(str_contains($css, '#usersSection .users-table th'), 'Users sticky table header rule is missing');
admin_users_ui_expect(str_contains($css, '@media(max-width:700px)'), 'Users mobile card breakpoint is missing');
admin_users_ui_expect(str_contains($css, 'content:attr(data-label)'), 'Mobile table-to-card labels are missing');
admin_users_ui_expect(str_contains($css, 'overflow:visible') && str_contains($css, 'min-width:0'), 'Mobile Users table must not force page-level overflow');
admin_users_ui_expect(str_contains($css, '.status-blocked') && str_contains($css, '.status-review') && str_contains($css, '.role-subadmin') && str_contains($css, '.role-admin'), 'Canonical role/status badge styles are incomplete');
admin_users_ui_expect(str_contains($css, '.user-detail-shell') && str_contains($css, '.user-wallet-form'), 'User Detail or Wallet presentation styles are missing');

echo "Admin Users UI polish tests passed ({$assertions} assertions).\n";
