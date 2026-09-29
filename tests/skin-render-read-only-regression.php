<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/skin-security-policy.php';
$root = dirname(__DIR__);
$failures = [];
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $manifest) {
    $skin = basename(dirname($manifest));
    foreach (snapsmack_skin_security_findings(dirname($manifest)) as $finding) {
        if (($finding['type'] ?? '') === 'render-time-mutation') {
            $failures[] = $skin . '/' . $finding['file'] . ':' . $finding['line'];
        }
    }
}

$tmp = tempnam(sys_get_temp_dir(), 'skin-mutation-');
try {
    file_put_contents($tmp, "<?php defined('SNAPSMACK_SKIN_RENDER') || exit; \$pdo->exec('UPDATE snap_settings SET setting_val=1');");
    if (snapsmack_skin_policy_scan_mutations($tmp, 'fixture.php') === []) {
        $failures[] = 'Mutating SQL fixture escaped the render-time mutation gate.';
    }
    file_put_contents($tmp, "<?php defined('SNAPSMACK_SKIN_RENDER') || exit; file_put_contents('x', 'y');");
    if (snapsmack_skin_policy_scan_mutations($tmp, 'fixture.php') === []) {
        $failures[] = 'Filesystem write fixture escaped the render-time mutation gate.';
    }
} finally {
    if (is_file($tmp)) unlink($tmp);
}

$stanley = (string)file_get_contents($root . '/skins/stanley/skin-header.php');
$cms = (string)file_get_contents($root . '/core/skin-view-model.php');
if (str_contains($stanley, 'snap_assets') || str_contains($stanley, 'stanley__stanley_2024_hero')) {
    $failures[] = 'STANLEY still owns hero lookup or persistence.';
}
if (!str_contains($cms, 'function snapsmack_initialize_skin_media_slots(')
    || !str_contains($cms, 'snapsmack_resolve_skin_media_slot($pdo, $settings, $slot, false)')) {
    $failures[] = 'CMS does not separate explicit initialization from read-only public resolution.';
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}
echo "All skin render paths are free of detected state mutation.\n";
