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
$chunkIndex = (int)($_POST['chunk_index'] ?? -1);
$totalChunks = (int)($_POST['total_chunks'] ?? 0);

if ($appId === '') { api_response(false, 'APP_ID_REQUIRED', 'app_id is required', [], 422); }
if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) { api_response(false, 'INVALID_CHUNK_META', 'Invalid chunk metadata', [], 422); }
if (!isset($_FILES['chunk']) || !is_array($_FILES['chunk'])) { api_response(false, 'CHUNK_REQUIRED', 'Chunk file is required', [], 422); }
if ((int)($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { api_response(false, 'CHUNK_UPLOAD_FAILED', 'Chunk upload failed', ['error' => (int)($_FILES['chunk']['error'] ?? 0)], 422); }

$appPath = 'Z_BUILDER_WORKER_APPS/' . $appId;
$app = fb_get($appPath);
if (!is_array($app)) { api_response(false, 'APP_NOT_FOUND', 'Worker app not found', [], 404); }
$ownerId = (string)($app['owner_id'] ?? '');

$safeAppId = preg_replace('/[^A-Z0-9_\-]/i', '', $appId);
$tmpRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'zbuilder_worker_apk_' . $safeAppId;
if (!is_dir($tmpRoot) && !mkdir($tmpRoot, 0700, true)) {
    api_response(false, 'TMP_CREATE_FAILED', 'Cannot create upload temp directory', [], 500);
}

$chunkPath = $tmpRoot . DIRECTORY_SEPARATOR . sprintf('chunk_%06d.part', $chunkIndex);
$tmpName = (string)($_FILES['chunk']['tmp_name'] ?? '');
if ($tmpName === '' || !move_uploaded_file($tmpName, $chunkPath)) {
    api_response(false, 'CHUNK_SAVE_FAILED', 'Failed to save chunk', [], 500);
}
@chmod($chunkPath, 0600);

$received = 0;
for ($i = 0; $i < $totalChunks; $i++) {
    if (is_file($tmpRoot . DIRECTORY_SEPARATOR . sprintf('chunk_%06d.part', $i))) { $received++; }
}

if ($received < $totalChunks) {
    api_response(true, 'CHUNK_RECEIVED', 'APK chunk received', [
        'app_id' => $appId,
        'received' => $received,
        'total' => $totalChunks,
    ]);
}

$baseDir = realpath(__DIR__ . '/../..');
if ($baseDir === false) { api_response(false, 'SERVER_PATH_ERROR', 'Public base path not found', [], 500); }
$downloadDir = $baseDir . '/z-builder/downloads/worker-apps';
if (!is_dir($downloadDir) && !mkdir($downloadDir, 0755, true)) {
    api_response(false, 'SERVER_PATH_ERROR', 'Cannot create APK download directory', [], 500);
}

$fileName = $safeAppId . '.apk';
$dest = $downloadDir . '/' . $fileName;
$out = fopen($dest, 'wb');
if (!$out) { api_response(false, 'SAVE_FAILED', 'Cannot open final APK file', [], 500); }
for ($i = 0; $i < $totalChunks; $i++) {
    $part = $tmpRoot . DIRECTORY_SEPARATOR . sprintf('chunk_%06d.part', $i);
    $in = fopen($part, 'rb');
    if (!$in) { fclose($out); api_response(false, 'CHUNK_READ_FAILED', 'Cannot read chunk', ['index' => $i], 500); }
    stream_copy_to_stream($in, $out);
    fclose($in);
}
fclose($out);
@chmod($dest, 0644);

$fh = fopen($dest, 'rb');
$magic = $fh ? fread($fh, 4) : '';
if ($fh) { fclose($fh); }
$size = filesize($dest) ?: 0;
if ($magic !== "PK\x03\x04" || $size < 1024) {
    @unlink($dest);
    api_response(false, 'INVALID_APK', 'Final APK signature is invalid', [], 422);
}

for ($i = 0; $i < $totalChunks; $i++) { @unlink($tmpRoot . DIRECTORY_SEPARATOR . sprintf('chunk_%06d.part', $i)); }
@rmdir($tmpRoot);

$downloadUrl = '/z-builder/downloads/worker-apps/' . $fileName;
$patch = [
    'status' => 'APK_READY',
    'build_status' => 'ARTIFACT_READY',
    'build_progress' => 100,
    'download_url' => $downloadUrl,
    'download_file' => $fileName,
    'download_size' => $size,
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
    'size' => $size,
]);
