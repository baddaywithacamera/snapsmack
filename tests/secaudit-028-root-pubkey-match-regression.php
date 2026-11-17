<?php
/**
 * SECAUDIT 028 regression — the ROOT trust anchor must be ONE key.
 *
 * The release key-rotation channel only works if the hub and every install
 * verify a rotation announcement against the SAME root public key. The hub
 * checks it in smack-central/sc-release.php (the Key Rotation panel, before it
 * writes key-rotation.json); every install checks it in core/updater.php
 * (SNAPSMACK_ROOT_PUBKEY, in updater_fetch_key_rotation). If those two values
 * ever differ, a rotation the hub accepts is rejected by the whole fleet, and a
 * release signed with the new key is then refused on every site.
 *
 * Measured 2026-11-10: they DIFFERED (hub d4c4256..., installs 3287b9b...),
 * both since commit 60afaf74 (0.7.159). This guard fails until they are one
 * value — the public key that the offline root private key actually derives
 * (use tools/_build/derive-root-pubkey.php to find it).
 *
 * Hub-only files are absent on a spoke, so this check SKIPS (passes) where
 * smack-central/sc-release.php is not present and only enforces in the repo/hub.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
$fails = 0;
function sa028_rk(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

$updater_path  = $root . '/core/updater.php';
$release_path  = $root . '/smack-central/sc-release.php';

if (!is_file($release_path)) {
    echo "SKIP smack-central/sc-release.php not present (spoke install); root-key match enforced in the repo/hub only.\n";
    echo "SECAUDIT 028 root-pubkey match: SKIPPED\n";
    exit(0);
}

$updater = (string) @file_get_contents($updater_path);
$release = (string) @file_get_contents($release_path);

// Install-side anchor: define('SNAPSMACK_ROOT_PUBKEY', '<64 hex>');
$install_pub = null;
if (preg_match("/SNAPSMACK_ROOT_PUBKEY'\s*,\s*'([0-9a-fA-F]{64})'/", $updater, $m)) {
    $install_pub = strtolower($m[1]);
}
// Hub-side anchor: $root_pub_hex = '<64 hex>';
$hub_pub = null;
if (preg_match("/\\\$root_pub_hex\s*=\s*'([0-9a-fA-F]{64})'/", $release, $m)) {
    $hub_pub = strtolower($m[1]);
}

sa028_rk('install-side SNAPSMACK_ROOT_PUBKEY is present in core/updater.php', $install_pub !== null);
sa028_rk('hub-side root_pub_hex is present in smack-central/sc-release.php', $hub_pub !== null);
sa028_rk(
    'hub and install root public keys are the SAME value'
        . ($install_pub !== null && $hub_pub !== null && $install_pub !== $hub_pub
            ? " (install={$install_pub} hub={$hub_pub})" : ''),
    $install_pub !== null && $hub_pub !== null && hash_equals($install_pub, $hub_pub)
);

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): the root trust anchor is not one key — rotation would break the fleet.\n");
    exit(1);
}
echo "SECAUDIT 028 root-pubkey match: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
