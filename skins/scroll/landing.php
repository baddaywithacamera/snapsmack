<?php
/**
 * SNAPSMACK — SCROLL landing page.
 * In-house native-aspect masonry (feed.js) + aspect thumbnails.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$now_local = date('Y-m-d H:i:s');
$scroll_per_page = max(12, min(60, (int)($settings['scroll_page_size'] ?? 36)));
$scroll_page = max(1, (int)($_GET['scroll_page'] ?? 1));
$scroll_offset = ($scroll_page - 1) * $scroll_per_page;
$scroll_count_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM snap_images
     WHERE img_status = 'published' AND img_date <= ?"
);
$scroll_count_stmt->execute([$now_local]);
$scroll_total = (int)$scroll_count_stmt->fetchColumn();
$grid_stmt = $pdo->prepare(
    "SELECT id, img_title, img_slug, img_file, img_thumb_aspect,
            img_width, img_height, img_orientation
     FROM snap_images
     WHERE img_status = 'published' AND img_date <= ?
     ORDER BY sort_order ASC, id DESC
     LIMIT ? OFFSET ?"
);
$grid_stmt->bindValue(1, $now_local);
$grid_stmt->bindValue(2, $scroll_per_page, PDO::PARAM_INT);
$grid_stmt->bindValue(3, $scroll_offset, PDO::PARAM_INT);
$grid_stmt->execute();
$images = $grid_stmt->fetchAll(PDO::FETCH_ASSOC);
$scroll_has_more = ($scroll_offset + count($images)) < $scroll_total;

$masthead_raw = trim((string)($settings['scroll_masthead_lines'] ?? 'USED CAR|PARTS'));
$masthead_lines = array_values(array_filter(array_map('trim', explode('|', $masthead_raw)), 'strlen'));
if (!$masthead_lines) $masthead_lines = [$settings['site_name'] ?? 'SnapSmack'];
$photographer_name = trim((string)($settings['photographer_name'] ?? ($settings['site_name'] ?? '')));
$byline_prefix = trim((string)($settings['scroll_byline_prefix'] ?? 'PHOTOGRAPHY BY'));
?>
<div class="scroll-landing">
    <section class="scroll-profile" aria-labelledby="scroll-site-title">
        <div class="scroll-photographer">
            <?php if ($byline_prefix !== ''): ?>
                <span class="scroll-byline-prefix"><?php echo htmlspecialchars($byline_prefix); ?></span>
            <?php endif; ?>
            <span class="scroll-byline-name"><?php echo htmlspecialchars($photographer_name); ?></span>
        </div>
        <h1 class="scroll-masthead" id="scroll-site-title">
            <?php foreach ($masthead_lines as $line): ?>
                <span><?php echo htmlspecialchars($line); ?></span>
            <?php endforeach; ?>
        </h1>

        <nav class="scroll-sticky-nav ss-grid-sticky-nav ss-grid-nav-inline-then-sticky"
             aria-label="Site navigation"
             data-grid-nav-observer="scroll-profile">
            <div class="ss-grid-nav-identity">
                <a class="ss-grid-nav-name" href="<?php echo BASE_URL; ?>"><?php echo htmlspecialchars($photographer_name); ?></a>
            </div>
            <div class="ss-grid-nav-links">
                <a class="ss-grid-nav-link" href="<?php echo BASE_URL; ?>" title="Home">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                    <span class="ss-grid-nav-label">Home</span>
                </a>
                <a class="ss-grid-nav-link" href="<?php echo BASE_URL; ?>albums.php" title="Albums">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v13H4zM3 4h18v3H3zm6 7h6" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                    <span class="ss-grid-nav-label">Albums</span>
                </a>
                <details class="scroll-nav-search">
                    <summary class="ss-grid-nav-link" title="Search">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m15.5 15.5 5 5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                        <span class="ss-grid-nav-label">Search</span>
                    </summary>
                    <form class="scroll-nav-search-panel" method="get" action="<?php echo BASE_URL; ?>archive.php">
                        <label class="ss-grid-nav-label" for="scroll-nav-search-input">Search photographs</label>
                        <input id="scroll-nav-search-input"
                               type="search"
                               name="q"
                               placeholder="<?php echo htmlspecialchars($settings['search_placeholder'] ?? 'Search or #tag…'); ?>"
                               autocomplete="off">
                        <button type="submit">GO</button>
                    </form>
                </details>
                <a class="ss-grid-nav-link" href="<?php echo BASE_URL; ?>archive.php#smack-archive-filter-btn" title="Filter">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18l-7 8v5l-4 2v-7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                    <span class="ss-grid-nav-label">Filter</span>
                </a>
            </div>
            <div class="ss-grid-nav-actions">
                <?php
                $social_dock_inline = true;
                include dirname(__DIR__, 2) . '/core/social-dock.php';
                unset($social_dock_inline);
                ?>
            </div>
        </nav>
    </section>

    <div class="scroll-browse-tools" aria-label="Browse photographs">
        <a class="scroll-browse-link" href="<?php echo BASE_URL; ?>">Show all</a>
        <a class="scroll-browse-link" href="<?php echo BASE_URL; ?>archive.php#smack-archive-filter-btn">Filter</a>
    </div>

    <main class="scroll-wall">
        <div id="scroll-feed-grid" class="scroll-feature-grid">
            <?php foreach ($images as $image):
                $stored_iw = (int)($image['img_width'] ?? 0);
                $stored_ih = (int)($image['img_height'] ?? 0);
                $has_dimensions = $stored_iw > 0 && $stored_ih > 0;
                $thumb = trim((string)($image['img_thumb_aspect'] ?? ''));
                $src = BASE_URL . ltrim($thumb !== '' ? $thumb : ($image['img_file'] ?? ''), '/');
            ?>
            <a class="scroll-feature-item"
               href="<?php echo BASE_URL . htmlspecialchars($image['img_slug']); ?>"
               aria-label="<?php echo htmlspecialchars($image['img_title'] ?? 'View photograph'); ?>">
                <img src="<?php echo htmlspecialchars($src); ?>"
                     <?php if ($has_dimensions): ?>width="<?php echo $stored_iw; ?>" height="<?php echo $stored_ih; ?>" <?php endif; ?>alt="<?php echo htmlspecialchars($image['img_title'] ?? ''); ?>"
                     loading="lazy">
                <span class="scroll-item-title"><?php echo htmlspecialchars($image['img_title'] ?? ''); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php if (!$images): ?>
            <p class="scroll-empty">No parts in inventory yet.</p>
        <?php endif; ?>
    </main>
    <div id="scroll-feed-sentinel"
         data-next-page="<?php echo $scroll_has_more ? $scroll_page + 1 : 0; ?>"
         aria-hidden="true"></div>
</div>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
