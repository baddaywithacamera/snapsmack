<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
$root = dirname(__DIR__);
$php = file_get_contents($root . '/smack-post-gram.php');
$js  = file_get_contents($root . '/assets/js/ss-engine-gram-post.js');

$checks = [
    'CMS reads orientation overrides' => str_contains($php, "\$_POST['orientation_override']"),
    'CMS reads colour mode' => str_contains($php, "\$_POST['color_mode']"),
    'CMS normalizes colour mode' => str_contains($php, 'snap_normalize_color_mode'),
    'CMS saves colour mode' => str_contains($php, 'img_color_mode'),
    'CMS saves selected orientation' => str_contains($php, '$img_orient'),
    'composer shows orientation control' => str_contains($js, 'class="gp-orientation"'),
    'composer shows colour control' => str_contains($js, 'class="gp-color-mode"'),
    'composer sends orientation override' => str_contains($js, "data.append('orientation_override[]'"),
    'composer sends colour mode' => str_contains($js, "data.append('color_mode[]'"),
];

$failed = array_keys(array_filter($checks, fn($ok) => !$ok));
if ($failed) {
    fwrite(STDERR, "FAIL: " . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo "PASS: GRAMOFSMACK CMS metadata options match the desktop publishing contract." . PHP_EOL;
// ===== SNAPSMACK EOF =====
