<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; connect-src 'self'; font-src 'self'; frame-src 'self'");

if ($path === '/api/znews/public/ad_frame.php') {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>Birthday ad fixture</body></html>';
    return true;
}

if (str_starts_with($path, '/api/znews/birthday/')) {
    session_name('birthday_browser_fixture');
    session_start();
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $template = [
        'id' => 'cosmic', 'name' => 'Cosmic', 'description' => 'A cinematic galaxy experience.',
        'preview_image' => '/znews/birthday/assets/images/birthday-universe-cosmic.webp',
        'renderer' => 'cosmic', 'configuration' => ['accent' => '#63e6be', 'secondary' => '#f6c86b'],
        'active' => true,
    ];
    $draft = is_array($_SESSION['birthday_draft'] ?? null) ? (array)$_SESSION['birthday_draft'] : [
        'id' => 'ZBD_BROWSER_001', 'name' => 'Mim', 'birthday_day' => 24, 'birthday_month' => 7,
        'birthday_year' => 0, 'sender_name' => 'Zakir', 'message' => 'Happy birthday! Keep shining.',
        'template' => $template, 'music' => null, 'audio_mode' => 'AMBIENT',
        'soundtrack' => ['mode' => 'AMBIENT', 'id' => 'cosmic-ambient', 'name' => 'Cosmic ambience', 'duration' => 0, 'url' => ''],
        'locale' => 'en', 'visibility' => 'UNLISTED', 'share_photo' => false,
        'photo_url' => '', 'photo_width' => 0, 'photo_height' => 0, 'expires_at' => time() + 86400,
    ];
    $respond = static function (array $data, string $code = 'BIRTHDAY_TEST_OK', int $status = 200): never {
        http_response_code($status);
        echo json_encode(['ok' => $status < 400, 'success' => $status < 400, 'code' => $code, 'message' => $status < 400 ? 'OK' : 'Request failed.', 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };
    $endpoint = basename($path);
    if ($endpoint === 'catalog.php') {
        $dreamy = $template;
        $dreamy['id'] = 'dreamy'; $dreamy['name'] = 'Dreamy'; $dreamy['description'] = 'Soft moonlight and floating wishes.';
        $dreamy['renderer'] = 'dreamy'; $dreamy['configuration'] = ['accent' => '#ff8fa3', 'secondary' => '#8ee3cf'];
        $celebration = $template;
        $celebration['id'] = 'celebration'; $celebration['name'] = 'Celebration'; $celebration['description'] = 'Warm stars and cosmic confetti.';
        $celebration['renderer'] = 'celebration'; $celebration['configuration'] = ['accent' => '#ff6b6b', 'secondary' => '#ffd43b'];
        $respond(['settings' => ['enabled' => true, 'allow_public_indexing' => true, 'photo_max_bytes' => 5242880, 'audio_max_bytes' => 10485760, 'audio_max_duration_seconds' => 30], 'templates' => [$template, $dreamy, $celebration], 'music' => []]);
    }
    if ($endpoint === 'draft.php') {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            $body = json_decode((string)file_get_contents('php://input'), true);
            $body = is_array($body) ? $body : [];
            $selectedTemplate = $template;
            $templateId = strtolower((string)($body['template_id'] ?? 'cosmic'));
            if ($templateId === 'dreamy') {
                $selectedTemplate['id'] = 'dreamy'; $selectedTemplate['name'] = 'Dreamy'; $selectedTemplate['renderer'] = 'dreamy';
                $selectedTemplate['configuration'] = ['accent' => '#ff8fa3', 'secondary' => '#8ee3cf'];
            } elseif ($templateId === 'celebration') {
                $selectedTemplate['id'] = 'celebration'; $selectedTemplate['name'] = 'Celebration'; $selectedTemplate['renderer'] = 'celebration';
                $selectedTemplate['configuration'] = ['accent' => '#ff6b6b', 'secondary' => '#ffd43b'];
            }
            $draft = array_merge($draft, [
                'name' => trim((string)($body['name'] ?? $draft['name'])),
                'birthday_day' => (int)($body['birthday_day'] ?? $draft['birthday_day']),
                'birthday_month' => (int)($body['birthday_month'] ?? $draft['birthday_month']),
                'birthday_year' => (int)($body['birthday_year'] ?? 0),
                'sender_name' => trim((string)($body['sender_name'] ?? '')),
                'message' => trim((string)($body['message'] ?? '')),
                'template' => $selectedTemplate,
                'audio_mode' => strtoupper((string)($body['audio_mode'] ?? 'AMBIENT')),
                'soundtrack' => ['mode' => strtoupper((string)($body['audio_mode'] ?? 'AMBIENT')), 'id' => 'cosmic-ambient', 'name' => 'Cosmic ambience', 'duration' => 0, 'url' => ''],
                'locale' => strtolower((string)($body['locale'] ?? 'en')) === 'bn' ? 'bn' : 'en',
                'visibility' => strtoupper((string)($body['visibility'] ?? 'UNLISTED')) === 'PUBLIC' ? 'PUBLIC' : 'UNLISTED',
            ]);
            $_SESSION['birthday_draft'] = $draft;
        }
        $respond(['draft' => $draft]);
    }
    if ($endpoint === 'media_upload.php') {
        $uploaded = is_array($_FILES['image'] ?? null) ? (array)$_FILES['image'] : [];
        $uploadedSize = max(0, (int)($uploaded['size'] ?? 0));
        $uploadedMime = strtolower(trim((string)($uploaded['type'] ?? '')));
        if ($uploadedSize <= 0 || $uploadedSize > 100 * 1024
            || !in_array($uploadedMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $respond(['uploaded_size' => $uploadedSize, 'uploaded_mime' => $uploadedMime], 'BIRTHDAY_TEST_PHOTO_NOT_OPTIMIZED', 422);
        }
        $draft['photo_url'] = '/api/znews/birthday/media.php?id=ZBM_BROWSER_PHOTO&draft=ZBD_BROWSER_001';
        $draft['photo_width'] = 300;
        $draft['photo_height'] = 500;
        $draft['share_photo'] = true;
        $draft['browser_upload_size'] = $uploadedSize;
        $_SESSION['birthday_draft'] = $draft;
        header('X-Birthday-Test-Upload-Size: ' . $uploadedSize);
        $respond(['media_id' => 'ZBM_BROWSER_PHOTO', 'draft' => $draft], 'BIRTHDAY_PHOTO_UPLOADED', 201);
    }
    if ($endpoint === 'media.php') {
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAB4AAAAyCAMAAAB8gJvdAAAABlBMVEXmUHgetNzqln3RAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAAGElEQVQ4y2NgxAsYRqVHpUeC9CgYBaMAAFSUAu9FHD8JAAAAAElFTkSuQmCC', true);
        header('Content-Type: image/png');
        echo $image;
        exit;
    }
    if ($endpoint === 'ad.php') {
        $origin = 'http://' . (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        $respond(['can_reward' => false, 'generation_requires_reward' => false, 'delivery' => [
            'enabled' => true,
            'frame_url' => $origin . '/api/znews/public/ad_frame.php?permit=birthday-test',
            'width' => '100%',
            'height' => 1400,
        ]]);
    }
    if ($endpoint === 'generate.php') {
        $origin = 'http://' . (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        $universe = array_merge($draft, [
            'slug' => 'mim-247-x8k2browser', 'url' => $origin . '/u/mim-247-x8k2browser',
            'star_id' => 'MIM-247-X8K2', 'view_count' => 0, 'created_at' => time(),
            'updated_at' => time(), 'expires_at' => time() + 90 * 86400, 'claimed' => false,
        ]);
        $_SESSION['birthday_universe'] = $universe;
        $respond(['universe' => $universe, 'idempotent_replay' => false]);
    }
    if ($endpoint === 'public.php') {
        $universe = is_array($_SESSION['birthday_universe'] ?? null)
            ? (array)$_SESSION['birthday_universe']
            : array_merge($draft, ['slug' => 'mim-247-x8k2browser', 'url' => 'http://' . (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . '/u/mim-247-x8k2browser', 'star_id' => 'MIM-247-X8K2', 'view_count' => 0, 'created_at' => time(), 'updated_at' => time(), 'expires_at' => time() + 90 * 86400, 'claimed' => false]);
        $respond(['universe' => $universe]);
    }
    if ($endpoint === 'event.php' || $endpoint === 'report.php') {
        $respond(['recorded' => true, 'counted' => true]);
    }
    $respond([], 'NOT_FOUND', 404);
}

if ($path === '/birthday' || str_starts_with($path, '/birthday/')) {
    $page = 'home';
    if ($path === '/birthday/create') {
        $page = 'create';
    } elseif ($path === '/birthday/templates') {
        $page = 'templates';
    } elseif ($path === '/birthday/privacy') {
        $page = 'privacy';
    } elseif ($path === '/birthday/terms') {
        $page = 'terms';
    } elseif ($path === '/birthday/contact') {
        $page = 'contact';
    } elseif (preg_match('#^/birthday/preview/([A-Za-z0-9_-]{1,160})$#D', $path, $matches) === 1) {
        $page = 'preview';
        $_GET['draft_id'] = $matches[1];
    } elseif (preg_match('#^/birthday/manage/([a-z0-9-]{8,100})$#D', $path, $matches) === 1) {
        $page = 'manage';
        $_GET['slug'] = $matches[1];
    }
    $_GET['page'] = $page;
    require $root . '/znews/birthday/index.php';
    return true;
}

if (preg_match('#^/u/([a-z0-9-]{8,100})$#D', $path, $matches) === 1) {
    $slug = htmlspecialchars($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
    echo '<link rel="icon" type="image/png" href="/assets/brand/favicon.png">';
    echo '<link rel="stylesheet" href="/znews/birthday/assets/birthday.css?v=5">';
    echo '<script defer src="/znews/birthday/assets/lib/qrcode.min.js?v=1"></script>';
    echo '<script defer src="/znews/birthday/assets/birthday-api.js?v=4"></script>';
    echo '<script defer src="/znews/birthday/assets/birthday-ad-service.js?v=1"></script>';
    echo '<script type="module" src="/znews/birthday/assets/birthday-scene.js?v=3"></script>';
    echo '<script defer src="/znews/birthday/assets/birthday-templates.js?v=5"></script>';
    echo '<script defer src="/znews/birthday/assets/birthday-public.js?v=4"></script>';
    echo '<title>Birthday Universe browser test</title></head>';
    echo '<body class="public-universe-page" data-slug="' . $slug . '" data-available="true">';
    echo '<header class="public-universe-header"><a class="birthday-brand" href="/birthday"><span class="birthday-logo" aria-hidden="true"><span>*</span></span><span><strong>Birthday Universe</strong><small>by Z Sky 24</small></span></a><button class="icon-button" id="publicShareTop" type="button" aria-label="Share this Birthday Universe">&#8599;</button></header>';
    echo '<main id="mainContent"><div class="universe-mount public-mount" id="publicUniverse"><div class="loading-state">Opening universe...</div></div>';
    echo '<section class="public-share-panel" aria-labelledby="shareTitle"><p class="eyebrow">Keep the celebration moving</p><h2 id="shareTitle">Share this Birthday Universe</h2><div class="share-buttons"><button class="button primary" id="nativeShare" type="button">Share</button><button class="button secondary" id="copyPublicLink" type="button">Copy Link</button><a class="button secondary" id="whatsappShare" href="#">WhatsApp</a><a class="button secondary" id="telegramShare" href="#">Telegram</a><a class="button secondary" id="facebookShare" href="#">Facebook</a></div><div class="qr-panel"><div id="qrCode" aria-label="QR code for this Birthday Universe"></div><div><strong>Scan to Open This Birthday Universe</strong><p>The QR contains only this public URL.</p><button class="button secondary" id="downloadQr" type="button">Download QR PNG</button></div></div></section><div class="ad-slot public-ad-slot" id="birthdayPublicAd" hidden><span>Advertisement</span></div>';
    echo '<section class="public-owner-actions"><a href="/birthday/manage/' . $slug . '">Manage this Universe</a><button id="reportUniverse" type="button">Report</button></section></main>';
    echo '<footer class="birthday-footer"><span>Digital Birthday Universe - Z Sky 24</span><p>This personalized star is fictional and does not represent ownership of a real astronomical object.</p></footer>';
    echo '<dialog class="report-dialog" id="reportDialog"><form method="dialog" id="reportForm"><button value="cancel">Close</button><select id="reportReason"><option value="OTHER">Other</option></select><textarea id="reportDetails"></textarea><button value="submit">Submit report</button></form></dialog><div class="toast" id="birthdayToast" role="status" hidden></div></body></html>';
    return true;
}

return false;
