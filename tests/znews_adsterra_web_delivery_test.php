<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;
$cooldownRows = [];

function adsterra_web_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function fb_get_with_etag(string $path): array
{
    global $cooldownRows;
    return ['ok' => true, 'status' => 200, 'etag' => '"test"', 'value' => $cooldownRows[$path] ?? null];
}

function fb_put_if_match(string $path, $value, string $etag): array
{
    global $cooldownRows;
    $cooldownRows[$path] = $value;
    return ['ok' => true, 'status' => 200, 'etag' => '"test-next"'];
}

putenv('ADSTERRA_ZSKY24_WEB_ADS_ENABLED=1');
putenv('ADSTERRA_ZSKY24_POST_READER_FORMAT=NATIVE_BANNER');
putenv('ADSTERRA_ZSKY24_POST_READER_KEY=0123456789abcdef0123456789abcdef');
putenv('ADSTERRA_ZSKY24_POST_READER_SCRIPT_URL=https://ads.example.test/0123456789abcdef0123456789abcdef/invoke.js');
putenv('ADSTERRA_ZSKY24_NATIVE_INITIAL_HEIGHT=300');
putenv('ADSTERRA_ZSKY24_POST_READER_SIZE=300x250');
putenv('ADSTERRA_ZSKY24_WEB_ALLOWED_SCRIPT_HOSTS=ads.example.test');
putenv('ZNEWS_AD_DELIVERY_SIGNING_KEY=unit-test-signing-key-with-more-than-32-characters');
putenv('ZNEWS_AD_DELIVERY_PERMIT_TTL_SECONDS=120');
putenv('ADSTERRA_PUBLISHER_API_TOKEN=publisher-token-must-never-leak');
$_SERVER['HTTP_HOST'] = 'zsky24.com';

require_once $root . '/api/znews/lib/adsterra_web_ads.php';

$placement = znews_adsterra_web_placement();
adsterra_web_expect(!empty($placement['ok']), 'Valid private Native Banner config was rejected.');
adsterra_web_expect(($placement['creative_format'] ?? '') === 'native_banner', 'Native Banner format was lost.');
adsterra_web_expect(($placement['container_id'] ?? '') === 'container-0123456789abcdef0123456789abcdef', 'Native container ID does not match its unit key.');
adsterra_web_expect((int)$placement['width'] === 0 && (int)$placement['height'] === 300, 'Native initial frame dimensions changed.');

$session = ['view_id' => 'VIEW_TEST_1', 'post_id' => 'POST_TEST_1'];
$guestGate = ['viewer_class' => 'GUEST', 'ad_eligible' => true, 'reason' => ''];
$delivery = znews_adsterra_web_delivery($session, $guestGate, 1000);
adsterra_web_expect(!empty($delivery['enabled']), 'Eligible guest did not receive ad delivery.');
adsterra_web_expect(($delivery['provider'] ?? '') === 'ADSTERRA', 'Adsterra is not the Web provider.');
adsterra_web_expect(($delivery['slot'] ?? '') === 'post_reader', 'Unverified ad slot was enabled.');
adsterra_web_expect(($delivery['creative_format'] ?? '') === 'native_banner', 'Native delivery format was not returned.');
adsterra_web_expect(preg_match('/^[a-f0-9]{24}$/D', (string)($delivery['resize_channel'] ?? '')) === 1, 'Native resize channel is invalid.');
adsterra_web_expect(str_starts_with((string)$delivery['frame_url'], 'https://www.zsky24.com/api/znews/public/ad_frame.php?permit='), 'Delivery is not isolated on the approved cross-origin host.');

$deliveryJson = json_encode($delivery, JSON_UNESCAPED_SLASHES);
adsterra_web_expect(!str_contains((string)$deliveryJson, 'publisher-token-must-never-leak'), 'Publisher API token leaked to delivery JSON.');
adsterra_web_expect(!str_contains((string)$deliveryJson, '0123456789abcdef0123456789abcdef'), 'Public tag key leaked outside the sandbox frame.');
adsterra_web_expect(!str_contains((string)$deliveryJson, 'ads.example.test'), 'Ad host leaked outside the sandbox frame.');

$query = parse_url((string)$delivery['frame_url'], PHP_URL_QUERY);
parse_str(is_string($query) ? $query : '', $parameters);
$permit = (string)($parameters['permit'] ?? '');
$verified = znews_adsterra_web_verify_permit($permit, 1050);
adsterra_web_expect(!empty($verified['ok']), 'Fresh delivery permit did not verify.');
adsterra_web_expect(($verified['payload']['view'] ?? '') === 'VIEW_TEST_1', 'Permit lost its canonical view binding.');
adsterra_web_expect(($verified['payload']['post'] ?? '') === 'POST_TEST_1', 'Permit lost its canonical post binding.');

