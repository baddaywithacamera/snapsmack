<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'assets/js/ss-engine-rows.js' => ['sentinelStillNear()', 'if (!exhausted() && sentinelStillNear()) loadNext();'],
    'assets/js/ss-engine-columns.js' => ['if (!exhausted() && nearEnd()) loadNext();'],
    'assets/js/ss-engine-masonry.js' => ['sentinelStillNear()', 'if (!exhausted() && sentinelStillNear()) loadNext();'],
    'assets/js/ss-engine-mosaic-feed.js' => ['fetchWithTimeout', 'controller.abort()', '15000'],
    'skins/scroll/style.css' => ['#system-footer .sep', 'margin: 0 .7em'],
    'skins/slickr/style.css' => ['#system-footer .sep', 'margin: 0 .7em'],
];

foreach ($checks as $relative => $needles) {
    $source = (string)file_get_contents($root . '/' . $relative);
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            throw new RuntimeException("{$relative} lost continuation/footer repair: {$needle}");
        }
    }
}

echo "GRAM walls keep requesting short continuation batches.\n";
