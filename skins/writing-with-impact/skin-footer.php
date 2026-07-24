<?php
/**
 * SNAPSMACK - Skin footer for the WRITING WITH IMPACT skin
 * v1.0.0
 *
 * Closes the content column + page frame, then loads manifest-required scripts,
 * the shared slot-bar footer (core/footer.php), and the shared public engines
 * including the REQUIRED Thomas the Bear easter egg (core/footer-scripts.php).
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
?>
    </div><!-- /#wwi-content -->
    <div class="wwi-tearoff" aria-hidden="true"></div>
</div><!-- /#wwi-page -->

<?php
// Manifest-required scripts
$skin_manifest = function_exists('load_skin_manifest')
    ? load_skin_manifest(basename(__DIR__))
    : include __DIR__ . '/manifest.php';
$requested     = $skin_manifest['require_scripts'] ?? [];
if (!empty($requested)) {
    $inventory = include dirname(__DIR__, 2) . '/core/manifest-inventory.php';
    if (isset($inventory['scripts'])) {
        foreach ($requested as $handle) {
            if (isset($inventory['scripts'][$handle])) {
                echo '<script src="' . BASE_URL . $inventory['scripts'][$handle]['path'] . '?v=' . SNAPSMACK_VERSION_SHORT . '"></script>' . "\n";
            }
        }
    }
}

// Shared slot-bar footer (COPYRIGHT / EMAIL / THEME / POWERED BY / PRIVACY / RSS).
include_once dirname(__DIR__, 2) . '/core/footer.php';

// Shared public engines: consent banner, comms/HUD, the REQUIRED Thomas the Bear
// easter egg, social dock, sticky header, SCROLL TIME tracker.
include dirname(__DIR__, 2) . '/core/footer-scripts.php';
?>
<?php // ===== SNAPSMACK EOF =====
