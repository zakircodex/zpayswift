<?php
declare(strict_types=1);

// Keep the bookmarked Contact Us route while using the single Support experience.
header('Cache-Control: no-store');
header('Location: /user/support', true, 302);
exit;
