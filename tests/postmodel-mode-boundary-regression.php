<?php
/** SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. */
$maintenance = file_get_contents(__DIR__ . '/../smack-maintenance.php');
$modcheck = file_get_contents(__DIR__ . '/../modcheck.php');
$failures = [];

foreach ([
    "\$repair_site_mode !== 'photoblog'",
    'Photo-to-post conversion is only valid for SMACKONEOUT sites',
    'Only valid for SMACKONEOUT sites',
] as $needle) {
    if (strpos($maintenance, $needle) === false) $failures[] = "Maintenance missing mode boundary: {$needle}";
}
foreach ([
    "\$site_mode === 'smacktalk'",
    "pm_table_exists(\$pdo, 'snap_bucket_items')",
    'Images in longform post buckets (expected editorial working set)',
    'Library images not currently in a post bucket',
    'Longform drafts',
    'Published longform posts',
    'FROM snap_bucket_items b',
    'NOT EXISTS (SELECT 1 FROM snap_bucket_items b WHERE b.image_id = i.id)',
    "WHERE p.post_type = 'longform'",
    'These images are not standalone posts and must not be converted',
    'does <b>not</b> use the SMACKONEOUT photo-to-post conversion',
] as $needle) {
    if (strpos($modcheck, $needle) === false) $failures[] = "modcheck missing mode-aware language: {$needle}";
}

// Regression for 538D's false fix: it called snap_post_images a longform
// "bucket". The real private editorial bucket is snap_bucket_items.
$smacktalkStart = strpos($modcheck, "if (\$site_mode === 'smacktalk')");
$smacktalkEnd = strpos($modcheck, "pm_row(\$rows, 'Images that ARE attached", $smacktalkStart ?: 0);
if ($smacktalkStart === false || $smacktalkEnd === false) {
    $failures[] = 'could not isolate the SMACKTALK content-shape branch';
} else {
    $smacktalkBranch = substr($modcheck, $smacktalkStart, $smacktalkEnd - $smacktalkStart);
    if (strpos($smacktalkBranch, 'snap_post_images') !== false) {
        $failures[] = 'SMACKTALK bucket diagnostic still queries snap_post_images instead of snap_bucket_items';
    }
}
if (strpos($modcheck, "} else {\n        pm_row(\$rows, 'Published solo photos (bare image, no post)'") === false) {
    $failures[] = 'published bare-photo row is not confined to the non-SMACKTALK branch';
}

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: post-model diagnostics and conversion respect install-mode boundaries.\n";

// ===== SNAPSMACK EOF =====
