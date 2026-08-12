<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$identityStore = [];
$legacyIdentityRows = [];
$identityLookupShouldFail = false;

function fb_get(string $path)
{
    global $identityStore;
    return $identityStore[$path] ?? null;
}

function fb_request(
    string $method,
    string $path,
    mixed $data = null,
    array $query = [],
    array $headers = [],
    bool $includeHeaders = false
): array {
    global $legacyIdentityRows, $identityLookupShouldFail;
    if ($identityLookupShouldFail) {
        return ['ok' => false, 'status' => 503, 'json' => null, 'error' => 'fixture failure'];
    }

    $hash = json_decode((string)($query['equalTo'] ?? 'null'), true);
    return [
        'ok' => true,
        'status' => 200,
        'json' => is_string($hash) ? ($legacyIdentityRows[$hash] ?? null) : null,
        'error' => null,
    ];
}

function identity_test_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function auth_normalize_country_code(string $country): string
{
    return strtoupper(trim($country));
}

function detect_phone_country(string $phone): string
{
    return '';
}

require_once dirname(__DIR__) . '/api/lib/auth_android.php';
require_once dirname(__DIR__) . '/api/lib/register_android.php';
require_once dirname(__DIR__) . '/api/lib/user_registration_identity.php';

$nidHash = auth_app_identity_hash('  nid 123 456 ');
identity_test_expect($nidHash !== '', 'NID hash must be generated');
identity_test_expect(hash_equals($nidHash, auth_app_identity_hash('NID123456')), 'NID whitespace/case equivalents must normalize to one hash');
$punctuatedNidHashes = user_web_registration_identity_hashes('nid-123 456');
identity_test_expect(in_array($nidHash, $punctuatedNidHashes, true), 'NID punctuation equivalents must include the same compact hash');

$passportHash = auth_app_identity_hash(' ab 1234567 ');
identity_test_expect($passportHash !== '', 'Passport hash must be generated');
identity_test_expect(hash_equals($passportHash, auth_app_identity_hash('AB1234567')), 'Passport whitespace/case equivalents must normalize to one hash');
$punctuatedPassportHashes = user_web_registration_identity_hashes('ab-1234567');
identity_test_expect(in_array($passportHash, $punctuatedPassportHashes, true), 'Passport punctuation equivalents must include the same compact hash');

$identityStore['USER_INDEX/NID/' . $nidHash] = ['uid' => 'UID_NID_OWNER'];
$identityStore['USER_INDEX/PASSPORT/' . $passportHash] = ['uid' => 'UID_PASSPORT_OWNER'];
identity_test_expect(reg_app_document_owner_uid($nidHash, 'NID') === 'UID_NID_OWNER', 'NID owner must resolve from the canonical typed index');
identity_test_expect(reg_app_document_owner_uid($passportHash, 'PASSPORT') === 'UID_PASSPORT_OWNER', 'Passport owner must resolve from the canonical typed index');
identity_test_expect(!empty(user_web_registration_identity_lookup($punctuatedNidHashes, 'NID')['occupied']), 'NID index lookup must block normalized equivalents');
identity_test_expect(!empty(user_web_registration_identity_lookup($punctuatedPassportHashes, 'PASSPORT')['occupied']), 'Passport index lookup must block normalized equivalents');

$legacyHash = auth_app_identity_hash('LEGACY998877');
$legacyIdentityRows[$legacyHash] = ['UID_LEGACY' => ['identity_number_hash' => $legacyHash]];
$legacyLookup = user_web_registration_identity_lookup([$legacyHash], 'NID');
identity_test_expect(!empty($legacyLookup['ok']) && !empty($legacyLookup['occupied']) && ($legacyLookup['source'] ?? '') === 'LEGACY_USER_HASH', 'pre-index user identity hash must still block registration');

$identityLookupShouldFail = true;
$failedLookup = user_web_registration_identity_lookup([auth_app_identity_hash('UNAVAILABLE123')], 'PASSPORT');
identity_test_expect(empty($failedLookup['ok']) && empty($failedLookup['occupied']), 'identity lookup failure must fail closed');
$identityLookupShouldFail = false;

$nidPaths = reg_app_document_index_paths($nidHash, 'NID');
$passportPaths = reg_app_document_index_paths($passportHash, 'PASSPORT');
identity_test_expect(in_array('USER_IDENTITY_INDEX/' . $nidHash, $nidPaths, true), 'NID must preserve the legacy global identity index');
identity_test_expect(in_array('USER_INDEX/NID/' . $nidHash, $nidPaths, true), 'NID must include its typed identity index');
identity_test_expect(in_array('USER_INDEX/PASSPORT/' . $passportHash, $passportPaths, true), 'Passport must include its typed identity index');

echo "User registration identity uniqueness tests passed\n";
