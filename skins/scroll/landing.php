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

// ── CHUNKED WALL ──────────────────────────────────────────────────────────
// The wall is paged. Each page of $page_size photographs is rendered as its own
// COMPLETE, INDEPENDENT .ss-masonry container and packed by ss-engine-masonry.js
// as its own asymmetric mosaic. Chunks stack down the page; chunk N arriving
// never re-solves chunk N-1 (all plan state is per-element and every DOM read in
// the engine is scoped to the grid it was handed).
//
// This same file serves BOTH the HTML page and the JSON chunk, through the
// generic hook at index.php:483 (?format=json&pg=<x>), so the appended fragment
// is produced by the SAME render helper as the first page and cannot drift from
// it. ?pg=wall is this skin's claim on that hook.
// scroll_page_size has NO row in snap_settings until an admin opens the SCROLL
// panel — manifest defaults are not seeded on activation. So the fallback and the
// clamp are both load-bearing, not defensive style. An empty or non-numeric row
// (a range control saved blank) must fall back to 36 as well, not clamp to 12 —
// `??` alone only catches null.
$_ss_ps    = $settings['scroll_page_size'] ?? '';
$page_size = is_numeric($_ss_ps) ? max(12, min(60, (int)$_ss_ps)) : 50;
$is_json   = (($_GET['format'] ?? '') === 'json') && (($_GET['pg'] ?? '') === 'wall');

// ?c= is the CHUNK INDEX and is meaningful ONLY on the JSON path. The HTML page
// always renders chunk 0: the container it emits is hardcoded data-ss-chunk="0"
// and the sentinel starts the fetcher at c=1, so honouring ?c= on the HTML page
// would render rows [c*size .. ] while labelling them chunk 0 and the fetcher
// would then append those same rows again. Force it to 0 off the JSON path.
// The upper cap keeps $chunk * $page_size inside PHP's integer range: without it
// ?c=9999999999999999999999 saturates to PHP_INT_MAX, the multiply promotes to
// float, and the (int) PDO coercion emits "not representable as an int". This
// include sits OUTSIDE index.php's try/catch with display_errors on, so an
// uncaught PDOException would print a trace to the visitor.
$chunk  = $is_json ? min(1000000, max(0, (int)($_GET['c'] ?? 0))) : 0;
$offset = $chunk * $page_size;

// Freeze the publish window across the whole scroll session. Without this a
// scheduled post going live mid-scroll shifts every later OFFSET by one and a
// photograph is silently duplicated or skipped at the seam.
//
// SECURITY: this is the only date gate in the codebase fed from the query string,
// so it MUST be clamped to now. img_date is the SCHEDULING gate (smack-manage.php
// treats published rows with a future img_date as scheduled), and index.php serves
// a photo page by slug with no img_date check at all — so an unclamped ?t= in the
// future would hand out the slug, title, thumbnail and dimensions of every
// scheduled photograph and make the full post page reachable. A shape check is not
// enough: '2099-01-01 00:00:00' passes the regex. Freezing only ever needs a
// cutoff in the PAST, so clamping costs the feature nothing. String comparison is
// exact here because the format is fixed-width and zero-padded.
$_ss_now = date('Y-m-d H:i:s');
$cutoff  = (string)($_GET['t'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cutoff)) $cutoff = $_ss_now;
if ($cutoff > $_ss_now) $cutoff = $_ss_now;

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published' AND img_date <= ?");
$total_stmt->execute([$cutoff]);
$total = (int)$total_stmt->fetchColumn();

// ORDER BY sort_order ASC, id DESC is already a STRICT TOTAL ORDER — id is the
// primary key, so the tuple (sort_order, id) is unique and every tie in
// sort_order is fully broken. A total order partitions cleanly into disjoint,
// contiguous LIMIT/OFFSET slices: no duplicate, no skip, provided the WHERE set
// does not move — which is what $cutoff pins down. Ordering itself is unchanged.
$grid_stmt = $pdo->prepare(
    "SELECT id, img_title, img_slug, img_file, img_thumb_aspect,
            img_width, img_height, img_orientation
     FROM snap_images
     WHERE img_status = 'published' AND img_date <= :cutoff
     ORDER BY sort_order ASC, id DESC
     LIMIT :lim OFFSET :off"
);
$grid_stmt->bindValue(':cutoff', $cutoff);
$grid_stmt->bindValue(':lim', $page_size, PDO::PARAM_INT);   // PARAM_INT required in LIMIT
$grid_stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$grid_stmt->execute();
$images   = $grid_stmt->fetchAll(PDO::FETCH_ASSOC);
$has_more = ($offset + count($images)) < $total;

/**
 * Spread portraits (tall tiles) evenly through a chunk so the dense pack doesn't
 * clump them into one region. Display-first ordering — order can move a bit for
 * the packing. Weave one portrait every $stride non-portrait tiles.
 *
 * This now runs PER CHUNK rather than over the whole library: computing a global
 * stride would require fetching every row, which is the thing the page limit
 * exists to prevent. Portraits are therefore spread evenly within each block of
 * $page_size rather than across the entire gallery. The packer only ever sees one
 * chunk, so only within-chunk distribution affects packing — and the weave stays
 * a pure permutation INSIDE an already-fixed DB slice, so it cannot interact with
 * paging stability at all.
 */
