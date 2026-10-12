<?php
declare(strict_types=1);

function notification_bot_main_keyboard(): array
{
    return [
        'keyboard' => [
            [['text' => 'Send Notification']],
            [['text' => 'Update Ringgit Rate']],
            [
                ['text' => 'View Current Rate'],
                ['text' => 'Recent Broadcasts'],
            ],
            [
                ['text' => 'Main Menu'],
                ['text' => 'Cancel'],
            ],
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false,
        'is_persistent' => true,
        'input_field_placeholder' => 'Choose an action',
    ];
}

function notification_bot_action_from_text(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    if (strcasecmp($text, 'Cancel') === 0
        || preg_match('/^\/cancel(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        return 'CANCEL';
    }
    if (strcasecmp($text, 'menu') === 0
        || strcasecmp($text, 'Main Menu') === 0
        || preg_match('/^\/(?:start|menu)(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        return 'MENU';
    }
    if (strcasecmp($text, 'Send Notification') === 0
        || preg_match('/^\/notice(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        return 'NOTICE';
    }
    if (strcasecmp($text, 'Update Ringgit Rate') === 0
        || preg_match('/^\/rate(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        return 'RATE';
    }
    if (strcasecmp($text, 'View Current Rate') === 0
        || preg_match('/^\/current_rate(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        return 'RATE_VIEW';
    }
    if (strcasecmp($text, 'Recent Broadcasts') === 0
        || preg_match('/^\/recent(?:@\w+)?(?:\s|$)/i', $text) === 1) {
        return 'RECENT';
    }

    return '';
}
