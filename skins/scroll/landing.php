<?php
/**
 * SNAPSMACK — SCROLL landing page.
 * 2D CSS-Grid MOSAIC ("bento") wall: portraits stand tall, landscapes run wide,
 * panos wider, squares square — dense-packed by CSS Grid with no gaps and no
 * skin-local JS. PHP only tags each photo by shape from its stored dimensions.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$now_local = date('Y-m-d H:i:s');
$grid_stmt = $pdo->prepare(
    "SELECT id, img_title, img_slug, img_file, img_thumb_aspect,
            img_width, img_height, img_orientation
     FROM snap_images
     WHERE img_status = 'published' AND img_date <= ?
     ORDER BY sort_order ASC, id DESC"
);
$grid_stmt->execute([$now_local]);
$images = $grid_stmt->fetchAll(PDO::FETCH_ASSOC);

$masthead_raw = trim((string)($settings['scroll_masthead_lines'] ?? 'USED CAR|PARTS'));
$masthead_lines = array_values(array_filter(array_map('trim', explode('|', $masthead_raw)), 'strlen'));
if (!$masthead_lines) $masthead_lines = [$settings['site_name'] ?? 'SnapSmack'];
$photographer_name = trim((string)($settings['photographer_name'] ?? ($settings['site_name'] ?? '')));
$byline_prefix = trim((string)($settings['scroll_byline_prefix'] ?? 'PHOTOGRAPHY BY'));
// Mosaic sizing (--mosaic-col / --mosaic-row / --mosaic-gap) is driven by the
// SCROLL config sliders via compiled custom_css_public; style.css carries the
// 230/230/6 fallbacks, so the wall is unchanged until those sliders are moved.
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
        <div class="scroll-mosaic">
            <?php if (!empty($images)): ?>
                <?php foreach ($images as $img):
                    $iw = (int)($img['img_width']  ?? 0);
                    $ih = (int)($img['img_height'] ?? 0);
                    $a  = ($iw > 0 && $ih > 0) ? ($iw / $ih) : 0;
                    // Shape class → CSS Grid span. Neither orientation is shrunk to
                    // serve the other: portraits get a tall cell, landscapes a wide one.
                    if      ($a >= 2.4)               $cls = 'is-pano';       // 3 cols wide
                    elseif  ($a >= 1.35)              $cls = 'is-landscape';  // 2 cols wide
                    elseif  ($a > 0 && $a <= 0.75)    $cls = 'is-portrait';   // 2 rows tall
                    else                              $cls = 'is-square';     // 1 x 1 (also unknown dims)
                    $thumb_rel = trim((string)($img['img_thumb_aspect'] ?? ''));
                    $img_url   = BASE_URL . ltrim($thumb_rel !== '' ? $thumb_rel : ($img['img_file'] ?? ''), '/');
                ?>
                <a class="mtile <?php echo $cls; ?>"
                   href="<?php echo BASE_URL . htmlspecialchars($img['img_slug']); ?>"
                   aria-label="<?php echo htmlspecialchars($img['img_title'] ?? 'View photograph'); ?>">
                    <img src="<?php echo htmlspecialchars($img_url); ?>"
                         alt="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>"
                         loading="lazy">
                    <span class="scroll-item-title"><?php echo htmlspecialchars($img['img_title'] ?? ''); ?></span>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="scroll-empty">No parts in inventory yet.</p>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
