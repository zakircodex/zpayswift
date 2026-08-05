<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/znews/assets/znews-config.js';
$source = is_file($path) ? file_get_contents($path) : false;

function zsky24_responsive_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

zsky24_responsive_expect(is_string($source), 'znews-config.js could not be read');

zsky24_responsive_expect(
    !str_contains($source, 'new MutationObserver(normaliseCreatorRevenueUi)'),
    'unbounded MutationObserver callback can recursively rewrite the page'
);
zsky24_responsive_expect(
    str_contains($source, 'revenueUiObserver?.disconnect()'),
    'observer is not disconnected while normalising its own DOM targets'
);
zsky24_responsive_expect(
    str_contains($source, 'revenueUiNormalising'),
    're-entrancy guard is missing'
);
zsky24_responsive_expect(
    str_contains($source, 'revenueUiScheduled'),
    'observer callback scheduling guard is missing'
);
zsky24_responsive_expect(
    str_contains($source, 'requestAnimationFrame'),
    'DOM normalisation is not throttled to an animation frame'
);
zsky24_responsive_expect(
    str_contains($source, 'records.some(mutationTouchesRevenueUi)'),
    'observer does not filter irrelevant feed mutations'
);
zsky24_responsive_expect(
    str_contains($source, 'element.childNodes.length > 0'),
    'empty ad slots can be rewritten repeatedly'
);
zsky24_responsive_expect(
    str_contains($source, 'setTextIfChanged'),
    'policy text can be replaced even when unchanged'
);
zsky24_responsive_expect(
    substr_count($source, "dataset.periodPolicyApplied !== 'true'") >= 2,
    'policy sections and notice are not both idempotent'
);

echo "Z Sky 24 page responsiveness regression passed.\n";
