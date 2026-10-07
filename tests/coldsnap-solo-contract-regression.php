<?php
declare(strict_types=1);

$endpoint = (string)file_get_contents(dirname(__DIR__) . '/smack-post-solo.php');
foreach (["'landscape' => 0", "'portrait' => 1", "'square' => 2"] as $mapping) {
    if (!str_contains($endpoint, $mapping)) {
        throw new RuntimeException("ColdSnap orientation mapping missing: {$mapping}");
    }
}
if (!str_contains($endpoint, '$orientation_values[$orient_override]')) {
    throw new RuntimeException('Solo endpoint does not use the bounded orientation vocabulary.');
}

$mode = (string)file_get_contents(dirname(__DIR__) . '/tools/coldsnap/coldsnap_qt/mode_solo.py');
foreach (['snap_library.category_map(url)', 'snap_library.album_map(url)',
          'site_data={"categories": categories, "albums": albums}'] as $needle) {
    if (!str_contains($mode, $needle)) {
        throw new RuntimeException("ColdSnap solo taxonomy connection missing: {$needle}");
    }
}

echo "PASS: ColdSnap solo taxonomy and orientation contracts are connected.\n";
// ===== SNAPSMACK EOF =====
