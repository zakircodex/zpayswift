<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

define('FIREBASE_DB_URL', 'https://example-default-rtdb.firebaseio.com/');
define('FIREBASE_AUTH', 'test-token');

require_once dirname(__DIR__) . '/api/lib/firebase.php';

$assertions = 0;

$assertSame = static function ($expected, $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
};

$assertSame(
    'https://example-default-rtdb.firebaseio.com/.json?auth=test-token',
    fb_build_url(''),
    'Firebase root URL must include the slash before .json.'
);

$assertSame(
    'https://example-default-rtdb.firebaseio.com/.json?auth=test-token',
    fb_build_url('/'),
    'Slash-only paths must resolve to the Firebase root REST endpoint.'
);

$assertSame(
    'https://example-default-rtdb.firebaseio.com/ZNEWS_POSTS/ZNP123.json?auth=test-token',
    fb_build_url('ZNEWS_POSTS/ZNP123'),
    'Nested Firebase paths must remain unchanged.'
);

$assertSame(
    'https://example-default-rtdb.firebaseio.com/ZNEWS_POSTS/value%20with%20space.json?orderBy=%22created_at%22&auth=test-token',
    fb_build_url('ZNEWS_POSTS/value with space', ['orderBy' => '"created_at"']),
    'Nested paths and explicit query parameters must be encoded safely.'
);

$source = file_get_contents(dirname(__DIR__) . '/api/lib/firebase.php');
$assertSame(true, is_string($source), 'Firebase helper source must be readable.');
$assertSame(
    true,
    is_string($source) && str_contains($source, "\$encodedPath === '' ? '/.json'"),
    'Root URL regression guard must remain in the Firebase helper.'
);

echo "PASS: {$assertions} Firebase root URL assertions.\n";
