<?php
/* SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. */
/** SNAPSMACK — GLIDE footer and requested engine loader. */
$skin_manifest = load_skin_manifest(basename(__DIR__));
$requested = $skin_manifest['require_scripts'] ?? [];
if ($requested) {
    $inventory = include dirname(__DIR__, 2) . '/core/manifest-inventory.php';
    foreach ($requested as $handle) {
        if (!empty($inventory['scripts'][$handle]['path'])) {
            echo '<script src="' . BASE_URL . $inventory['scripts'][$handle]['path']
                . '?v=' . SNAPSMACK_VERSION_SHORT . '"></script>' . "\n";
        }
    }
}
include_once dirname(__DIR__, 2) . '/core/footer.php';
?>
<?php // ===== SNAPSMACK EOF =====
