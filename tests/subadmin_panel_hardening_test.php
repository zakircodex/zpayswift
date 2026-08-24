<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$assertions = 0;

function subadmin_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function subadmin_function_source(string $source, string $functionName): string
{
    $start = strpos($source, 'function ' . $functionName . '(');
    if ($start === false) {
        return '';
    }

    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        return '';
    }

    $depth = 0;
    $length = strlen($source);
    for ($i = $brace; $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }

    return '';
}

$root = dirname(__DIR__);
$login = (string)file_get_contents($root . '/api/auth/login_start.php');
$auth = (string)file_get_contents($root . '/api/lib/auth.php');
$proxy = (string)file_get_contents($root . '/api/subadmin/proxy.php');
$subapi = (string)file_get_contents($root . '/api/lib/subadmin_api.php');
$usersEndpoint = (string)file_get_contents($root . '/api/users_list.php');
$usersLib = (string)file_get_contents($root . '/api/lib/users_admin.php');
$topupApi = (string)file_get_contents($root . '/api/public_api/topup_create.php');
$bundleApi = (string)file_get_contents($root . '/api/public_api/bundle_offers.php');
$mfs = (string)file_get_contents($root . '/api/lib/mfs.php');
$addMoney = (string)file_get_contents($root . '/api/lib/add_money.php');
$wallet = (string)file_get_contents($root . '/api/lib/wallet.php');
$ledger = (string)file_get_contents($root . '/api/wallet_ledger_list.php');
$dashboard = (string)file_get_contents($root . '/api/subadmin/dashboard.php');
$javascript = (string)file_get_contents($root . '/api/subadmin/assets/subadmin.js');
$css = (string)file_get_contents($root . '/api/subadmin/assets/subadmin.css');

$precheck = strpos($login, 'auth_admin_login_attempt_state(');
$lookup = strpos($login, 'auth_find_uid_by_phone_country(');
$verify = strpos($login, 'password_verify(');
$reset = strpos($login, 'auth_admin_login_reset_failed_passwords(');
subadmin_expect($precheck !== false && $lookup !== false && $precheck < $lookup, 'Password limiter must run before account lookup');
subadmin_expect($verify !== false && $precheck < $verify, 'Password limiter must run before password verification');
subadmin_expect($reset !== false && $verify < $reset, 'Successful password verification must reset prior failures');
subadmin_expect(str_contains($login, "api_response(false, 'RATE_LIMITED'") && str_contains($login, '], 429);'), 'Blocked login must return a stable HTTP 429 response');
subadmin_expect(str_contains($auth, 'fb_get_with_etag($path)') && str_contains($auth, 'fb_put_if_match($path, $row'), 'Failed-password updates must use Firebase CAS');
subadmin_expect(str_contains($login, "['SUBADMIN', 'ADMIN']"), 'Subadmin login role boundary changed');

$findKey = subadmin_function_source($subapi, 'subapi_find_key_by_plain');
subadmin_expect($findKey !== '', 'API key lookup helper is missing');
subadmin_expect(str_contains($findKey, "fb_get('USER_API_KEY_INDEX/' . \$hash)"), 'API key lookup must use the hashed owner index');
subadmin_expect(!str_contains($findKey, "fb_get('USER_API_KEYS')"), 'API authentication must not read all Subadmin API keys');
subadmin_expect(str_contains($subapi, "'USER_API_KEYS/' . \$uid . '/' . \$keyId") && str_contains($subapi, "'USER_API_KEY_INDEX/' . \$keyHash"), 'API key creation must atomically write key and lookup index');
subadmin_expect(!str_contains(subadmin_function_source($subapi, 'subapi_authenticate_request'), "'plain_key'"), 'Authenticated API context must not retain the plaintext key');

