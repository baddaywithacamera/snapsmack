<?php
/**
 * SNAPSMACK - Footer for the kiosk skin
 * Alpha v0.7.3
 *
 * Injects skin-specific JavaScript libraries and closes the document.
 */

// Get the requested scripts from the manifest
$requested = $skin_manifest['require_scripts'] ?? [];

// Cross-reference with core inventory and inject required scripts
if (!empty($requested) && isset($inventory['scripts'])) {
    echo "\n\n";
    foreach ($requested as $handle) {
        if (isset($inventory['scripts'][$handle])) {
            $script = $inventory['scripts'][$handle];
            echo '<script src="' . BASE_URL . $script['path'] . '"></script>' . "\n";
        }
    }
}

// Include core footer to close the document
include_once(dirname(__DIR__, 2) . '/core/footer.php');
?>