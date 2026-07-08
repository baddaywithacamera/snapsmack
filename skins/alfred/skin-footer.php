<?php
/**
 * SNAPSMACK - Skin footer for the Alfred skin
 * v1.0.0
 *
 * Renders the .credits bar, loads manifest-required scripts,
 * includes core footer to close the document.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$site_display_name = $settings['site_name'] ?? 'SNAPSMACK';
?>
<footer class="credits" role="contentinfo">
    <div class="section-inner">
        <p><a href="<?php echo BASE_URL; ?>"><?php echo htmlspecialchars(strtoupper($site_display_name)); ?></a></p>
        <p>Powered by <a href="https://snapsmack.ca" target="_blank" rel="noopener noreferrer">SnapSmack</a></p>
    </div>
</footer>

<?php
// Load manifest-required scripts
$skin_manifest = include __DIR__ . '/manifest.php';
$requested     = $skin_manifest['require_scripts'] ?? [];

if (!empty($requested)) {
    $inventory = include dirname(__DIR__, 2) . '/core/manifest-inventory.php';
    if (isset($inventory['scripts'])) {
        foreach ($requested as $handle) {
            if (isset($inventory['scripts'][$handle])) {
                $script = $inventory['scripts'][$handle];
                echo '<script src="' . BASE_URL . $script['path'] . '?v=' . SNAPSMACK_VERSION_SHORT . '"></script>' . "\n";
            }
        }
    }
}

include_once dirname(__DIR__, 2) . '/core/footer.php';

// Global public engines: consent banner, comms/HUD, the Thomas the Bear easter
// egg (REQUIRED in every skin fork), the Social Profile Dock (Alfred's chosen
// social surface), sticky header, and the SCROLL TIME tracker. Mainline
// controllers include this on every public page; Alfred renders its own
// document via preload.php, so it must pull the shared engine loader itself.
include dirname(__DIR__, 2) . '/core/footer-scripts.php';
?>
<?php // ===== SNAPSMACK EOF =====
