<?php
/** Regression coverage for mixed-version fleets and GYSS-only profile filtering. */

$api = file_get_contents(__DIR__ . '/../tools/gyss/src/scripts/api.js');
$profiles = file_get_contents(__DIR__ . '/../tools/gyss/src/scripts/profiles.js');
$main = file_get_contents(__DIR__ . '/../tools/gyss/src/scripts/main.js');
$server = file_get_contents(__DIR__ . '/../core/gyss-api.php');

foreach ([
    [$api, "replace(/\\/uploads\\/img_uploads\\//g, '/img_uploads/')", 'desktop media URL repair'],
    [$profiles, "extras.gyss_site_mode === 'smacktalk'", 'SMACKTALK profile exclusion'],
    [$main, 'state.meta = { categories: [], albums: [] }', 'optional metadata fallback'],
    [$server, "SELECT c.id, c.cat_name AS name, 0 AS `count`", 'category metadata fallback'],
    [$server, "SELECT a.id, a.album_name AS name, 0 AS `count`", 'album metadata fallback'],
    [$server, "gy_has_column(\$pdo, 'snap_images', 'modified_at')", 'legacy image timestamp detection'],
    [$server, "'i.img_date AS modified_at'", 'legacy image timestamp fallback'],
    [$server, "'NULL AS modified_at'", 'legacy save conflict fallback'],
    [$server, "gy_has_column(\$pdo, 'snap_images', 'sort_order')", 'legacy image ordering detection'],
    [$server, "'0 AS sort_order'", 'legacy image ordering fallback'],
    [$server, "function gy_has_table(PDO \$pdo, string \$table)", 'optional table detection'],
    [$server, 'cm2.cat_id', 'canonical image-category membership column'],
    [$server, 'PDO::PARAM_INT', 'numeric pagination binding'],
] as [$source, $needle, $label]) {
    if ($source === false || strpos($source, $needle) === false) {
        fwrite(STDERR, "GYSS compatibility safeguard missing: {$label}\n");
        exit(1);
    }
}

// Production 0.7.649D regressed to `snap_image_cat_map.category_id`, but the
// canonical table (and every deployed site inspected) names that column
// `cat_id`. Keep the invalid spelling out of every GYSS query.
if (strpos($server, 'snap_image_cat_map cm2 JOIN snap_categories c2 ON c2.id = cm2.category_id') !== false
    || strpos($server, 'LEFT JOIN snap_image_cat_map cm ON cm.category_id = c.id') !== false) {
    fwrite(STDERR, "GYSS compatibility safeguard missing: invalid category_id mapping column returned\n");
    exit(1);
}

echo "GYSS desktop compatibility regression: PASS\n";
