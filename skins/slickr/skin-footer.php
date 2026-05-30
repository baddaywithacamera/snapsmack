<?php
/**
 * SNAPSMACK - Slickr Skin Footer Template
 * Spec v0.1 — Flickr visual idiom clone for archive migrations.
 *
 * @author Sean McCormick
 */

/**
 * SNAPSMACK_HEADER_PROTECTION
 * <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// Verify script inventory array mapping is set before processing
if (!isset($manifest_inventory) && file_exists(dirname(__DIR__, 2) . '/core/manifest-inventory.php')) {
    $manifest_inventory = include dirname(__DIR__, 2) . '/core/manifest-inventory.php';
}

if (!empty($skin_manifest['require_scripts']) && is_array($skin_manifest['require_scripts'])) {
    foreach ($skin_manifest['require_scripts'] as $script_handle) {
        if (isset($manifest_inventory['scripts'][$script_handle])) {
            $script_src = BASE_URL . ltrim($manifest_inventory['scripts'][$script_handle]['file'], '/');
            echo '<script src="' . $script_src . '"></script>' . "\n";
        }
    }
}

// Relinquish execution flow back to the native core footer script
include dirname(__DIR__, 2) . '/core/footer.php';
// ===== SNAPSMACK EOF =====