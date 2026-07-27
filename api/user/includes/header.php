<?php
declare(strict_types=1);

if (empty($userPage['show_header'])) {
    echo '<div class="user-page-content user-page-content-custom-header">';
    return;
}
?>
<header class="mobile-header user-shell-header">
  <div class="mobile-top-card">
    <div class="mobile-top-row">
      <button id="openSidebarBtn" class="icon-btn" type="button" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">
        <span aria-hidden="true">&#9776;</span>
      </button>
      <div class="mobile-title"><?= htmlspecialchars((string)$userPage['title'], ENT_QUOTES, 'UTF-8') ?></div>
      <a class="icon-btn notification-button" href="/user/notifications" aria-label="Notifications">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.35-1.65h-4.7A2.5 2.5 0 0 0 12 22Zm7-5.5-1.4-1.7V10a5.6 5.6 0 0 0-4.35-5.45V3.5a1.25 1.25 0 1 0-2.5 0v1.05A5.6 5.6 0 0 0 6.4 10v4.8L5 16.5V18h14v-1.5Z"/></svg>
        <span data-notification-badge class="notification-badge hidden">0</span>
      </a>
    </div>
  </div>
</header>
<div class="user-page-content">

