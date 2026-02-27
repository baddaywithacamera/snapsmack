<?php
/**
 * SnapSmack Skin Footer: Mi Casa es Su Picasa
 * Version: 1.0
 * -------------------------------------------------------------------------
 * 1. Handshake: Cross-references manifest require_scripts with inventory.
 * 2. Thomas the Bear: Injects CSS + JS for Ctrl+Shift+Y easter egg.
 * 3. Calls core/footer.php for the visual footer bar.
 * NOTE: Does NOT output </body></html> — the calling controller owns that.
 * -------------------------------------------------------------------------
 */

// 1. HANDSHAKE — Load requested engines from inventory
$skin_manifest = include __DIR__ . '/manifest.php';
$requested = $skin_manifest['require_scripts'] ?? [];

if (!empty($requested)) {
    $inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
    if (isset($inventory['scripts'])) {
        foreach ($requested as $handle) {
            if (isset($inventory['scripts'][$handle])) {
                $script = $inventory['scripts'][$handle];
                if (!empty($script['css'])) {
                    echo '<link rel="stylesheet" href="' . BASE_URL . $script['css'] . '">' . "\n";
                }
                echo '<script src="' . BASE_URL . $script['path'] . '"></script>' . "\n";
            }
        }
    }
}

// 2. THOMAS THE BEAR — Skin-specific easter egg (Ctrl+Shift+Y)
echo '<link rel="stylesheet" href="' . BASE_URL . 'assets/css/ss-engine-thomas.css">' . "\n";
echo '<script src="' . BASE_URL . 'assets/js/ss-engine-thomas.js"></script>' . "\n";

// 3. CORE FOOTER (Visual bar only — copyright, email, branding)
include_once(dirname(__DIR__, 2) . '/core/footer.php');
?>
