<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/api/user/history.php');
$css = (string)file_get_contents($root . '/api/user/assets/pages/history-page.css');
$js = (string)file_get_contents($root . '/api/user/assets/pages/history-page.js');
$proxy = (string)file_get_contents($root . '/api/user/proxy.php');
$bottomNav = (string)file_get_contents($root . '/api/user/includes/bottom-nav.php');
$assertions = 0;

function history_ui_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

history_ui_expect(
    str_contains($page, "'show_header' => false")
    && str_contains($page, "'show_drawer' => false")
    && str_contains($page, "'show_bottom_nav' => true") === false
    && str_contains($page, "'active_nav' => 'history'"),
    'History shell must use its Android-style header while retaining the default bottom navigation'
);

history_ui_expect(
    str_contains($page, 'id="historyBackButton"')
    && str_contains($page, 'href="/user/dashboard"')
    && str_contains($page, 'href="/user/notifications"')
    && str_contains($page, 'id="historyPageTitle">History</h1>'),
    'Android-style History header actions are incomplete'
);

foreach (['My History', 'History Month', 'historyMonthInput', 'Refresh History', 'data-filter="PENDING"'] as $legacyText) {
    history_ui_expect(!str_contains($page, $legacyText), "legacy History control remains: {$legacyText}");
}

history_ui_expect(
    substr_count($page, 'class="history-skeleton"') === 1
    && str_contains($page, '$index < 4')
    && !str_contains($page, 'Loading history...'),
    'Initial History loading must use four skeleton cards'
);

history_ui_expect(
    str_contains($js, 'const HISTORY_DAYS = 30')
    && str_contains($js, 'recentMonthKeys()')
    && str_contains($js, "shell.get('request_logs'")
    && str_contains($js, "shell.get('transfer_history'")
    && str_contains($js, 'Promise.allSettled(requests)')
    && str_contains($js, '.slice(0, HISTORY_LIMIT)'),
    'Bounded recent 30-day multi-source loading is incomplete'
);

foreach (['TOPUP', 'BUNDLE', 'MFS', 'ADD_MONEY', 'TRANSFER'] as $source) {
    history_ui_expect(str_contains($js, "'{$source}'"), "History source {$source} is not supported");
}

history_ui_expect(
    str_contains($js, 'const key = `${item.source}:${item.id}`.toUpperCase()')
    && str_contains($js, '.sort((left, right) => right.timestamp - left.timestamp)'),
    'History dedupe or newest-first sorting is missing'
);

history_ui_expect(
    str_contains($js, "'WAITING_ADMIN'")
    && str_contains($js, "label: 'Pending'")
    && str_contains($js, "label: 'Successful'")
    && str_contains($js, "label: 'Failed'")
    && str_contains($js, "label: 'Processing'"),
    'Android status normalization is incomplete'
);

history_ui_expect(
    str_contains($js, "addDetail(cardRows, 'Operator', operator);")
    && str_contains($js, "addDetail(detailRows, 'Operator', operator);"),
    'Top-Up must preserve the Android operator-code presentation'
);

$transferStart = strpos($js, 'function transferItem(row)');
$transferEnd = strpos($js, 'function mfsItem(row)');
$transferBlock = ($transferStart !== false && $transferEnd !== false && $transferEnd > $transferStart)
    ? substr($js, $transferStart, $transferEnd - $transferStart)
    : '';
history_ui_expect(
    $transferBlock !== ''
    && !str_contains($transferBlock, "addDetail(cardRows, 'Balance After'")
    && str_contains($transferBlock, "addDetail(detailRows, 'Balance After'"),
    'Transfer Balance After must remain modal-only like Android'
);

history_ui_expect(
    str_contains($page, 'id="historyDetailModal"')
    && str_contains($page, 'data-history-modal-close')
    && str_contains($js, "actionButton('Share'")
    && str_contains($js, "actionButton('Close'")
    && str_contains($js, "actionButton('Open'")
    && str_contains($js, "event.key === 'Escape'")
    && str_contains($js, "window.addEventListener('popstate'"),
    'History detail modal and dismissal behavior are incomplete'
);

history_ui_expect(
    str_contains($js, 'if (item.receiptUrl) actions.append')
    && str_contains($js, "window.open(item.receiptUrl, '_blank', 'noopener,noreferrer')")
    && str_contains($js, 'canonicalReceiptUrl'),
    'Receipt Open action is not conditional and safely validated'
);

