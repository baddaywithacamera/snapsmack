<?php
/**
 * SNAPSMACK - Footer for the pocket-rocket skin
 * Alpha v0.7.3
 *
 * Loads manifest-requested scripts and includes the core footer.
 */

// Load requested scripts from manifest
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

// Load skin-specific drawer toggle scripts
include_once __DIR__ . '/skin-footer-scripts.php';

// Include core footer to close the document
include_once(dirname(__DIR__, 2) . '/core/footer.php');
?>
