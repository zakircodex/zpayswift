<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/page-bootstrap.php';
$page = user_page_config([
    'key' => 'notifications',
    'title' => 'Notifications',
    'section_id' => 'notificationsSection',
    'body_class' => 'user-notifications-page',
    'page_css' => 'notifications-page.css',
    'page_js' => 'notifications-page.js',
    'active_nav' => '',
    'show_header' => false,
]);
user_page_begin($page);
?>
<section id="notificationsSection" class="page-section notification-page-section active" aria-labelledby="notificationsPageTitle">
  <div class="notification-page-shell">
    <div class="notification-page-fixed-area">
      <header class="notification-page-header">
        <a class="notification-page-icon-button" href="/user/dashboard" aria-label="Back to dashboard">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m14.7 5.3-1.4-1.4L5.2 12l8.1 8.1 1.4-1.4L9 13h11v-2H9l5.7-5.7Z"/></svg>
        </a>
        <h2 id="notificationsPageTitle">Notifications</h2>
        <button id="notificationsEditButton" class="notification-page-icon-button notification-edit-button" type="button" aria-label="Edit notifications" aria-pressed="false">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m4 16.6 9.9-9.9 3.4 3.4L7.4 20H4v-3.4ZM18.7 8.7l-3.4-3.4 1.4-1.4a2 2 0 0 1 2.8 0l.6.6a2 2 0 0 1 0 2.8l-1.4 1.4Z"/></svg>
        </button>
      </header>
      <div class="notification-page-tabs" role="tablist" aria-label="Notification filters">
        <button class="notification-page-tab active" type="button" role="tab" aria-selected="true" data-notification-filter="ALL">All Notifications</button>
        <button class="notification-page-tab" type="button" role="tab" aria-selected="false" data-notification-filter="UNREAD">Unread <span id="notificationUnreadCount">0</span></button>
      </div>
    </div>
    <div class="notification-page-scroll-body">
      <div id="notificationPageLive" class="notification-page-live" aria-live="polite"></div>
      <div id="notificationList" class="notification-page-list" aria-busy="true"></div>
    </div>
    <div id="notificationEditBar" class="notification-edit-bar hidden" role="toolbar" aria-label="Selected notification actions">
      <button id="notificationsSelectAllButton" type="button">Select All</button>
      <button id="notificationsDeleteButton" class="danger" type="button" disabled>Delete</button>
      <button id="notificationsMarkSelectedButton" type="button" disabled>Mark Read</button>
    </div>
  </div>

  <div id="notificationDetailModal" class="notification-detail-modal hidden" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="notificationDetailTitle" aria-describedby="notificationDetailBody" inert>
    <div class="notification-detail-backdrop" data-notification-detail-close></div>
    <div class="notification-detail-sheet">
      <div class="notification-detail-handle" aria-hidden="true"></div>
      <header>
        <span id="notificationDetailIcon" class="notification-page-card-icon" aria-hidden="true">Z</span>
        <h3 id="notificationDetailTitle">Notification</h3>
        <button id="notificationDetailCloseButton" type="button" aria-label="Close notification details">&times;</button>
      </header>
      <div class="notification-detail-content">
        <time id="notificationDetailTime"></time>
        <p id="notificationDetailBody">Loading notification...</p>
      </div>
      <div class="notification-detail-actions">
        <button id="notificationDetailDeleteButton" class="danger" type="button">Delete</button>
        <button id="notificationDetailOpenButton" type="button">Open Related Page</button>
      </div>
    </div>
  </div>
</section>
<?php user_page_end($page); ?>
