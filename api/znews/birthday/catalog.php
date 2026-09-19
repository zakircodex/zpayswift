<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/birthday.php';
require_once dirname(__DIR__) . '/lib/birthday_media.php';

api_require_method('GET');
$settings = birthday_settings();
$photoMaxBytes = defined('BIRTHDAY_UNIVERSE_PHOTO_MAX_BYTES')
    ? max(1024 * 1024, min(5 * 1024 * 1024, (int)constant('BIRTHDAY_UNIVERSE_PHOTO_MAX_BYTES')))
    : 5 * 1024 * 1024;
api_response(true, 'BIRTHDAY_CONFIG_OK', 'Birthday Universe configuration loaded.', [
    'settings' => [
        'enabled' => !empty($settings['enabled']),
        'retention_days' => (int)$settings['retention_days'],
        'allow_public_indexing' => !empty($settings['allow_public_indexing']),
        'photo_max_bytes' => $photoMaxBytes,
        'audio_max_bytes' => birthday_audio_max_bytes(),
        'audio_max_duration_seconds' => birthday_audio_max_duration_seconds(),
        'supported_locales' => ['en', 'bn'],
    ],
    'templates' => birthday_templates(),
    'music' => birthday_music(),
    'ads' => [
        'provider' => 'ADSTERRA',
        'rewarded_supported' => false,
        'generation_requires_reward' => false,
    ],
]);
