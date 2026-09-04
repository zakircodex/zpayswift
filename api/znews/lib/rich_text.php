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

function znews_post_formatting_color_ids(): array
{
    return ['default', 'white', 'light_blue', 'green', 'yellow', 'orange', 'red'];
}

function znews_post_formatting_runs($value, string $text, bool $strict = false): array
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
    if (!is_array($value) || count($value) > 200) {
        return $invalid();
    }

    $length = znews_rich_text_length($text);
    $allowedColors = array_fill_keys(znews_post_formatting_color_ids(), true);
    $runs = [];
    foreach ($value as $run) {
        if (!is_array($run)) {
            return $invalid();
        }
        $unknown = array_diff(array_keys($run), ['start', 'end', 'bold', 'color']);
        $start = $run['start'] ?? null;
        $end = $run['end'] ?? null;
        $bold = $run['bold'] ?? false;
        $color = $run['color'] ?? 'default';
        if ($unknown
            || !is_int($start)
            || !is_int($end)
            || !is_bool($bold)
            || !is_string($color)
            || !isset($allowedColors[$color])
            || $start < 0
            || $end <= $start
            || $end > $length) {
            return $invalid();
        }
        if (!$bold && $color === 'default') {
            continue;
        }
        $normalized = ['start' => $start, 'end' => $end];
        if ($bold) {
            $normalized['bold'] = true;
        }
        if ($color !== 'default') {
            $normalized['color'] = $color;
        }
        $runs[] = $normalized;
    }

    usort($runs, static function (array $left, array $right): int {
        $byStart = $left['start'] <=> $right['start'];
        return $byStart !== 0 ? $byStart : ($left['end'] <=> $right['end']);
    });
    $previousEnd = 0;
    foreach ($runs as $index => $run) {
        if ($index > 0 && $run['start'] < $previousEnd) {
            return $invalid();
        }
        $previousEnd = $run['end'];
    }

    return $runs;
}

function znews_validate_post_formatting_runs($value, string $text): array
{
    return znews_post_formatting_runs($value, $text, true);
}

function znews_post_formatting_runs_from_bold_ranges(array $ranges, string $text): array
{
    return array_map(
        static fn(array $range): array => [
            'start' => (int)$range['start'],
            'end' => (int)$range['end'],
            'bold' => true,
        ],
        znews_post_bold_ranges($ranges, $text)
    );
}

function znews_post_bold_ranges_from_formatting_runs(array $runs, string $text): array
{
    $bold = [];
    foreach (znews_post_formatting_runs($runs, $text) as $run) {
        if (empty($run['bold'])) {
            continue;
        }
        $lastIndex = count($bold) - 1;
        if ($lastIndex >= 0 && $bold[$lastIndex]['end'] === $run['start']) {
            $bold[$lastIndex]['end'] = $run['end'];
            continue;
        }
        $bold[] = ['start' => $run['start'], 'end' => $run['end']];
    }
    return $bold;
}
