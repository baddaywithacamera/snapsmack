<?php
/**
 * SNAPSMACK - Footer for the 50-shades-of-noah-grey skin
 * Alpha v0.7.9c
 *
 * Injects skin-specific JavaScript libraries and closes the document.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// Load requested engines from manifest
$skin_manifest = load_skin_manifest(basename(__DIR__));
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
<?php // ===== SNAPSMACK EOF =====
