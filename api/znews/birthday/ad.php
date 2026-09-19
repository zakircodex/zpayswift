<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/birthday.php';
require_once dirname(__DIR__) . '/lib/adsterra_web_ads.php';

api_require_method('GET');
$context = strtolower(trim((string)($_GET['context'] ?? 'public')));
$slot = $context === 'preview' ? 'birthday_preview' : 'birthday_public';
$contextId = '';
if ($context === 'preview') {
    $draftId = trim((string)($_GET['draft_id'] ?? ''));
    $token = trim((string)(api_get_header('X-Draft-Token') ?? ''));
    $draft = birthday_load_draft($draftId, $token);
    $contextId = (string)$draft['id'];
} else {
    $universe = birthday_universe_by_slug(strtolower(trim((string)($_GET['slug'] ?? ''))));
    if (!is_array($universe)) {
        api_response(false, 'BIRTHDAY_UNIVERSE_NOT_FOUND', 'Universe not found.', [], 404);
    }
    $contextId = (string)$universe['id'];
}

birthday_rate_limit('ad_' . substr(hash('sha256', $contextId), 0, 16), 3, 300, false);
$session = [
    'view_id' => znews_make_id('ZBV'),
    'post_id' => $contextId,
    'viewer_class' => 'GUEST',
    'ad_eligible' => true,
    'self_view' => false,
    'fingerprint_hash' => birthday_request_ip_hash(),
];
$gate = ['viewer_class' => 'GUEST', 'ad_eligible' => true, 'reason' => ''];
$delivery = znews_adsterra_web_delivery($session, $gate, birthday_now(), $slot);
if (!empty($delivery['enabled'])) {
    $eventId = znews_make_id('ZBA');
    fb_put(birthday_path('AD_EVENTS', $eventId), [
        'id' => $eventId,
        'context_id' => $contextId,
        'slot' => $slot,
        'provider' => 'ADSTERRA',
        'event_type' => 'OFFERED',
        'rewarded' => false,
        'created_at' => birthday_now(),
    ]);
}
api_response(true, 'BIRTHDAY_AD_CAPABILITY', 'Advertisement capability resolved.', [
    'can_reward' => false,
    'generation_requires_reward' => false,
    'delivery' => $delivery,
]);
