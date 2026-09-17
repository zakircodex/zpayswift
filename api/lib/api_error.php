<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function api_error_request_id(): string
{
    static $requestId = '';

    if ($requestId !== '') {
        return $requestId;
    }

    try {
        $requestId = bin2hex(random_bytes(8));
    } catch (Throwable $exception) {
        $requestId = substr(hash('sha256', uniqid('api_error_', true)), 0, 16);
    }

    return $requestId;
}

function api_error_payload(string $requestId): array
{
    return [
        'ok' => false,
        'success' => false,
        'code' => 'INTERNAL_SERVER_ERROR',
        'message' => 'The request could not be completed. Please try again.',
        'data' => [
            'request_id' => $requestId,
        ],
    ];
}

function api_error_log(string $type, string $message, string $file = '', int $line = 0): void
{
    $requestId = api_error_request_id();
    $location = $file !== '' ? basename($file) . ':' . max(0, $line) : 'unknown';
    error_log(sprintf(
        'Z-Pay API %s [%s] at %s: %s',
        strtoupper(trim($type)) ?: 'ERROR',
        $requestId,
        $location,
        str_replace(["\r", "\n"], ' ', $message)
    ));
}

function api_error_emit_json(): void
{
    if (!empty($GLOBALS['zpay_api_error_emitted'])) {
        return;
    }
    $GLOBALS['zpay_api_error_emitted'] = true;

    if (headers_sent()) {
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $requestId = api_error_request_id();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8', true);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
    header('X-Request-ID: ' . $requestId, true);

    $encoded = json_encode(
        api_error_payload($requestId),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    echo is_string($encoded)
        ? $encoded
        : '{"ok":false,"success":false,"code":"INTERNAL_SERVER_ERROR","message":"The request could not be completed. Please try again.","data":{}}';
}

function api_error_register_handlers(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    header('X-Request-ID: ' . api_error_request_id(), true);

    set_exception_handler(static function (Throwable $exception): void {
        api_error_log('exception', $exception->getMessage(), $exception->getFile(), $exception->getLine());
        api_error_emit_json();
        exit;
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (!in_array((int)($error['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        api_error_log(
            'fatal',
            (string)($error['message'] ?? 'Fatal PHP error'),
            (string)($error['file'] ?? ''),
            (int)($error['line'] ?? 0)
        );
        api_error_emit_json();
    });
}
