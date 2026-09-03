<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function znews_active_categories(): array
{
    return [
        'INTERNATIONAL_NEWS',
        'BD_NEWS',
        'MOBILE_PRICING',
    ];
}

function znews_normalize_category($value, bool $allowEmpty = true): string
{
    $category = strtoupper(trim((string)$value));
    if ($category === '' && $allowEmpty) {
        return '';
    }
    if (!in_array($category, znews_active_categories(), true)) {
        api_response(false, 'ZNEWS_POST_CATEGORY_INVALID', 'Choose a valid post category.', [
            'allowed' => znews_active_categories(),
        ], 422);
    }
    return $category;
}

function znews_category_created_at(string $category, int $createdAt): string
{
    $category = znews_normalize_category($category, false);
    return $category . '|' . str_pad((string)max(0, $createdAt), 12, '0', STR_PAD_LEFT);
}

function znews_category_query_start(string $category): string
{
    return znews_normalize_category($category, false) . '|000000000000';
}

function znews_category_query_end(string $category, int $snapshotAt): string
{
    return znews_category_created_at($category, $snapshotAt);
}