$usersPage = subadmin_function_source($usersLib, 'admin_users_subadmin_page');
subadmin_expect(str_contains($usersPage, "admin_firebase_cursor_page(\n        'USERS'"), 'Subadmin Users must use bounded Firebase pagination');
subadmin_expect(str_contains($usersPage, "admin_users_actor_can_access_user(\$row, \$actorUid, 'SUBADMIN')"), 'Users page must filter ownership before collecting rows');
subadmin_expect(str_contains($usersPage, '$roleFilter') && str_contains($usersPage, '$statusFilter') && str_contains($usersPage, '$search'), 'Users role/status/search filters must run server-side');
subadmin_expect(str_contains($usersEndpoint, "(\$actor['role'] ?? ''))) === 'SUBADMIN'") && str_contains($usersEndpoint, 'admin_users_subadmin_page('), 'Users endpoint must select the Subadmin-scoped page path');
subadmin_expect(str_contains($usersEndpoint, 'min(10, max(1, $requestedLimit > 0 ? $requestedLimit : 10))'), 'Subadmin Users page size must be capped at ten');

require_once $root . '/api/lib/users_admin.php';
subadmin_expect(admin_users_actor_can_access_user(['created_by_uid' => 'SA'], 'SA', 'SUBADMIN'), 'Subadmin must access its own created user');
subadmin_expect(!admin_users_actor_can_access_user(['created_by_uid' => 'SB'], 'SA', 'SUBADMIN'), 'Subadmin A must not access Subadmin B user');
subadmin_expect(admin_users_actor_can_access_user(['parent_subadmin_uid' => 'SA'], 'SA', 'SUBADMIN'), 'Canonical parent ownership must be accepted');

$bundlePanel = subadmin_function_source($subapi, 'subapi_panel_bundle_offers');
subadmin_expect(str_contains($bundlePanel, "admin_firebase_cursor_page(\n        'BUNDLE_OFFERS'"), 'Panel Bundle Offers must use bounded Firebase pagination');
subadmin_expect(!str_contains($bundlePanel, "fb_get('BUNDLE_OFFERS')"), 'Panel Bundle Offers must not read the full offer root');
subadmin_expect(str_contains($bundleApi, 'subapi_panel_bundle_offers(') && str_contains($bundleApi, "'pagination'"), 'Public Subadmin Bundle API must reuse bounded pagination');
subadmin_expect(!str_contains($bundleApi, 'bundle_list_visible_offers_for_user('), 'Public Subadmin Bundle API still calls the full-root helper');

subadmin_expect(str_contains($topupApi, "\$countryCode = 'BD';"), 'Public Subadmin Top-Up service denomination must be server-authoritative BDT');
subadmin_expect(!str_contains($topupApi, "topup_country_code(\$body['country_code']"), 'Client country still controls Public API Top-Up semantics');
subadmin_expect(str_contains($subapi, "\$countryCode = 'BD';"), 'Panel Top-Up service denomination changed');
subadmin_expect(str_contains($subapi, 'topup_calculate_payment_context('), 'Panel Top-Up no longer uses canonical payment context');

subadmin_expect(str_contains($proxy, 'mfs_preview_payload($uid, $body)'), 'Subadmin MFS preview must use canonical backend calculator');
subadmin_expect(str_contains($proxy, "mfs_create_request(\$uid, \$body, 'SUBADMIN_PANEL'"), 'Subadmin MFS submit must use canonical backend calculator');
subadmin_expect(str_contains($mfs, "'MFS_USER_STATUS_COUNTERS/' . \$uid") && str_contains($mfs, 'mfs_move_request_bucket('), 'MFS owner counters must move atomically with canonical status transitions');
subadmin_expect(str_contains($mfs, "'COUNTER_SOURCE_QUERY_FAILED'") && str_contains($mfs, "if (empty(\$response['ok']))"), 'MFS counter rebuild must fail closed when an indexed source query fails');
subadmin_expect(str_contains($proxy, 'mfs_read_bucket_page(') && str_contains($proxy, 'min(10, max(1, $limit))'), 'Subadmin MFS list must be bounded to ten');

