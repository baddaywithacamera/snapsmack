<?php
/**
 * SNAPSMACK — SCROLL wall TEST page (0.7.492D, temporary).
 * Same photographs and tile markup as landing.php, but the wall is driven by the
 * unified ss-engine-scroll-wall.js so the three layouts (columns / rows / mosaic)
 * can be tried in-product without touching the live landing. Reached at
 * ?walltest=1 (routed in index.php). Its layout + emphasis come from the
 * "PHOTO WALL — TEST" controls in the skin admin. The container is .ss-scroll-wall
 * (NOT .ss-masonry) so the live columns engine never touches it; the tiles are the
 * same .ss-masonry-item markup and the same ?pg=wall JSON feed (landing.php).
 * Delete this file + the walltest route + the test controls once the picker is
 * promoted onto landing.php.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

// ── PAGED WALL ──────────────────────────────────────────────────────────────
// The wall is paged so the DOM isn't built from every published photo at once.
// The first page renders here; ss-engine-columns.js fetches later pages as JSON
// from THIS file and appends them into the SAME .ss-masonry — columns just keep
// flowing, no join seam. scroll_page_size rows per page.
$_ss_ps    = $settings['scroll_page_size'] ?? '';
$page_size = is_numeric($_ss_ps) ? max(12, min(60, (int)$_ss_ps)) : 50;

// TEST-page wall controls (stored-value selects, read from $settings). These pick
// which layout the unified engine runs and how MOSAIC emphasises orientation.
$_ss_test_layout = (string)($settings['scroll_test_wall_layout'] ?? 'columns');
if (!in_array($_ss_test_layout, ['columns', 'rows', 'mosaic'], true)) $_ss_test_layout = 'columns';
$_ss_test_emph = (string)($settings['scroll_test_emphasis'] ?? 'landscape');
if (!in_array($_ss_test_emph, ['natural', 'balanced', 'landscape', 'portrait'], true)) $_ss_test_emph = 'landscape';
$is_json   = (($_GET['format'] ?? '') === 'json') && (($_GET['pg'] ?? '') === 'wall');

// ?c= chunk index — meaningful only on the JSON path; the HTML page is always
// page 0. Upper cap keeps $chunk * $page_size inside PHP's int range.
$chunk  = $is_json ? min(1000000, max(0, (int)($_GET['c'] ?? 0))) : 0;
$offset = $chunk * $page_size;

// Freeze the publish window across the scroll session so a post going live
// mid-scroll can't shift OFFSET and duplicate/skip at a page seam.
// SECURITY: ?t= is the only query-fed date gate here — clamp it to now. An
// unclamped future cutoff would surface scheduled (future-dated) photos' slugs
// and thumbnails before their publish time.
$_ss_now = date('Y-m-d H:i:s');
$cutoff  = (string)($_GET['t'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cutoff)) $cutoff = $_ss_now;
if ($cutoff > $_ss_now) $cutoff = $_ss_now;

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published' AND img_date <= ?");
$total_stmt->execute([$cutoff]);
$total = (int)$total_stmt->fetchColumn();

$grid_stmt = $pdo->prepare(
    "SELECT id, img_title, img_slug, img_file, img_thumb_aspect,
            img_width, img_height, img_orientation
     FROM snap_images
     WHERE img_status = 'published' AND img_date <= :cutoff
     ORDER BY sort_order ASC, id DESC
     LIMIT :lim OFFSET :off"
);
$grid_stmt->bindValue(':cutoff', $cutoff);
$grid_stmt->bindValue(':lim', $page_size, PDO::PARAM_INT);  // PARAM_INT required in LIMIT
$grid_stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$grid_stmt->execute();
$images   = $grid_stmt->fetchAll(PDO::FETCH_ASSOC);
$has_more = ($offset + count($images)) < $total;

// Weave portraits evenly within THIS page so a clump doesn't load one column with
// all the height. Per-page (we only ever hold one page), which is fine — the
// engine drops each tile into the currently-shortest column regardless.
if (!function_exists('scroll_wall_weave')) {
function scroll_wall_weave(array $rows): array {
    $port = $rest = [];
    foreach ($rows as $r) {
        $iw = (int)($r['img_width'] ?? 0); $ih = (int)($r['img_height'] ?? 0);
        if ($iw > 0 && $ih > 0 && ($iw / $ih) < 0.9) $port[] = $r; else $rest[] = $r;
    }
    if (!$port || !$rest) return $rows;
    $stride = max(1, (int)floor(count($rest) / count($port))); $woven = []; $pi = 0;
    foreach ($rest as $k => $r) { $woven[] = $r; if ((($k + 1) % $stride) === 0 && $pi < count($port)) $woven[] = $port[$pi++]; }
    while ($pi < count($port)) $woven[] = $port[$pi++];
    return $woven;
}
}
$images = scroll_wall_weave($images);

// One tile, rendered identically on the HTML page and the JSON chunk. data-w/h
// feed ss-engine-columns.js (it never reads naturalWidth — lazyload swaps src for
// a 1x1 GIF), falling back to 3:2 so the layout matches the engine's own default.
if (!function_exists('scroll_wall_tile')) {
function scroll_wall_tile(array $img): string {
    $iw = (int)($img['img_width'] ?? 0); $ih = (int)($img['img_height'] ?? 0);
    if ($iw <= 0 || $ih <= 0) { $iw = 3; $ih = 2; }
    $thumb_rel = trim((string)($img['img_thumb_aspect'] ?? ''));
    $img_url   = BASE_URL . ltrim($thumb_rel !== '' ? $thumb_rel : (string)($img['img_file'] ?? ''), '/');
    $title     = (string)($img['img_title'] ?? '');
    return '<a class="ss-masonry-item" href="' . BASE_URL . htmlspecialchars((string)($img['img_slug'] ?? '')) . '"'
         . ' aria-label="' . htmlspecialchars($title !== '' ? $title : 'View photograph') . '">'
         . '<img src="' . htmlspecialchars($img_url) . '" data-w="' . $iw . '" data-h="' . $ih . '"'
         . ' alt="' . htmlspecialchars($title) . '" loading="lazy">'
         . '<span class="scroll-item-title">' . htmlspecialchars($title) . '</span></a>';
}
}

// JSON chunk path (via index.php's ?format=json&pg=wall hook): emit just this
// page's tiles + paging state, then stop.
if ($is_json) {
    $_html = '';
    foreach ($images as $_im) $_html .= scroll_wall_tile($_im);
    header('Content-Type: application/json');
    echo json_encode(['html' => $_html, 'next' => $chunk + 1, 'has_more' => $has_more, 'cutoff' => $cutoff]);
    return;
}

$masthead_raw = trim((string)($settings['scroll_masthead_lines'] ?? 'USED CAR|PARTS'));
$masthead_lines = array_values(array_filter(array_map('trim', explode('|', $masthead_raw)), 'strlen'));
if (!$masthead_lines) $masthead_lines = [$settings['site_name'] ?? 'SnapSmack'];
$photographer_name = trim((string)($settings['photographer_name'] ?? ($settings['site_name'] ?? '')));
$byline_prefix = trim((string)($settings['scroll_byline_prefix'] ?? 'PHOTOGRAPHY BY'));
// Optional masthead logo (type:image, stored scoped as scroll__masthead_logo). When
// set it REPLACES the tilted title text on the landing; the title text stays in the
// source, visually hidden, so the <h1> keeps its SEO/screen-reader value.
$scroll_masthead_logo = trim((string)($settings['scroll__masthead_logo'] ?? ''));
?>
<?php
// Filter modal data — reuses the archive's proven filter panel + JS
// (ss-engine-archive-filter.js). Only the HTML render reaches here; the JSON
// paged-wall path has already returned above.
$af_cats        = $pdo->query("SELECT id, cat_name FROM snap_categories WHERE show_in_archive = 1 ORDER BY cat_name ASC")->fetchAll();
$af_albums      = $pdo->query("SELECT id, album_name FROM snap_albums ORDER BY album_name ASC")->fetchAll();
$af_collections = $pdo->query("SELECT id, title FROM snap_collections ORDER BY title ASC")->fetchAll();
$af_authors     = $pdo->query("SELECT u.id, u.username FROM snap_users u WHERE EXISTS (SELECT 1 FROM snap_images i2 WHERE i2.user_id = u.id AND i2.img_status = 'published') ORDER BY u.username ASC")->fetchAll();
?>
<div class="scroll-landing">
    <section class="scroll-profile" aria-labelledby="scroll-site-title">
        <div class="scroll-photographer">
            <?php if ($byline_prefix !== ''): ?>
                <span class="scroll-byline-prefix"><?php echo htmlspecialchars($byline_prefix); ?></span>
            <?php endif; ?>
            <span class="scroll-byline-name"><?php echo htmlspecialchars($photographer_name); ?></span>
        </div>
        <h1 class="scroll-masthead<?php echo $scroll_masthead_logo !== '' ? ' has-logo' : ''; ?>" id="scroll-site-title">
            <?php if ($scroll_masthead_logo !== ''): ?>
                <span class="scroll-visually-hidden"><?php echo htmlspecialchars(implode(' ', $masthead_lines)); ?></span>
                <img class="scroll-masthead-logo" src="<?php echo BASE_URL . htmlspecialchars(ltrim($scroll_masthead_logo, '/')); ?>" alt="">
            <?php else: ?>
                <?php foreach ($masthead_lines as $line): ?>
                    <span><?php echo htmlspecialchars($line); ?></span>
                <?php endforeach; ?>
            <?php endif; ?>
        </h1>

        <nav class="scroll-sticky-nav ss-grid-sticky-nav ss-grid-nav-inline-then-sticky"
             aria-label="Site navigation"
             data-grid-nav-observer="scroll-profile">
            <div class="ss-grid-nav-identity">
                <?php
                // The identity slot is display:none on the inline landing header and only
                // shows in the fixed/sticky bar. It carries the BLOG NAME (the masthead,
                // shrunk, in the masthead font) so the site identity stays put once the big
                // masthead scrolls away.
                ?>
                <a class="ss-grid-nav-name" href="<?php echo BASE_URL; ?>"><?php echo htmlspecialchars(implode(' ', $masthead_lines)); ?></a>
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
                <div class="saf-wrap scroll-nav-filter">
                    <button id="smack-archive-filter-btn" type="button" class="ss-grid-nav-link" aria-expanded="false" aria-controls="smack-archive-filter-panel" title="Filter">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18l-7 8v5l-4 2v-7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                        <span class="ss-grid-nav-label">Filter</span>
                    </button>
                    <div id="smack-archive-filter-panel" class="saf-panel" role="dialog" aria-label="Filter photographs">
                        <input type="text" id="smack-archive-filter-search" class="saf-search" placeholder="SEARCH FILTERS…" autocomplete="off" spellcheck="false">
                        <?php if ($af_cats): ?>
                        <div class="saf-group"><div class="saf-group-header">CATEGORIES</div>
                            <?php foreach ($af_cats as $c): ?>
                            <label class="saf-item"><input type="checkbox" class="saf-checkbox" data-type="cat" value="<?php echo (int)$c['id']; ?>"><span class="saf-label"><?php echo htmlspecialchars(strtoupper($c['cat_name'])); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($af_albums): ?>
                        <div class="saf-group"><div class="saf-group-header">ALBUMS</div>
                            <?php foreach ($af_albums as $a): ?>
                            <label class="saf-item"><input type="checkbox" class="saf-checkbox" data-type="alb" value="<?php echo (int)$a['id']; ?>"><span class="saf-label"><?php echo htmlspecialchars(strtoupper($a['album_name'])); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($af_collections): ?>
                        <div class="saf-group"><div class="saf-group-header">COLLECTIONS</div>
                            <?php foreach ($af_collections as $col): ?>
                            <label class="saf-item"><input type="checkbox" class="saf-checkbox" data-type="col" value="<?php echo (int)$col['id']; ?>"><span class="saf-label"><?php echo htmlspecialchars(strtoupper($col['title'])); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (count($af_authors) > 1): ?>
                        <div class="saf-group"><div class="saf-group-header">PHOTOGRAPHER</div>
                            <?php foreach ($af_authors as $au): ?>
                            <label class="saf-item"><input type="checkbox" class="saf-checkbox" data-type="usr" value="<?php echo (int)$au['id']; ?>"><span class="saf-label"><?php echo htmlspecialchars(strtoupper($au['username'])); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
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

    <main class="scroll-wall scroll-wall-test">
        <div class="ss-scroll-wall"
             data-wall-layout="<?php echo htmlspecialchars($_ss_test_layout); ?>"
             data-emphasis="<?php echo htmlspecialchars($_ss_test_emph); ?>">
            <?php if (!empty($images)): ?>
                <?php foreach ($images as $img) echo scroll_wall_tile($img); ?>
            <?php else: ?>
                <p class="scroll-empty">No parts in inventory yet.</p>
            <?php endif; ?>
        </div>
        <?php if ($has_more): ?>
        <!-- Infinite scroll: ss-engine-scroll-wall.js watches this, fetches the next
             page as JSON (shared ?pg=wall feed → landing.php), appends the tiles
             into .ss-scroll-wall above, and relayouts in the chosen layout. -->
        <div class="scroll-wall-sentinel"
             data-next="1"
             data-cutoff="<?php echo htmlspecialchars($cutoff); ?>"
             data-has-more="1"
             aria-hidden="true"></div>
        <?php endif; ?>
    </main>
</div>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-archive-filter.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>
<!-- TEST wall: the unified three-layout engine + Codex's mosaic compositor + the
     scoped CSS bundle. Loaded ONLY here (not via require_scripts), so the live
     landing keeps using ss-engine-columns.js untouched. -->
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-scroll-wall.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-mosaic.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-scroll-wall.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
