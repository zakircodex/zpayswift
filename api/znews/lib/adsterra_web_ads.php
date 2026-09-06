<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_adsterra_web_setting(string $name): string
{
    $environment = getenv($name);
    if (is_string($environment) && trim($environment) !== '') {
        return trim($environment);
    }
    if (!defined($name)) {
        return '';
    }
    $value = constant($name);
    return is_scalar($value) ? trim((string)$value) : '';
}

function znews_adsterra_web_enabled(): bool
{
    return in_array(
        strtolower(znews_adsterra_web_setting('ADSTERRA_ZSKY24_WEB_ADS_ENABLED')),
        ['1', 'true', 'yes', 'on'],
        true
    );
}

function znews_adsterra_web_creative_format(): string
{
    $configured = strtoupper(str_replace(['-', ' '], '_', znews_adsterra_web_setting(
        'ADSTERRA_ZSKY24_POST_READER_FORMAT'
    )));
    return match ($configured) {
        '', 'BANNER', 'DISPLAY_BANNER' => 'banner',
        'NATIVE', 'NATIVE_BANNER' => 'native_banner',
        default => '',
    };
}

function znews_adsterra_web_native_initial_height(): int
{
    $configured = (int)znews_adsterra_web_setting('ADSTERRA_ZSKY24_NATIVE_INITIAL_HEIGHT');
    return max(160, min(640, $configured > 0 ? $configured : 300));
}

function znews_adsterra_web_allowed_hosts(): array
{
    $raw = znews_adsterra_web_setting('ADSTERRA_ZSKY24_WEB_ALLOWED_SCRIPT_HOSTS');
    $hosts = [];
    foreach (preg_split('/[\s,]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $host) {
        $host = rtrim(trim((string)$host), '.');
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host) === 1) {
            $hosts[$host] = true;
        }
    }
    return array_keys($hosts);
}

function znews_adsterra_web_size(): array
{
    $sizes = [
        '160x300' => [160, 300],
        '160x600' => [160, 600],
        '300x250' => [300, 250],
        '320x50' => [320, 50],
        '468x60' => [468, 60],
        '728x90' => [728, 90],
    ];
    $configured = strtolower(znews_adsterra_web_setting('ADSTERRA_ZSKY24_POST_READER_SIZE'));
    return $sizes[$configured] ?? [];
}

function znews_adsterra_web_placement(): array
{
    if (!znews_adsterra_web_enabled()) {
        return ['ok' => false, 'code' => 'ADSTERRA_WEB_DISABLED'];
    }

    $key = znews_adsterra_web_setting('ADSTERRA_ZSKY24_POST_READER_KEY');
    $scriptUrl = znews_adsterra_web_setting('ADSTERRA_ZSKY24_POST_READER_SCRIPT_URL');
    $creativeFormat = znews_adsterra_web_creative_format();
    $parts = parse_url($scriptUrl);
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    $allowedHosts = znews_adsterra_web_allowed_hosts();

    $validUrl = is_array($parts)
        && strtolower((string)($parts['scheme'] ?? '')) === 'https'
        && $host !== ''
        && in_array($host, $allowedHosts, true)
        && !isset($parts['user'])
        && !isset($parts['pass'])
        && !isset($parts['fragment'])
        && (!isset($parts['port']) || (int)$parts['port'] === 443)
        && preg_match('#/invoke\.js$#D', (string)($parts['path'] ?? '')) === 1;

    if (!$validUrl || $creativeFormat === '') {
        return ['ok' => false, 'code' => 'ADSTERRA_WEB_PLACEMENT_INVALID'];
    }

    $containerId = '';
    if ($creativeFormat === 'native_banner') {
        $validKey = preg_match('/^[a-f0-9]{32}$/D', $key) === 1;
        $validPath = (string)($parts['path'] ?? '') === '/' . $key . '/invoke.js';
        if (!$validKey || !$validPath) {
            return ['ok' => false, 'code' => 'ADSTERRA_WEB_PLACEMENT_INVALID'];
        }
        $width = 0;
        $height = znews_adsterra_web_native_initial_height();
        $containerId = 'container-' . $key;
    } else {
        $size = znews_adsterra_web_size();
        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $key) !== 1 || count($size) !== 2) {
            return ['ok' => false, 'code' => 'ADSTERRA_WEB_PLACEMENT_INVALID'];
        }
        [$width, $height] = $size;
    }

    $configHash = substr(hash('sha256', implode('|', [
        'ADSTERRA',
        'post_reader',
        $creativeFormat,
        $key,
        $scriptUrl,
        (string)$width,
        (string)$height,
        $containerId,
    ])), 0, 24);

    return [
        'ok' => true,
        'provider' => 'ADSTERRA',
        'slot' => 'post_reader',
        'format' => 'iframe',
        'creative_format' => $creativeFormat,
        'key' => $key,
        'script_url' => $scriptUrl,
        'script_origin' => 'https://' . $host,
        'container_id' => $containerId,
        'width' => $width,
        'height' => $height,
        'config_hash' => $configHash,
    ];
}

