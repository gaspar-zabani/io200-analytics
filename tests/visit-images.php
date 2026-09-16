<?php

// Run from the repository root: php tests/visit-images.php
require_once __DIR__ . '/../visits.php';

$safeUrl = static function ($value) {
    return is_string($value) && str_starts_with($value, '/images/')
        ? $value : null;
};

$queries = 0;
$sources = [
    585 => ['photo_id' => 585, 'image_url' => '/images/585.jpg', 'is_admin' => 0],
    636 => ['photo_id' => 636, 'image_url' => '/images/636.jpg', 'is_admin' => 0],
    700 => ['photo_id' => 700, 'image_url' => '/images/admin.jpg', 'is_admin' => 1],
    999 => ['photo_id' => 999, 'image_url' => '/images/unrelated.jpg', 'is_admin' => 0],
];
$lookup = static function (array $photoIds) use (&$queries, $sources) {
    $queries++;
    $rows = [];
    foreach ($photoIds as $photoId) {
        $row = $sources[$photoId] ?? null;
        if ($row !== null && $row['is_admin'] === 0) $rows[] = $row;
    }
    return $rows;
};

// A basket-remove-only Visit has identity but no local image. Both render sets
// must resolve it from the same targeted, non-admin event source.
$latestImages = ioaEnrichVisitImages([585], [], $safeUrl, $lookup);
$sessionImages = ioaEnrichVisitImages([585], [], $safeUrl, $lookup);
if (($latestImages[585] ?? null) !== '/images/585.jpg'
    || ($sessionImages[585] ?? null) !== '/images/585.jpg') {
    throw new RuntimeException('Latest and Session rendering resolved different thumbnails');
}

// The resolved map is shared by the representative, Basket, and activity UI.
foreach (['representative', 'basket', 'activity'] as $surface) {
    if (($sessionImages[585] ?? null) !== '/images/585.jpg') {
        throw new RuntimeException('Missing shared image for ' . $surface);
    }
}

$images = ioaEnrichVisitImages(
    [585, 636, 700],
    [636 => '/images/local-636.jpg'],
    $safeUrl,
    $lookup
);
if (($images[585] ?? null) !== '/images/585.jpg') {
    throw new RuntimeException('Missing fallback image');
}
if (($images[636] ?? null) !== '/images/local-636.jpg') {
    throw new RuntimeException('Visit-local image was replaced');
}
if (isset($images[700])) {
    throw new RuntimeException('Admin event became an enrichment source');
}
if (isset($images[999])) {
    throw new RuntimeException('Unrelated photo enriched the Visit');
}

$replacedUnusable = ioaEnrichVisitImages(
    [585],
    [585 => 'https://unsafe.example/585.jpg'],
    $safeUrl,
    $lookup
);
if (($replacedUnusable[585] ?? null) !== '/images/585.jpg') {
    throw new RuntimeException('Unusable Visit-local image did not use fallback');
}

$queriesBeforeResolvedCase = $queries;
$resolved = ioaEnrichVisitImages(
    [585, 636],
    [585 => '/images/local-585.jpg', 636 => '/images/local-636.jpg'],
    $safeUrl,
    static function () use (&$queries) {
        $queries++;
        throw new RuntimeException('Fallback lookup ran for fully resolved images');
    }
);
if ($queries !== $queriesBeforeResolvedCase || count($resolved) !== 2) {
    throw new RuntimeException('Fully resolved images performed fallback work');
}

// Guard the production lookup's two essential constraints.
$dashboard = file_get_contents(__DIR__ . '/../dashboard.php');
if (!preg_match('/WHERE photo_id IN \(\{\$idList\}\)[\s\S]*?\{\$whereAdmin\}/', $dashboard)) {
    throw new RuntimeException('Visit image lookup is not ID-scoped and admin-filtered');
}

echo "Visit image enrichment regression cases passed\n";
