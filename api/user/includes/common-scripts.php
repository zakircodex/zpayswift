<?php
declare(strict_types=1);

$pageJs = basename((string)$userPage['page_js']);
?>
<script>
window.USER_PROXY_URL = '/api/user/proxy.php';
window.USER_LOGIN_URL = '/user/';
window.USER_PAGE_KEY = <?= json_encode((string)$userPage['key'], JSON_UNESCAPED_SLASHES) ?>;
window.USER_BOOTSTRAP_ACTION = <?= json_encode((string)$userPage['bootstrap_action'], JSON_UNESCAPED_SLASHES) ?>;
window.USER_BOOTSTRAP_PARAMS = <?= json_encode((array)$userPage['bootstrap_params'], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/api/user/assets/user-shell.js?v=<?= user_page_asset_version('user-shell.js') ?>"></script>
<script src="/api/user/assets/pages/<?= htmlspecialchars($pageJs, ENT_QUOTES, 'UTF-8') ?>?v=<?= user_page_asset_version('pages/' . $pageJs) ?>"></script>
</body>
</html>
