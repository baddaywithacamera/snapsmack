<?php
/**
 * SNAPSMACK — SCROLL landing page.
 * FOUR-COLUMN photo wall. Every photograph is drawn at its own native aspect
 * ratio and is never cropped, so shape alone decides a tile's height: in equal
 * columns a 2:3 portrait covers 2.25x the area of a 3:2 landscape, which is what
 * makes the portraits read as the big pictures. PHP emits the photographs and
 * their stored dimensions; assets/js/ss-engine-columns.js owns the geometry.
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

// Spread portraits (tall tiles) evenly through the stream. The engine drops each
// photo into whichever column is currently shortest, so a clump of consecutive
// portraits would load one or two columns with all the height and leave the wall
// lopsided for a long stretch. Weave one portrait every $stride non-portrait
// tiles. Display-first ordering — order can move a little for the look.
$_ss_port = $_ss_rest = [];
foreach ($images as $_im) {
    $iw = (int)($_im['img_width'] ?? 0);
    $ih = (int)($_im['img_height'] ?? 0);
    if ($iw > 0 && $ih > 0 && ($iw / $ih) < 0.9) { $_ss_port[] = $_im; }
    else { $_ss_rest[] = $_im; }
}
if ($_ss_port && $_ss_rest) {
    $stride = max(1, (int)floor(count($_ss_rest) / count($_ss_port)));
    $woven  = [];
    $pi     = 0;
    foreach ($_ss_rest as $k => $_im) {
        $woven[] = $_im;
        if ((($k + 1) % $stride) === 0 && $pi < count($_ss_port)) $woven[] = $_ss_port[$pi++];
    }
    while ($pi < count($_ss_port)) $woven[] = $_ss_port[$pi++];
    $images = $woven;
}

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
                <a class="ss-grid-nav-link" href="<?php echo BASE_URL; ?>about" title="About">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><line x1="12" y1="11" x2="12" y2="16.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="7.8" r="1.05" fill="currentColor"/></svg>
                    <span class="ss-grid-nav-label">About</span>
                </a>
                <a class="ss-grid-nav-link" href="<?php echo BASE_URL; ?>blogroll.php" title="Blogroll">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="6" r="1.5" fill="currentColor"/><circle cx="5" cy="12" r="1.5" fill="currentColor"/><circle cx="5" cy="18" r="1.5" fill="currentColor"/><path d="M9 6h11M9 12h11M9 18h11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <span class="ss-grid-nav-label">Blogroll</span>
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
        <div class="ss-masonry">
            <?php if (!empty($images)): ?>
                <?php foreach ($images as $img):
                    $iw = (int)($img['img_width']  ?? 0);
                    $ih = (int)($img['img_height'] ?? 0);
                    // data-w/data-h feed ss-engine-columns.js — it reads the shape
                    // from these attributes and NEVER from naturalWidth, because the
                    // lazy loader swaps src for a 1x1 GIF until a photo scrolls in.
                    $thumb_rel = trim((string)($img['img_thumb_aspect'] ?? ''));
                    $img_url   = BASE_URL . ltrim($thumb_rel !== '' ? $thumb_rel : ($img['img_file'] ?? ''), '/');
                ?>
                <a class="ss-masonry-item"
                   href="<?php echo BASE_URL . htmlspecialchars($img['img_slug']); ?>"
                   aria-label="<?php echo htmlspecialchars($img['img_title'] ?? 'View photograph'); ?>">
                    <img src="<?php echo htmlspecialchars($img_url); ?>"
                         <?php if ($iw > 0 && $ih > 0): ?>data-w="<?php echo $iw; ?>" data-h="<?php echo $ih; ?>"<?php endif; ?>
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
