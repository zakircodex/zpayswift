<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/support.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$body = api_read_json_body();
$existing = support_config();

$email = support_clean_text($body['support_email'] ?? ($existing['support_email'] ?? ''), 120);
$emailEnabled = support_bool($body['email_enabled'] ?? ($existing['email_enabled'] ?? false), false);
if ($emailEnabled && $email === '') {
    api_response(false, 'SUPPORT_EMAIL_REQUIRED', 'Support email is required when email contact is enabled.', [], 422);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_response(false, 'SUPPORT_EMAIL_INVALID', 'Support email is invalid.', [], 422);
}

$whatsappEnabled = support_bool($body['whatsapp_enabled'] ?? ($existing['whatsapp_enabled'] ?? false), false);
$whatsappNumber = support_clean_phone($body['whatsapp_number'] ?? ($existing['whatsapp_number'] ?? ''));
if ($whatsappEnabled && $whatsappNumber === '') {
    api_response(false, 'SUPPORT_WHATSAPP_REQUIRED', 'WhatsApp number is required when WhatsApp contact is enabled.', [], 422);
}

$callEnabled = support_bool($body['call_enabled'] ?? ($existing['call_enabled'] ?? false), false);
$supportPhone = support_clean_phone($body['support_phone'] ?? ($existing['support_phone'] ?? ''));
if ($callEnabled && $supportPhone === '') {
    api_response(false, 'SUPPORT_PHONE_REQUIRED', 'Support phone number is required when call contact is enabled.', [], 422);
}

$maxAttachments = support_int($body['max_attachments'] ?? ($existing['max_attachments'] ?? 3), 3, 0, 5);
$maxFileSize = support_int($body['max_file_size'] ?? ($existing['max_file_size'] ?? 2097152), 2097152, 1024, 10485760);
$rateLimit = support_int($body['ticket_rate_limit_seconds'] ?? ($existing['ticket_rate_limit_seconds'] ?? 20), 20, 0, 3600);

$payload = [
    'contact_us_enabled' => support_bool($body['contact_us_enabled'] ?? ($existing['contact_us_enabled'] ?? true), true),
    'ticket_enabled' => support_bool($body['ticket_enabled'] ?? ($existing['ticket_enabled'] ?? true), true),
    'whatsapp_enabled' => $whatsappEnabled,
    'whatsapp_number' => $whatsappNumber,
    'call_enabled' => $callEnabled,
    'support_phone' => $supportPhone,
    'email_enabled' => $emailEnabled,
    'support_email' => $email,
    'support_hours' => support_clean_text($body['support_hours'] ?? ($existing['support_hours'] ?? ''), 160),
    'average_response_text' => support_clean_text($body['average_response_text'] ?? ($existing['average_response_text'] ?? ''), 160),
    'support_notice' => support_clean_text($body['support_notice'] ?? ($existing['support_notice'] ?? ''), 240),
    'attachments_enabled' => support_bool($body['attachments_enabled'] ?? ($existing['attachments_enabled'] ?? true), true),
    'max_attachments' => $maxAttachments,
    'max_file_size' => $maxFileSize,
    'ticket_rate_limit_seconds' => $rateLimit,
    'reopen_allowed' => support_bool($body['reopen_allowed'] ?? ($existing['reopen_allowed'] ?? true), true),
    'updated_at' => support_now(),
    'updated_by' => (string)($auth['user']['uid'] ?? ''),
];

if (!fb_patch('SUPPORT_CONFIG', $payload)) {
    api_response(false, 'SUPPORT_CONFIG_SAVE_FAILED', 'Support config could not be saved.', [], 500);
}

api_response(true, 'ADMIN_SUPPORT_CONFIG_SAVED', 'Support config saved.', [
    'config' => support_config(),
    'public_config' => support_public_config(),
]);
