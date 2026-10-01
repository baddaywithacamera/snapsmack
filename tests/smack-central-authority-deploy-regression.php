<?php
/** Ensure Smack Central self-updates deploy the fail-closed skin authority. */
$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/smack-central/sc-update.php');

foreach ([
    "'core/skin-security-policy.php'",
    "'core/manifest-inventory.php'",
    "'assets/ASSET-INVENTORY.json'",
    '$authority_root = dirname(__DIR__)',
    'Trusted skin authority file missing from release',
    'Trusted skin authority deployed (policy + inventories)',
] as $needle) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException("Smack Central updater does not deploy trusted skin authority: {$needle}");
    }
}

echo "smack-central-authority-deploy-regression: ok\n";
// ===== SNAPSMACK EOF =====