$inlineDelivery = znews_adsterra_web_delivery($session, $guestGate, 1000, 'post_inline');
adsterra_web_expect(!empty($inlineDelivery['enabled']) && ($inlineDelivery['slot'] ?? '') === 'post_inline', 'Server-gated inline placement was not issued.');
$inlineQuery = parse_url((string)$inlineDelivery['frame_url'], PHP_URL_QUERY);
parse_str(is_string($inlineQuery) ? $inlineQuery : '', $inlineParameters);
$inlineVerified = znews_adsterra_web_verify_permit((string)($inlineParameters['permit'] ?? ''), 1050);
adsterra_web_expect(($inlineVerified['payload']['slot'] ?? '') === 'post_inline', 'Inline permit lost its slot binding.');

$tampered = substr($permit, 0, -1) . (substr($permit, -1) === 'a' ? 'b' : 'a');
adsterra_web_expect(empty(znews_adsterra_web_verify_permit($tampered, 1050)['ok']), 'Tampered permit was accepted.');
adsterra_web_expect(empty(znews_adsterra_web_verify_permit($permit, 1201)['ok']), 'Expired permit was accepted.');

$creator = znews_adsterra_web_delivery($session, ['viewer_class' => 'CREATOR', 'ad_eligible' => false], 1000);
$otherCreator = znews_adsterra_web_delivery($session, ['viewer_class' => 'CREATOR', 'ad_eligible' => true], 1000);
$selfCreator = znews_adsterra_web_delivery($session + ['self_view' => true], ['viewer_class' => 'CREATOR', 'ad_eligible' => true], 1000);
$android = znews_adsterra_web_delivery($session, ['viewer_class' => 'ANDROID_APP', 'ad_eligible' => false], 1000);
$blocked = znews_adsterra_web_delivery($session, ['viewer_class' => 'GUEST', 'ad_eligible' => false, 'reason' => 'GUEST_VIEW_WINDOW_LIMIT_EXCEEDED'], 1000);
adsterra_web_expect(empty($creator['enabled']), 'Authenticated creator received an ad permit.');
adsterra_web_expect(!empty($otherCreator['enabled']), 'A creator viewing another creator post did not receive a permit.');
adsterra_web_expect(empty($selfCreator['enabled']), 'A creator viewing their own post received a permit.');
adsterra_web_expect(empty($android['enabled']), 'Android app received a Web ad permit.');
adsterra_web_expect(empty($blocked['enabled']), 'Spam-blocked guest received an ad permit.');

$_SERVER['HTTP_HOST'] = 'www.zsky24.com';
$wwwDelivery = znews_adsterra_web_delivery($session, $guestGate, 1000);
adsterra_web_expect(str_starts_with((string)($wwwDelivery['frame_url'] ?? ''), 'https://zsky24.com/api/znews/public/ad_frame.php?permit='), 'WWW delivery did not switch to the root cross-origin frame.');
$_SERVER['HTTP_HOST'] = 'zpayswift.com';
$embeddedDelivery = znews_adsterra_web_delivery($session, $guestGate, 1000);
adsterra_web_expect(str_starts_with((string)($embeddedDelivery['frame_url'] ?? ''), 'https://zsky24.com/api/znews/public/ad_frame.php?permit='), 'Embedded Z-Pay delivery did not use the approved Z Sky frame origin.');
$_SERVER['HTTP_HOST'] = 'untrusted.example';
$untrustedHostDelivery = znews_adsterra_web_delivery($session, $guestGate, 1000);
adsterra_web_expect(empty($untrustedHostDelivery['enabled']), 'Untrusted request host received an ad frame permit.');
$_SERVER['HTTP_HOST'] = 'zsky24.com';

$frame = znews_adsterra_web_frame_html($placement, (array)$verified['payload']);
adsterra_web_expect(!str_contains($frame, 'window.atOptions='), 'Standard Banner options leaked into the Native frame.');
adsterra_web_expect(str_contains($frame, 'data-cfasync="false"'), 'Native Banner script attributes are missing.');
adsterra_web_expect(str_contains($frame, 'https://ads.example.test/0123456789abcdef0123456789abcdef/invoke.js'), 'Approved Native Banner script is missing from the frame.');
adsterra_web_expect(str_contains($frame, 'id="container-0123456789abcdef0123456789abcdef"'), 'Approved Native Banner container is missing from the frame.');
adsterra_web_expect(str_contains($frame, 'znews:adsterra-native-size'), 'Native responsive-height bridge is missing.');
adsterra_web_expect(!str_contains($frame, 'publisher-token-must-never-leak'), 'Publisher API token leaked into the frame.');

