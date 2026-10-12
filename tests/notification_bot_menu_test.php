<?php
declare(strict_types=1);

$notificationBotMenuAssertions = 0;

function notification_bot_menu_expect(bool $condition, string $message): void
{
    global $notificationBotMenuAssertions;
    $notificationBotMenuAssertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/api/lib/notification_bot_menu.php';

$keyboard = notification_bot_main_keyboard();
notification_bot_menu_expect(isset($keyboard['keyboard']) && is_array($keyboard['keyboard']), 'Reply keyboard is missing.');
notification_bot_menu_expect(($keyboard['resize_keyboard'] ?? null) === true, 'Reply keyboard must resize on mobile.');
notification_bot_menu_expect(($keyboard['one_time_keyboard'] ?? null) === false, 'Reply keyboard must remain available.');
notification_bot_menu_expect(($keyboard['is_persistent'] ?? null) === true, 'Reply keyboard is not persistent.');
notification_bot_menu_expect(!isset($keyboard['inline_keyboard']), 'Main menu must not be an inline keyboard.');

$buttonLabels = [];
foreach ((array)$keyboard['keyboard'] as $row) {
    foreach ((array)$row as $button) {
        $buttonLabels[] = (string)($button['text'] ?? '');
    }
}

$expectedLabels = [
    'Send Notification',
    'Update Ringgit Rate',
    'View Current Rate',
    'Recent Broadcasts',
    'Main Menu',
    'Cancel',
];
notification_bot_menu_expect($buttonLabels === $expectedLabels, 'Persistent menu labels or ordering changed unexpectedly.');

$actions = [
    '/start' => 'MENU',
    '/start@ZPayNoticeBot payload' => 'MENU',
    '/menu' => 'MENU',
    'menu' => 'MENU',
    'Main Menu' => 'MENU',
    '/cancel' => 'CANCEL',
    'Cancel' => 'CANCEL',
    '/notice' => 'NOTICE',
    'Send Notification' => 'NOTICE',
    '/rate' => 'RATE',
    'Update Ringgit Rate' => 'RATE',
    '/current_rate' => 'RATE_VIEW',
    'View Current Rate' => 'RATE_VIEW',
    '/recent' => 'RECENT',
    'Recent Broadcasts' => 'RECENT',
];
foreach ($actions as $input => $expectedAction) {
    notification_bot_menu_expect(
        notification_bot_action_from_text($input) === $expectedAction,
        "Action mapping failed for {$input}."
    );
}

foreach (['', 'Rate update for today', 'Cancel this transfer', 'Send notification tomorrow'] as $content) {
    notification_bot_menu_expect(
        notification_bot_action_from_text($content) === '',
        "Ordinary notification content was treated as a menu action: {$content}"
    );
}

$webhook = (string)file_get_contents(dirname(__DIR__) . '/api/telegram/notification_webhook.php');
notification_bot_menu_expect(
    str_contains($webhook, "notification_bot_action_from_text(\$text)"),
    'Notification webhook is not using the tested action mapper.'
);
notification_bot_menu_expect(
    str_contains($webhook, "nb_send(\$chatId, 'Cancelled.', nb_menu_keyboard())"),
    'Cancel does not restore the persistent menu.'
);

fwrite(STDOUT, "PASS: {$notificationBotMenuAssertions} notification bot menu assertions\n");
