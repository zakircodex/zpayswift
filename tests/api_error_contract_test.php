<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require_once dirname(__DIR__) . '/api/lib/api_error.php';

function api_error_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$requestId = api_error_request_id();
$payload = api_error_payload($requestId);
api_error_expect(preg_match('/^[a-f0-9]{16}$/D', $requestId) === 1, 'request correlation ID must be random and bounded');
api_error_expect(($payload['code'] ?? '') === 'INTERNAL_SERVER_ERROR', 'safe exception code changed');
api_error_expect(($payload['data']['request_id'] ?? '') === $requestId, 'safe response must expose only the correlation ID');
api_error_expect(!str_contains(json_encode($payload), DIRECTORY_SEPARATOR), 'safe response must not expose a server path');

$bootstrap = (string)file_get_contents(dirname(__DIR__) . '/api/bootstrap.php');
$handler = (string)file_get_contents(dirname(__DIR__) . '/api/lib/api_error.php');
api_error_expect(str_contains($bootstrap, 'api_error_register_handlers();'), 'global API handlers are not registered');
api_error_expect(str_contains($handler, 'set_exception_handler'), 'uncaught exceptions are not normalized');
api_error_expect(str_contains($handler, 'register_shutdown_function'), 'fatal shutdown errors are not normalized');
api_error_expect(str_contains($handler, "header('Cache-Control: no-store"), 'internal error responses must be non-cacheable');
api_error_expect(str_contains($bootstrap, '?array $data = []'), 'nullable legacy API data must not trigger a TypeError');

$fixture = __DIR__ . '/fixtures/api_error_uncaught_fixture.php';
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 2>&1';
$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);
$jsonLine = '';
foreach ($output as $line) {
    if (str_starts_with(trim((string)$line), '{')) {
        $jsonLine = trim((string)$line);
    }
}
$runtimePayload = json_decode($jsonLine, true);
api_error_expect(is_array($runtimePayload), 'uncaught exceptions must emit a JSON response');
api_error_expect(($runtimePayload['code'] ?? '') === 'INTERNAL_SERVER_ERROR', 'runtime exception code changed');
api_error_expect(!str_contains($jsonLine, '/home/example/private'), 'runtime error response exposed a private server path');
api_error_expect(preg_match('/^[a-f0-9]{16}$/D', (string)($runtimePayload['data']['request_id'] ?? '')) === 1, 'runtime error response is missing its correlation ID');

echo "API error contract tests passed.\n";
