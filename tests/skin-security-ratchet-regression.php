<?php
require_once dirname(__DIR__) . '/core/skin-security-policy.php';
$root = dirname(__DIR__);
$baseline = require $root . '/core/skin-security-legacy.php';
$failed = [];
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $manifest) {
    $dir = dirname($manifest);
    $excess = snapsmack_skin_security_gate($dir, $baseline);
    if ($excess) $failed[basename($dir)] = $excess;
}
if ($failed) {
    fwrite(STDERR, json_encode($failed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
echo "Skin security debt ratchet: PASS\n";