$addMoneyPage = subadmin_function_source($addMoney, 'add_money_list_user_history_page');
subadmin_expect(str_contains($addMoneyPage, "admin_firebase_cursor_page(\n        'ADD_MONEY_BY_USER/' . \$uid"), 'Subadmin Add Money history must use its bounded owner index');
subadmin_expect(str_contains($addMoneyPage, 'add_money_public_request_row('), 'Subadmin Add Money history must use the public response allowlist');
subadmin_expect(str_contains($proxy, "unset(\$data['receipt_token'], \$data['preview_token_hash'])"), 'Subadmin MFS response must remove private receipt material');
subadmin_expect(str_contains($proxy, 'add_money_public_request_rows(') || str_contains($addMoneyPage, 'add_money_public_request_row('), 'Add Money receipt rows are not sanitized');

subadmin_expect(str_contains($wallet, 'function wallet_list_transfer_history_page('), 'Transfer history bounded helper is missing');
subadmin_expect(str_contains($wallet, 'function wallet_list_user_history_page('), 'Wallet history bounded helper is missing');
subadmin_expect(str_contains($ledger, 'function wallet_ledger_subadmin_page(') && str_contains($ledger, "['shallow' => 'true']"), 'Wallet ledger must inventory only shallow month keys');
subadmin_expect(str_contains($proxy, 'sub_proxy_history_page(') && str_contains($proxy, 'admin_firebase_cursor_page('), 'Unified Subadmin History must use bounded source pages');

$navigation = [
    'overviewSection', 'usersSection', 'createUserSection', 'panelTopupSection',
    'bundleOffersSection', 'mfsCreateSection', 'mfsRequestsSection', 'addMoneySection',
    'requestLogsSection', 'apiKeysSection', 'integrationGuideSection', 'apiTestSection',
];
$lastPosition = -1;
foreach ($navigation as $section) {
    $position = strpos($dashboard, 'data-page-section="' . $section . '"');
    subadmin_expect($position !== false && $position > $lastPosition, 'Subadmin navigation order is wrong at ' . $section);
    $lastPosition = $position;
}

foreach ([
    'bundleOffersPrevBtn', 'bundleOffersNextBtn', 'subMfsPrevBtn', 'subMfsNextBtn',
    'historyLogsPrevBtn', 'historyLogsNextBtn', 'usersPrevBtn', 'usersNextBtn',
    'addMoneyPrevBtn', 'addMoneyNextBtn', 'walletLedgerPrevBtn', 'walletLedgerNextBtn',
    'transferHistoryPrevBtn', 'transferHistoryNextBtn',
] as $id) {
    subadmin_expect(str_contains($dashboard, 'id="' . $id . '"'), 'Missing Subadmin pagination control: ' . $id);
    subadmin_expect(str_contains($javascript, "el('" . $id . "')"), 'Missing Subadmin pagination binding: ' . $id);
}

subadmin_expect(str_contains($javascript, 'resetCursorPager(') && str_contains($javascript, 'cursorPagerNext(') && str_contains($javascript, 'cursorPagerPrevious('), 'Subadmin filter/page cursor controls are incomplete');
subadmin_expect(str_contains($javascript, "limit: 10") || str_contains($javascript, 'limit:10'), 'Subadmin list requests must use a ten-item limit');
subadmin_expect(str_contains($css, '@media (max-width:430px)') && str_contains($css, '.list-pager'), 'Subadmin mobile pagination styles are missing');
subadmin_expect(str_contains($css, 'overflow-x:hidden') && str_contains($css, 'minmax(0,1fr)'), 'Subadmin shell lacks page-overflow protection');

$renderSources = $dashboard . "\n" . $javascript;
foreach (['password_hash', 'pin_hash', 'retailer_secret_pin', 'firebase_secret', 'telegram_token'] as $secretField) {
    subadmin_expect(!str_contains(strtolower($renderSources), $secretField), 'Private field is referenced by Subadmin rendering: ' . $secretField);
}

echo "Subadmin panel hardening tests passed ({$assertions} assertions).\n";
