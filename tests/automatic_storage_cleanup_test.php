<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$root = dirname(__DIR__);
$assertions = 0;

function automatic_cleanup_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require_once $root . '/api/lib/storage_cleanup.php';
require_once $root . '/api/lib/storage_cleanup_email.php';

$tool = (string)file_get_contents($root . '/api/tools/cleanup_storage.php');
$config = (string)file_get_contents($root . '/api/config.example.php');
$workflow = (string)file_get_contents($root . '/.github/workflows/cpanel-production-deploy.yml');

automatic_cleanup_expect(!storage_cleanup_automatic_enabled(), 'Automatic deletion must be disabled when no explicit private setting exists.');
automatic_cleanup_expect(storage_cleanup_automatic_batch_limit() <= storage_cleanup_batch_limit(), 'Automatic runs must never exceed the global deletion batch limit.');
automatic_cleanup_expect(storage_cleanup_automatic_categories() === [
    'add_money_receipts',
    'notification_images',
    'support_attachments',
], 'Default automatic categories must exclude APK files.');
automatic_cleanup_expect(!storage_cleanup_automatic_apk_deletion_enabled(), 'Automatic APK deletion must require a separate private opt-in.');
automatic_cleanup_expect(storage_cleanup_trigger('AUTOMATIC') === 'AUTOMATIC', 'Automatic trigger must be preserved in audit metadata.');
automatic_cleanup_expect(storage_cleanup_trigger('unexpected') === 'MANUAL', 'Unknown cleanup triggers must fall back safely.');

automatic_cleanup_expect(str_contains($tool, "PHP_SAPI !== 'cli'") && str_contains($tool, "PHP_SAPI !== 'phpdbg'"), 'Automatic cleanup runner must be CLI-only.');
automatic_cleanup_expect(str_contains($tool, 'storage_cleanup_automatic_enabled()'), 'Automatic runner must require the explicit enable switch.');
automatic_cleanup_expect(str_contains($tool, 'storage_cleanup_acquire_lock()'), 'Automatic runner must acquire the shared nonblocking cleanup lock.');
automatic_cleanup_expect(str_contains($tool, "'trigger' => 'AUTOMATIC'"), 'Automatic runner must mark every deletion audit with its trigger.');
automatic_cleanup_expect(str_contains($tool, "'SYSTEM_AUTOMATIC_STORAGE_CLEANUP'"), 'Automatic cleanup must use a dedicated system actor.');
automatic_cleanup_expect(str_contains($tool, 'storage_cleanup_email_backup_candidates'), 'Automatic runner must back up every candidate before deletion.');
automatic_cleanup_expect(str_contains($tool, '--dry-run') && str_contains($tool, '--status') && str_contains($tool, '--email-test') && str_contains($tool, '--limit='), 'Automatic runner must provide bounded operational diagnostics.');
automatic_cleanup_expect(!str_contains($tool, 'relative_path') && !str_contains($tool, "['path']"), 'CLI output must not expose private storage paths.');

automatic_cleanup_expect(str_contains($config, "define('STORAGE_CLEANUP_AUTOMATIC_ENABLED', false);"), 'Example configuration must remain fail-closed.');
automatic_cleanup_expect(str_contains($config, "define('STORAGE_CLEANUP_AUTOMATIC_BATCH_LIMIT', 100);"), 'Example configuration must document the bounded daily batch.');
automatic_cleanup_expect(str_contains($config, "define('STORAGE_CLEANUP_AUTOMATIC_ALLOW_APK_DELETION', false);"), 'Example configuration must keep automatic APK deletion disabled.');
automatic_cleanup_expect(str_contains($config, "define('STORAGE_CLEANUP_EMAIL_BACKUP_ENABLED', false);"), 'Example email backup configuration must remain fail-closed.');
automatic_cleanup_expect(str_contains($workflow, 'deployment/api/tools/cleanup_storage.php'), 'Production package verification must require the automatic runner.');
automatic_cleanup_expect(str_contains($workflow, 'deployment/api/lib/storage_cleanup_email.php'), 'Production package verification must require the email backup gate.');

fwrite(STDOUT, "PASS: {$assertions} automatic storage cleanup assertions\n");
