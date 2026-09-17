<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('html_errors', '0');

require_once dirname(__DIR__, 2) . '/api/lib/api_error.php';
api_error_register_handlers();

throw new RuntimeException('private path /home/example/private/config.php must not reach the response');
