<?php
declare(strict_types=1);

/* Keep the real copy outside every public document root. */
define('MIGRATION_TARGET', 'stage');
define('MYSQL_MIGRATION_ALLOW_WRITE', false);

/* Read-only source access. */
define('FIREBASE_DB_URL', 'https://example-default-rtdb.firebaseio.com');
define('FIREBASE_AUTH', '');
define('FIREBASE_CONNECT_TIMEOUT_SECONDS', 30);
define('FIREBASE_REQUEST_TIMEOUT_SECONDS', 300);

/* Isolated staging database only. */
define('MYSQL_DSN', 'mysql:host=localhost;dbname=cpanel_stage;charset=utf8mb4');
define('MYSQL_USER', 'cpanel_stage_user');
define('MYSQL_PASSWORD', '');
define('MYSQL_TABLE_PREFIX', 'zps_');
define('MYSQL_CONNECT_TIMEOUT_SECONDS', 5);
