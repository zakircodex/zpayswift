<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/api/user/support.php');
$contactPage = (string)file_get_contents($root . '/api/user/contact-us.php');
$js = (string)file_get_contents($root . '/api/user/assets/pages/support-page.js');
$css = (string)file_get_contents($root . '/api/user/assets/pages/support-page.css');
$proxy = (string)file_get_contents($root . '/api/user/proxy.php');
$backend = (string)file_get_contents($root . '/api/lib/support.php');
$createEndpoint = (string)file_get_contents($root . '/api/support/create.php');
$assertions = 0;

function support_ui_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

support_ui_expect(
    str_contains($page, "'show_global_loader' => false")
    && str_contains($page, "'show_bottom_nav' => true")
    && str_contains($page, 'class="page-section user-support-experience active"')
    && !str_contains($page, 'id="loadingWrap"'),
    'Support must own its loader, activate its page root and retain the shared bottom navigation'
);

support_ui_expect(
    !str_contains($page, 'id="supportCategoryBack"')
    && !str_contains($page, '<span>Send</span>')
    && str_contains($page, 'class="support-state-header support-centered-state-header"'),
    'Category header or icon-only chat composer markup is incorrect'
);

support_ui_expect(
    str_contains($contactPage, "header('Cache-Control: no-store')")
    && str_contains($contactPage, "header('Location: /user/support', true, 302)"),
    'The Contact Us menu route must open the canonical Support experience'
);

foreach ([
    'supportMainView', 'supportCategoryView', 'supportCreateView', 'supportConversationView',
    'supportCategoryGrid', 'supportAttachmentPreview', 'supportReplyAttachmentPreview',
    'supportInfoButton', 'supportTicketStrip', 'supportActionModal',
] as $id) {
    support_ui_expect(str_contains($page, 'id="' . $id . '"'), "Missing Support UI element {$id}");
}

support_ui_expect(
    str_contains($page, 'Need Help?')
    && str_contains($page, 'My Conversations')
    && str_contains($page, 'How can we help?')
    && str_contains($page, 'Z-Pay Swift Support'),
    'Android-aligned Support headings are incomplete'
);

support_ui_expect(
    str_contains($js, "get('support_config'")
    && str_contains($js, "get('support_list'")
    && str_contains($js, "get('support_details'")
    && str_contains($js, "postForm('support_create'")
    && str_contains($js, "postForm('support_reply'"),
    'Support page is not reusing the established proxy actions'
);

support_ui_expect(
    str_contains($js, "makeIdempotencyKey('SUPPORT-CREATE')")
    && str_contains($js, "makeIdempotencyKey('SUPPORT-REPLY')")
    && str_contains($js, 'if (state.creating) return')
    && str_contains($js, 'if (state.replying'),
    'Support duplicate create/reply protection is incomplete'
);

support_ui_expect(
    str_contains($js, "new Set(['image/jpeg', 'image/png', 'image/webp'])")
    && str_contains($js, 'state.config.max_attachments')
    && str_contains($js, 'state.config.max_file_size')
    && str_contains($js, 'support-file-remove'),
    'Attachment policy or removable preview integration is incomplete'
);

support_ui_expect(
    str_contains($js, "'?action=support_attachment&ticket_id='")
    && str_contains($js, "link.rel = 'noopener noreferrer'")
    && str_contains($proxy, "case 'support_attachment':")
    && str_contains($proxy, "header('Cache-Control: private, no-store"),
    'Support attachments are not routed through the authenticated private bridge'
);

support_ui_expect(
    str_contains($js, 'text.textContent = String(message.message')
    && !str_contains($js, 'bubble.innerHTML'),
    'Dynamic Support message/ticket rendering is not DOM-safe'
);

support_ui_expect(
    str_contains($js, 'supportIsClosed(ticket.status)')
    && str_contains($js, 'This ticket is closed. You can no longer reply.')
    && str_contains($backend, "return ['ok' => false, 'code' => 'SUPPORT_TICKET_CLOSED'"),
    'Closed/resolved reply blocking is incomplete'
);

support_ui_expect(
    str_contains($js, 'function activeSupportTicket()')
    && str_contains($js, "button.textContent = active ? 'Open Conversation' : 'Start Chat'")
    && str_contains($js, "'SUPPORT_ACTIVE_TICKET_EXISTS'")
    && str_contains($backend, 'function support_claim_active_ticket_slot')
    && str_contains($backend, "'SUPPORT_ACTIVE_TICKET_EXISTS'")
    && str_contains($backend, "'SUPPORT_USER_INDEX/' . \$uid")
    && str_contains($createEndpoint, "'active_ticket_id'"),
    'Single-active-ticket UI/server enforcement is incomplete'
);

support_ui_expect(
    str_contains($js, 'openSupportLoading')
    && str_contains($js, 'closeSupportLoading')
    && str_contains($js, 'openSupportError')
    && str_contains($js, 'openTicketInfo')
    && str_contains($js, 'if (state.modal.open)'),
    'Page-local modal and modal-first Back handling are incomplete'
);

support_ui_expect(
    str_contains($js, "window.addEventListener('popstate', handlePopState)")
    && str_contains($js, "window.visualViewport?.addEventListener('resize', updateKeyboardState)")
    && str_contains($js, "target.scrollIntoView({ behavior: 'smooth'"),
    'Support Back or mobile keyboard handling is incomplete'
);

support_ui_expect(
    str_contains($css, 'body.user-support-page')
    && str_contains($css, 'height: 100dvh')
    && str_contains($css, 'body.user-support-page #appView')
    && str_contains($css, '#supportSection {')
    && str_contains($css, 'margin: 0')
    && str_contains($css, '#supportSection .support-ticket-scroll')
    && str_contains($css, '#supportSection .support-messages')
    && str_contains($css, '#supportSection .support-composer-zone')
    && str_contains($css, '#supportSection .support-action-modal'),
    'Support page fixed regions/body-only scrolling CSS is incomplete'
);

support_ui_expect(
    str_contains($css, 'body.user-support-page .user-drawer-floating-trigger')
    && str_contains($css, 'body.user-support-page.support-chat-open .bottom-nav')
    && str_contains($css, '#supportSection .support-centered-state-header')
    && str_contains($css, 'grid-template-columns: 48px minmax(0, 1fr) 48px')
    && substr_count($css, 'border-radius: 50%') >= 3,
    'Support hero/category/chat scoped visual rules are incomplete'
);

support_ui_expect(
    !preg_match('/^\s*\.(?:card|button|modal|input|menu)\b/m', $css),
    'Support CSS contains a forbidden unscoped generic selector'
);

support_ui_expect(
    str_contains($backend, 'function support_user_can_access')
    && str_contains($backend, "fb_get('SUPPORT_USER_INDEX/' . \$uid)")
    && str_contains($backend, 'support_notify_telegram_user_reply'),
    'Existing support ownership/index/Telegram integration is missing'
);

echo "User Support UI tests passed ({$assertions} assertions).\n";
