<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');
api_require_admin_key();

$appId = trim((string)($_POST['app_id'] ?? ''));
$artifactName = trim((string)($_POST['artifact_name'] ?? ''));
$runId = trim((string)($_POST['run_id'] ?? ''));
$runUrl = trim((string)($_POST['run_url'] ?? ''));
if ($appId === '') { api_response(false, 'APP_ID_REQUIRED', 'app_id is required', [], 422); }
if (!isset($_FILES['apk']) || !is_array($_FILES['apk'])) { api_response(false, 'APK_REQUIRED', 'APK file is required', [], 422); }
if ((int)($_FILES['apk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { api_response(false, 'UPLOAD_FAILED', 'APK upload failed', ['error' => (int)($_FILES['apk']['error'] ?? 0)], 422); }

$appPath = 'Z_BUILDER_WORKER_APPS/' . $appId;
$app = fb_get($appPath);
if (!is_array($app)) { api_response(false, 'APP_NOT_FOUND', 'Worker app not found', [], 404); }
$ownerId = (string)($app['owner_id'] ?? '');

$tmp = (string)($_FILES['apk']['tmp_name'] ?? '');
$size = (int)($_FILES['apk']['size'] ?? 0);
if ($tmp === '' || $size < 1024) { api_response(false, 'INVALID_APK', 'Invalid APK file', [], 422); }
$fh = fopen($tmp, 'rb');
$magic = $fh ? fread($fh, 4) : '';
if ($fh) { fclose($fh); }
if ($magic !== "PK\x03\x04") { api_response(false, 'INVALID_APK', 'APK file signature is invalid', [], 422); }

$baseDir = realpath(__DIR__ . '/../..');
if ($baseDir === false) { api_response(false, 'SERVER_PATH_ERROR', 'Public base path not found', [], 500); }
$downloadDir = $baseDir . '/z-builder/downloads/worker-apps';
if (!is_dir($downloadDir) && !mkdir($downloadDir, 0755, true)) {
    api_response(false, 'SERVER_PATH_ERROR', 'Cannot create APK download directory', [], 500);
}

$fileName = preg_replace('/[^A-Z0-9_\-]/i', '', $appId) . '.apk';
$dest = $downloadDir . '/' . $fileName;
if (!move_uploaded_file($tmp, $dest)) { api_response(false, 'SAVE_FAILED', 'Failed to save APK', [], 500); }
@chmod($dest, 0644);

$downloadUrl = '/z-builder/downloads/worker-apps/' . $fileName;
$patch = [
    'status' => 'APK_READY',
    'build_status' => 'ARTIFACT_READY',
    'build_progress' => 100,
    'download_url' => $downloadUrl,
    'download_file' => $fileName,
    'download_size' => filesize($dest) ?: $size,
    'artifact_name' => $artifactName,
    'github_run_id' => $runId,
    'github_run_url' => $runUrl,
    'updated_at' => zb_now_iso(),
];
fb_patch($appPath, $patch);
if ($ownerId !== '') { fb_patch('Z_BUILDER_OWNER_WORKER_APPS/' . $ownerId . '/' . $appId, $patch); }

api_response(true, 'APK_UPLOADED', 'Worker APK uploaded to Z Builder download storage', [
    'app_id' => $appId,
    'download_url' => $downloadUrl,
    'size' => $patch['download_size'],
]);
