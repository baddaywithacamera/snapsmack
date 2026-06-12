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
<!-- Post modal overlay (populated by tg-modal.js). Rendered in the SHARED
     footer so every Grid page that includes skin-footer.php (landing, archive,
     hashtag, skin-page, and the solo post view) has the container the modal
     script requires. Modal fragments skip skin-footer (layout.php modal-mode
     guard), so there is never a duplicate inside the fetched fragment. -->
<div id="tg-modal-overlay" class="tg-modal-overlay" hidden
     data-grid-url="<?php echo htmlspecialchars(BASE_URL); ?>"<?php echo !empty($tg_autoopen) ? ' data-autoopen="1"' : ''; ?>>
    <div class="tg-modal-backdrop"></div>
    <div id="tg-modal-frame" class="tg-modal-frame"></div>
</div>
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
