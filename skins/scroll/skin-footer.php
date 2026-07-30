<?php
/**
 * SNAPSMACK — SCROLL footer and requested engine loader.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$skin_manifest = load_skin_manifest(basename(__DIR__));
$requested = $skin_manifest['require_scripts'] ?? [];
if (!empty($requested)) {
    $inventory = include dirname(__DIR__, 2) . '/core/manifest-inventory.php';
    foreach ($requested as $handle) {
        if (!empty($inventory['scripts'][$handle]['path'])) {
            echo '<script src="' . BASE_URL . $inventory['scripts'][$handle]['path']
                . '?v=' . SNAPSMACK_VERSION_SHORT . '"></script>' . "\n";
        }
    }
}
echo '<script src="' . BASE_URL . 'skins/scroll/feed.js?v='
    . SNAPSMACK_VERSION_SHORT . '"></script>' . "\n";
include_once dirname(__DIR__, 2) . '/core/footer.php';
?>
<?php // ===== SNAPSMACK EOF =====
