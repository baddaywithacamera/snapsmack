<?php
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
    'Images in longform post buckets (expected editorial working set)',
    'Library images not currently in a post bucket',
    'A bucket is private editorial state, not a list of image-posts',
    'they are not posts and must not be converted',
    'does not use the SMACKONEOUT photo-to-post conversion',
] as $needle) {
    if (strpos($modcheck, $needle) === false) $failures[] = "modcheck missing mode-aware language: {$needle}";
}

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: post-model diagnostics and conversion respect install-mode boundaries.\n";
