<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$failures = [];
function ohsnap_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

$api = file_get_contents(__DIR__ . '/../core/ohsnap-api.php');
$client = file_get_contents(__DIR__ . '/../tools/oh-snap/src/scripts/api.js');
$main = file_get_contents(__DIR__ . '/../tools/oh-snap/src/scripts/main.js');
$project = file_get_contents(__DIR__ . '/../tools/oh-snap/src/scripts/project.js');
$config = json_decode(file_get_contents(__DIR__ . '/../tools/oh-snap/src-tauri/tauri.conf.json'), true);

ohsnap_test(str_contains($api, "isset(\$body['skin_slug'], \$body['vars'])"), 'variable push does not require an explicit skin slug');
ohsnap_test(str_contains($api, 'hash_equals((string)$active_skin, $requested_skin)'), 'variable push does not reject an active-skin race');
ohsnap_test(str_contains($api, "empty(\$manifest['oh_snap_ready'])"), 'server does not enforce explicit OH SNAP readiness');
ohsnap_test(str_contains($api, 'empty($declared[$prop])'), 'server accepts undeclared skin variables');
ohsnap_test(str_contains($client, 'JSON.stringify({ skin_slug: skinSlug, vars })'), 'client does not name the reviewed skin');
ohsnap_test(str_contains($main, 'New Offline Skin') || str_contains($main, 'enterOfflineProject'), 'offline entry workflow is absent');
ohsnap_test(str_contains($project, 'schema_version: SCHEMA_VERSION'), 'schema-v2 project format is absent');
ohsnap_test(str_contains($project, "package_lane: 'SHAREABLE'"), 'project lane is not locked to SHAREABLE');
ohsnap_test(($config['app']['security']['csp'] ?? null) !== null, 'production Tauri CSP is null');
ohsnap_test(($config['bundle']['targets'] ?? []) === ['nsis'], 'Windows bundle target is not deterministic NSIS');

foreach (['new-horizon', '50-shades-of-noah-grey'] as $slug) {
    $manifest = json_decode(file_get_contents(__DIR__ . "/../skins/{$slug}/manifest.json"), true);
    ohsnap_test(!empty($manifest['oh_snap_ready']), "{$slug} does not declare OH SNAP readiness");
}

if ($failures) {
    fwrite(STDERR, "OH SNAP regression failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OH SNAP regression checks passed.\n";
// ===== SNAPSMACK EOF =====