if (!function_exists('scroll_wall_weave')) {
function scroll_wall_weave(array $rows): array {
    $port = $rest = [];
    foreach ($rows as $r) {
        $iw = (int)($r['img_width'] ?? 0);
        $ih = (int)($r['img_height'] ?? 0);
        if ($iw > 0 && $ih > 0 && ($iw / $ih) < 0.9) { $port[] = $r; }
        else { $rest[] = $r; }
    }
    if (!$port || !$rest) return $rows;
    $stride = max(1, (int)floor(count($rest) / count($port)));
    $woven  = [];
    $pi     = 0;
    foreach ($rest as $k => $r) {
        $woven[] = $r;
        if ((($k + 1) % $stride) === 0 && $pi < count($port)) $woven[] = $port[$pi++];
    }
    while ($pi < count($port)) $woven[] = $port[$pi++];
    return $woven;
}
}

/**
 * One tile. data-w/data-h feed ss-engine-masonry.js, which reads them and NEVER
 * naturalWidth (the lazy loader swaps src for a 1x1 GIF).
 *
 * They are emitted UNCONDITIONALLY, falling back to 3:2 server-side. The engine
 * applies exactly that same 3:2 fallback for a missing pair, so the layout is
 * identical either way — but a missing pair also counts as "provisional", and
 * >10% provisional arms a forced re-solve on the next image load event. Since
 * lazyload swaps in a placeholder, that load fires immediately for the 1x1 GIF
 * and the forced re-solve re-reads the same absent attributes and produces the
 * same layout: a guaranteed-useless ~2s solve. Emitting the fallback here keeps
 * the provisional count at zero.
 */
if (!function_exists('scroll_wall_tile')) {
function scroll_wall_tile(array $img): string {
    $iw = (int)($img['img_width']  ?? 0);
    $ih = (int)($img['img_height'] ?? 0);
    if ($iw <= 0 || $ih <= 0) { $iw = 3; $ih = 2; }
    $thumb_rel = trim((string)($img['img_thumb_aspect'] ?? ''));
    $img_url   = BASE_URL . ltrim($thumb_rel !== '' ? $thumb_rel : (string)($img['img_file'] ?? ''), '/');
    $title     = (string)($img['img_title'] ?? '');
    return '<a class="ss-masonry-item"'
         . ' href="' . BASE_URL . htmlspecialchars((string)($img['img_slug'] ?? '')) . '"'
         . ' aria-label="' . htmlspecialchars($title !== '' ? $title : 'View photograph') . '">'
         . '<img src="' . htmlspecialchars($img_url) . '"'
         . ' data-w="' . $iw . '" data-h="' . $ih . '"'
         . ' alt="' . htmlspecialchars($title) . '" loading="lazy">'
         . '<span class="scroll-item-title">' . htmlspecialchars($title) . '</span>'
         . '</a>';
}
}

/**
 * One chunk = one complete, independent mosaic.
 *
 * data-ss-seed gives each chunk a distinct packing seed so consecutive chunks
 * don't settle into visually similar arrangements. data-ss-defer opts this
 * container into the engine's deferred-offscreen-relayout behaviour, so a window
 * resize re-solves only the chunks near the viewport.
 *
 * CHUNK CONTAINERS MUST BE FLAT SIBLINGS — never nested inside one another. The
 * engine harvests tiles with a plain descendant selector, so a nested .ss-masonry
 * would have its items claimed by the outer container as well and two layouts
 * would fight over the same inline styles.
 */
if (!function_exists('scroll_wall_chunk')) {
function scroll_wall_chunk(array $rows, int $index, bool $trim = false): string {
    // data-ss-trim: cut this chunk at its waterline (the deepest line where the
    // wall is solid edge to edge) and hand the remainder to the next chunk, so
    // stacked chunks butt together flat. Only set when another chunk follows —
    // the last one has nowhere to hand a remainder to and stays ragged.
    $out = '<div class="ss-masonry" data-ss-chunk="' . $index . '"'
         . ' data-ss-seed="c' . $index . '" data-ss-defer="1"'
         . ($trim ? ' data-ss-trim' : '') . '>';
    foreach (scroll_wall_weave($rows) as $r) $out .= scroll_wall_tile($r);
    return $out . '</div>';
}
}

// ── JSON chunk response ───────────────────────────────────────────────────
// GET /?pg=wall&format=json&c=<int>&t=<cutoff> -> {html, has_more, next}
// has_more is server-authoritative (from COUNT(*)), not a client-side guess.
// Reached before skin-meta.php emits any HTML, so the headers are clean.
if ($is_json) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    // A json_encode() failure (invalid UTF-8 in a title) returns false, which would
    // echo a ZERO-LENGTH 200 with a JSON content type. The fetcher's r.ok test would
    // pass, r.json() would throw, and after MAX_FAILS consecutive throws it stops
    // paging for the rest of the visit — silently. Fail loudly instead.
    $_ss_json = json_encode([
        'html'     => $images ? scroll_wall_chunk($images, $chunk, $has_more) : '',
        'has_more' => $has_more,
        'next'     => $chunk + 1,
    ]);
    if ($_ss_json === false) {
        http_response_code(500);
        echo '{"html":"","has_more":false,"next":0}';
        exit;
    }
    echo $_ss_json;
    exit;
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
        <?php if (!empty($images)): ?>
            <?php
            // Chunk 0. Further chunks are fetched by assets/js/ss-engine-scroll-chunks.js
            // and inserted as FLAT SIBLINGS of this one, immediately before the sentinel.
            // Never nest a .ss-masonry inside another — see scroll_wall_chunk().
            echo scroll_wall_chunk($images, 0, $has_more);
            ?>
            <div id="scroll-wall-sentinel" class="scroll-wall-sentinel"
                 data-next="1"
                 data-has-more="<?php echo $has_more ? '1' : '0'; ?>"
                 data-cutoff="<?php echo htmlspecialchars($cutoff); ?>"
                 aria-hidden="true"></div>
        <?php else: ?>
            <p class="scroll-empty">No parts in inventory yet.</p>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
