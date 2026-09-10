<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = [];
$assertions = 0;

function znews_firebase_key(string $value, string $field): string
{
    $key = trim($value);
    if ($key === '' || preg_match('/[.#$\[\]\/]/', $key) === 1) {
        throw new InvalidArgumentException('Invalid ' . $field);
    }
    return $key;
}

function fb_get(string $path)
{
    global $fixture;
    return $fixture[$path] ?? null;
}

function reply_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require_once $root . '/api/znews/lib/comments/common.php';

$fixture['ZNEWS_COMMENTS/POST1/PARENT1'] = [
    'comment_id' => 'PARENT1',
    'post_id' => 'POST1',
    'author_uid' => 'USER1',
    'author_name' => 'A Creator',
    'status' => 'ACTIVE',
    'moderation_status' => 'APPROVED',
    'deleted_at' => 0,
];

$target = znews_comment_reply_target('POST1', 'PARENT1');
reply_expect(($target['parent_comment_id'] ?? '') === 'PARENT1', 'Public parent ID was not retained.');
reply_expect(($target['root_comment_id'] ?? '') === 'PARENT1', 'First-level reply root was not assigned.');
reply_expect(($target['reply_to_uid'] ?? '') === 'USER1', 'Reply target UID snapshot is missing.');
reply_expect(($target['reply_to_name'] ?? '') === 'A Creator', 'Reply target name snapshot is missing.');

$fixture['ZNEWS_COMMENTS/POST1/REPLY1'] = array_merge(
    $fixture['ZNEWS_COMMENTS/POST1/PARENT1'],
    [
        'comment_id' => 'REPLY1',
        'parent_comment_id' => 'PARENT1',
        'root_comment_id' => 'PARENT1',
        'author_uid' => 'USER2',
        'author_name' => 'B Creator',
    ]
);
$nested = znews_comment_reply_target('POST1', 'REPLY1');
reply_expect(($nested['root_comment_id'] ?? '') === 'PARENT1', 'Nested reply did not retain the thread root.');
reply_expect(($nested['reply_to_name'] ?? '') === 'B Creator', 'Nested reply did not target the direct author.');

$fixture['ZNEWS_COMMENTS/POST1/BLOCKED1'] = array_merge(
    $fixture['ZNEWS_COMMENTS/POST1/PARENT1'],
    ['comment_id' => 'BLOCKED1', 'status' => 'BLOCKED']
);
reply_expect(znews_comment_reply_target('POST1', 'BLOCKED1') === [], 'A non-public comment can be used as a reply target.');

$formatted = znews_comment_format([
    'comment_id' => 'REPLY2',
    'post_id' => 'POST1',
    'author_uid' => 'USER3',
    'author_name' => 'C Creator',
    'parent_comment_id' => 'PARENT1',
    'root_comment_id' => 'PARENT1',
    'reply_to_uid' => 'USER1',
    'reply_to_name' => 'A Creator',
    'text' => 'Thanks',
]);
reply_expect(($formatted['parent_comment_id'] ?? '') === 'PARENT1', 'Public format omitted parent_comment_id.');
reply_expect(($formatted['reply_to_name'] ?? '') === 'A Creator', 'Public format omitted reply_to_name.');

echo "Z News comment replies passed ({$assertions} assertions).\n";
