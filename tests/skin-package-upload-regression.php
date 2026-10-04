<?php
/**
 * SNAPSMACK - Registry-only skin gallery regression checks.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
$gallery = file_get_contents($root . '/smack-skin.php') ?: '';
$packager = file_get_contents($root . '/smack-central/sc-skins.php') ?: '';
$updater = file_get_contents($root . '/smack-update.php') ?: '';
$updaterCore = file_get_contents($root . '/core/updater.php') ?: '';
$dashboard = file_get_contents($root . '/smack-admin.php') ?: '';
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$expect(!str_contains($gallery, 'name="skin_package"'), 'gallery must not expose a ZIP upload control');
$expect(!str_contains($gallery, "gallery_action\" value=\"upload"), 'gallery must not post an upload action');
$expect(!str_contains($gallery, 'accept_skin_code'), 'gallery must not offer executable-code upload consent');
$expect(!str_contains($gallery, 'activate_uploaded_skin'), 'gallery must not offer uploaded-skin activation');
$expect(str_contains($gallery, 'skin_registry_fetch('), 'gallery must retain registry-backed skin installation');
$expect(str_contains($packager, 'function sc_extract_one_skin('), 'Skin Packager must support fetching one directory');
$expect(str_contains($packager, 'name="fetch_one_skin"'), 'Skin Packager must expose the one-skin fetch action');
$expect(str_contains($packager, "['master', 'dev']"), 'one-skin fetch must offer stable and dev branches');
$expect(!str_contains($updater, "setting_key='update_public_key'"), 'skin updates must not depend on a nonexistent database key');
$expect(substr_count($updater, "defined('SNAPSMACK_RELEASE_PUBKEY') ? SNAPSMACK_RELEASE_PUBKEY : ''") >= 2,
    'manual and automatic skin updates must use the protected installed release key');
$expect(!str_contains($updaterCore, '// Brand new skin not installed locally'),
    'uninstalled gallery skins must not become system update notifications');
$expect(!str_contains($updater, 'available in the Skin Gallery'),
    'system updates must not advertise newly published skins');
$expect(!str_contains($dashboard, 'new skin" .'),
    'dashboard warning must not count newly published skins');
$expect(str_contains($updaterCore, 'isset($local[$slug]) &&'),
    'skin update checks must be limited to skins already installed locally');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "PASS: Registry-only skin gallery regression suite\n";
// ===== SNAPSMACK EOF =====
