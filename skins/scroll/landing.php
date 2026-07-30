<?php
/**
 * SNAPSMACK — SCROLL landing page.
 * Uses the established shared justified engine and aspect thumbnails.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$now_local = date('Y-m-d H:i:s');
$grid_stmt = $pdo->prepare(
    "SELECT id, img_title, img_slug, img_file, img_thumb_aspect, img_width, img_height
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
                <a class="ss-grid-nav-link" href="<?php echo BASE_URL; ?>archive.php#archive-search" title="Search">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m15.5 15.5 5 5" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                    <span class="ss-grid-nav-label">Search</span>
                </a>
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
        <form class="scroll-search" method="get" action="<?php echo BASE_URL; ?>archive.php">
            <label class="scroll-search-label" for="scroll-search-input">Search</label>
            <input id="scroll-search-input"
                   class="scroll-search-input"
                   type="search"
                   name="q"
                   placeholder="<?php echo htmlspecialchars($settings['search_placeholder'] ?? 'Search or #tag…'); ?>"
                   autocomplete="off">
        </form>
    </div>

    <main class="scroll-wall">
        <?php
        $target_row_h = max(1, (int)($settings['scroll_row_height'] ?? 280));
        $gap = max(0, min(25, (int)($settings['scroll_tile_gap'] ?? 0)));
        $ref_w = max(1, (int)($settings['main_canvas_width'] ?? 1500));

        // Give every tile stable geometry before the shared lazy loader observes
        // it, so an unlaid-out wall cannot make all images intersect at once.
        $rows = [];
        $current_row = [];
        $current_row_width = 0;
        foreach ($images as $img) {
            $iw = max(1, (int)($img['img_width'] ?? 1));
            $ih = max(1, (int)($img['img_height'] ?? 1));
            $img['_aspect'] = $iw / $ih;
            $current_row[] = $img;
            $current_row_width += round($img['_aspect'] * $target_row_h) + $gap;
            if ($current_row_width - $gap >= $ref_w) {
                $rows[] = ['images' => $current_row, 'full' => true];
                $current_row = [];
                $current_row_width = 0;
            }
        }
        if ($current_row) $rows[] = ['images' => $current_row, 'full' => false];
        ?>
        <div id="scroll-justified-grid"
             style="--scroll-row-height: <?php echo $target_row_h; ?>px; --scroll-tile-gap: <?php echo $gap; ?>px;">
            <?php foreach ($rows as $row_data):
                $row_class = 'justified-row' . (!$row_data['full'] ? ' justified-row-last' : '');
            ?>
            <div class="<?php echo $row_class; ?>">
                <?php foreach ($row_data['images'] as $image):
                    $thumb = trim((string)($image['img_thumb_aspect'] ?? ''));
                    $src = BASE_URL . ltrim($thumb !== '' ? $thumb : ($image['img_file'] ?? ''), '/');
                ?>
                <a class="justified-item"
                   href="<?php echo BASE_URL . htmlspecialchars($image['img_slug']); ?>"
                   aria-label="<?php echo htmlspecialchars($image['img_title'] ?? 'View photograph'); ?>"
                   style="flex-grow: <?php echo round($image['_aspect'] * 100); ?>; flex-basis: 0; aspect-ratio: <?php echo round($image['_aspect'], 4); ?>;">
                    <img src="<?php echo htmlspecialchars($src); ?>"
                         width="<?php echo max(1, (int)($image['img_width'] ?? 1)); ?>"
                         height="<?php echo max(1, (int)($image['img_height'] ?? 1)); ?>"
                         alt="<?php echo htmlspecialchars($image['img_title'] ?? ''); ?>"
                         loading="lazy">
                    <span class="scroll-item-title"><?php echo htmlspecialchars($image['img_title'] ?? ''); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$images): ?>
            <p class="scroll-empty">No parts in inventory yet.</p>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
