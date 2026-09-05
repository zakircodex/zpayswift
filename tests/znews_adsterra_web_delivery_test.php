<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function adsterra_web_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

putenv('ADSTERRA_ZSKY24_WEB_ADS_ENABLED=1');
putenv('ADSTERRA_ZSKY24_POST_READER_KEY=0123456789abcdef0123456789abcdef');
putenv('ADSTERRA_ZSKY24_POST_READER_SCRIPT_URL=https://ads.example.test/0123456789abcdef/invoke.js');
putenv('ADSTERRA_ZSKY24_POST_READER_SIZE=300x250');
putenv('ADSTERRA_ZSKY24_WEB_ALLOWED_SCRIPT_HOSTS=ads.example.test');
putenv('ZNEWS_AD_DELIVERY_SIGNING_KEY=unit-test-signing-key-with-more-than-32-characters');
putenv('ZNEWS_AD_DELIVERY_PERMIT_TTL_SECONDS=120');
putenv('ADSTERRA_PUBLISHER_API_TOKEN=publisher-token-must-never-leak');

require_once $root . '/api/znews/lib/adsterra_web_ads.php';

$placement = znews_adsterra_web_placement();
adsterra_web_expect(!empty($placement['ok']), 'Valid private banner config was rejected.');
adsterra_web_expect((int)$placement['width'] === 300 && (int)$placement['height'] === 250, 'Banner size changed.');

$session = ['view_id' => 'VIEW_TEST_1', 'post_id' => 'POST_TEST_1'];
$guestGate = ['viewer_class' => 'GUEST', 'ad_eligible' => true, 'reason' => ''];
$delivery = znews_adsterra_web_delivery($session, $guestGate, 1000);
adsterra_web_expect(!empty($delivery['enabled']), 'Eligible guest did not receive ad delivery.');
adsterra_web_expect(($delivery['provider'] ?? '') === 'ADSTERRA', 'Adsterra is not the Web provider.');
adsterra_web_expect(($delivery['slot'] ?? '') === 'post_reader', 'Unverified ad slot was enabled.');
adsterra_web_expect(str_starts_with((string)$delivery['frame_url'], '/api/znews/public/ad_frame.php?permit='), 'Delivery is not same-origin.');

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

$tampered = substr($permit, 0, -1) . (substr($permit, -1) === 'a' ? 'b' : 'a');
adsterra_web_expect(empty(znews_adsterra_web_verify_permit($tampered, 1050)['ok']), 'Tampered permit was accepted.');
adsterra_web_expect(empty(znews_adsterra_web_verify_permit($permit, 1201)['ok']), 'Expired permit was accepted.');

$creator = znews_adsterra_web_delivery($session, ['viewer_class' => 'CREATOR', 'ad_eligible' => false], 1000);
$android = znews_adsterra_web_delivery($session, ['viewer_class' => 'ANDROID_APP', 'ad_eligible' => false], 1000);
$blocked = znews_adsterra_web_delivery($session, ['viewer_class' => 'GUEST', 'ad_eligible' => false, 'reason' => 'GUEST_VIEW_WINDOW_LIMIT_EXCEEDED'], 1000);
adsterra_web_expect(empty($creator['enabled']), 'Authenticated creator received an ad permit.');
adsterra_web_expect(empty($android['enabled']), 'Android app received a Web ad permit.');
adsterra_web_expect(empty($blocked['enabled']), 'Spam-blocked guest received an ad permit.');

$frame = znews_adsterra_web_frame_html($placement);
adsterra_web_expect(str_contains($frame, 'window.atOptions='), 'Adsterra banner options are missing from the frame.');
adsterra_web_expect(str_contains($frame, 'https://ads.example.test/0123456789abcdef/invoke.js'), 'Approved banner script is missing from the frame.');
adsterra_web_expect(!str_contains($frame, 'publisher-token-must-never-leak'), 'Publisher API token leaked into the frame.');

putenv('ADSTERRA_ZSKY24_WEB_ALLOWED_SCRIPT_HOSTS=another.example.test');
adsterra_web_expect(empty(znews_adsterra_web_placement()['ok']), 'Unapproved ad script host was accepted.');
putenv('ADSTERRA_ZSKY24_WEB_ALLOWED_SCRIPT_HOSTS=ads.example.test');

$startSource = (string)file_get_contents($root . '/api/znews/views/start.php');
$frameSource = (string)file_get_contents($root . '/api/znews/public/ad_frame.php');
$webSource = (string)file_get_contents($root . '/znews/assets/znews-ads.js');
$appSource = (string)file_get_contents($root . '/znews/assets/znews.js');
$configSource = (string)file_get_contents($root . '/znews/assets/znews-config.js');

adsterra_web_expect(str_contains($startSource, "'ad_delivery' => \$adDelivery"), 'View start does not return server-gated delivery.');
adsterra_web_expect(str_contains($frameSource, "frame-ancestors 'self'") && str_contains($frameSource, 'X-Frame-Options: SAMEORIGIN'), 'Ad frame embedding is not same-origin constrained.');
adsterra_web_expect(str_contains($webSource, "provider: 'ADSTERRA'") && !str_contains($webSource, 'INMOBI'), 'InMobi remains in the active Web adapter.');
adsterra_web_expect(str_contains($webSource, 'allow-top-navigation-by-user-activation') && !str_contains($webSource, 'allow-same-origin'), 'Ad frame sandbox is too permissive.');
adsterra_web_expect(!str_contains($appSource, "dataset.znewsAdSlot = 'post_inline'"), 'Ungated feed ad insertion remains active.');
adsterra_web_expect(!str_contains($appSource, 'mountAll('), 'Legacy eager ad mounting remains active.');
adsterra_web_expect(str_contains($configSource, "mode: 'SERVER_GATED'") && !str_contains($configSource, "provider: 'NONE'"), 'Adsterra server-gated client mode is not active.');
adsterra_web_expect(!str_contains($configSource, "document.querySelectorAll('.ad-slot')"), 'Revenue UI observer still deletes live ad slots.');

foreach ([
    'ADSTERRA_ZSKY24_WEB_ADS_ENABLED',
    'ADSTERRA_ZSKY24_POST_READER_KEY',
    'ADSTERRA_ZSKY24_POST_READER_SCRIPT_URL',
    'ADSTERRA_ZSKY24_POST_READER_SIZE',
    'ADSTERRA_ZSKY24_WEB_ALLOWED_SCRIPT_HOSTS',
    'ZNEWS_AD_DELIVERY_SIGNING_KEY',
    'ZNEWS_AD_DELIVERY_PERMIT_TTL_SECONDS',
    'ADSTERRA_PUBLISHER_API_TOKEN',
] as $name) {
    putenv($name);
}

echo "PASS: Z Sky Adsterra Web delivery tests ({$assertions} assertions).\n";
