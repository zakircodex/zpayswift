<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = [];
$queries = [];
$assertions = 0;

function rich_mine_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function rich_mine_query(array $rows, array $query): array
{
    uasort($rows, static function ($left, $right): int {
        return ((int)($left['created_at'] ?? 0)) <=> ((int)($right['created_at'] ?? 0));
    });
    if (isset($query['endAt'])) {
        $end = (int)json_decode((string)$query['endAt'], true);
        $rows = array_filter(
            $rows,
            static fn($row): bool => (int)($row['created_at'] ?? 0) <= $end
        );
    }
    if (isset($query['limitToLast'])) {
        $rows = array_slice($rows, -(int)$query['limitToLast'], null, true);
    }
    return $rows;
}

function fb_get(string $path, array $query = []): mixed
{
    global $fixture, $queries;
    $queries[] = ['path' => $path, 'query' => $query];
    $value = $fixture[$path] ?? null;
    return is_array($value) && $query !== [] ? rich_mine_query($value, $query) : $value;
}

function api_response(bool $ok, string $code, string $message, array $data = [], int $status = 200): never
{
    throw new RuntimeException($code . '|' . $status);
}

require_once $root . '/api/znews/lib/common.php';
require_once $root . '/api/znews/lib/posts.php';
require_once $root . '/api/znews/lib/post_access.php';

$text = 'শুরু bold শেষ';
$valid = znews_validate_post_bold_ranges([['start' => 5, 'end' => 9]], $text);
rich_mine_expect($valid === [['start' => 5, 'end' => 9]], 'Unicode-safe bold range was not accepted.');
rich_mine_expect(
    znews_post_bold_ranges([['start' => 5, 'end' => 9]], $text) === $valid,
    'Read-side range normalization changed valid formatting.'
);

foreach ([
    [['start' => -1, 'end' => 2]],
    [['start' => 2, 'end' => 99]],
    [['start' => 1, 'end' => 4], ['start' => 3, 'end' => 6]],
    [['start' => 3, 'end' => 3]],
    [['start' => '1', 'end' => '2']],
    'not-an-array',
] as $invalid) {
    $rejected = false;
    try {
        znews_validate_post_bold_ranges($invalid, 'Plain text');
    } catch (RuntimeException $exception) {
        $rejected = str_starts_with($exception->getMessage(), 'ZNEWS_POST_FORMAT_INVALID|422');
    }
    rich_mine_expect($rejected, 'Invalid bold range was not rejected.');
}

$tooMany = array_fill(0, 101, ['start' => 0, 'end' => 1]);
$tooManyRejected = false;
try {
    znews_validate_post_bold_ranges($tooMany, 'Text');
} catch (RuntimeException $exception) {
    $tooManyRejected = str_starts_with($exception->getMessage(), 'ZNEWS_POST_FORMAT_INVALID|422');
}
rich_mine_expect($tooManyRejected, 'More than 100 formatting ranges were accepted.');

$formatText = 'বাংলা bold 🎉 color';
$formatRuns = znews_validate_post_formatting_runs([
    ['start' => 0, 'end' => 5, 'bold' => true, 'color' => 'green'],
    ['start' => 6, 'end' => 10, 'bold' => true],
    ['start' => 13, 'end' => 18, 'color' => 'light_blue'],
], $formatText);
rich_mine_expect(count($formatRuns) === 3, 'Valid Bangla/emoji formatting runs were not accepted.');
rich_mine_expect(
    znews_post_bold_ranges_from_formatting_runs($formatRuns, $formatText) === [
        ['start' => 0, 'end' => 5],
        ['start' => 6, 'end' => 10],
    ],
    'Bold compatibility ranges were not derived from formatting runs.'
);

foreach ([
    [['start' => 0, 'end' => 2, 'color' => '#ffffff']],
    [['start' => 0, 'end' => 2, 'color' => 'url(javascript:alert(1))']],
    [['start' => 0, 'end' => 2, 'html' => '<script>']],
    [['start' => 0, 'end' => 2, 'bold' => 'true']],
    [['start' => 0, 'end' => 99, 'color' => 'red']],
] as $invalidRuns) {
    $rejected = false;
    try {
        znews_validate_post_formatting_runs($invalidRuns, 'Safe text');
    } catch (RuntimeException $exception) {
        $rejected = str_starts_with($exception->getMessage(), 'ZNEWS_POST_FORMAT_INVALID|422');
    }
    rich_mine_expect($rejected, 'Malicious or malformed formatting run was not rejected.');
}

$formatted = znews_format_public_post([
    'post_id' => 'POST_RICH',
    'creator_uid' => 'USER_A',
    'creator_name' => 'Creator',
    'title' => 'Safe title',
    'text' => '<b>plain</b> bold',
    'bold_ranges' => [['start' => 13, 'end' => 17]],
    'formatting_runs' => [['start' => 13, 'end' => 17, 'bold' => true, 'color' => 'orange']],
    'status' => 'ACTIVE',
    'visibility' => 'PUBLIC',
]);
rich_mine_expect(($formatted['text'] ?? '') === '<b>plain</b> bold', 'Canonical text was converted into HTML.');
rich_mine_expect(($formatted['bold_ranges'] ?? []) === [['start' => 13, 'end' => 17]], 'Public formatter lost bold ranges.');
rich_mine_expect(($formatted['formatting_runs'][0]['color'] ?? '') === 'orange', 'Public formatter lost the allowlisted text color.');
rich_mine_expect(!isset($formatted['email'], $formatted['wallet']), 'Public formatter exposed private fields.');

