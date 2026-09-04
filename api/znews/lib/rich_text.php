<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_rich_text_length(string $text): int
{
    if (function_exists('znews_text_length')) {
        return znews_text_length($text);
    }
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($text, 'UTF-8');
    }
    $count = preg_match_all('/./us', $text, $matches);
    return $count === false ? strlen($text) : $count;
}

function znews_post_bold_ranges($value, string $text, bool $strict = false): array
{
    $invalid = static function () use ($strict): array {
        if ($strict) {
            api_response(
                false,
                'ZNEWS_POST_FORMAT_INVALID',
                'Post formatting is invalid.',
                [],
                422
            );
        }
        return [];
    };

    if ($value === null || $value === '') {
        return [];
    }
    if (!is_array($value) || count($value) > 100) {
        return $invalid();
    }

    $length = znews_rich_text_length($text);
    $ranges = [];
    foreach ($value as $range) {
        if (!is_array($range)) {
            return $invalid();
        }
        $start = $range['start'] ?? null;
        $end = $range['end'] ?? null;
        if (!is_int($start) || !is_int($end) || $start < 0 || $end <= $start || $end > $length) {
            return $invalid();
        }
        $ranges[] = ['start' => $start, 'end' => $end];
    }

    usort($ranges, static function (array $left, array $right): int {
        $byStart = $left['start'] <=> $right['start'];
        return $byStart !== 0 ? $byStart : ($left['end'] <=> $right['end']);
    });
    $previousEnd = 0;
    foreach ($ranges as $index => $range) {
        if ($index > 0 && $range['start'] < $previousEnd) {
            return $invalid();
        }
        $previousEnd = $range['end'];
    }

    return $ranges;
}

function znews_validate_post_bold_ranges($value, string $text): array
{
    return znews_post_bold_ranges($value, $text, true);
}
