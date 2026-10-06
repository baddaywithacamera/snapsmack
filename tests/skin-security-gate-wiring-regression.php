<?php
$root = dirname(__DIR__);
$checks = [
    'tools/_build/package-skin.php' => 'snapsmack_skin_security_gate',
    'tools/_build/build-skin-package.php' => 'snapsmack_skin_security_gate',
    'smack-central/sc-skins.php' => 'snapsmack_skin_security_gate',
    'core/skin-registry.php' => 'snapsmack_skin_security_gate',
    'core/smackback.php' => 'snapsmack_skin_security_gate',
    '.githooks/pre-commit' => 'skin-security-ratchet-regression.php',
    // SECAUDIT 060 finding A: the parity half of the gate must run too.
    '.githooks/pre-commit:parity' => 'skin-parity-ratchet-regression.php',
];
foreach ($checks as $file => $needle) {
    $source = (string)file_get_contents($root . '/' . explode(':', $file)[0]);
    if (!str_contains($source, $needle)) throw new RuntimeException("{$file} does not use the shared skin gate.");
}
$install = (string)file_get_contents($root . '/tools/_build/build-install-package.php');
$release = (string)file_get_contents($root . '/smack-central/sc-release.php');
if (!str_contains($install, "'skins',") || !str_contains($release, "'skins/',")) {
    throw new RuntimeException('Core/install packages must exclude skins.');
}
$recovery = (string)file_get_contents($root . '/core/recovery-engine.php');
if (!str_contains($recovery, 'Manifest-only entries') || !str_contains($recovery, "['bundled'] === false")) {
    throw new RuntimeException('Recovery must not restore inventoried, unbundled skin code.');
}
echo "Skin security gate wiring regression: PASS\n";
