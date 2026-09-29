<?php
declare(strict_types=1);

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$registry = file_get_contents(__DIR__ . '/../core/skin-registry.php');
$admin = file_get_contents(__DIR__ . '/../smack-skin.php');
$smackback = file_get_contents(__DIR__ . '/../core/smackback.php');
$packagerA = file_get_contents(__DIR__ . '/../tools/_build/package-skin.php');
$packagerB = file_get_contents(__DIR__ . '/../tools/_build/build-skin-package.php');
$ohsnap = file_get_contents(__DIR__ . '/../core/ohsnap-api.php');

$expect(str_contains($registry, 'function skin_registry_verify_installed_provenance('),
    'Registry must expose a fail-closed installed-package provenance check.');
$expect(substr_count($admin, 'skin_registry_verify_installed_provenance(') >= 2,
    'Both gallery activation and settings-save activation must verify provenance.');
$expect(strpos($registry, 'smackback_init_skin_manifest($tmp_zip, $slug)')
    > strpos($registry, "hash_equals(\$expected_manifest_hash"),
    'Installer must record trust only after final live-package validation.');
$expect(str_contains($registry, 'if (!smackback_init_skin_manifest($tmp_zip, $slug))'),
    'Installer must fail and roll back when provenance cannot be recorded.');
$expect(str_contains($smackback, 'if ($skin_id === null)')
    && str_contains($smackback, "DELETE FROM snap_file_manifest WHERE skin_id = ?"),
    'Core refresh and per-skin provenance must use separate manifest lifecycles.');
$expect(!str_contains($packagerA, "in_array(\$ext, ['php', 'css', 'js']"),
    'Primary skin packager must hash every packaged file.');
$expect(!str_contains($packagerB, "in_array(\$ext, ['php', 'css', 'js']"),
    'Alternate skin packager must hash every packaged file.');
$expect(!str_contains($registry, 'function skin_registry_install_upload('),
    'Direct skin ZIP upload capability must remain absent.');
$expect(str_contains($ohsnap, "if (\$resource === 'skin' && \$sub === 'push')")
    && str_contains($ohsnap, "', 410);"),
    'OH SNAP skin push must remain unconditionally gone.');
$expect(str_contains($registry, 'sodium_crypto_sign_verify_detached')
    && str_contains($registry, 'Skin install refused: package signature or public key is missing.'),
    'Registry installer must have no unsigned fallback.');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}
echo "Skin install provenance regression checks passed.\n";
