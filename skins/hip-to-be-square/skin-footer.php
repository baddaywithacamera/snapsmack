<?php
/**
 * SNAPSMACK - Skin footer for Hip to be Square
 * v1.0
 *
 * Loads required JavaScript engines from the manifest, then renders
 * the core footer (copyright bar, injection scripts, etc.).
 */

// Load requested engines from manifest
$skin_manifest = include __DIR__ . '/manifest.php';
$requested = $skin_manifest['require_scripts'] ?? [];

if (!empty($requested)) {
    $inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
    if (isset($inventory['scripts'])) {
        foreach ($requested as $handle) {
            if (isset($inventory['scripts'][$handle])) {
                $script = $inventory['scripts'][$handle];
                echo '<script src="' . BASE_URL . $script['path'] . '?v=' . SNAPSMACK_VERSION_SHORT . '"></script>' . "\n";
            }
        }
    }
}

// Include core footer to close the document
include_once(dirname(__DIR__, 2) . '/core/footer.php');
?>
<?php // EOF
