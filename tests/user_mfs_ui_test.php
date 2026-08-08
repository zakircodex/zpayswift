<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function mfs_ui_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function mfs_ui_read(string $path): string
{
    $source = file_get_contents($path);
    mfs_ui_expect($source !== false, "unable to read {$path}");
    return (string)$source;
}

$bkash = mfs_ui_read($root . '/api/user/bkash.php');
$nagad = mfs_ui_read($root . '/api/user/nagad.php');
$flow = mfs_ui_read($root . '/api/user/includes/mfs-flow.php');
$script = mfs_ui_read($root . '/api/user/assets/pages/mfs-page.js');
$style = mfs_ui_read($root . '/api/user/assets/pages/mfs-page.css');

foreach ([
    'bKash' => [$bkash, "\$mfsProvider = 'BKASH';"],
    'Nagad' => [$nagad, "\$mfsProvider = 'NAGAD';"],
] as $label => [$page, $providerConfig]) {
    mfs_ui_expect(str_contains($page, $providerConfig), "{$label} provider is not server-configured");
    mfs_ui_expect(
        str_contains($page, "require __DIR__ . '/includes/mfs-flow.php'"),
        "{$label} does not use the shared MFS flow"
    );
    mfs_ui_expect(
        str_contains($page, "'show_header' => false")
        && str_contains($page, "'show_global_loader' => false"),
        "{$label} still enables a shared header or global loader"
    );
}

foreach (['mfsStepReceiver', 'mfsStepAmount', 'mfsStepPin', 'mfsStepPreview'] as $stepId) {
    mfs_ui_expect(str_contains($flow, 'id="' . $stepId . '"'), "missing MFS step {$stepId}");
}

mfs_ui_expect(
    str_contains($flow, 'id="mfsActionModal"')
    && str_contains($flow, 'class="mfs-action-modal"'),
    'MFS page-local action modal is missing'
);
mfs_ui_expect(
    str_contains($flow, 'id="mfsHoldConfirm"')
    && str_contains($flow, 'class="mfs-hold-control"'),
    'MFS long-hold control is missing'
);
mfs_ui_expect(
    str_contains($flow, 'id="mfsReference"')
    && !preg_match('/<button[^>]*>\s*Back\s*<\/button>/i', $flow),
    'Preview reference or Android-style no-visible-back layout is incorrect'
);
mfs_ui_expect(
    str_contains($flow, 'id="mfsAmountMyrField"')
    && str_contains($flow, 'id="mfsAmountMyr"')
    && str_contains($flow, 'id="mfsAmountBdt"'),
    'MYR/BDT dual amount controls are missing from the shared flow'
);

mfs_ui_expect(
    str_contains($script, "window.proxyPost('mfs_preview'")
    && str_contains($script, "window.proxyPost('validate_pin'")
    && str_contains($script, "window.proxyPost('mfs_create'"),
    'canonical MFS proxy actions are not preserved'
);
mfs_ui_expect(
    str_contains($script, 'preview_token: serverPreview.preview_token')
    && !preg_match('/(?:fee|total_debit|balance_after)\s*:\s*[^,]*(?:\+|\-|\*)/i', $script),
    'preview-token binding or backend-authoritative finance rendering regressed'
);
mfs_ui_expect(
    str_contains($script, 'function syncAmountInputs(source)')
    && str_contains($script, 'state.syncingAmount')
    && str_contains($script, 'amountMyr * wallet.rate')
    && str_contains($script, 'amountBdt / wallet.rate'),
    'guarded bidirectional MYR/BDT convenience conversion is missing'
);
mfs_ui_expect(
    str_contains($script, "currency: 'BDT'")
    && str_contains($script, 'amount_bdt: state.amountBdt')
    && str_contains($script, 'amount_rm: 0')
    && str_contains($script, 'amount_myr: 0'),
    'client-side conversion became authoritative in the preview payload'
);
mfs_ui_expect(
    str_contains($script, "purpose: 'TOPUP'")
    && str_contains($script, "'WAITING_ADMIN'")
    && str_contains($script, "return 'Pending'"),
    'PIN purpose or pending status mapping changed'
);
mfs_ui_expect(
    str_contains($script, 'HOLD_DURATION_MS = 2300')
    && str_contains($script, "addEventListener('pointerdown'")
    && str_contains($script, "addEventListener('pointerup'"),
    'long-hold timing or pointer handling is missing'
);
mfs_ui_expect(
    str_contains($script, 'window.visualViewport')
    && str_contains($script, "addEventListener('focusin'")
    && str_contains($script, 'keepFocusedControlsVisible(input)')
    && str_contains($script, "body.scrollTo({ top: Math.max(0, nextScrollTop), behavior: 'smooth' })"),
    'mobile keyboard visibility handling is missing'
);
mfs_ui_expect(
    str_contains($script, "window.localStorage.setItem(state.favoriteStorageKey")
    && str_contains($script, 'number: normalized')
    && str_contains($script, 'maskNumber(favorite.number)'),
    'provider-scoped favourite full-number storage/masked rendering is missing'
);
mfs_ui_expect(
    str_contains($script, 'canonicalTrackingUrl(result)')
    && str_contains($script, "modalFeedback('Tracking link copied')")
    && str_contains($script, "label: alreadyFavorite ? 'Saved' : 'Favorite'")
    && str_contains($script, 'dismissible: false'),
    'canonical tracking Open/Copy integration is missing'
);
mfs_ui_expect(
    str_contains($script, "window.addEventListener('popstate'")
    && str_contains($script, 'clearPin();'),
    'step back handling does not clear sensitive PIN state'
);
mfs_ui_expect(
    !str_contains($script, "preview: 'mfsReference'")
    && str_contains($script, 'if (targetId) window.setTimeout'),
    'Preview still auto-focuses Reference and shifts the card under the fixed header'
);

mfs_ui_expect(
    str_contains($style, '.user-mfs-page')
    && !preg_match('/^\s*\.(?:card|modal|button|input)\b/m', $style),
    'MFS CSS is not page scoped'
);
mfs_ui_expect(
    str_contains($style, 'height: 100dvh')
    && str_contains($style, '.user-mfs-page .mfs-scroll-body')
    && str_contains($style, 'overflow-y: auto'),
    'fixed shell/body-only scrolling contract is missing'
);
mfs_ui_expect(
    str_contains($style, '.user-mfs-page .mfs-hold-control')
    && str_contains($style, '-webkit-touch-callout: none')
    && str_contains($style, 'user-select: none'),
    'long-hold callout/selection isolation is missing'
);
mfs_ui_expect(
    str_contains($style, '.user-mfs-page .mfs-action-modal')
    && str_contains($style, '@media (max-width: 359px)'),
    'page-local modal or narrow-mobile responsive rules are missing'
);
mfs_ui_expect(
    str_contains($style, 'width: min(calc(100% - 24px), 560px)')
    && str_contains($style, 'grid-template-columns: repeat(var(--mfs-action-count, 1), minmax(0, 1fr))')
    && str_contains($style, '.user-mfs-page .mfs-action-modal.is-success .mfs-modal-close'),
    'centred shell or compact Android-style success action layout is missing'
);

echo "User MFS UI tests passed ({$assertions} assertions).\n";
