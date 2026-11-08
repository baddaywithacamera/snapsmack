<?php
// THE REPAIR MOVED, AND SO DID THESE CHECKS. They used to read core/meta.php,
// which is the LEGACY head -- strict skins never include it, so these very
// checks passed for months while the repair could not run on a single strict
// page. The logic now lives in one shared function and the BEHAVIOUR is proved
// by calling it, in tests/strict-skin-css-self-heal-regression.php. What is
// left here is the narrow structural claim: the shared repair still exists and
// the legacy head still reaches it.
$root = dirname(__DIR__);
$meta = file_get_contents($root . '/core/meta.php');
$repair = file_get_contents($root . '/core/skin-css-recompile.php');
$compiler = $repair;
$checks = [
    'the repair computes the target stamp' => str_contains($repair, 'snapsmack_public_css_target_stamp'),
    'the repair recompiles stale CSS' => str_contains($repair, 'snapsmack_recompile_public_skin_css'),
    'the repair rejects a differently identified skin blob' => str_contains($repair, 'SKIN_ID')
        && str_contains($repair, 'str_contains'),
    'the repair reloads what it wrote' => str_contains($repair, "WHERE setting_key IN ('custom_css_public', 'custom_css_public_stamp')"),
    'the legacy head still reaches the shared repair' => str_contains($meta, 'snapsmack_public_css_refresh_if_stale('),
    'and no longer carries its own copy of the staleness test'
        => !str_contains($meta, 'snapsmack_public_css_target_stamp'),
    'compiler persists its stamp' => str_contains($compiler, "'custom_css_public_stamp'"),
    'compiler embeds the same identity inside the CSS blob' => str_contains($compiler, '/* SKIN_ID {$target_stamp} */'),
    'stamp covers CMS and skin versions' => str_contains($compiler, "'+cms@'") && str_contains($compiler, 'SNAPSMACK_VERSION_SHORT'),
    'stamp also folds in a manifest-options hash (986D, so a same-version option change recompiles)'
        => str_contains($compiler, "'+opt@'") && str_contains($compiler, "sha1((string)json_encode(\$manifest['options']))"),
];
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "PASS: public skin CSS self-heal is wired to the CMS compiler.\n";