$legacy = znews_format_public_post([
    'post_id' => 'POST_LEGACY',
    'text' => 'Legacy post',
    'status' => 'ACTIVE',
]);
rich_mine_expect(($legacy['bold_ranges'] ?? null) === [], 'Legacy post did not default to plain text.');
rich_mine_expect(($legacy['formatting_runs'] ?? null) === [], 'Legacy post did not default to empty formatting runs.');

$countsByDataset = [];
foreach ([100, 1000, 10000] as $datasetSize) {
    $fixture = ['ZNEWS_USER_POSTS/U1' => []];
    for ($index = 1; $index <= $datasetSize; $index++) {
        $postId = 'POST' . str_pad((string)$index, 5, '0', STR_PAD_LEFT);
        $fixture['ZNEWS_USER_POSTS/U1'][$postId] = [
            'post_id' => $postId,
            'created_at' => $index,
            'status' => 'ACTIVE',
        ];
        $fixture['ZNEWS_POSTS/' . $postId] = [
            'post_id' => $postId,
            'creator_uid' => 'U1',
            'creator_name' => 'Creator',
            'title' => 'Title ' . $index,
            'text' => 'Body ' . $index,
            'bold_ranges' => [['start' => 0, 'end' => 4]],
            'formatting_runs' => [['start' => 0, 'end' => 4, 'bold' => true, 'color' => 'yellow']],
            'status' => 'ACTIVE',
            'visibility' => 'PUBLIC',
            'created_at' => $index,
            'updated_at' => $index,
        ];
        $fixture['ZNEWS_ENGAGEMENT/' . $postId] = [
            'like_count' => $index,
            'comment_count' => 2,
            'share_count' => 3,
        ];
    }

    $queries = [];
    $page = znews_owned_posts_page('U1', 10);
    $countsByDataset[(string)$datasetSize] = count($queries);
    rich_mine_expect(count($page['items']) === 10, "{$datasetSize}-row page exceeded or missed the 10-row limit.");
    rich_mine_expect((int)$page['items'][0]['created_at'] === $datasetSize, "{$datasetSize}-row page is not newest first.");
    rich_mine_expect((int)$page['items'][0]['like_count'] === $datasetSize, "{$datasetSize}-row page lost canonical engagement.");
    rich_mine_expect(($page['items'][0]['bold_ranges'] ?? []) === [['start' => 0, 'end' => 4]], "{$datasetSize}-row page lost formatting.");
    rich_mine_expect(($page['items'][0]['formatting_runs'][0]['color'] ?? '') === 'yellow', "{$datasetSize}-row page lost rich formatting.");
    rich_mine_expect(!empty($page['has_more']) && ($page['next_cursor'] ?? '') !== '', "{$datasetSize}-row page lost its cursor.");
    rich_mine_expect(count($queries) === 23, "{$datasetSize}-row page read count is not bounded at 23.");
    rich_mine_expect(($queries[0]['path'] ?? '') === 'ZNEWS_USER_POSTS/U1', "{$datasetSize}-row page did not use the owner index.");
    rich_mine_expect((int)($queries[0]['query']['limitToLast'] ?? 0) === 33, "{$datasetSize}-row owner query is not bounded at 33 candidates.");
    rich_mine_expect(
        count(array_filter($queries, static fn(array $call): bool => in_array($call['path'], ['ZNEWS_POSTS', 'ZNEWS_ENGAGEMENT'], true))) === 0,
        "{$datasetSize}-row page performed a growing root read."
    );

    $firstIds = array_column($page['items'], 'post_id');
    $queries = [];
    $next = znews_owned_posts_page('U1', 10, znews_cursor_decode((string)$page['next_cursor']));
    rich_mine_expect(count($next['items']) === 10, "{$datasetSize}-row next page did not contain 10 rows.");
    rich_mine_expect(array_intersect($firstIds, array_column($next['items'], 'post_id')) === [], "{$datasetSize}-row cursor repeated a post.");
    rich_mine_expect(count($queries) === 23, "{$datasetSize}-row next-page read count grew with the dataset.");
}

rich_mine_expect(count(array_unique($countsByDataset)) === 1, 'My Posts request count grows with dataset size.');

$accessSource = (string)file_get_contents($root . '/api/znews/lib/post_access.php');
$endpointSource = (string)file_get_contents($root . '/api/znews/posts/mine.php');
$updateSource = (string)file_get_contents($root . '/api/znews/lib/post_media_update.php');
$apiSource = (string)file_get_contents($root . '/znews/assets/znews-api.js');
rich_mine_expect(str_contains($accessSource, 'curl_multi_init'), 'Production My Posts child reads are not parallelized.');
rich_mine_expect(str_contains($accessSource, 'CURLMOPT_MAX_HOST_CONNECTIONS') && str_contains($accessSource, ', 8)'), 'My Posts parallel connection pressure is not capped.');
rich_mine_expect(!str_contains($endpointSource, 'znews_engagement_overlay'), 'My Posts endpoint retained the second engagement N+1 pass.');
rich_mine_expect(str_contains($updateSource, 'hash_equals($currentText, $text)'), 'Legacy client title edits do not preserve valid stored formatting.');
rich_mine_expect(str_contains($apiSource, 'timeoutMs: 20000'), 'My Posts safety timeout is missing.');

fwrite(STDOUT, 'PASS: ' . $assertions . ' rich-text/My Posts assertions; RTDB calls=' . json_encode($countsByDataset) . "\n");
