<?php
/**
 * SNAPSMACK - Skin footer for 52 Card Pickup
 * v1.0
 *
 * Loads required JavaScript engines from the manifest, then renders
 * the core footer (copyright bar, injection scripts, etc.).
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// Ghost-chrome footer — a fixed bar that appears only on bottom-edge hover
// (the 52 PICKUP layer toggles .om-chrome-show via the [data-ghost-footer] hook).
// Rendered only on the tabletop landing, where $om_ghost_chrome is set.
if (!empty($om_ghost_chrome)):
?>
<footer class="pickup-ghost-footer" data-ghost-footer>
    <span class="pickup-ghost-credit"><?php echo htmlspecialchars($site_name); ?></span>
    <a href="<?php echo BASE_URL; ?>archive">ARCHIVE</a>
</footer>
<?php
endif;

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
<?php // ===== SNAPSMACK EOF =====
