<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function admin_storage_cleanup_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$dashboardJs = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');
$cleanupJs = (string)file_get_contents($root . '/api/admin/assets/storage-cleanup.js');
$cleanupCss = (string)file_get_contents($root . '/api/admin/assets/admin-storage-cleanup.css');
$proxy = (string)file_get_contents($root . '/api/admin/proxy.php');
$library = (string)file_get_contents($root . '/api/lib/storage_cleanup.php');

foreach (['storageCleanupSection', 'storageCleanupPreviewBtn', 'storageCleanupDeleteBtn', 'storageCleanupTableBody'] as $id) {
    admin_storage_cleanup_expect(str_contains($dashboard, 'id="' . $id . '"'), 'Missing Storage Cleanup control: ' . $id);
}
admin_storage_cleanup_expect(str_contains($dashboard, 'id="storageCleanupAutomationStatus"'), 'Storage Cleanup must display automatic runner status.');
admin_storage_cleanup_expect(str_contains($dashboard, 'data-section="storageCleanupSection"'), 'Storage Cleanup must be reachable from Admin navigation.');
admin_storage_cleanup_expect(str_contains($dashboard, 'admin-storage-cleanup.css'), 'Storage Cleanup stylesheet must be loaded.');
admin_storage_cleanup_expect(str_contains($dashboard, 'storage-cleanup.js'), 'Storage Cleanup controller must be loaded.');
admin_storage_cleanup_expect(str_contains($dashboardJs, "sectionId === 'storageCleanupSection'"), 'Admin section loader must initialize Storage Cleanup.');

admin_storage_cleanup_expect(str_contains($cleanupJs, "proxyGet('storage_cleanup_preview'"), 'Cleanup UI must preview through the authenticated proxy.');
admin_storage_cleanup_expect(str_contains($cleanupJs, "proxyPost('storage_cleanup_run'"), 'Cleanup UI must execute through the CSRF-protected proxy.');
admin_storage_cleanup_expect(str_contains($cleanupJs, 'preview_token'), 'Cleanup UI must submit its short-lived preview token.');
admin_storage_cleanup_expect(str_contains($cleanupJs, 'DELETE 90 DAY FILES'), 'Cleanup UI must require an explicit typed confirmation.');
admin_storage_cleanup_expect(str_contains($cleanupJs, 'automatic.enabled === true'), 'Cleanup UI must render only an explicitly enabled automatic runner as active.');
admin_storage_cleanup_expect(str_contains($cleanupJs, 'emailBackup.ready === true'), 'Cleanup UI must expose the fail-closed email backup readiness state.');
admin_storage_cleanup_expect(str_contains($cleanupJs, 'Automatic APK deletion is off'), 'Cleanup UI must clearly report the automatic APK protection.');
admin_storage_cleanup_expect(!preg_match('/loadStorageCleanup\([^)]*\).*storage_cleanup_run/s', substr($cleanupJs, -500)), 'Loading the cleanup section must never auto-delete files.');

admin_storage_cleanup_expect(str_contains($proxy, "case 'storage_cleanup_preview':"), 'Admin proxy must expose cleanup preview.');
admin_storage_cleanup_expect(str_contains($proxy, "case 'storage_cleanup_run':"), 'Admin proxy must expose cleanup execution.');
admin_storage_cleanup_expect(str_contains($proxy, 'proxy_require_csrf();'), 'Cleanup execution must require CSRF validation.');
admin_storage_cleanup_expect(str_contains($proxy, "hash('sha256', \$previewToken)"), 'Cleanup preview tokens must be stored as hashes.');
admin_storage_cleanup_expect(str_contains($proxy, 'storage_cleanup_acquire_lock()'), 'Cleanup execution must be protected by a process lock.');

admin_storage_cleanup_expect(str_contains($library, 'return max(90,'), 'Backend retention must enforce a hard 90-day minimum.');
admin_storage_cleanup_expect(str_contains($library, "'APPROVED', 'REJECTED'"), 'Add Money cleanup must only allow terminal requests.');
admin_storage_cleanup_expect(str_contains($library, "\$status !== 'CLOSED'"), 'Support cleanup must protect non-closed tickets.');
admin_storage_cleanup_expect(str_contains($library, "'rollback_apk'"), 'APK cleanup must preserve a rollback release.');
admin_storage_cleanup_expect(str_contains($library, 'STORAGE_CLEANUP_RUNS/'), 'Every cleanup must create an audit record.');
admin_storage_cleanup_expect(str_contains($library, "'trigger' => \$trigger"), 'Cleanup audits must distinguish manual and automatic runs.');
admin_storage_cleanup_expect(str_contains($library, 'STORAGE_CLEANUP_BACKUP_FAILED'), 'Automatic cleanup must fail closed when its email backup fails.');
admin_storage_cleanup_expect(str_contains($cleanupCss, '@media (max-width:640px)'), 'Storage Cleanup must remain usable on mobile Admin screens.');

fwrite(STDOUT, "PASS: {$assertions} admin storage cleanup UI assertions\n");
