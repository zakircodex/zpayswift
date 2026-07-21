<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$store = [];
$versions = [];
$injectCompetitorPath = '';
$injectCompetitorUid = '';

function test_parts(string $path): array
{
    return array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
}

function test_get(string $path)
{
    global $store;
    $node = $store;
    foreach (test_parts($path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }
    return $node;
}

function test_set(string $path, $value): void
{
    global $store, $versions;
    $node =& $store;
    foreach (test_parts($path) as $part) {
        if (!isset($node[$part]) || !is_array($node[$part])) {
            $node[$part] = [];
        }
        $node =& $node[$part];
    }
    $node = $value;
    $versions[$path] = (int)($versions[$path] ?? 0) + 1;
}

function fb_get(string $path)
{
    return test_get($path);
}

function fb_get_with_etag(string $path): array
{
    global $versions;
    return [
        'ok' => true,
        'status' => 200,
        'etag' => 'E' . (string)($versions[$path] ?? 0),
        'value' => test_get($path),
    ];
}

function fb_put_if_match(string $path, $data, string $etag): array
{
    global $versions, $injectCompetitorPath, $injectCompetitorUid;
    if ($injectCompetitorPath === $path) {
        $competitor = $injectCompetitorUid;
        $injectCompetitorPath = '';
        $injectCompetitorUid = '';
        test_set($path, $competitor);
        return ['ok' => false, 'status' => 412];
    }

    if ($etag !== 'E' . (string)($versions[$path] ?? 0)) {
        return ['ok' => false, 'status' => 412];
    }
    test_set($path, $data);
    return ['ok' => true, 'status' => 200];
}

function fb_delete_if_match(string $path, string $etag): array
{
    global $versions;
    if ($etag !== 'E' . (string)($versions[$path] ?? 0)) {
        return ['ok' => false, 'status' => 412];
    }
    test_set($path, null);
    return ['ok' => true, 'status' => 200];
}

require_once dirname(__DIR__) . '/api/lib/auth.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$emailKeys = auth_email_index_keys('Person.Name@example.com');
assert_true(in_array(md5('person.name@example.com'), $emailKeys, true), 'legacy MD5 email index key must remain supported');
assert_true(count($emailKeys) === 2, 'canonical and legacy email index keys should both be reserved');

$path = 'USER_INDEX/EMAIL/' . $emailKeys[0];
$first = auth_index_claim($path, 'UID_A', 'UID_A');
assert_true(!empty($first['ok']) && !empty($first['claimed']), 'first identity index claim should win');
$sameOwner = auth_index_claim($path, 'UID_A', 'UID_A');
assert_true(!empty($sameOwner['ok']) && empty($sameOwner['claimed']), 'same owner retry should be idempotent');
$otherOwner = auth_index_claim($path, 'UID_B', 'UID_B');
assert_true(empty($otherOwner['ok']) && !empty($otherOwner['conflict']), 'different owner must not steal identity index');
assert_true(!auth_index_release($path, 'UID_B'), 'different owner must not release identity index');
assert_true(auth_index_release($path, 'UID_A'), 'owner should release its own identity index');
assert_true(test_get($path) === null, 'released identity index should be empty');

$racePath = 'USER_INDEX/PHONE/60123456789';
$injectCompetitorPath = $racePath;
$injectCompetitorUid = 'UID_WINNER';
$loser = auth_index_claim($racePath, 'UID_LOSER', 'UID_LOSER');
assert_true(empty($loser['ok']) && !empty($loser['conflict']), 'CAS loser must observe the competing index owner');
assert_true(test_get($racePath) === 'UID_WINNER', 'CAS loser must not overwrite the winning identity index');

echo "auth index CAS tests passed\n";
