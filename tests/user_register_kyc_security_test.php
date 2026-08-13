<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;
$fixtureRoot = sys_get_temp_dir() . '/zpay-register-kyc-' . bin2hex(random_bytes(6));
$privateRoot = $fixtureRoot . '/private';

function kyc_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function app_private_config_path(): string
{
    global $privateRoot;
    return $privateRoot . '/config.php';
}

function kyc_source(string $path): string
{
    $value = file_get_contents($path);
    if ($value === false) {
        fwrite(STDERR, "FAIL: could not read {$path}\n");
        exit(1);
    }
    return $value;
}

require_once $root . '/api/lib/user_registration_kyc.php';

$tokenA = 'URKYC' . str_repeat('A', 40);
$tokenB = 'URKYC' . str_repeat('B', 40);
$rootA = user_registration_kyc_token_root($tokenA);
$rootB = user_registration_kyc_token_root($tokenB);
mkdir($rootA, 0750, true);
mkdir($rootB, 0750, true);

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$documentPath = $rootA . '/document_fixture.png';
$selfiePath = $rootA . '/selfie_fixture.png';
$foreignPath = $rootB . '/foreign_fixture.png';
$invalidPath = $rootA . '/invalid.txt';
file_put_contents($documentPath, $png);
file_put_contents($selfiePath, $png);
file_put_contents($foreignPath, $png);
file_put_contents($invalidPath, 'not an image');

kyc_expect(user_registration_kyc_valid_private_file($documentPath, $tokenA), 'token owner document must be accepted');
kyc_expect(!user_registration_kyc_valid_private_file($documentPath, $tokenB), 'another registration token must not reuse a document');
kyc_expect(!user_registration_kyc_valid_private_file($foreignPath, $tokenA), 'forged foreign file path must be rejected');
kyc_expect(!user_registration_kyc_valid_private_file($invalidPath, $tokenA), 'unsupported actual MIME must be rejected');
kyc_expect(!user_registration_kyc_valid_private_file('https://example.test/document.png', $tokenA), 'public URL must never be accepted as private KYC storage');

$state = user_registration_kyc_state([
    'KYC' => [
        'document_path_private' => $documentPath,
        'selfie_path_private' => $selfiePath,
    ],
], $tokenA);
kyc_expect(!empty($state['document_ready']) && !empty($state['selfie_ready']) && !empty($state['kyc_ready']), 'owned document and selfie must complete KYC state');

$missingState = user_registration_kyc_state(['KYC' => ['document_path_private' => $documentPath]], $tokenA);
kyc_expect(!empty($missingState['document_ready']) && empty($missingState['selfie_ready']) && empty($missingState['kyc_ready']), 'missing selfie must block KYC completion');

$upload = kyc_source($root . '/api/auth/register_upload_kyc.php');
$prepare = kyc_source($root . '/api/auth/user_register_prepare_kyc.php');
$sendOtp = kyc_source($root . '/api/auth/user_register_send_otp.php');
$confirm = kyc_source($root . '/api/auth/user_register_confirm.php');
$proxy = kyc_source($root . '/api/user/proxy.php');
$androidApi = kyc_source(dirname($root) . '/zpayswift-android/app/src/main/java/com/zpayswift/app/api/ApiConfig.java');

kyc_expect(str_contains($androidApi, 'auth/register_upload_kyc.php'), 'Android KYC upload endpoint contract must remain canonical');
kyc_expect(str_contains($upload, "'image/jpeg' => 'jpg'") && str_contains($upload, "'image/png' => 'png'") && str_contains($upload, '8 * 1024 * 1024'), 'upload endpoint must validate actual MIME and size server-side');
kyc_expect(str_contains($prepare, "'web_kyc_draft' => true") && str_contains($prepare, 'AUTH_USER_REGISTER_PREAUTH/'), 'Web KYC draft must be bound to canonical pre-auth storage');
kyc_expect(str_contains($sendOtp, 'KYC_SESSION_MISMATCH') && str_contains($sendOtp, 'user_registration_kyc_state'), 'OTP preparation must bind KYC to registration details');
kyc_expect(str_contains($confirm, 'user_registration_kyc_state') && str_contains($confirm, "'document_path_private'") && str_contains($confirm, "'selfie_path_private'"), 'final registration must revalidate private KYC files');
kyc_expect(str_contains($proxy, "array_intersect_key(\$_FILES") && str_contains($proxy, "'document_photo', 'selfie_photo', 'file'"), 'proxy must allowlist registration upload fields');
kyc_expect(!str_contains($proxy, 'document_path_private' . "' => \$_POST") && !str_contains($proxy, 'selfie_path_private' . "' => \$_POST"), 'proxy must not accept client-selected private paths');

foreach ([$documentPath, $selfiePath, $foreignPath, $invalidPath] as $path) {
    @unlink($path);
}
@rmdir($rootA);
@rmdir($rootB);
@rmdir(dirname($rootA));
@rmdir($privateRoot);
@rmdir($fixtureRoot);

echo "User registration KYC security tests passed ({$assertions} assertions).\n";
