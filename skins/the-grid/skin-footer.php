<?php
/**
 * SNAPSMACK - The Grid Skin Footer
 * Alpha v0.7.9
 *
 * Renders the minimal site footer, loads required JS engines from the
 * manifest, and includes core/footer.php to close </body></html>.
 *
 * $settings is available from the calling template.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


?>
<?php
// ── Load required JS engines from manifest ─────────────────────────────────
$skin_manifest = include __DIR__ . '/manifest.php';
$requested     = $skin_manifest['require_scripts'] ?? [];

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

// ── tg-modal.js — load directly in case manifest-inventory is stale ────────
echo '<script src="' . BASE_URL . 'skins/the-grid/assets/js/tg-modal.js?v=' . SNAPSMACK_VERSION_SHORT . '" defer></script>' . "\n";

// ── Core footer (closes </body></html>) ────────────────────────────────────
include_once(dirname(__DIR__, 2) . '/core/footer.php');
// ===== SNAPSMACK EOF =====
