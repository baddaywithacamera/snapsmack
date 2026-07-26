<?php
/**
 * SNAPSMACK - Skin footer for the WRITING WITH IMPACT skin
 * v1.1.0
 *
 * Closes the content column + page frame, then loads manifest-required scripts,
 * the shared slot-bar footer (core/footer.php), and the shared public engines
 * including the REQUIRED Thomas the Bear easter egg (core/footer-scripts.php).
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
// STANLEY geometry: the navigation, recent writing and about copy occupy the
// right column on wide screens and fall below the writing on small screens.
$wwi_show_sidebar = ($settings['show_sidebar'] ?? '1') === '1';
?>
        </div><!-- /#wwi-content -->
<?php if ($wwi_show_sidebar):
    try {
        $wwi_recent = $pdo->query("SELECT title, slug FROM snap_posts WHERE post_type = 'longform' AND status = 'published' ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $wwi_recent = []; }
    $wwi_about = trim($settings['site_tagline'] ?? '');
?>
        <aside id="wwi-sidebar" aria-label="Sidebar">
            <nav class="widget wwi-pages" aria-label="Site navigation">
                <h2>PAGES</h2>
                <ul>
                <?php if ($_use_json) { _wwi_nav_items($_nav_items, $pdo); } else { foreach (_wwi_default_nav($settings, $wwi_pages) as $it): ?>
                    <li><a href="<?php echo htmlspecialchars($it['url']); ?>"><?php echo htmlspecialchars($it['label']); ?></a></li>
                <?php endforeach; } ?>
                </ul>
            </nav>
            <?php if ($wwi_about !== ''): ?>
            <section class="widget"><h2>ABOUT</h2><p><?php echo htmlspecialchars($wwi_about); ?></p></section>
            <?php endif; ?>
            <?php if (!empty($wwi_recent)): ?>
            <section class="widget"><h2>ARCHIVES</h2>
                <ul>
                <?php foreach ($wwi_recent as $rp): ?>
                    <li><a href="<?php echo BASE_URL . '?post=' . rawurlencode($rp['slug']); ?>"><?php echo htmlspecialchars($rp['title']); ?></a></li>
                <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>
        </aside>
<?php endif; ?>
    </div><!-- /#wwi-wrapper -->
    <footer id="wwi-skin-footer">
        <p>WRITING WITH IMPACT — STANLEY'S BONES, RUN THROUGH A DOT-MATRIX PRINT HEAD.</p>
    </footer>
    <div class="wwi-tearoff" aria-hidden="true"></div>
</div><!-- /#wwi-page -->

<?php
// Manifest-required scripts
$skin_manifest = load_skin_manifest(basename(__DIR__));
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
