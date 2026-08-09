<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/page-bootstrap.php';
$page = user_page_config([
    'key' => 'support',
    'title' => 'Contact Us',
    'section_id' => 'supportSection',
    'body_class' => 'user-support-page',
    'page_css' => 'support-page.css',
    'page_js' => 'support-page.js',
    'active_nav' => '',
    'show_header' => false,
    'show_bottom_nav' => true,
    'show_global_loader' => false,
]);
user_page_begin($page);
?>
<section id="supportSection" class="page-section user-support-experience active" aria-busy="true">
  <div id="supportMainView" class="support-view support-main-view">
    <div class="support-main-fixed">
      <div class="support-live-hero">
        <span class="support-decor-bubble support-decor-bubble-one" aria-hidden="true"></span>
        <span class="support-decor-bubble support-decor-bubble-two" aria-hidden="true"></span>
        <h1>Need Help?</h1>
        <p>Start a conversation with Z-Pay Swift Support.</p>
        <button id="supportStartChatButton" class="support-primary-button" type="button">Start Chat</button>
      </div>
      <div class="support-list-heading">
        <div>
          <h2>My Conversations</h2>
          <p>Open a request to continue the conversation.</p>
        </div>
        <button id="supportRefreshButton" class="support-icon-button" type="button" aria-label="Refresh conversations" title="Refresh conversations">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M18.8 6.2A8 8 0 1 0 20 15h-2.2A6 6 0 1 1 17.2 8L14 11h8V3l-3.2 3.2Z"/></svg>
        </button>
      </div>
    </div>
    <div id="supportTicketList" class="support-ticket-scroll" aria-live="polite">
      <div class="support-empty-state">Loading conversations...</div>
    </div>
  </div>

  <div id="supportCategoryView" class="support-view support-category-view hidden">
    <header class="support-state-header">
      <button id="supportCategoryBack" class="support-icon-button" type="button" aria-label="Back to conversations">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M20 11H7.8l5.6-5.6L12 4l-8 8 8 8 1.4-1.4L7.8 13H20v-2Z"/></svg>
      </button>
      <div><h1>How can we help?</h1><p>Choose the topic that best matches your issue.</p></div>
      <span aria-hidden="true"></span>
    </header>
    <div id="supportCategoryGrid" class="support-category-grid" aria-live="polite"></div>
  </div>

  <div id="supportCreateView" class="support-view support-create-view hidden">
    <header class="support-state-header support-compact-header">
      <button id="supportCreateBack" class="support-icon-button" type="button" aria-label="Back to categories">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M20 11H7.8l5.6-5.6L12 4l-8 8 8 8 1.4-1.4L7.8 13H20v-2Z"/></svg>
      </button>
      <div><h1>Start Chat</h1><p>Never share your password, PIN or OTP.</p></div>
      <span aria-hidden="true"></span>
    </header>
    <div id="supportCreateScroll" class="support-create-scroll">
      <form id="supportCreateForm" class="support-form-card" novalidate>
        <div class="support-selected-category"><span>Category</span><strong id="supportSelectedCategory">Support</strong></div>
        <label id="supportRelatedWrap" class="support-field hidden" for="supportRelatedRequest">
          <span>Related request <small>Optional</small></span>
          <select id="supportRelatedRequest" name="related_request_id"><option value="">No related request</option></select>
        </label>
        <label class="support-field" for="supportSubject"><span>Subject</span><input id="supportSubject" name="subject" maxlength="120" autocomplete="off" placeholder="Short issue title" required></label>
        <label class="support-field" for="supportMessage"><span>Message</span><textarea id="supportMessage" name="message" maxlength="2500" rows="5" placeholder="Describe your issue" required></textarea></label>
        <div class="support-screenshot-block">
          <div><strong>Screenshots</strong><span id="supportAttachmentCount">0/3 selected</span></div>
          <div id="supportAttachmentPreview" class="support-attachment-preview"></div>
        </div>
        <input id="supportAttachments" class="visually-hidden" type="file" accept="image/jpeg,image/png,image/webp" multiple>
        <button id="supportAddScreenshot" class="support-secondary-button" type="button">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 4h16v16H4V4Zm2 2v9.2l3.2-3.2 2.5 2.5 3.2-3.2L18 14.4V6H6Zm3 1.5a1.7 1.7 0 1 0 0 3.4 1.7 1.7 0 0 0 0-3.4Z"/></svg>
          <span>Add Screenshot</span>
        </button>
        <button id="supportCreateButton" class="support-primary-button" type="submit">Create Chat</button>
      </form>
    </div>
  </div>

  <div id="supportConversationView" class="support-view support-conversation-view hidden">
    <header class="support-chat-header">
      <button id="supportConversationBack" class="support-icon-button" type="button" aria-label="Back to conversations">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M20 11H7.8l5.6-5.6L12 4l-8 8 8 8 1.4-1.4L7.8 13H20v-2Z"/></svg>
      </button>
      <div><h1>Z-Pay Swift Support</h1><p id="supportConversationStatus">Open</p></div>
      <button id="supportInfoButton" class="support-info-button" type="button" aria-label="Ticket information">i</button>
    </header>
    <button id="supportTicketStrip" class="support-ticket-strip" type="button" aria-label="Open ticket information">
      <span id="supportConversationTicketId">-</span>
      <strong id="supportConversationSubject">Support Request</strong>
    </button>
    <div id="supportMessages" class="support-messages" aria-live="polite"></div>
    <div class="support-composer-zone">
      <div id="supportReplyAttachmentPreview" class="support-reply-attachment-preview"></div>
      <form id="supportReplyForm" class="support-composer" novalidate>
        <input id="supportReplyAttachment" class="visually-hidden" type="file" accept="image/jpeg,image/png,image/webp" multiple>
        <button id="supportReplyAttachmentButton" class="support-composer-attachment" type="button" aria-label="Attach screenshot">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M16.5 6.5v9a4.5 4.5 0 0 1-9 0v-10a3 3 0 0 1 6 0V15a1.5 1.5 0 0 1-3 0V7H12v8a.5.5 0 0 0 1 0V5.5a2 2 0 0 0-4 0v10a3 3 0 0 0 6 0v-9h1.5Z"/></svg>
        </button>
        <textarea id="supportReplyMessage" rows="1" maxlength="2500" placeholder="Message"></textarea>
        <button id="supportReplyButton" class="support-send-button" type="submit" aria-label="Send message">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m3 20.5 18-8.5L3 3.5V10l12 2-12 2v6.5Z"/></svg><span>Send</span>
        </button>
      </form>
      <div id="supportClosedNotice" class="support-closed-notice hidden"></div>
    </div>
  </div>

  <div id="supportActionModal" class="support-action-modal hidden" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="supportModalTitle" inert>
    <div class="support-action-modal-card">
      <div id="supportModalSpinner" class="support-modal-spinner" aria-hidden="true"></div>
      <div id="supportModalIcon" class="support-modal-icon hidden" aria-hidden="true">!</div>
      <h2 id="supportModalTitle">Loading support...</h2>
      <p id="supportModalMessage"></p>
      <div id="supportTicketInfoRows" class="support-ticket-info-rows hidden"></div>
      <button id="supportModalAction" class="support-primary-button hidden" type="button">OK</button>
    </div>
  </div>
</section>
<?php user_page_end($page); ?>