function znews_adsterra_web_signing_key(): string
{
    $dedicated = znews_adsterra_web_setting('ZNEWS_AD_DELIVERY_SIGNING_KEY');
    if (strlen($dedicated) >= 32) {
        return hash('sha256', "ZNEWS_AD_DELIVERY_V1\0" . $dedicated, true);
    }

    $handoff = znews_adsterra_web_setting('ZNEWS_HANDOFF_ENCRYPTION_KEY');
    return strlen($handoff) >= 32
        ? hash('sha256', "ZNEWS_AD_DELIVERY_V1\0" . $handoff, true)
        : '';
}

function znews_adsterra_web_permit_ttl(): int
{
    $configured = (int)znews_adsterra_web_setting('ZNEWS_AD_DELIVERY_PERMIT_TTL_SECONDS');
    return max(30, min(300, $configured > 0 ? $configured : 120));
}

function znews_adsterra_web_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function znews_adsterra_web_base64url_decode(string $value): ?string
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
        return null;
    }
    $padding = (4 - strlen($value) % 4) % 4;
    $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
    return is_string($decoded) && znews_adsterra_web_base64url_encode($decoded) === $value
        ? $decoded
        : null;
}

function znews_adsterra_web_safe_id($value): string
{
    $value = trim((string)$value);
    return preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $value) === 1 ? $value : '';
}

function znews_adsterra_web_delivery(array $session, array $gate, ?int $now = null): array
{
    $placement = znews_adsterra_web_placement();
    if (empty($placement['ok'])) {
        return ['enabled' => false, 'provider' => 'ADSTERRA', 'reason' => (string)($placement['code'] ?? 'ADSTERRA_WEB_DISABLED')];
    }

    $viewerClass = strtoupper(trim((string)($gate['viewer_class'] ?? '')));
    if ($viewerClass !== 'GUEST' || empty($gate['ad_eligible'])) {
        return [
            'enabled' => false,
            'provider' => 'ADSTERRA',
            'reason' => trim((string)($gate['reason'] ?? 'AD_POLICY_NOT_ELIGIBLE')) ?: 'AD_POLICY_NOT_ELIGIBLE',
        ];
    }

    $viewId = znews_adsterra_web_safe_id($session['view_id'] ?? '');
    $postId = znews_adsterra_web_safe_id($session['post_id'] ?? '');
    $signingKey = znews_adsterra_web_signing_key();
    if ($viewId === '' || $postId === '' || $signingKey === '') {
        return ['enabled' => false, 'provider' => 'ADSTERRA', 'reason' => 'AD_DELIVERY_UNAVAILABLE'];
    }

    $issuedAt = $now ?? time();
    try {
        $nonce = bin2hex(random_bytes(12));
    } catch (Throwable $error) {
        return ['enabled' => false, 'provider' => 'ADSTERRA', 'reason' => 'AD_DELIVERY_UNAVAILABLE'];
    }

    $payload = [
        'v' => 1,
        'view' => $viewId,
        'post' => $postId,
        'slot' => 'post_reader',
        'cfg' => (string)$placement['config_hash'],
        'iat' => $issuedAt,
        'exp' => $issuedAt + znews_adsterra_web_permit_ttl(),
        'nonce' => $nonce,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return ['enabled' => false, 'provider' => 'ADSTERRA', 'reason' => 'AD_DELIVERY_UNAVAILABLE'];
    }
    $encoded = znews_adsterra_web_base64url_encode($json);
    $signature = znews_adsterra_web_base64url_encode(hash_hmac('sha256', $encoded, $signingKey, true));
    $permit = $encoded . '.' . $signature;

    $delivery = [
        'enabled' => true,
        'provider' => 'ADSTERRA',
        'slot' => 'post_reader',
        'format' => 'iframe',
        'creative_format' => (string)$placement['creative_format'],
        'width' => (int)$placement['width'],
        'height' => (int)$placement['height'],
        'expires_at' => (int)$payload['exp'],
        'frame_url' => '/api/znews/public/ad_frame.php?permit=' . rawurlencode($permit),
    ];
    if ((string)$placement['creative_format'] === 'native_banner') {
        $delivery['resize_channel'] = $nonce;
    }
    return $delivery;
}

