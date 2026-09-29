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

// Prove the baseline is scoped to both file and capability, not just a skin total.
$tmp = sys_get_temp_dir() . '/snapsmack-ratchet-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    file_put_contents($tmp . '/manifest.json', '{"schema_version":1}');
    $guard = "<?php defined('SNAPSMACK_SKIN_RENDER') || exit; ";
    file_put_contents($tmp . '/legacy.php', $guard . '$x = $_GET["x"];');
    $slug = basename($tmp);
    $fixture_baseline = [$slug => ['legacy.php' => ['request-global' => 1]]];
    if (snapsmack_skin_security_gate($tmp, $fixture_baseline) !== []) {
        throw new RuntimeException('An unchanged per-file legacy finding did not match its baseline.');
    }

    file_put_contents($tmp . '/legacy.php', $guard . '$x = $_GET["x"]; $y = $_POST["y"];');
    if (snapsmack_skin_security_gate($tmp, $fixture_baseline) === []) {
        throw new RuntimeException('An added finding in an existing file escaped the ratchet.');
    }

    file_put_contents($tmp . '/legacy.php', $guard . 'echo "clean";');
    file_put_contents($tmp . '/moved.php', $guard . '$x = $_GET["x"];');
    if (snapsmack_skin_security_gate($tmp, $fixture_baseline) === []) {
        throw new RuntimeException('A moved legacy finding escaped the per-file ratchet.');
    }

    unlink($tmp . '/moved.php');
    file_put_contents($tmp . '/new.php', $guard . '$x = $_GET["x"];');
    if (snapsmack_skin_security_gate($tmp, $fixture_baseline) === []) {
        throw new RuntimeException('A finding in a new file escaped the ratchet.');
    }
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) unlink($file);
    rmdir($tmp);
}
echo "Skin security debt ratchet: PASS\n";