history_ui_expect(
    str_contains($js, "'zpay_history_share.png'")
    && str_contains($js, "canvas.toBlob")
    && str_contains($js, 'navigator.canShare?.({ files: [file] })')
    && str_contains($js, 'navigator.share({ files: [file]')
    && str_contains($js, "shell.toast('Transaction image saved.'"),
    'Local PNG share and safe fallback are incomplete'
);

history_ui_expect(
    str_contains($js, 'SHARE_ALLOWED_LABELS')
    && str_contains($js, 'function isShareAllowedLabel(value)')
    && str_contains($js, "return SHARE_ALLOWED_LABELS.has(label) && !/\\bBALANCE\\b/.test(label);")
    && str_contains($js, 'if (!isShareAllowedLabel(label)) return false;')
    && str_contains($js, "item.source === 'BUNDLE'")
    && str_contains($js, "['COMMISSION', 'RATE'].includes(label)")
    && !str_contains($js, "'UID', 'SESSION TOKEN'")
    && !str_contains($js, 'receipt_token'),
    'Share allowlist does not preserve Android privacy behavior'
);

$shareAllowedStart = strpos($js, 'const SHARE_ALLOWED_LABELS');
$shareAllowedEnd = strpos($js, 'const SHARE_LAYOUT');
$shareAllowedBlock = ($shareAllowedStart !== false && $shareAllowedEnd !== false && $shareAllowedEnd > $shareAllowedStart)
    ? substr($js, $shareAllowedStart, $shareAllowedEnd - $shareAllowedStart)
    : '';
history_ui_expect(
    $shareAllowedBlock !== '' && !str_contains($shareAllowedBlock, 'BALANCE'),
    'Balance labels must never be part of the History share allowlist'
);

history_ui_expect(
    str_contains($js, 'function prepareShareLayout(context, item)')
    && str_contains($js, "actualBoundingBoxDescent")
    && str_contains($js, 'const lastValueY = lineYs[lineYs.length - 1]')
    && str_contains($js, 'contentBottom = lastValueY + valueDescent')
    && str_contains($js, 'Math.ceil(contentBottom + SHARE_LAYOUT.cardPadding + SHARE_LAYOUT.cardInset)')
    && str_contains($js, 'layout.rows.forEach((entry) =>')
    && str_contains($js, 'entry.lineYs[index]'),
    'Share card height must be driven by the final rendered wrapped line'
);

history_ui_expect(
    str_contains($js, "if (context.measureText(word).width > maxWidth)")
    && str_contains($js, 'Array.from(word).forEach((character) =>')
    && str_contains($js, 'context.measureText(next).width > maxWidth'),
    'Long unbroken transaction IDs must wrap within the share card width'
);

history_ui_expect(
    str_contains($js, "params.get('entity_id')")
    && str_contains($js, "params.get('transfer_id')")
    && str_contains($js, "params.get('request_id')")
    && str_contains($js, 'state.rows.find'),
    'Authenticated History deep-link matching is missing'
);

history_ui_expect(
    str_contains($js, 'No transaction history found in the last 30 days.')
    && str_contains($js, 'History could not be loaded. Please try again.')
    && str_contains($js, 'if (hadRows) shell.toast'),
    'History empty/error/refresh fallback behavior is incomplete'
);

history_ui_expect(
    str_contains($js, 'document.createElement')
    && str_contains($js, 'line.append(document.createTextNode')
    && !str_contains($js, 'list.innerHTML = rows.map'),
    'History backend fields must be rendered through DOM text nodes'
);

history_ui_expect(
    str_contains($css, 'grid-template-columns: 50px minmax(0, 1fr) 50px')
    && str_contains($css, 'top: calc(8px + env(safe-area-inset-top))')
    && str_contains($css, 'margin: calc(12px + env(safe-area-inset-top)) 0 0')
    && str_contains($css, 'inset: calc(-8px - env(safe-area-inset-top)) -1px auto')
    && str_contains($css, 'border-radius: 16px')
    && str_contains($css, 'gap: 7px')
    && str_contains($css, 'width: min(90vw, 430px)')
    && str_contains($css, '@media (max-width: 359px)')
    && str_contains($css, 'overflow-x: hidden'),
    'Android proportions or narrow-mobile overflow protection are incomplete'
);

history_ui_expect(
    str_contains($bottomNav, "['history', '/user/history'")
    && str_contains($proxy, "case 'request_logs':")
    && str_contains($proxy, "case 'transfer_history':")
    && str_contains($proxy, 'user_proxy_require_login(true, false);'),
    'History navigation or authenticated canonical endpoints were not preserved'
);

echo "User History UI tests passed ({$assertions} assertions).\n";