function znews_adsterra_web_verify_permit(string $permit, ?int $now = null): array
{
    if (strlen($permit) > 2048 || substr_count($permit, '.') !== 1) {
        return ['ok' => false, 'code' => 'AD_DELIVERY_PERMIT_INVALID'];
    }
    [$encoded, $providedSignature] = explode('.', $permit, 2);
    $signingKey = znews_adsterra_web_signing_key();
    $json = znews_adsterra_web_base64url_decode($encoded);
    $signature = znews_adsterra_web_base64url_decode($providedSignature);
    if ($signingKey === '' || $json === null || $signature === null) {
        return ['ok' => false, 'code' => 'AD_DELIVERY_PERMIT_INVALID'];
    }
    $expected = hash_hmac('sha256', $encoded, $signingKey, true);
    if (!hash_equals($expected, $signature)) {
        return ['ok' => false, 'code' => 'AD_DELIVERY_PERMIT_INVALID'];
    }

    $payload = json_decode($json, true);
    $placement = znews_adsterra_web_placement();
    $current = $now ?? time();
    $issuedAt = (int)($payload['iat'] ?? 0);
    $expiresAt = (int)($payload['exp'] ?? 0);
    if (!is_array($payload)
        || empty($placement['ok'])
        || (int)($payload['v'] ?? 0) !== 1
        || (string)($payload['slot'] ?? '') !== 'post_reader'
        || (string)($payload['cfg'] ?? '') !== (string)$placement['config_hash']
        || znews_adsterra_web_safe_id($payload['view'] ?? '') === ''
        || znews_adsterra_web_safe_id($payload['post'] ?? '') === ''
        || preg_match('/^[a-f0-9]{24}$/D', (string)($payload['nonce'] ?? '')) !== 1
        || $issuedAt < 1
        || $issuedAt > $current + 30
        || $expiresAt < $issuedAt
        || $expiresAt < $current
        || $expiresAt - $issuedAt > 300) {
        return ['ok' => false, 'code' => 'AD_DELIVERY_PERMIT_INVALID'];
    }

    return ['ok' => true, 'payload' => $payload, 'placement' => $placement];
}

function znews_adsterra_web_frame_html(array $placement, array $payload = []): string
{
    $creativeFormat = (string)($placement['creative_format'] ?? 'banner');
    $scriptUrl = htmlspecialchars((string)$placement['script_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($creativeFormat === 'native_banner') {
        $containerId = (string)($placement['container_id'] ?? '');
        $channel = (string)($payload['nonce'] ?? '');
        $containerJson = json_encode($containerId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $channelJson = json_encode($channel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $containerHtml = htmlspecialchars($containerId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Advertisement</title><style>html,body{width:100%;margin:0;overflow:hidden;background:transparent}'
            . 'body{min-height:1px}body>div{max-width:100%}</style></head><body>'
            . '<script async="async" data-cfasync="false" src="' . $scriptUrl . '"></script>'
            . '<div id="' . $containerHtml . '"></div>'
            . '<script>(()=>{"use strict";const containerId=' . $containerJson . ',channel=' . $channelJson . ';'
            . 'const container=document.getElementById(containerId);if(!container||!channel)return;let last=0;'
            . 'const publish=()=>{const rect=container.getBoundingClientRect();const raw=Math.ceil(Math.max(rect.height,container.scrollHeight));'
            . 'if(raw<1)return;const height=Math.max(90,Math.min(1600,raw));if(height===last)return;last=height;'
            . 'parent.postMessage({type:"znews:adsterra-native-size",channel,height},"*");};'
            . 'if("ResizeObserver" in window)new ResizeObserver(publish).observe(container);'
            . 'new MutationObserver(publish).observe(container,{childList:true,subtree:true,attributes:true});'
            . 'addEventListener("load",publish);[0,250,1000,3000].forEach(delay=>setTimeout(publish,delay));})();</script>'
            . '<noscript><span hidden>Advertisement requires JavaScript.</span></noscript></body></html>';
    }

    $options = json_encode([
        'key' => (string)$placement['key'],
        'format' => 'iframe',
        'height' => (int)$placement['height'],
        'width' => (int)$placement['width'],
        'params' => (object)[],
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $width = (int)$placement['width'];
    $height = (int)$placement['height'];

    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Advertisement</title><style>html,body{width:100%;height:100%;margin:0;overflow:hidden;background:transparent}'
        . 'body{display:grid;place-items:center}</style></head><body>'
        . '<script>window.atOptions=' . $options . ';</script>'
        . '<script src="' . $scriptUrl . '"></script>'
        . '<noscript><span hidden>Advertisement requires JavaScript.</span></noscript>'
        . '<!-- ' . $width . 'x' . $height . ' --></body></html>';
}
