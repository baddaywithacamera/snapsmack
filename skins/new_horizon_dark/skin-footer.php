<?php
/**
 * SNAPSMACK Skin Footer: New Horizon Dark
 * Version: 5.0 - Self-Contained Handshake
 * -------------------------------------------------------------------------
 * 1. Handshake: Cross-references manifest require_scripts with inventory.
 * 2. Calls core/footer.php for the visual footer bar.
 * NOTE: Thomas & Comms engine loaded globally via core/footer-scripts.php.
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
                    echo '<link rel="stylesheet" href="' . BASE_URL . $script['css'] . '?v=' . time() . '">' . "\n";
                }
                echo '<script src="' . BASE_URL . $script['path'] . '?v=' . time() . '"></script>' . "\n";
            }
        }
    }
}

// 2. CORE FOOTER (Visual bar only — copyright, email, branding)
include_once(dirname(__DIR__, 2) . '/core/footer.php');
?>
