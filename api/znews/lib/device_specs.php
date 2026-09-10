<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_device_category(): string
{
    return 'MOBILE_PRICING';
}

function znews_device_types(): array
{
    return ['MOBILE', 'LAPTOP', 'TABLET', 'SMARTWATCH', 'CAMERA', 'ACCESSORY', 'OTHER'];
}

function znews_device_clean_text($value, int $maximum): string
{
    $text = trim(strip_tags((string)$value));
    $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length <= $maximum) {
        return $text;
    }
    return function_exists('mb_substr')
        ? trim((string)mb_substr($text, 0, $maximum, 'UTF-8'))
        : trim(substr($text, 0, $maximum));
}

function znews_format_device_specs($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $rows = [];
    foreach (array_slice($value, 0, 16) as $key => $item) {
        if (is_array($item)) {
            $label = znews_device_clean_text($item['label'] ?? '', 48);
            $specValue = znews_device_clean_text($item['value'] ?? '', 300);
        } elseif (is_string($key)) {
            $label = znews_device_clean_text($key, 48);
            $specValue = znews_device_clean_text($item, 300);
        } else {
            continue;
        }
        if ($label === '' || $specValue === '') {
            continue;
        }
        $rows[] = ['label' => $label, 'value' => $specValue];
    }
    return $rows;
}

function znews_device_details_from_post(array $post): array
{
    $category = strtoupper(trim((string)($post['category'] ?? '')));
    if ($category !== znews_device_category()) {
        return ['device_type' => '', 'device_specs' => []];
    }

    $type = strtoupper(trim((string)($post['device_type'] ?? '')));
    if (!in_array($type, znews_device_types(), true)) {
        $type = '';
    }
    return [
        'device_type' => $type,
        'device_specs' => znews_format_device_specs($post['device_specs'] ?? []),
    ];
}

function znews_validate_device_details(
    string $category,
    $typeValue,
    $specsValue,
    bool $detailsProvided
): array {
    if ($category !== znews_device_category()) {
        return ['device_type' => '', 'device_specs' => []];
    }

    $type = strtoupper(trim((string)$typeValue));
    if ($type !== '' && !in_array($type, znews_device_types(), true)) {
        api_response(false, 'ZNEWS_DEVICE_TYPE_INVALID', 'Choose a valid device type.', [
            'allowed' => znews_device_types(),
        ], 422);
    }
    if ($detailsProvided && !is_array($specsValue)) {
        api_response(false, 'ZNEWS_DEVICE_SPECS_INVALID', 'Device specifications are invalid.', [], 422);
    }

    $specs = znews_format_device_specs($specsValue);
    if ($detailsProvided && ($type === '' || $specs === [])) {
        api_response(
            false,
            'ZNEWS_DEVICE_DETAILS_REQUIRED',
            'Choose a device type and add at least one specification.',
            [],
            422
        );
    }
    return ['device_type' => $type, 'device_specs' => $specs];
}

function znews_device_specs_moderation_text(array $details): string
{
    $parts = [];
    $type = trim((string)($details['device_type'] ?? ''));
    if ($type !== '') {
        $parts[] = 'Device type: ' . $type;
    }
    foreach (znews_format_device_specs($details['device_specs'] ?? []) as $spec) {
        $parts[] = (string)$spec['label'] . ': ' . (string)$spec['value'];
    }
    return implode("\n", $parts);
}
