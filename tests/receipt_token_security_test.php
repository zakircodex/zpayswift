<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
define('RECEIPT_TOKEN_TTL_SECONDS', 3600);

require_once dirname(__DIR__) . '/api/lib/add_money.php';

function receipt_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$issuedAt = 1700000000;
$tokenA = add_money_token(18);
$tokenB = add_money_token(18);
receipt_assert(strlen($tokenA) === 36 && preg_match('/^[a-f0-9]{36}$/', $tokenA) === 1, 'new receipt token must have 144 bits of random entropy');
receipt_assert(!hash_equals($tokenA, $tokenB), 'separate receipt tokens must not repeat');

$metadata = add_money_receipt_token_metadata($issuedAt);
receipt_assert((int)$metadata['receipt_token_version'] === 2, 'new receipt token must be versioned');
receipt_assert((int)$metadata['expires_at'] === $issuedAt + 3600, 'new receipt token expiry must use configured server TTL');
receipt_assert(!empty(add_money_receipt_token_access($metadata, $issuedAt + 3599)['ok']), 'unexpired receipt token must be accepted');
receipt_assert((add_money_receipt_token_access($metadata, $issuedAt + 3600)['code'] ?? '') === 'RECEIPT_TOKEN_EXPIRED', 'expired receipt token must be denied');

$revoked = array_merge($metadata, ['status' => 'REVOKED']);
receipt_assert((add_money_receipt_token_access($revoked, $issuedAt)['code'] ?? '') === 'RECEIPT_TOKEN_REVOKED', 'revoked receipt token must be denied');
receipt_assert(!empty(add_money_receipt_token_access(['created_at' => $issuedAt], $issuedAt + 999999)['ok']), 'legacy unversioned receipt token must follow explicit compatibility policy');

$tokenRow = array_merge($metadata, [
    'request_id' => 'AM_TEST_A',
    'uid' => 'UID_A',
    'path' => 'storage/add_money/2026-08/AM_TEST_A.png',
    'hash' => str_repeat('a', 64),
]);
$requestA = [
    'request_id' => 'AM_TEST_A',
    'uid' => 'UID_A',
    'receipt_token' => $tokenA,
    'receipt_path' => 'storage/add_money/2026-08/AM_TEST_A.png',
    'receipt_hash' => str_repeat('a', 64),
];
$requestB = array_merge($requestA, ['request_id' => 'AM_TEST_B']);
receipt_assert(add_money_receipt_token_matches_request($tokenA, $tokenRow, $requestA), 'new token must open its bound request');
receipt_assert(!add_money_receipt_token_matches_request($tokenA, $tokenRow, $requestB), 'Request A token must not open Request B');
receipt_assert(!add_money_receipt_token_matches_request($tokenB, $tokenRow, $requestA), 'random token must not open a receipt');

$publicRow = add_money_public_request_row(array_merge($requestA, [
    'receipt_url' => 'https://example.invalid/api/add_money/receipt.php?t=' . $tokenA,
]));
receipt_assert(
    !isset(
        $publicRow['receipt_token'],
        $publicRow['receipt_path'],
        $publicRow['receipt_hash'],
        $publicRow['receipt_token_version'],
        $publicRow['receipt_token_issued_at'],
        $publicRow['receipt_token_expires_at'],
        $publicRow['receipt_token_status']
    ),
    'authenticated browser payload must not expose private receipt metadata'
);
receipt_assert(isset($publicRow['receipt_url']), 'authenticated History must retain the working receipt action');

$endpoint = (string)file_get_contents(dirname(__DIR__) . '/api/add_money/receipt.php');
receipt_assert(str_contains($endpoint, 'Receipt Link Expired') && str_contains($endpoint, 'expired for security reasons'), 'expired receipt must render safe user-facing UX');
receipt_assert(str_contains($endpoint, "header('Cache-Control: private, no-store"), 'receipt responses must remain no-store');
receipt_assert(str_contains($endpoint, 'add_money_receipt_token_matches_request'), 'receipt endpoint must enforce token/request binding');

$userProxy = (string)file_get_contents(dirname(__DIR__) . '/api/user/proxy.php');
receipt_assert(substr_count($userProxy, 'add_money_public_request_rows(add_money_list_user_history') >= 4, 'User History callers must apply receipt metadata filtering');

$rewrite = (string)file_get_contents(dirname(__DIR__) . '/.htaccess');
receipt_assert(str_contains($rewrite, 'api/mfs/receipt.php?t=$1'), 'public MFS tracking route must remain unchanged');

foreach (['api/mfs/receipt.php', 'api/transfer/receipt.php'] as $publicReceiptPath) {
    $publicReceipt = (string)file_get_contents(dirname(__DIR__) . '/' . $publicReceiptPath);
    receipt_assert(str_contains($publicReceipt, "header('Cache-Control: private, no-store"), $publicReceiptPath . ' must not be cached');
}

echo "receipt token security tests passed\n";
