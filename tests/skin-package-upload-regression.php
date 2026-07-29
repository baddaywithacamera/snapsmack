<?php
/**
 * SNAPSMACK - Manual skin package upload regression checks.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
$gallery = file_get_contents($root . '/smack-skin.php') ?: '';
$registry = file_get_contents($root . '/core/skin-registry.php') ?: '';
$packager = file_get_contents($root . '/smack-central/sc-skins.php') ?: '';
$tilez_raw = file_get_contents($root . '/skins/tilez/manifest.json') ?: '';
$tilez = json_decode($tilez_raw, true);
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$expect(str_contains($gallery, 'name="skin_package"'), 'gallery must expose a ZIP upload control');
$expect(str_contains($gallery, "gallery_action\" value=\"upload"), 'gallery must post the upload action');
$expect(str_contains($gallery, 'reauth_verify('), 'skin upload must require password and TOTP step-up');
$expect(str_contains($gallery, "'admin', 'administrator', 'owner'"), 'skin upload must reject editor accounts');
$expect(str_contains($gallery, 'accept_skin_code'), 'skin upload must require executable-code consent');
$expect(str_contains($registry, "in_array('..', \$parts, true)"), 'upload installer must reject traversal paths');
$expect(str_contains($registry, 'count($manifest_entries) !== 1'), 'upload installer must require one package root');
$expect(str_contains($registry, 'smackback_init_skin_manifest('), 'upload installer must require a SMACKBACK manifest');
$expect(str_contains($registry, 'smackback_run_skin_js_scan()'), 'upload installer must run the skin JavaScript scan');
$expect(str_contains($registry, '.upload-backup-'), 'upload installer must preserve the previous skin until validation passes');
$expect(is_array($tilez) && ($tilez['status'] ?? '') === 'beta', 'TILEZ must use the supported beta status');
$expect(is_array($tilez) && ($tilez['version'] ?? '') === '0.1.3', 'TILEZ package version must advance');
$expect(str_contains($packager, 'function sc_extract_one_skin('), 'Skin Packager must support fetching one directory');
$expect(str_contains($packager, 'name="fetch_one_skin"'), 'Skin Packager must expose the one-skin fetch action');
$expect(str_contains($packager, "['master', 'dev']"), 'one-skin fetch must offer stable and dev branches');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "PASS: Skin package upload regression suite\n";
// ===== SNAPSMACK EOF =====
