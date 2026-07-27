<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/page-bootstrap.php';
$page = user_page_config([
    'key' => 'support',
    'title' => 'Support',
    'section_id' => 'supportSection',
    'body_class' => 'user-support-page',
    'page_css' => 'support-page.css',
    'page_js' => 'support-page.js',
    'active_nav' => '',
    'show_header' => false,
    'show_bottom_nav' => false,
]);
user_page_begin($page);
?>
<section id="supportSection" class="page-section support-workspace-page-section active">
  <div id="supportRequestWorkspace" class="support-request-workspace">
    <div class="support-live-hero">
      <span class="support-contact-bubble one" aria-hidden="true"></span>
      <span class="support-contact-bubble two" aria-hidden="true"></span>
      <h3>Need Help?</h3>
      <p>Start a conversation with Z-Pay Swift Support.</p>
      <button id="supportStartChatButton" class="android-primary-button" type="button">Start Chat</button>
    </div>
    <div class="support-workspace-head">
      <div><h3>My Conversations</h3><p>Open a request to continue the conversation.</p></div>
      <button id="supportRefreshTopButton" class="icon-command" type="button" aria-label="Refresh support requests">&#8635;</button>
    </div>
    <div class="segmented-tabs support-hidden-tabs" role="tablist" aria-label="Support views">
      <button id="supportNewTab" type="button" role="tab" aria-selected="false">Contact Us</button>
      <button id="supportListTab" class="active" type="button" role="tab" aria-selected="true">My Requests <span id="supportUnreadBadge" class="tab-badge hidden">0</span></button>
    </div>
    <div id="supportCreatePanel" class="support-tab-panel">
      <form id="supportCreateForm" class="feature-card support-form" novalidate>
        <label class="feature-field" for="supportCategory"><span>Category</span><select id="supportCategory" name="category_code"><option value="">Select a category</option></select></label>
        <label class="feature-field" for="supportSubject"><span>Subject</span><input id="supportSubject" name="subject" maxlength="120" placeholder="Short issue title"></label>
        <label id="supportRelatedWrap" class="feature-field hidden" for="supportRelatedRequest"><span>Related request <small>Optional</small></span><select id="supportRelatedRequest" name="related_request_id"><option value="">No related request</option></select></label>
        <label class="feature-field" for="supportMessage"><span>Message</span><textarea id="supportMessage" name="message" maxlength="2500" rows="5" placeholder="Describe your issue"></textarea></label>
        <label class="attachment-picker" for="supportAttachments"><span>Add screenshots</span><small>JPG, PNG or WebP. Up to 3 files.</small><input id="supportAttachments" type="file" accept="image/jpeg,image/png,image/webp" multiple></label>
        <div id="supportAttachmentSummary" class="attachment-summary"></div>
        <button id="supportCreateButton" class="android-primary-button" type="submit">Create Ticket</button>
      </form>
    </div>
    <div id="supportListPanel" class="support-tab-panel active">
      <div id="supportTicketList" class="support-ticket-list"><div class="feature-empty-state">Loading conversations...</div></div>
    </div>
  </div>

  <div id="supportConversationView" class="support-conversation hidden">
    <div class="conversation-header">
      <button id="supportConversationBack" class="icon-command" type="button" aria-label="Back to support requests">&lsaquo;</button>
      <div><h2 id="supportConversationTitle">Support Request</h2><p id="supportConversationMeta">-</p></div>
      <span id="supportConversationStatus" class="status-pill pending">Open</span>
    </div>
    <div id="supportMessages" class="support-messages" aria-live="polite"></div>
    <form id="supportReplyForm" class="support-composer" novalidate>
      <label class="composer-attachment" for="supportReplyAttachment" aria-label="Attach screenshot">+</label>
      <input id="supportReplyAttachment" class="visually-hidden" type="file" accept="image/jpeg,image/png,image/webp" multiple>
      <textarea id="supportReplyMessage" rows="1" maxlength="2500" placeholder="Write a reply..."></textarea>
      <button id="supportReplyButton" type="submit" aria-label="Send reply">Send</button>
      <div id="supportReplyAttachmentSummary" class="attachment-summary composer-summary"></div>
    </form>
    <div id="supportClosedNotice" class="closed-notice hidden"></div>
  </div>
</section>
<?php user_page_end($page); ?>