$dwellSession = [
    'view_id' => 'VIEW_DWELL_1',
    'post_id' => 'POST_DWELL_1',
    'viewer_uid' => '',
    'fingerprint_hash' => 'fingerprint-hash-1',
    'viewer_class' => 'GUEST',
    'ad_eligible' => true,
    'self_view' => false,
    'active_seconds' => 4,
];
adsterra_web_expect(empty(znews_adsterra_web_delivery_after_dwell($dwellSession, 'post_reader', 2000)['enabled']), 'Ad was issued before five active seconds.');
$dwellSession['active_seconds'] = 5;
$firstDwellDelivery = znews_adsterra_web_delivery_after_dwell($dwellSession, 'post_reader', 2000);
adsterra_web_expect(!empty($firstDwellDelivery['enabled']), 'Five-second eligible reader did not receive an ad.');
$cooldownDelivery = znews_adsterra_web_delivery_after_dwell(array_merge($dwellSession, ['view_id' => 'VIEW_DWELL_2']), 'post_reader', 2200);
adsterra_web_expect(empty($cooldownDelivery['enabled']) && ($cooldownDelivery['reason'] ?? '') === 'AD_POST_COOLDOWN_ACTIVE', 'Same viewer/post was not capped for five minutes.');
$afterCooldown = znews_adsterra_web_delivery_after_dwell(array_merge($dwellSession, ['view_id' => 'VIEW_DWELL_3']), 'post_reader', 2300);
adsterra_web_expect(!empty($afterCooldown['enabled']), 'Same viewer/post remained blocked after the five-minute cooldown.');

putenv('ADSTERRA_ZSKY24_POST_READER_SCRIPT_URL=https://ads.example.test/fedcba9876543210fedcba9876543210/invoke.js');
adsterra_web_expect(empty(znews_adsterra_web_placement()['ok']), 'Mismatched Native script key was accepted.');

putenv('ADSTERRA_ZSKY24_POST_READER_FORMAT=BANNER');
putenv('ADSTERRA_ZSKY24_POST_READER_SCRIPT_URL=https://ads.example.test/banner/invoke.js');
$standardPlacement = znews_adsterra_web_placement();
adsterra_web_expect(!empty($standardPlacement['ok']) && ($standardPlacement['creative_format'] ?? '') === 'banner', 'Standard Banner compatibility was broken.');
adsterra_web_expect((int)$standardPlacement['width'] === 300 && (int)$standardPlacement['height'] === 250, 'Standard Banner size changed.');
$standardFrame = znews_adsterra_web_frame_html($standardPlacement);
adsterra_web_expect(str_contains($standardFrame, 'window.atOptions='), 'Standard Banner options are missing from the frame.');

putenv('ADSTERRA_ZSKY24_POST_READER_FORMAT=NATIVE_BANNER');
putenv('ADSTERRA_ZSKY24_POST_READER_SCRIPT_URL=https://ads.example.test/0123456789abcdef0123456789abcdef/invoke.js');

putenv('ADSTERRA_ZSKY24_WEB_ALLOWED_SCRIPT_HOSTS=another.example.test');
adsterra_web_expect(empty(znews_adsterra_web_placement()['ok']), 'Unapproved ad script host was accepted.');
putenv('ADSTERRA_ZSKY24_WEB_ALLOWED_SCRIPT_HOSTS=ads.example.test');

$startSource = (string)file_get_contents($root . '/api/znews/views/start.php');
$heartbeatSource = (string)file_get_contents($root . '/api/znews/views/heartbeat.php');
$feedAdSource = (string)file_get_contents($root . '/api/znews/ads/feed.php');
$frameSource = (string)file_get_contents($root . '/api/znews/public/ad_frame.php');
$webSource = (string)file_get_contents($root . '/znews/assets/znews-ads.js');
$appSource = (string)file_get_contents($root . '/znews/assets/znews.js');
$configSource = (string)file_get_contents($root . '/znews/assets/znews-config.js');
$znewsHeaders = (string)file_get_contents($root . '/znews/.htaccess');

