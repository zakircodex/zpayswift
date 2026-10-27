<?php
declare(strict_types=1);

$pwaRegisterPath = dirname(__DIR__) . '/assets/pwa-register.js';
$pwaRegisterVersion = is_file($pwaRegisterPath) ? filemtime($pwaRegisterPath) : false;
?>
<script src="/api/user/assets/pwa-register.js?v=<?= htmlspecialchars((string)($pwaRegisterVersion ?: 1), ENT_QUOTES, 'UTF-8') ?>"></script>
