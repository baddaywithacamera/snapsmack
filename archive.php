<?php
/**
 * SNAPSMACK - Archive page with multiple layout modes
 *
 * Displays all published images with support for square, cropped, and
 * masonry layouts. Handles category and album filtering via query parameters.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/parser.php';
require_once __DIR__ . '/core/skin-settings.php';
require_once __DIR__ . '/core/stats-logger.php';

// --- INITIALIZATION ---
$settings = [];
$site_name = 'ISWA.CA';
$active_skin = 'smackdown';

try {
    $snapsmack = new SnapSmack($pdo);

    // --- SETTINGS LOADING ---
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    // Define BASE_URL from settings with trailing slash for consistent routing
    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? 'https://example.com/';
        define('BASE_URL', rtrim($db_url, '/') . '/');
    }

    $active_skin = $settings['active_skin'] ?? 'smackdown';
    $site_name = $settings['site_name'] ?? $site_name;

    // Force Pocket Rocket on mobile devices (phones only, not tablets)
    if (snapsmack_is_mobile() && is_dir(__DIR__ . '/skins/' . SNAPSMACK_MOBILE_SKIN)) {
        $active_skin = SNAPSMACK_MOBILE_SKIN;
    }

    // Overlay skin-scoped settings so each skin retains its own customizations
    snapsmack_apply_skin_settings($settings, $active_skin);

    // --- ARCHIVE LAYOUT MODE ---
    // Owner sets the default in Archive Appearance. Visitor can override via ?layout=
    // URL param if the owner has enabled multiple modes. Preference persists via JS/localStorage.
    // Skin manifest may declare a preferred default via features.archive_layout_default.
    $skin_layout_fallback = 'square';
    $skin_has_calendar = false;
    $_manifest_path = __DIR__ . '/skins/' . $active_skin . '/manifest.php';
    $skin_show_archive_filter = true;
    if (file_exists($_manifest_path)) {
        $_m = include $_manifest_path;
        if (!empty($_m['features']['archive_layout_default'])) {
            $skin_layout_fallback = $_m['features']['archive_layout_default'];
        }
        $skin_has_calendar = in_array('smack-calendar', $_m['require_scripts'] ?? []);
        // Skins may set features.archive_filter = false to suppress the unified filter panel.
        if (isset($_m['features']['archive_filter']) && $_m['features']['archive_filter'] === false) {
            $skin_show_archive_filter = false;
        }
        unset($_m, $_manifest_path);
    }
    $archive_layout_default = $settings['archive_layout'] ?? $skin_layout_fallback;
    if ($archive_layout_default === 'none') {
        $base = rtrim($settings['site_url'] ?? '/', '/') . '/';
        header('Location: ' . $base);
        exit;
    }
    if (!in_array($archive_layout_default, ['square', 'cropped', 'masonry', 'croppedwithcalendar'])) {
        $archive_layout_default = 'square';
    }

    // Which modes the owner has offered to visitors.
    // Canonical order enforced regardless of how they were saved in the DB:
    // square → cropped → croppedwithcalendar → masonry
    $_canonical_order = ['square', 'cropped', 'croppedwithcalendar', 'masonry'];
    $available_raw    = $settings['archive_layouts_available'] ?? $archive_layout_default;
    $_enabled         = array_flip(array_filter(
        array_map('trim', explode(',', $available_raw)),
        fn($m) => in_array($m, $_canonical_order)
    ));
    $available_modes  = array_values(array_filter($_canonical_order, fn($m) => isset($_enabled[$m])));
    unset($_canonical_order, $_enabled);
    if (empty($available_modes)) $available_modes = [$archive_layout_default];
    // Only offer croppedwithcalendar if the skin has the calendar engine.
    if (!$skin_has_calendar) {
        $available_modes = array_values(array_filter($available_modes, fn($m) => $m !== 'croppedwithcalendar'));
    }
    if (!in_array($archive_layout_default, $available_modes)) {
        $available_modes[] = $archive_layout_default;
    }

    // Accept visitor ?layout= override only if it's in the allowed set.
    $layout_override = $_GET['layout'] ?? '';
    $archive_layout  = (in_array($layout_override, $available_modes))
                       ? $layout_override
                       : $archive_layout_default;

    // archive.php renders its own toggle only when no skin-specific archive-layout.php exists.
    // Skins with their own archive-layout.php handle the toggle themselves.
    $skin_archive_file = __DIR__ . '/skins/' . $active_skin . '/archive-layout.php';
    $offer_toggle = (count($available_modes) > 1) && !file_exists($skin_archive_file);

    // --- THUMBNAIL SIZE RESOLUTION ---
    // Maps abstract 5-step scale (xs, s, m, l, xl) to pixel values.
    // Cropped values are ~25% larger than square to maintain visual weight.
    // Backwards compatible with old numeric pixel values.
    $thumb_size_map = [
        'square' => ['xs' => 120, 's' => 150, 'm' => 200, 'l' => 250, 'xl' => 300],
        'cropped' => ['xs' => 150, 's' => 190, 'm' => 250, 'l' => 310, 'xl' => 375],
    ];
    $thumb_step = $settings['thumb_size'] ?? 'm';
    // Convert old pixel values to step names for backwards compatibility
    if (is_numeric($thumb_step)) {
        $px = (int)$thumb_step;
        if ($px <= 130) $thumb_step = 'xs';
        elseif ($px <= 170) $thumb_step = 's';
        elseif ($px <= 230) $thumb_step = 'm';
        elseif ($px <= 290) $thumb_step = 'l';
        else $thumb_step = 'xl';
    }
    if (!in_array($thumb_step, ['xs', 's', 'm', 'l', 'xl'])) $thumb_step = 'm';
    // croppedwithcalendar renders the same grid as cropped
    $grid_layout = ($archive_layout === 'croppedwithcalendar') ? 'cropped' : $archive_layout;
    $thumb_px = $thumb_size_map[$grid_layout][$thumb_step] ?? $thumb_size_map['square']['m'];

    // Justified row target height for masonry layout
    $justified_row_height = (int)($settings['justified_row_height'] ?? 180);

    // --- FILTER PARAMETERS ---
    // Unified multi-select filter: ?f[]=cat:5&f[]=alb:3&f[]=col:2
    // AND logic: image must satisfy every selected filter to appear.
    // Backward compat: legacy ?cat=N and ?album=N single-select links still work.
    $filter_cats        = [];
    $filter_albums      = [];
    $filter_collections = [];

    $raw_f = $_GET['f'] ?? [];
    if (is_array($raw_f)) {
        foreach ($raw_f as $token) {
            if (preg_match('/^(cat|alb|col):(\d+)$/', trim($token), $m)) {
                $id = (int)$m[2];
                if ($id > 0) {
                    if ($m[1] === 'cat') $filter_cats[]        = $id;
                    if ($m[1] === 'alb') $filter_albums[]      = $id;
                    if ($m[1] === 'col') $filter_collections[] = $id;
                }
            }
        }
    }
    // Legacy single-select backwards compat
    if (empty($filter_cats) && isset($_GET['cat']))     $filter_cats[]   = (int)$_GET['cat'];
    if (empty($filter_albums) && isset($_GET['album'])) $filter_albums[] = (int)$_GET['album'];

    $active_filter_count = count($filter_cats) + count($filter_albums) + count($filter_collections);

    // Legacy vars for any skin templates that may reference them
    $cat_filter   = $filter_cats[0]   ?? null;
    $album_filter = $filter_albums[0] ?? null;

    $search_query  = trim($_GET['q'] ?? '');
    // Calendar date-range filter: ?from=YYYY-MM-DD&to=YYYY-MM-DD
    $from_filter   = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']))
                     ? $_GET['from'] : null;
    $to_filter     = (isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))
                     ? $_GET['to'] : null;
    if ($from_filter && $to_filter && $from_filter > $to_filter) {
        list($from_filter, $to_filter) = [$to_filter, $from_filter];
    }

    // Calendar date filter: YYYY-MM-DD — shows all posts on that specific date.
    $date_filter   = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']))
                     ? $_GET['date'] : null;

    // If search query looks like a hashtag, redirect to tag archive
    if ($search_query !== '' && $search_query[0] === '#') {
        $tag_candidate = substr($search_query, 1);
        if (preg_match('/^(?:[a-zA-Z][a-zA-Z0-9_]{0,49}|[0-9][0-9a-fA-F]{5})$/', $tag_candidate)) {
            header('Location: ' . BASE_URL . '?tag=' . rawurlencode(strtolower($tag_candidate)));
            exit;
        }
    }

    // --- DATABASE QUERY ---
    // Timezone is configured globally in core/db.php.
    // Filters by publication status and timestamp to respect scheduled posts.
    $now_local = date('Y-m-d H:i:s');

    $sql = "SELECT DISTINCT i.* FROM snap_images i ";
    $where_clauses = ["i.img_status = 'published'", "i.img_date <= ?"];
    $params = [$now_local];

    if ($search_query !== '') {
        // Search: join tags, match title/description/tags/colour-family
        // color_family match enables "blue", "teal" etc. to return images tagged with
        // matching hex colour codes (e.g. searching "teal" finds #007a8b-tagged images).
        $sql .= "LEFT JOIN snap_image_tags sit ON sit.image_id = i.id ";
        $sql .= "LEFT JOIN snap_tags st ON st.id = sit.tag_id ";
        $like         = '%' . $search_query . '%';
        $tag_like     = '%' . strtolower($search_query) . '%';
        $family_exact = strtolower(trim($search_query));
        $where_clauses[] = "(i.img_title LIKE ? OR i.img_description LIKE ? OR st.slug LIKE ? OR st.color_family = ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $tag_like;
        $params[] = $family_exact;
    } elseif ($from_filter && $to_filter) {
        // Calendar date-range browse.
        $where_clauses[] = "DATE(i.img_date) >= ? AND DATE(i.img_date) <= ?";
        $params[] = $from_filter;
        $params[] = $to_filter;
    } elseif ($date_filter) {
        // Calendar day browse — all posts published on a specific date.
        $where_clauses[] = "DATE(i.img_date) = ?";
        $params[] = $date_filter;
    } elseif ($active_filter_count > 0) {
        // Unified multi-filter: AND EXISTS for each selected taxonomy item.
        // Every clause must be satisfied — more selections = narrower results.

        foreach ($filter_cats as $cid) {
            $where_clauses[] = "EXISTS (SELECT 1 FROM snap_image_cat_map _cm WHERE _cm.image_id = i.id AND _cm.cat_id = ?)";
            $params[] = $cid;
        }
        foreach ($filter_albums as $aid) {
            $where_clauses[] = "EXISTS (SELECT 1 FROM snap_image_album_map _am WHERE _am.image_id = i.id AND _am.album_id = ?)";
            $params[] = $aid;
        }
        // Collection: image qualifies if it's a direct post member, OR belongs to
        // an album or category that is a member of the collection.
        foreach ($filter_collections as $colid) {
            $where_clauses[] = "EXISTS (
                SELECT 1 FROM snap_collection_items _ci
                WHERE _ci.collection_id = ?
                AND (
                    (_ci.item_type = 'post'     AND i.post_id IS NOT NULL AND _ci.item_id = i.post_id)
                    OR (_ci.item_type = 'album'    AND EXISTS (SELECT 1 FROM snap_image_album_map _am2 WHERE _am2.image_id = i.id AND _am2.album_id = _ci.item_id))
                    OR (_ci.item_type = 'category' AND EXISTS (SELECT 1 FROM snap_image_cat_map _cm2   WHERE _cm2.image_id = i.id AND _cm2.cat_id   = _ci.item_id))
                )
            )";
            $params[] = $colid;
        }
    } else {
        // Unfiltered browse: exclude images that belong exclusively to hidden categories.
        // Images with no category (uncategorized) always show.
        // Images in at least one visible category show even if also in a hidden one.
        $where_clauses[] = "
            (NOT EXISTS (SELECT 1 FROM snap_image_cat_map _cm WHERE _cm.image_id = i.id)
             OR EXISTS (
                SELECT 1 FROM snap_image_cat_map _cm2
                INNER JOIN snap_categories _sc ON _sc.id = _cm2.cat_id
                WHERE _cm2.image_id = i.id AND _sc.show_in_archive = 1
             )
            )";
    }

    $sql .= " WHERE " . implode(" AND ", $where_clauses);
    $sql .= " ORDER BY i.sort_order ASC, i.img_date DESC, i.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $images = $stmt->fetchAll();

    // --- METADATA FOR FILTERS ---
    // Fetch all categories, albums, and collections for the unified filter panel.
    // Only surface categories set to visible in the archive.
    $all_cats        = $pdo->query("SELECT id, cat_name FROM snap_categories WHERE show_in_archive = 1 ORDER BY cat_name ASC")->fetchAll();
    $all_albums      = $pdo->query("SELECT id, album_name FROM snap_albums ORDER BY album_name ASC")->fetchAll();
    $all_collections = $pdo->query("SELECT id, name FROM snap_collections ORDER BY name ASC")->fetchAll();

    // Matching tags (shown when searching)
    // Includes colour-family matches so searching "teal" surfaces #007a8b etc.
    $matched_tags = [];
    if ($search_query !== '') {
        $tag_like     = '%' . strtolower($search_query) . '%';
        $family_exact = strtolower(trim($search_query));
        $tag_stmt = $pdo->prepare("
            SELECT slug, use_count, color_family
            FROM snap_tags
            WHERE (slug LIKE ? OR color_family = ?)
              AND use_count > 0
            ORDER BY
                CASE WHEN color_family = ? THEN 0 ELSE 1 END,
                use_count DESC
            LIMIT 8
        ");
        $tag_stmt->execute([$tag_like, $family_exact, $family_exact]);
        $matched_tags = $tag_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    die("<div style='background:#300;color:#f99;padding:20px;border:1px solid red;font-family:monospace;'><h3>ARCHIVE_TRANSMISSION_ERROR</h3>" . $e->getMessage() . "</div>");
}

$page_title = "Archive";
$skin_path  = 'skins/' . $active_skin;

// --- STATS LOGGING ---
snapsmack_log_hit($pdo, $settings, [
    'page_type'   => 'archive',
    'page_slug'   => null,
    'search_term' => $_GET['search'] ?? null,
]);

if (file_exists(__DIR__ . '/' . $skin_path . '/skin-meta.php')) {
    include __DIR__ . '/' . $skin_path . '/skin-meta.php';
}
?>

<body class="archive-page archive-layout-<?php echo $archive_layout; ?>">
    <div id="page-wrapper">

        <?php
        $header_file = __DIR__ . '/' . $skin_path . '/skin-header.php';
        include (file_exists($header_file)) ? $header_file : __DIR__ . '/core/header.php';
        ?>

        <div id="infobox">
            <div class="nav-links">
                <div class="center">
                    <a href="archive.php" class="<?php echo $active_filter_count === 0 && $search_query === '' ? 'active' : 'inactive'; ?>">
                        [ SHOW ALL ]
                    </a>
                    <span class="sep">/</span>

                    <?php if ($skin_show_archive_filter): ?>
                    <!-- Unified taxonomy filter panel -->
                    <div class="filter-group saf-wrap">
                        <button id="smack-archive-filter-btn"
                                class="saf-btn<?php echo $active_filter_count > 0 ? ' saf-btn--active' : ''; ?>"
                                aria-expanded="false"
                                aria-controls="smack-archive-filter-panel"
                                type="button">
                            <span class="saf-btn-label"><?php echo $active_filter_count > 0 ? $active_filter_count . ' SELECTED' : 'FILTER'; ?></span>
                            <span class="saf-btn-arrow">▾</span>
                        </button>

                        <div id="smack-archive-filter-panel" class="saf-panel" role="dialog" aria-label="Filter photos">

                            <input type="text" id="smack-archive-filter-search"
                                   class="saf-search" placeholder="SEARCH FILTERS…"
                                   autocomplete="off" spellcheck="false">

                            <?php if ($all_cats): ?>
                            <div class="saf-group">
                                <div class="saf-group-header">REGISTRY</div>
                                <?php foreach ($all_cats as $c): ?>
                                <label class="saf-item">
                                    <input type="checkbox" class="saf-checkbox"
                                           data-type="cat" value="<?php echo (int)$c['id']; ?>"
                                           <?php echo in_array((int)$c['id'], $filter_cats) ? 'checked' : ''; ?>>
                                    <span class="saf-label"><?php echo htmlspecialchars(strtoupper($c['cat_name'])); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($all_albums): ?>
                            <div class="saf-group">
                                <div class="saf-group-header">ALBUMS</div>
                                <?php foreach ($all_albums as $a): ?>
                                <label class="saf-item">
                                    <input type="checkbox" class="saf-checkbox"
                                           data-type="alb" value="<?php echo (int)$a['id']; ?>"
                                           <?php echo in_array((int)$a['id'], $filter_albums) ? 'checked' : ''; ?>>
                                    <span class="saf-label"><?php echo htmlspecialchars(strtoupper($a['album_name'])); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($all_collections): ?>
                            <div class="saf-group">
                                <div class="saf-group-header">COLLECTIONS</div>
                                <?php foreach ($all_collections as $col): ?>
                                <label class="saf-item">
                                    <input type="checkbox" class="saf-checkbox"
                                           data-type="col" value="<?php echo (int)$col['id']; ?>"
                                           <?php echo in_array((int)$col['id'], $filter_collections) ? 'checked' : ''; ?>>
                                    <span class="saf-label"><?php echo htmlspecialchars(strtoupper($col['name'])); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                        </div><!-- /saf-panel -->
                    </div><!-- /saf-wrap -->
                    <?php endif; ?>

                    <span class="sep">|</span>

                    <div class="filter-group">
                        <form method="GET" action="archive.php" class="archive-search-form">
                            <input type="search" name="q" placeholder="<?php echo htmlspecialchars($settings['search_placeholder'] ?? 'Search or #tag…'); ?>"
                                   value="<?php echo htmlspecialchars($search_query); ?>"
                                   class="archive-search-input" autocomplete="off">
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($offer_toggle): ?>
        <div class="archive-layout-toggle" role="group" aria-label="Layout">
            <?php
            $toggle_labels = ['square' => 'Grid', 'cropped' => 'Crop', 'masonry' => 'Flow', 'croppedwithcalendar' => 'Cal'];
            foreach ($available_modes as $mode):
                $is_active = ($mode === $archive_layout);
                // Build URL preserving existing query params except layout.
                $qp = $_GET;
                $qp['layout'] = $mode;
                unset($qp['q']); // don't conflict with search — reset to all on layout switch
                unset($qp['cat']); unset($qp['album']); unset($qp['date']); unset($qp['from']); unset($qp['to']);
                $qs = http_build_query($qp);
            ?>
                <a href="archive.php?<?php echo $qs; ?>"
                   class="alt-btn<?php echo $is_active ? ' alt-btn--active' : ''; ?>"
                   data-layout="<?php echo htmlspecialchars($mode); ?>">
                    <?php echo $toggle_labels[$mode] ?? strtoupper($mode); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <script>
        // Persist layout preference in localStorage so it survives navigation.
        // Runs regardless of whether this skin owns the toggle UI.
        (function() {
            var pref = localStorage.getItem('smack_archive_layout');
            var avail = <?php echo json_encode($available_modes); ?>;
            var current = <?php echo json_encode($archive_layout); ?>;
            // If no explicit ?layout= in URL but a pref exists, redirect to it.
            if (pref && pref !== current && avail.indexOf(pref) !== -1 && !location.search.match(/layout=/)) {
                location.replace('archive.php?layout=' + encodeURIComponent(pref));
            } else {
                localStorage.setItem('smack_archive_layout', current);
            }
        }());
        </script>

        <?php if ($search_query !== ''): ?>
        <div class="archive-search-status">
            <?php echo count($images); ?> result<?php echo count($images) !== 1 ? 's' : ''; ?> for &ldquo;<?php echo htmlspecialchars($search_query); ?>&rdquo;
            &nbsp; <a href="archive.php">[ CLEAR ]</a>
        </div>
        <?php if (!empty($matched_tags)): ?>
        <div class="tg-tags archive-tags-row">
            <?php foreach ($matched_tags as $mt): ?>
                <a href="?tag=<?php echo rawurlencode($mt['slug']); ?>" class="tg-tag">#<?php echo htmlspecialchars($mt['slug']); ?> (<?php echo (int)$mt['use_count']; ?>)</a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <div id="scroll-stage">

            <?php
            // Skin-specific archive layout: if the active skin provides archive-layout.php,
            // use that instead of the default grid rendering. $images, $settings, $all_cats,
            // $all_albums, $cat_filter, and $album_filter are available to the template.
            $skin_archive = __DIR__ . '/' . $skin_path . '/archive-layout.php';
            if (file_exists($skin_archive)):
                include $skin_archive;
            elseif ($archive_layout === 'masonry'): ?>
            <!-- Justified layout — Flickr-style row-fill with full aspect ratios.
                 PHP groups images into rows for semantics; CSS flexbox handles sizing.
                 Each item's flex-grow equals its aspect ratio for perfect row alignment. -->
            <?php
                $target_row_h = (int)($settings['justified_row_height'] ?? 180);
                $gap          = (int)($settings['justified_gap'] ?? 4);
                $ref_w = (int)($settings['main_canvas_width'] ?? 1280);

                // Build rows before opening the grid div so we can pass
                // --last-row-ar-sum as a CSS variable on the container.
                $rows = [];
                $current_row = [];
                $current_row_width = 0;

                if ($images) {
                    foreach ($images as $img) {
                        $iw = (int)($img['img_width'] ?? 400);
                        $ih = (int)($img['img_height'] ?? 400);
                        if ($ih <= 0) $ih = 400;
                        if ($iw <= 0) $iw = 400;

                        $img['_aspect'] = $iw / $ih;
                        $scaled_w = round($img['_aspect'] * $target_row_h);

                        $current_row[] = $img;
                        $current_row_width += $scaled_w + $gap;

                        if ($current_row_width - $gap >= $ref_w) {
                            $rows[] = ['images' => $current_row, 'full' => true];
                            $current_row = [];
                            $current_row_width = 0;
                        }
                    }
                    if (!empty($current_row)) {
                        $rows[] = ['images' => $current_row, 'full' => false];
                    }
                }

                // AR sum of last full row — CSS uses this to match last-row height
                $last_full_ar_sum = 0;
                for ($i = count($rows) - 1; $i >= 0; $i--) {
                    if ($rows[$i]['full']) {
                        foreach ($rows[$i]['images'] as $_img) {
                            $last_full_ar_sum += $_img['_aspect'];
                        }
                        break;
                    }
                }
                if ($last_full_ar_sum <= 0) $last_full_ar_sum = $ref_w / $target_row_h;
            ?>
            <div id="justified-grid" class="justified-grid" style="--justified-gap: <?php echo $gap; ?>px; --justified-row-height: <?php echo $target_row_h; ?>px; --last-row-ar-sum: <?php echo round($last_full_ar_sum, 4); ?>;">
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row_data):
                        $row = $row_data['images'];
                        $is_full = $row_data['full'];
                        $row_class = 'justified-row' . (!$is_full ? ' justified-row-last' : '');
                    ?>
                        <div class="<?php echo $row_class; ?>">
                            <?php foreach ($row as $img):
                                $link = BASE_URL . htmlspecialchars($img['img_slug']);
                                $img_url = BASE_URL . ltrim($img['img_file'], '/');
                                $flex_grow = round($img['_aspect'] * 100);
                            ?>
                                <a href="<?php echo $link; ?>" class="justified-item" title="<?php echo htmlspecialchars($img['img_title']); ?>" style="flex-grow: <?php echo $flex_grow; ?>; flex-basis: 0; aspect-ratio: <?php echo round($img['_aspect'], 4); ?>;">
                                    <img src="<?php echo $img_url; ?>" alt="<?php echo htmlspecialchars($img['img_title']); ?>" loading="lazy">
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($active_filter_count > 0): ?>
                    <div class="empty-sector-msg">NOTHING CLEARS ALL THOSE HURDLES.<br><a href="archive.php">[ EASE UP ]</a></div>
                <?php else: ?>
                    <div class="empty-sector-msg">NO TRANSMISSIONS RECORDED IN THIS SECTOR.</div>
                <?php endif; ?>
            </div>

            <?php elseif ($archive_layout === 'cropped'): ?>
            <!-- Cropped layout — Center-cropped to max 3:2 or 2:3 aspect ratio -->
            <div id="browse-grid" class="cropped-grid" style="--grid-cols: <?php echo htmlspecialchars($settings['browse_cols'] ?? 4); ?>; --thumb-width: <?php echo $thumb_px; ?>px;">
                <?php if ($images): ?>
                    <?php foreach ($images as $img): ?>
                        <div class="thumb-container cropped-item">
                            <?php
                                $link = BASE_URL . htmlspecialchars($img['img_slug']);
                                $full_img_path = ltrim($img['img_file'], '/');
                                $filename = basename($full_img_path);
                                $folder = str_replace($filename, '', $full_img_path);

                                // Cropped mode uses a_ (aspect-preserved) thumbnails
                                $thumb_url = BASE_URL . $folder . 'thumbs/a_' . $filename;

                                // Orientation class constrains aspect ratio clamping via CSS
                                $orientation = (int)($img['img_orientation'] ?? 0);
                                $orient_class = 'orient-landscape';
                                if ($orientation === 1) $orient_class = 'orient-portrait';
                                elseif ($orientation === 2) $orient_class = 'orient-square';
                            ?>
                            <a href="<?php echo $link; ?>" class="thumb-link <?php echo $orient_class; ?>" title="<?php echo htmlspecialchars($img['img_title']); ?>">
                                <img src="<?php echo $thumb_url; ?>" alt="<?php echo htmlspecialchars($img['img_title']); ?>" loading="lazy">
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($active_filter_count > 0): ?>
                    <div class="empty-sector-msg">NOTHING CLEARS ALL THOSE HURDLES.<br><a href="archive.php">[ EASE UP ]</a></div>
                <?php else: ?>
                    <div class="empty-sector-msg">NO TRANSMISSIONS RECORDED IN THIS SECTOR.</div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <!-- Square layout — Classic 1:1 center-cropped grid (default) -->
            <div id="browse-grid" class="square-grid" style="--grid-cols: <?php echo htmlspecialchars($settings['browse_cols'] ?? 4); ?>; --thumb-width: <?php echo $thumb_px; ?>px;">
                <?php if ($images): ?>
                    <?php foreach ($images as $img): ?>
                        <div class="thumb-container">
                            <?php
                                $link = BASE_URL . htmlspecialchars($img['img_slug']);
                                $full_img_path = ltrim($img['img_file'], '/');
                                $filename = basename($full_img_path);
                                $folder = str_replace($filename, '', $full_img_path);

                                // Square mode uses t_ (square-cropped) thumbnails
                                $thumb_url = BASE_URL . $folder . 'thumbs/t_' . $filename;
                            ?>
                            <a href="<?php echo $link; ?>" class="thumb-link" title="<?php echo htmlspecialchars($img['img_title']); ?>">
                                <img src="<?php echo $thumb_url; ?>" alt="<?php echo htmlspecialchars($img['img_title']); ?>" loading="lazy">
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($active_filter_count > 0): ?>
                    <div class="empty-sector-msg">NOTHING CLEARS ALL THOSE HURDLES.<br><a href="archive.php">[ EASE UP ]</a></div>
                <?php else: ?>
                    <div class="empty-sector-msg">NO TRANSMISSIONS RECORDED IN THIS SECTOR.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php
            $footer_file = __DIR__ . '/' . $skin_path . '/skin-footer.php';
            if (file_exists($footer_file)) include $footer_file;
            ?>
        </div>
    </div>

    <?php if ($skin_show_archive_filter): ?>
    <script src="<?php echo BASE_URL; ?>assets/js/ss-engine-archive-filter.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>
    <?php endif; ?>
    <?php include __DIR__ . '/core/footer-scripts.php'; ?>


</body>
</html>
<?php // ===== SNAPSMACK EOF =====