adsterra_web_expect(str_contains($startSource, 'AD_DWELL_REQUIRED') && !str_contains($startSource, 'znews_adsterra_web_delivery('), 'View start still issues an ad before the dwell gate.');
adsterra_web_expect(str_contains($heartbeatSource, 'znews_adsterra_web_delivery_after_dwell') && str_contains($heartbeatSource, "'ad_delivery' => \$adDelivery"), 'Heartbeat does not return the server-verified ad delivery.');
adsterra_web_expect(str_contains($feedAdSource, 'znews_feed_load_session') && str_contains($feedAdSource, '$selfView') && str_contains($feedAdSource, "'post_inline'"), 'Inline feed endpoint lacks session, ownership, or slot enforcement.');
adsterra_web_expect(str_contains($feedAdSource, 'znews_optional_creator_uid()') && !str_contains($feedAdSource, "\$body['creator_uid']"), 'Inline feed endpoint trusts a client-supplied creator identity.');
adsterra_web_expect(str_contains($frameSource, "znews_adsterra_web_frame_ancestors()") && !str_contains($frameSource, 'X-Frame-Options: SAMEORIGIN'), 'Ad frame is not constrained to reciprocal trusted parent origins.');
adsterra_web_expect(str_contains($webSource, "provider: 'ADSTERRA'") && !str_contains($webSource, 'INMOBI'), 'InMobi remains in the active Web adapter.');
adsterra_web_expect(str_contains($webSource, 'allow-top-navigation-by-user-activation') && str_contains($webSource, 'allow-same-origin'), 'Cross-origin ad frame cannot run the approved provider runtime.');
adsterra_web_expect(str_contains($webSource, 'event.source !== frame.contentWindow') && str_contains($webSource, 'data.channel !== safe.resizeChannel'), 'Ad resize messages are not source-and-nonce bound.');
adsterra_web_expect(str_contains($appSource, "dataset.znewsAdSlot = 'post_inline'") && str_contains($appSource, 'FEED_AD_INTERVAL = 5'), 'Five-post server-gated feed cadence is missing.');
adsterra_web_expect(str_contains($appSource, 'result.data?.ad_delivery') && str_contains($appSource, 'heartbeatDelay'), 'Reader does not wait for heartbeat delivery.');
adsterra_web_expect(str_contains($appSource, 'scheduleReaderAd(postId)') && str_contains($appSource, 'api.feedAdDelivery(feedSessionId, postId, { timeoutMs: 15000 })'), 'Reader ad is still blocked by the slow analytics view start.');
adsterra_web_expect(str_contains($appSource, '}, 5000);'), 'Reader fallback does not preserve the five-second dwell.');
adsterra_web_expect(preg_match('/requestPriority\.FEED,\s*\(\{ signal \}\) => api\.heartbeatView/s', $appSource) === 1, 'Five-second reader heartbeat can still wait behind media analytics.');
adsterra_web_expect(str_contains($appSource, "slot.className = 'ad-slot post-reader-ad-slot'") && str_contains($appSource, "querySelector('.post-copy')"), 'Reader ad remains detached below the complete post card.');
adsterra_web_expect(!str_contains($appSource, 'mountAll('), 'Legacy eager ad mounting remains active.');
adsterra_web_expect(str_contains($configSource, "mode: 'SERVER_GATED'") && !str_contains($configSource, "provider: 'NONE'"), 'Adsterra server-gated client mode is not active.');
adsterra_web_expect(!str_contains($configSource, "document.querySelectorAll('.ad-slot')"), 'Revenue UI observer still deletes live ad slots.');
adsterra_web_expect(str_contains($znewsHeaders, "frame-src 'self' https://zsky24.com https://www.zsky24.com"), 'Z Sky CSP still blocks its isolated ad frame.');

foreach ([
    'ADSTERRA_ZSKY24_WEB_ADS_ENABLED',
    'ADSTERRA_ZSKY24_POST_READER_FORMAT',
    'ADSTERRA_ZSKY24_POST_READER_KEY',
    'ADSTERRA_ZSKY24_POST_READER_SCRIPT_URL',
    'ADSTERRA_ZSKY24_NATIVE_INITIAL_HEIGHT',
    'ADSTERRA_ZSKY24_POST_READER_SIZE',
    'ADSTERRA_ZSKY24_WEB_ALLOWED_SCRIPT_HOSTS',
    'ZNEWS_AD_DELIVERY_SIGNING_KEY',
    'ZNEWS_AD_DELIVERY_PERMIT_TTL_SECONDS',
    'ADSTERRA_PUBLISHER_API_TOKEN',
] as $name) {
    putenv($name);
}

echo "PASS: Z Sky Adsterra Web delivery tests ({$assertions} assertions).\n";
