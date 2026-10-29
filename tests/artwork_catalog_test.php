<?php
declare(strict_types=1);

require __DIR__ . '/../includes/helpers.php';

$expected = [
    'concert' => 'Concert',
    'cinema' => 'Cinema',
    'gallery' => 'Gallery',
    'digital' => 'Digital',
    'city' => 'City',
    'nature' => 'Nature',
    'learning' => 'Study',
    'sports' => 'Court',
    'social' => 'Gathering',
    'other' => 'Abstract',
];

$failures = 0;
if (artworkChoices() !== array_keys($expected)) {
    fwrite(STDERR, "FAIL artwork identifiers changed\n");
    $failures++;
}
foreach ($expected as $key => $label) {
    if (artworkLabel($key) !== $label) {
        fwrite(STDERR, "FAIL artwork label: {$key}\n");
        $failures++;
    }
    $path = artworkPath($key);
    if ($path !== "assets/art/{$key}.svg" || !is_file(__DIR__ . '/../' . $path)) {
        fwrite(STDERR, "FAIL artwork SVG missing: {$key}\n");
        $failures++;
    }
}
if (artworkLabel('unknown') !== 'Concert' || artworkPath('unknown') !== 'assets/art/concert.svg') {
    fwrite(STDERR, "FAIL invalid artwork fallback\n");
    $failures++;
}
foreach (['Music' => 'concert', 'Film' => 'cinema', 'Arts' => 'gallery',
    'Technology' => 'digital', 'Community' => 'city', 'Outdoors' => 'nature',
    'Learning' => 'learning', 'Sports' => 'sports', 'Social' => 'social',
    'Other' => 'other'] as $category => $artwork) {
    if (categoryArtwork($category) !== $artwork) {
        fwrite(STDERR, "FAIL uploaded cover fallback: {$category}\n");
        $failures++;
    }
}

if ($failures) {
    fwrite(STDERR, "{$failures} artwork catalogue tests failed\n");
    exit(1);
}
echo "PASS artwork IDs, display labels, SVG files and deprecated asset cleanup\n";
