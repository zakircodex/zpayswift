<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function zsky_rules_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function zsky_rules_no_client_grants(array $node, string $path = 'rules'): void
{
    foreach ($node as $key => $value) {
        $childPath = $path . '/' . $key;
        if ($key === '.read' || $key === '.write') {
            zsky_rules_expect($value === false, "Direct Firebase access was granted at {$childPath}.");
            continue;
        }
        if (is_array($value)) {
            zsky_rules_no_client_grants($value, $childPath);
        }
    }
}

function zsky_rules_read(string $path): string
{
    $source = file_get_contents($path);
    zsky_rules_expect(is_string($source), 'Required source file is unavailable: ' . $path);
    return (string)$source;
}

$firebaseConfig = json_decode(zsky_rules_read($root . '/firebase.json'), true);
$rulesDocument = json_decode(zsky_rules_read($root . '/database.rules.json'), true);

zsky_rules_expect(is_array($firebaseConfig), 'firebase.json must contain valid JSON.');
zsky_rules_expect(is_array($rulesDocument), 'database.rules.json must contain valid JSON.');
zsky_rules_expect(
    ($firebaseConfig['database']['rules'] ?? '') === 'database.rules.json',
    'Firebase deployment does not point to the audited RTDB rules file.'
);

$rules = is_array($rulesDocument['rules'] ?? null) ? $rulesDocument['rules'] : [];
zsky_rules_expect(($rules['.read'] ?? null) === false, 'RTDB must deny direct reads by default.');
zsky_rules_expect(($rules['.write'] ?? null) === false, 'RTDB must deny direct writes by default.');
zsky_rules_no_client_grants($rules);

zsky_rules_expect(
    ($rules['ZNEWS_PUBLIC_FEED']['.indexOn'] ?? null) === ['created_at'],
    'ZNEWS_PUBLIC_FEED created_at index is missing.'
);
zsky_rules_expect(
    ($rules['ZNEWS_USER_POSTS']['$uid']['.indexOn'] ?? null) === ['created_at'],
    'ZNEWS_USER_POSTS/$uid created_at index is missing.'
);
zsky_rules_expect(
    ($rules['ZNEWS_COMMENTS']['$post_id']['.indexOn'] ?? null) === ['created_at'],
    'ZNEWS_COMMENTS/$post_id created_at index is missing.'
);

$firebaseHelper = zsky_rules_read($root . '/api/lib/firebase.php');
zsky_rules_expect(
    str_contains($firebaseHelper, "if (FIREBASE_AUTH !== '')")
        && str_contains($firebaseHelper, "\$query['auth'] = FIREBASE_AUTH"),
    'Backend Firebase REST calls must keep using the server credential.'
);

$feed = zsky_rules_read($root . '/api/znews/lib/feed_ranking.php');
$mine = zsky_rules_read($root . '/api/znews/lib/post_access.php');
$comments = zsky_rules_read($root . '/api/znews/lib/comments/access.php');
foreach ([
    'feed' => $feed,
    'mine' => $mine,
    'comments' => $comments,
] as $label => $source) {
    zsky_rules_expect(str_contains($source, "'orderBy' => json_encode('created_at')"), "{$label} query is not using created_at.");
    zsky_rules_expect(
        str_contains($source, "'limitToLast'") || str_contains($source, "'limitToFirst'"),
        "{$label} query is not hard bounded."
    );
}

foreach ([
    'posts/create.php',
    'posts/update.php',
    'posts/delete.php',
    'posts/mine.php',
    'likes/set.php',
    'comments/create.php',
    'shares/create.php',
] as $relative) {
    $source = zsky_rules_read($root . '/api/znews/' . $relative);
    zsky_rules_expect(str_contains($source, 'znews_require_creator'), "{$relative} lost creator authorization.");
}

$feedEndpoint = zsky_rules_read($root . '/api/znews/public/feed.php');
$commentsEndpoint = zsky_rules_read($root . '/api/znews/comments/list.php');
$mineEndpoint = zsky_rules_read($root . '/api/znews/posts/mine.php');
zsky_rules_expect(
    str_contains($feedEndpoint, "znews_limit(\$_GET['limit'] ?? 20, 20, 20)"),
    'Public feed must enforce a maximum page size of 20.'
);
zsky_rules_expect(
    str_contains($commentsEndpoint, "znews_limit(\$_GET['limit'] ?? 20, 20, 20)"),
    'Public comments must enforce a page size of 20.'
);
zsky_rules_expect(
    str_contains($mineEndpoint, "znews_limit(\$_GET['limit'] ?? 10, 10, 10)"),
    'My Posts must enforce a page size of 10.'
);

echo "Z Sky 24 Firebase security rules passed ({$assertions} assertions).\n";
