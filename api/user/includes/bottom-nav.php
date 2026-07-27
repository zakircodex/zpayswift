<?php
declare(strict_types=1);
?>
</div>
</main>
</div>
</div>

<?php if (!empty($userPage['show_bottom_nav'])): ?>
<nav class="bottom-nav" aria-label="Primary navigation">
  <div class="bottom-nav-inner">
    <?php
    $items = [
        ['dashboard', '/user/dashboard', 'M3 10.5 12 3l9 7.5v9A1.5 1.5 0 0 1 19.5 21h-4.25v-6h-6.5v6H4.5A1.5 1.5 0 0 1 3 19.5v-9Z', 'Home'],
        ['add-money', '/user/add-money', 'M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5V8h-5a3 3 0 0 0 0 6h5v3.5a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5v-11Zm11 3.5a1 1 0 1 0 0 2h5v-2h-5Z', 'Add Money'],
        ['transfer', '/user/transfer', 'm15.5 4 4 4-4 4V9H5V7h10.5V4ZM8.5 12v3H19v2H8.5v3l-4-4 4-4Z', 'Transfer'],
        ['history', '/user/history', 'M12 3a9 9 0 1 1-8.5 12h2.2A7 7 0 1 0 5 12H2l4-4 4 4H7a5 5 0 1 1 1.5 3.6l1.4-1.4A3 3 0 1 0 9 12h3V7h2v7H9V9.4l-1.8 1.8A7 7 0 0 0 12 19a7 7 0 0 0 0-14Z', 'History'],
        ['profile', '/user/profile', 'M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-5 0-8 2.6-8 6v1h16v-1c0-3.4-3-6-8-6Z', 'Profile'],
    ];
    foreach ($items as [$key, $href, $iconPath, $label]):
        $active = (string)$userPage['active_nav'] === $key;
    ?>
      <a class="bottom-btn<?= $active ? ' active' : '' ?>" href="<?= $href ?>"<?= $active ? ' aria-current="page"' : '' ?>>
        <span class="bottom-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="<?= htmlspecialchars($iconPath, ENT_QUOTES, 'UTF-8') ?>"/></svg></span>
        <span><?= $label ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</nav>
<?php endif; ?>

<div id="userLogoutDialog" class="user-shell-dialog hidden" role="dialog" aria-modal="true" aria-labelledby="userLogoutTitle" aria-hidden="true" inert>
  <div class="user-shell-dialog-card">
    <div class="user-shell-dialog-icon" aria-hidden="true">&larr;</div>
    <h2 id="userLogoutTitle">Logout?</h2>
    <p>You will need to sign in again on this browser.</p>
    <div class="user-shell-dialog-actions">
      <button id="cancelUserLogout" class="user-shell-dialog-secondary" type="button">Cancel</button>
      <button id="confirmUserLogout" class="user-shell-dialog-primary" type="button">Logout</button>
    </div>
  </div>
</div>

<?php if (!empty($userPage['show_global_loader'])): ?>
<div id="loadingWrap" class="loading" aria-live="polite" aria-hidden="true">
  <div class="loading-box"><div class="spinner"></div><div id="loadingText">Loading...</div></div>
</div>
<?php endif; ?>
<div id="toastWrap" class="toast-wrap" aria-live="polite"></div>
