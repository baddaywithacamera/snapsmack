<?php
/** GYSS must use the canonical snap_image_cat_map.cat_id column. */

$server = file_get_contents(__DIR__ . '/../core/gyss-api.php');
if ($server === false) {
    fwrite(STDERR, "Cannot read core/gyss-api.php\n");
    exit(1);
}

foreach (['cm.cat_id', 'cm2.cat_id', 'cm3.cat_id', '(image_id, cat_id)', 'SELECT image_id, cat_id'] as $needle) {
    if (strpos($server, $needle) === false) {
        fwrite(STDERR, "Missing canonical GYSS category mapping: {$needle}\n");
        exit(1);
    }
}

foreach (['cm.category_id', 'cm2.category_id', 'cm3.category_id', '(image_id, category_id)', 'SELECT image_id, category_id'] as $invalid) {
    if (strpos($server, $invalid) !== false) {
        fwrite(STDERR, "Invalid GYSS category mapping returned: {$invalid}\n");
        exit(1);
    }
}

if (strpos($server, 'PDO::PARAM_INT') === false) {
    fwrite(STDERR, "GYSS pagination is not integer-bound\n");
    exit(1);
}

echo "GYSS category-column regression: PASS\n";

