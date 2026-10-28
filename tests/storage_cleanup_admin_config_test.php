<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/zpay-cleanup-admin-' . bin2hex(random_bytes(6));
mkdir($fixture . '/api/admin/config', 0700, true);
mkdir($fixture . '/api/lib', 0700, true);
$assertions = 0;

function cleanup_admin_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['get.php', 'save.php'] as $name) {
    copy($root . '/api/admin/config/' . $name, $fixture . '/api/admin/config/' . $name);
}
foreach (['storage_cleanup.php', 'storage_cleanup_email.php', 'app_runtime.php'] as $name) {
    copy($root . '/api/lib/' . $name, $fixture . '/api/lib/' . $name);
}

$bootstrap = <<<'PHP'
<?php
declare(strict_types=1);
$input = json_decode(file_get_contents(__DIR__ . '/../input.json'), true, 512, JSON_THROW_ON_ERROR);
define('STORAGE_CLEANUP_EMAIL_BACKUP_ENABLED', true);
define('STORAGE_CLEANUP_EMAIL_BACKUP_RECIPIENT', 'old@example.com');
require_once __DIR__ . '/lib/app_runtime.php';
function app_private_config_path(): string { return dirname(__DIR__) . '/private/config.php'; }
function api_response(bool $ok, string $code, string $message, ?array $data = [], int $httpStatus = 200): void {
    echo json_encode(compact('ok', 'code', 'data', 'httpStatus'), JSON_THROW_ON_ERROR);
    exit;
}
function api_require_method(string $method): void {}
function auth_require_admin_session(bool $strict): array {
    global $input;
    if (empty($input['authorized'])) { api_response(false, 'UNAUTHORIZED', 'Admin required', [], 401); }
    return ['user' => ['uid' => 'FIXTURE_ADMIN']];
}
function api_read_json_body(): array { global $input; return $input['body'] ?? []; }
function now_ts(): int { return 1800000000; }
function fb_request(string $method, string $path, mixed $data = null, array $query = [], array $headers = [], bool $includeHeaders = false): array {
    global $input;
    file_put_contents(dirname(__DIR__) . '/reads', 'read', FILE_APPEND);
    return $input['read'];
}
function fb_put_if_match(string $path, mixed $data, string $etag): array {
    global $input;
    if ($etag !== '"config-1"') { throw new RuntimeException('Missing expected config version'); }
    if (!empty($input['conflict'])) { return ['ok' => false, 'status' => 412]; }
    file_put_contents(dirname(__DIR__) . '/written.json', json_encode($data, JSON_THROW_ON_ERROR));
    return ['ok' => true, 'status' => 200];
}
function admin_action_log(...$arguments): void {}
function system_log(...$arguments): void {}
PHP;
file_put_contents($fixture . '/api/bootstrap.php', $bootstrap);

function cleanup_admin_run(string $route, array $input): array
{
    global $fixture;
    foreach (['reads', 'written.json'] as $name) {
        if (is_file($fixture . '/' . $name)) {
            unlink($fixture . '/' . $name);
        }
    }
    file_put_contents($fixture . '/input.json', json_encode($input, JSON_THROW_ON_ERROR));
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture . '/api/admin/config/' . $route);
    exec($command, $output, $exitCode);
    cleanup_admin_expect($exitCode === 0, 'Fixture route must exit cleanly.');
    $response = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
    $response['written'] = is_file($fixture . '/written.json')
        ? json_decode(file_get_contents($fixture . '/written.json'), true, 512, JSON_THROW_ON_ERROR) : null;
    $response['read_performed'] = is_file($fixture . '/reads');
    return $response;
}

try {
    $row = ['storage_cleanup_email_enabled' => false, 'storage_cleanup_email_recipient' => 'new@example.com',
        'storage_cleanup_email_revision' => str_repeat('a', 32), 'unrelated_child' => ['preserved' => true]];
    $base = ['authorized' => true, 'read' => ['ok' => true, 'status' => 200,
        'body' => json_encode($row, JSON_THROW_ON_ERROR), 'headers' => ['etag' => '"config-1"']]];
    foreach (['get.php', 'save.php'] as $route) {
        $unauthorized = cleanup_admin_run($route, array_replace($base, ['authorized' => false]));
        cleanup_admin_expect($unauthorized['code'] === 'UNAUTHORIZED' && !$unauthorized['read_performed'] && $unauthorized['written'] === null, 'Unauthorized requests cannot read settings or authorize a cleanup configuration change.');
        foreach ([['ok' => false, 'status' => 0], ['ok' => false, 'status' => 403], ['ok' => false, 'status' => 500],
            ['ok' => true, 'status' => 200, 'body' => '{invalid', 'headers' => ['etag' => '"1"']]] as $failure) {
            $response = cleanup_admin_run($route, array_replace($base, ['read' => $failure]));
            cleanup_admin_expect(!$response['ok'] && $response['httpStatus'] === 503 && $response['written'] === null, 'A failed Admin settings read must not present defaults or save them.');
        }
    }

    $get = cleanup_admin_run('get.php', $base);
    cleanup_admin_expect($get['ok'] && $get['data']['storage_cleanup_email_enabled'] === false && $get['data']['storage_cleanup_email_recipient'] === 'new@example.com', 'Admin read must preserve disabled state and changed recipient.');
    $save = cleanup_admin_run('save.php', $base);
    cleanup_admin_expect($save['ok'] && $save['written']['storage_cleanup_email_enabled'] === false
        && $save['written']['storage_cleanup_email_recipient'] === 'new@example.com', 'Omitted backup fields must preserve verified current settings.');
    cleanup_admin_expect($save['written']['storage_cleanup_email_revision'] === $row['storage_cleanup_email_revision']
        && $save['written']['unrelated_child'] === $row['unrelated_child'], 'Unchanged settings keep their revision and unrelated config children.');

    $changed = cleanup_admin_run('save.php', $base + ['body' => ['storage_cleanup_email_enabled' => true, 'storage_cleanup_email_recipient' => 'third@example.com']]);
    cleanup_admin_expect($changed['ok'] && $changed['written']['storage_cleanup_email_revision'] !== $row['storage_cleanup_email_revision'], 'A settings change must receive a fresh server revision.');
    $conflict = cleanup_admin_run('save.php', $base + ['conflict' => true]);
    cleanup_admin_expect(!$conflict['ok'] && $conflict['code'] === 'APP_CONFIG_CHANGED' && $conflict['written'] === null, 'A concurrent settings change must reject a stale save.');
    $invalid = cleanup_admin_run('save.php', $base + ['body' => ['storage_cleanup_email_enabled' => 'true']]);
    cleanup_admin_expect(!$invalid['ok'] && $invalid['httpStatus'] === 422 && $invalid['written'] === null, 'Malformed client values cannot enable backups.');

    require_once $root . '/api/lib/storage_cleanup.php';
    function app_private_config_path(): string { global $fixture; return $fixture . '/private/config.php'; }
    $lock = storage_cleanup_acquire_lock();
    cleanup_admin_expect(is_resource($lock), 'Fixture cleanup lock must be held.');
    try {
        $busy = cleanup_admin_run('save.php', $base);
        cleanup_admin_expect($busy['code'] === 'STORAGE_CLEANUP_BUSY' && !$busy['read_performed'] && $busy['written'] === null, 'Admin save must not race a run holding the cleanup lock.');
    } finally {
        storage_cleanup_release_lock($lock);
    }
    fwrite(STDOUT, "PASS: {$assertions} cleanup Admin configuration assertions\n");
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($fixture);
}
