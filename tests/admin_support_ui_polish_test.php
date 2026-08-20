<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$js = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');
$css = (string)file_get_contents($root . '/api/admin/assets/admin-support.css');
$support = (string)file_get_contents($root . '/api/lib/support.php');
$proxy = (string)file_get_contents($root . '/api/admin/proxy.php');

$assertions = 0;

function admin_support_ui_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

admin_support_ui_expect(str_contains($dashboard, "filemtime(__DIR__ . '/assets/admin-support.css')"), 'Support stylesheet must use deploy-safe cache versioning');
admin_support_ui_expect(str_contains($dashboard, 'class="card support-admin-card"'), 'Support operations card hook is missing');
admin_support_ui_expect(str_contains($dashboard, 'class="support-ticket-table"'), 'Support ticket table hook is missing');
admin_support_ui_expect(str_contains($dashboard, 'colspan="8"'), 'Support empty state must span the current table');

foreach (['reloadSupportBtn', 'supportStatusFilter', 'supportSearch', 'supportTicketsTableBody', 'saveSupportConfigBtn', 'supportCategoryAddBtn', 'supportCategoriesTableBody'] as $id) {
    admin_support_ui_expect(str_contains($dashboard, 'id="' . $id . '"'), "Existing Support control is missing: {$id}");
}

foreach (['supportContactEnabled', 'supportTicketEnabled', 'supportAttachmentsEnabled', 'supportMaxAttachments', 'supportMaxFileSize', 'supportRateLimit', 'supportReopenAllowed'] as $id) {
    admin_support_ui_expect(str_contains($dashboard, 'id="' . $id . '"'), "Existing Support setting is missing: {$id}");
}

foreach (['Ticket', 'User', 'Conversation', 'Related Request', 'Status', 'Attachments', 'Last Activity', 'Actions'] as $label) {
    admin_support_ui_expect(str_contains($js, 'data-label="' . $label . '"'), "Responsive Support label is missing: {$label}");
}

foreach (["proxyGet('support_list'", "proxyGet('support_details'", "proxyPost('support_reply'", "action=support_reply_upload", "proxyPost('support_status'", "proxyPost('support_config_save'", "proxyPost('support_category_save'"] as $contract) {
    admin_support_ui_expect(str_contains($js, $contract), "Existing Support frontend API contract changed: {$contract}");
}

foreach (['supportReplyMessage', 'supportReplyAttachments', 'supportReplySendBtn'] as $id) {
    admin_support_ui_expect(str_contains($js, 'id="' . $id . '"'), "Existing Support reply control is missing: {$id}");
}

admin_support_ui_expect(str_contains($js, 'setSupportTicketStatus(\'${jsArg(id)}\',\'PENDING\')'), 'Pending ticket action changed');
admin_support_ui_expect(str_contains($js, 'setSupportTicketStatus(\'${jsArg(id)}\',\'CLOSED\')'), 'Close ticket action changed');
admin_support_ui_expect(str_contains($js, 'form.append(\'idempotency_key\', `${ticketId}_${Date.now()}`)'), 'Attachment reply idempotency key flow changed');
admin_support_ui_expect(str_contains($js, 'idempotency_key: `${ticketId}_${Date.now()}`'), 'Text reply idempotency key flow changed');

admin_support_ui_expect(str_contains($js, 'function supportStatusBadge(row)'), 'Support status presentation helper is missing');
foreach (['status-open', 'status-pending', 'status-replied', 'status-resolved', 'status-closed'] as $statusClass) {
    admin_support_ui_expect(str_contains($css, '.' . $statusClass), "Support status styling is missing: {$statusClass}");
}

admin_support_ui_expect(str_contains($js, "if (source === 'TELEGRAM') return { label:'Telegram Admin'"), 'Telegram-origin Support reply label is missing');
admin_support_ui_expect(str_contains($js, 'esc(identity.label)') && str_contains($js, 'esc(msg.message ||'), 'Support message sender/text must remain escaped');
admin_support_ui_expect(str_contains($js, 'supportConversationHtml(messages, id, attachments)'), 'Conversation renderer wiring is missing');
admin_support_ui_expect(str_contains($js, "convo.scrollTop = convo.scrollHeight"), 'Newest Support message must remain reachable');

$messageStart = strpos($js, 'function supportMessageHtml(msg, ticketId, attachments)');
$messageEnd = strpos($js, 'function supportConversationHtml', $messageStart === false ? 0 : $messageStart);
admin_support_ui_expect($messageStart !== false && $messageEnd !== false && $messageEnd > $messageStart, 'Unable to isolate Support message renderer');
$messageBlock = substr($js, (int)$messageStart, (int)$messageEnd - (int)$messageStart);
admin_support_ui_expect(!str_contains($messageBlock, '${msg.message}'), 'Raw Support message interpolation is not allowed');

$attachmentStart = strpos($js, 'function supportAttachmentUrl(ticketId, attachmentId)');
$attachmentEnd = strpos($js, 'function supportIsAdminMessage', $attachmentStart === false ? 0 : $attachmentStart);
admin_support_ui_expect($attachmentStart !== false && $attachmentEnd !== false && $attachmentEnd > $attachmentStart, 'Unable to isolate Support attachment renderer');
$attachmentBlock = strtolower(substr($js, (int)$attachmentStart, (int)$attachmentEnd - (int)$attachmentStart));
admin_support_ui_expect(str_contains($attachmentBlock, 'action=support_attachment'), 'Attachments must keep the authenticated Admin proxy route');
admin_support_ui_expect(str_contains($attachmentBlock, 'original_name') && str_contains($attachmentBlock, 'row.mime') && str_contains($attachmentBlock, 'row.size'), 'Safe attachment metadata display is incomplete');
foreach (['absolute_path', 'storage_path', 'firebase', 'app_key', 'admin_key', 'worker_key', 'session_token'] as $privateField) {
    admin_support_ui_expect(!str_contains($attachmentBlock, $privateField), "Private attachment field leaked into Support UI: {$privateField}");
}

admin_support_ui_expect(str_contains($css, '#supportSection'), 'Support queue CSS must remain section scoped');
admin_support_ui_expect(str_contains($css, '.support-conversation') && str_contains($css, 'overflow:auto'), 'Conversation must scroll internally');
admin_support_ui_expect(str_contains($css, '.support-composer') && str_contains($css, '.support-composer-actions'), 'Support reply composer layout is missing');
admin_support_ui_expect(str_contains($css, '.support-message.admin') && str_contains($css, '.support-message.source-telegram'), 'Admin and Telegram message distinction is missing');
admin_support_ui_expect(str_contains($css, '.support-attachment-card img') && str_contains($css, 'object-fit:contain'), 'Attachment preview must remain contained');
admin_support_ui_expect(str_contains($css, '@media(max-width:760px)') && str_contains($css, '@media(max-width:360px)'), 'Support mobile fallbacks are missing');
admin_support_ui_expect(str_contains($css, 'content:attr(data-label)'), 'Responsive Support card labels are missing');
admin_support_ui_expect(str_contains($css, 'min-width:0 !important'), 'Support mobile table width override is missing');

admin_support_ui_expect(str_contains($support, 'function support_reply(') && str_contains($support, 'support_reply_claim_operation('), 'Canonical Support CAS helper is missing');
admin_support_ui_expect(str_contains($proxy, "case 'support_reply':") && str_contains($proxy, "case 'support_reply_upload':"), 'Admin Support proxy actions changed');

echo "Admin Support UI polish tests passed ({$assertions} assertions).\n";
