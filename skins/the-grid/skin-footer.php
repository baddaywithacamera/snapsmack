<?php
/**
 * SNAPSMACK - The Grid Skin Footer
 * Alpha v0.7.3
 *
 * Renders the minimal site footer, loads required JS engines from the
 * manifest, and includes core/footer.php to close </body></html>.
 *
 * $settings is available from the calling template.
 */
?>

<footer class="tg-footer">
    <div class="tg-wrap">
        <?php
        $site_name = htmlspecialchars($settings['site_name'] ?? 'SnapSmack');
        $footer_text = htmlspecialchars($settings['footer_text'] ?? '');
        ?>
        <?php if ($footer_text): ?>
            <p><?php echo $footer_text; ?></p>
        <?php else: ?>
            <p>&copy; <?php echo date('Y'); ?> <?php echo $site_name; ?></p>
        <?php endif; ?>
    </div>
</footer>

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
                if (!empty($script['css'])) {
                    echo '<link rel="stylesheet" href="' . BASE_URL . $script['css'] . '?v=' . time() . '">' . "\n";
                }
                echo '<script src="' . BASE_URL . $script['path'] . '?v=' . time() . '"></script>' . "\n";
            }
        }
    }
}

// ── Core footer (closes </body></html>) ────────────────────────────────────
include_once(dirname(__DIR__, 2) . '/core/footer.php');
