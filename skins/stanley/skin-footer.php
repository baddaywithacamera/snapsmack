<?php
/**
 * SNAPSMACK - Skin footer for the STANLEY skin
 * v1.0.0
 *
 * Closes the content column, renders the Kubrick sidebar (About / Recent Posts /
 * Pages), closes the page frame, then loads manifest-required scripts, the shared
 * slot-bar footer (core/footer.php), and the shared public engines including the
 * REQUIRED Thomas the Bear easter egg (core/footer-scripts.php).
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$stanley_show_sidebar = ($settings['show_sidebar'] ?? '1') === '1';
?>
        </div><!-- /#stanley-content -->
<?php if ($stanley_show_sidebar):
    try {
        $stanley_recent = $pdo->query("SELECT title, slug FROM snap_posts WHERE post_type = 'longform' AND status = 'published' ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $stanley_recent = []; }
    try {
        $stanley_side_pages = $pdo->query("SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $stanley_side_pages = []; }
    $stanley_about = trim($settings['site_tagline'] ?? '');
?>
        <aside id="stanley-sidebar">
            <?php if ($stanley_about !== ''): ?>
            <section class="widget"><h3>About</h3><p><?php echo htmlspecialchars($stanley_about); ?></p></section>
            <?php endif; ?>
            <?php if (!empty($stanley_recent)): ?>
            <section class="widget"><h3>Recent Posts</h3>
                <ul>
                <?php foreach ($stanley_recent as $rp): ?>
                    <li><a href="<?php echo BASE_URL . '?post=' . rawurlencode($rp['slug']); ?>"><?php echo htmlspecialchars($rp['title']); ?></a></li>
                <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>
            <?php if (!empty($stanley_side_pages)): ?>
            <section class="widget"><h3>Pages</h3>
                <ul>
                <?php foreach ($stanley_side_pages as $sp): ?>
                    <li><a href="<?php echo BASE_URL . 'page.php?slug=' . rawurlencode($sp['slug']); ?>"><?php echo htmlspecialchars($sp['title']); ?></a></li>
                <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>
        </aside><!-- /#stanley-sidebar -->
<?php endif; ?>
    </div><!-- /#stanley-wrapper -->
</div><!-- /#stanley-page -->

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
