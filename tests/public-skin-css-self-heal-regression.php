<?php
$root = dirname(__DIR__);
$meta = file_get_contents($root . '/core/meta.php');
$compiler = file_get_contents($root . '/core/skin-css-recompile.php');
$checks = [
    'renderer computes the target stamp' => str_contains($meta, 'snapsmack_public_css_target_stamp'),
    'renderer repairs stale CSS' => str_contains($meta, 'snapsmack_recompile_public_skin_css'),
    'renderer reloads repaired CSS' => str_contains($meta, "WHERE setting_key IN ('custom_css_public', 'custom_css_public_stamp')"),
    'compiler persists its stamp' => str_contains($compiler, "'custom_css_public_stamp'"),
    'stamp covers CMS and skin versions' => str_contains($compiler, "'+cms@'") && str_contains($compiler, 'SNAPSMACK_VERSION_SHORT'),
];
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "PASS: public skin CSS self-heal is wired to the CMS compiler.\n";
