<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboardJs = (string)file_get_contents($root . '/api/user/assets/dashboard.js');
$dashboardUxJs = (string)file_get_contents($root . '/api/user/assets/dashboard-ux.js');
$proxy = (string)file_get_contents($root . '/api/user/proxy.php');
$mfsPreview = (string)file_get_contents($root . '/api/mfs/preview.php');
$legacyMfsCreate = (string)file_get_contents($root . '/api/user/mfs_create_telegram.php');
$assertions = 0;

function canonical_flow_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

canonical_flow_expect(
    str_contains($dashboardUxJs, "window.proxyPost('mfs_preview'")
    && str_contains($dashboardUxJs, 'preview_token: serverPreview.preview_token')
    && str_contains($dashboardUxJs, "window.proxyPost('mfs_create'"),
    'MFS Web flow must use canonical preview and create actions.'
);
canonical_flow_expect(
    !str_contains($dashboardUxJs, "fetch('/api/user/mfs_create_telegram.php'"),
    'MFS Web flow must not bypass the authenticated proxy.'
);
canonical_flow_expect(
    str_contains($legacyMfsCreate, 'HTTP_X_CSRF_TOKEN')
    && str_contains($legacyMfsCreate, "hash_equals(\$sessionCsrf, \$csrf)"),
    'Legacy MFS create endpoint must retain mutation CSRF protection.'
);
canonical_flow_expect(
    str_contains($proxy, "'mfs/preview.php'")
    && str_contains($proxy, "'mfs/create.php'")
    && substr_count($proxy, "case 'mfs_create':") === 1,
    'Web proxy must forward to one canonical MFS create route.'
);
canonical_flow_expect(
    str_contains($mfsPreview, 'auth_pricing_country_from_user($user, $wallet)')
    && strpos($mfsPreview, "\$user['pricing_country']") < strpos($mfsPreview, "\$user['country_code']"),
    'MFS pricing country must take precedence over phone/account country fallbacks.'
);
canonical_flow_expect(
    str_contains($dashboardJs, "proxyPost('topup_preview'")
    && str_contains($dashboardJs, "proxyPost('topup_submit'")
    && str_contains($dashboardJs, 'preview_token: previewToken'),
    'Top-Up Web flow must use canonical preview and submit actions.'
);
canonical_flow_expect(
    !str_contains($dashboardJs, 'function topupDebitPreview')
    && !str_contains($dashboardJs, 'function topupHasEnoughBalance'),
    'Top-Up review must not duplicate backend financial calculations.'
);
canonical_flow_expect(
    str_contains($dashboardJs, "proxyPost('bundle_preview'")
    && str_contains($dashboardJs, "proxyPost('bundle_submit'")
    && str_contains($dashboardJs, 'idempotency_key: state.bundleBuy.idempotencyKey'),
    'Bundle Web flow must use preview-token and idempotent submit actions.'
);
canonical_flow_expect(
    str_contains($proxy, "'topup/preview.php'")
    && str_contains($proxy, "'topup/submit.php'")
    && str_contains($proxy, "'bundle/preview.php'")
    && str_contains($proxy, "'bundle/submit.php'"),
    'Web proxy must expose canonical Top-Up and Bundle route adapters.'
);

echo "User Web canonical financial flow tests passed ({$assertions} assertions).\n";
