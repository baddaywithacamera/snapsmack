<?php
/** Regression: numeric mosaic continuations must use the public page query. */

$js = file_get_contents(__DIR__ . '/../assets/js/ss-engine-mosaic-feed.js');
if ($js === false) {
    fwrite(STDERR, "Could not read mosaic feed engine.\n");
    exit(1);
}

$checks = [
    'numeric next-page normalization exists' => str_contains($js, "function pageRequestUrl(value)"),
    'numeric page becomes a query route' => str_contains($js, "'?page=' + encodeURIComponent(value)"),
    'the normalized URL is fetched' => str_contains($js, 'fetchWithTimeout(pageRequestUrl(nextUrl))'),
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "PASS: numeric mosaic continuation uses ?page=N.\n";
// ===== SNAPSMACK EOF =====
