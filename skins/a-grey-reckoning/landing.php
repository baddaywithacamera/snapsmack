<?php
/**
 * SNAPSMACK - Landing page for the grey-expectations skin
 * Alpha v0.7.3a
 *
 * Two layout modes:
 *   split — Navigation menu stacked on the left, large photo on the right
 *   hero  — Full-width hero photo with overlay navigation
 *
 * Inspired by the hub page of noahgrey.com circa 2006: a single strong
 * photograph with a quiet navigation menu alongside it.
 *
 * Variables available from index.php: $pdo, $settings, $img, $active_skin, $site_name
 */

// Fetch the most recent published image for the hero
$now_local = date('Y-m-d H:i:s');
$hero_stmt = $pdo->prepare("
    SELECT id, img_title, img_slug, img_file
    FROM snap_images
    WHERE img_status = 'published' AND img_date <= ?
    ORDER BY img_date DESC
    LIMIT 1
");
$hero_stmt->execute([$now_local]);
$hero_image = $hero_stmt->fetch(PDO::FETCH_ASSOC);

// Homepage mode
$homepage_mode    = $settings['homepage_mode'] ?? 'latest_post';
$homepage_page_id = (int)($settings['homepage_page_id'] ?? 0);

// Dynamic pages for the nav
try {
    if ($homepage_mode === 'static_page' && $homepage_page_id > 0) {
        $pages_stmt = $pdo->prepare("SELECT title, slug FROM snap_pages WHERE is_active = 1 AND id != ? ORDER BY menu_order ASC");
        $pages_stmt->execute([$homepage_page_id]);
        $dynamic_pages = $pages_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $pages_stmt = $pdo->query("SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC");
        $dynamic_pages = $pages_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $dynamic_pages = [];
}

// Layout mode
$landing_layout = $settings['htbs_landing_layout'] ?? 'split';
$site_display_name = $site_name ?? 'SNAPSMACK';

// Hero image URL
$hero_url = '';
$hero_link = '';
if ($hero_image) {
    $hero_url = BASE_URL . ltrim($hero_image['img_file'], '/');
    $hero_link = BASE_URL . htmlspecialchars($hero_image['img_slug']);
}
?>

<div id="scroll-stage">

<?php if ($landing_layout === 'hero'): ?>

    <!-- HERO LAYOUT -->
    <div class="ge-landing-hero">
        <?php if ($hero_url): ?>
            <a href="<?php echo $hero_link; ?>">
                <img src="<?php echo $hero_url; ?>"
                     alt="<?php echo htmlspecialchars($hero_image['img_title'] ?? ''); ?>">
            </a>
        <?php endif; ?>

        <div class="ge-landing-hero-nav">
            <div class="ge-nav">
                <a href="<?php echo BASE_URL; ?>archive.php">ARCHIVE</a>

                <?php if (($settings['blogroll_enabled'] ?? '1') == '1'): ?>
                    <span class="sep">&middot;</span>
                    <a href="<?php echo BASE_URL; ?>blogroll.php">BLOGROLL</a>
                <?php endif; ?>

                <?php foreach ($dynamic_pages as $page): ?>
                    <span class="sep">&middot;</span>
                    <a href="<?php echo BASE_URL . 'page.php?slug=' . htmlspecialchars($page['slug']); ?>">
                        <?php echo strtoupper(htmlspecialchars($page['title'])); ?>
                    </a>
                <?php endforeach; ?>

                <span class="sep">&middot;</span>
                <a href="<?php echo BASE_URL; ?>feed" title="RSS Feed">RSS</a>
            </div>
        </div>
    </div>

<?php else: ?>

    <!-- SPLIT LAYOUT (default) -->
    <div class="ge-landing-split">
        <div class="ge-landing-menu">
            <div class="ge-landing-logo">
                <a href="<?php echo BASE_URL; ?>"><?php echo htmlspecialchars($site_display_name); ?></a>
            </div>

            <?php if ($homepage_mode === 'static_page'): ?>
            <div class="ge-landing-nav-item">
                <a href="<?php echo BASE_URL; ?>blog.php">BLOG</a>
                <div class="ge-landing-nav-desc">Latest entries</div>
            </div>
            <?php endif; ?>

            <div class="ge-landing-nav-item">
                <a href="<?php echo BASE_URL; ?>archive.php">ARCHIVE</a>
                <div class="ge-landing-nav-desc">Browse the complete collection</div>
            </div>

            <?php if (($settings['show_wall_link'] ?? '1') === '1'): ?>
            <div class="ge-landing-nav-item">
                <a href="<?php echo BASE_URL; ?>gallery-wall.php">GALLERY</a>
                <div class="ge-landing-nav-desc">Floating gallery view</div>
            </div>
            <?php endif; ?>

            <?php if (($settings['blogroll_enabled'] ?? '1') == '1'): ?>
            <div class="ge-landing-nav-item">
                <a href="<?php echo BASE_URL; ?>blogroll.php">BLOGROLL</a>
                <div class="ge-landing-nav-desc">Friends and fellow photographers</div>
            </div>
            <?php endif; ?>

            <?php foreach ($dynamic_pages as $page): ?>
            <div class="ge-landing-nav-item">
                <a href="<?php echo BASE_URL . 'page.php?slug=' . htmlspecialchars($page['slug']); ?>">
                    <?php echo strtoupper(htmlspecialchars($page['title'])); ?>
                </a>
            </div>
            <?php endforeach; ?>

            <div class="ge-landing-nav-item" style="margin-top: auto; padding-top: 40px;">
                <a href="<?php echo BASE_URL; ?>feed" style="font-size: 10px; opacity: 0.5;">RSS</a>
            </div>
        </div>

        <div class="ge-landing-photo">
            <?php if ($hero_url): ?>
                <a href="<?php echo $hero_link; ?>">
                    <img src="<?php echo $hero_url; ?>"
                         alt="<?php echo htmlspecialchars($hero_image['img_title'] ?? ''); ?>">
                </a>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

    <?php include('skin-footer.php'); ?>
</div>
