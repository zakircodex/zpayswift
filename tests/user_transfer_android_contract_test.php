<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$androidRoot = trim((string)(getenv('ZPAY_ANDROID_REPO') ?: ''));
if ($androidRoot === '') {
    $androidRoot = dirname($root) . '/zpayswift-android';
}

if (!is_file($androidRoot . '/app/src/main/java/com/zpayswift/app/TransferActivity.java')) {
    echo "Android contract test skipped: Android repository is unavailable.\n";
    exit(0);
}

$tests = 0;
function transfer_contract_expect(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$androidConfig = (string)file_get_contents($androidRoot . '/app/src/main/java/com/zpayswift/app/api/ApiConfig.java');
$androidTransfer = (string)file_get_contents($androidRoot . '/app/src/main/java/com/zpayswift/app/TransferActivity.java');
$webProxy = (string)file_get_contents($root . '/api/user/proxy.php');
$webTransfer = (string)file_get_contents($root . '/api/user/assets/pages/transfer-page.js');
$transferCreate = (string)file_get_contents($root . '/api/transfer/create.php');
$mobileTransfer = (string)file_get_contents($root . '/api/lib/mobile_transfer.php');

transfer_contract_expect(
    str_contains($androidConfig, 'TRANSFER_CREATE = "transfer/create.php"')
    && str_contains($androidConfig, 'TOPUP_READ_TIMEOUT_MS = 60000'),
    'Android transfer endpoint or read timeout changed'
);
transfer_contract_expect(
    str_contains($androidTransfer, 'payload.put("preview_token", previewToken)')
    && str_contains($androidTransfer, 'payload.put("reference", referenceText)')
    && str_contains($androidTransfer, 'postJson(ApiConfig.TRANSFER_CREATE, payload, ApiConfig.TOPUP_READ_TIMEOUT_MS)'),
    'Android create request contract is incomplete'
);
transfer_contract_expect(
    str_contains($webProxy, "'transfer/create.php'")
    && str_contains($webProxy, "'preview_token' => trim")
    && str_contains($webProxy, "'reference' => trim")
    && str_contains($webProxy, "'canonical_only' => true")
    && str_contains($webProxy, "'max_attempts' => 1")
    && str_contains($webProxy, "'connect_timeout' => 15")
    && str_contains($webProxy, "'timeout' => 60"),
    'Web proxy does not preserve the Android create transport contract'
);
transfer_contract_expect(
    str_contains($webTransfer, 'const payload = { preview_token: token, reference }')
    && substr_count($webTransfer, "shell.post('transfer_create', payload") === 2,
    'Web create payload or one-shot recovery contract is incomplete'
);
transfer_contract_expect(
    str_contains($transferCreate, 'zpay_transfer_claim_preview_token')
    && str_contains($transferCreate, "!empty(\$claim['resume'])")
    && str_contains($transferCreate, 'zpay_transfer_execute_preview')
    && str_contains($mobileTransfer, 'zpay_transfer_execute_financial'),
    'Web create route is not connected to preview replay and the financial executor'
);
transfer_contract_expect(
    str_contains($transferCreate, "api_response(true, 'TRANSFER_SUCCESS'")
    && str_contains($transferCreate, "'transfer' => zpay_transfer_public_row"),
    'Web backend canonical success envelope is incomplete'
);

echo "Android/Web transfer contract tests passed ({$tests} assertions).\n";
