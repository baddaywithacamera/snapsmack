<?php
/** Generate the legacy per-file security debt snapshot. Never run implicitly. */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require_once $root . '/core/skin-security-policy.php';
$baseline = [];
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $manifest) {
    $dir = dirname($manifest);
    $slug = basename($dir);
    if (snapsmack_skin_security_strict($dir)) continue;
    foreach (snapsmack_skin_security_findings($dir) as $finding) {
        $file = $finding['file'];
        $type = $finding['type'];
        $baseline[$slug][$file][$type] = ($baseline[$slug][$file][$type] ?? 0) + 1;
    }
}
ksort($baseline);
foreach ($baseline as &$files) {
    ksort($files);
    foreach ($files as &$types) ksort($types);
}
unset($files, $types);
$export = "<?php\n/** Generated legacy debt snapshot. Counts are ceilings and may only shrink. */\nreturn "
    . var_export($baseline, true) . ";\n";
$export = preg_replace('/[ \t]+$/m', '', $export);
$target = $root . '/core/skin-security-legacy.php';
if (@file_put_contents($target, $export, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write {$target}\n");
    exit(1);
}
echo "Wrote per-file baseline for " . count($baseline) . " legacy skins.\n";

// ===== SNAPSMACK EOF =====
