<?php
/**
 * Regression: GYSS must use the canonical snap_image_cat_map.cat_id column.
 *
 * The desktop client previously authenticated successfully, then GET gyss/meta
 * failed because its server queries used the nonexistent category_id column.
 */

$source = file_get_contents(__DIR__ . '/../core/gyss-api.php');
if ($source === false) {
    fwrite(STDERR, "Could not read core/gyss-api.php\n");
    exit(1);
}

$forbidden = [
    'cm.category_id',
    'cm2.category_id',
    'cm3.category_id',
    'SELECT image_id, category_id FROM snap_image_cat_map',
    'snap_image_cat_map (image_id, category_id)',
];

foreach ($forbidden as $needle) {
    if (strpos($source, $needle) !== false) {
        fwrite(STDERR, "GYSS still references noncanonical category column: {$needle}\n");
        exit(1);
    }
}

$required = [
    'cm.cat_id = c.id',
    'c2.id = cm2.cat_id',
    'SELECT image_id, cat_id FROM snap_image_cat_map',
    'snap_image_cat_map (image_id, cat_id)',
];

foreach ($required as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "GYSS canonical category mapping missing: {$needle}\n");
        exit(1);
    }
}

echo "GYSS category schema regression: PASS\n";
