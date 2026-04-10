<?php
/**
 * SNAPSMACK - Archive page with multiple layout modes
 * Alpha v0.7.9c
 *
 * Displays all published images with support for square, cropped, and
 * masonry layouts. Handles category and album filtering via query parameters.
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
    $archive_layout_default = $settings['archive_layout'] ?? 'square';
    if ($archive_layout_default === 'none') {
        $base = rtrim($settings['site_url'] ?? '/', '/') . '/';
        header('Location: ' . $base);
        exit;
    }
    if (!in_array($archive_layout_default, ['square', 'cropped', 'masonry'])) {
        $archive_layout_default = 'square';
    }

    // Which modes the owner has offered to visitors.
    $available_raw    = $settings['archive_layouts_available'] ?? $archive_layout_default;
    $available_modes  = array_filter(
        array_map('trim', explode(',', $available_raw)),
        fn($m) => in_array($m, ['square', 'cropped', 'masonry'])
    );
    if (empty($available_modes)) $available_modes = [$archive_layout_default];
    $available_modes = array_values($available_modes);
    if (!in_array($archive_layout_default, $available_modes)) {
        $available_modes[] = $archive_layout_default;
    }

    // Accept visitor ?layout= override only if it's in the allowed set.
    $layout_override = $_GET['layout'] ?? '';
    $archive_layout  = (in_array($layout_override, $available_modes))
                       ? $layout_override
                       : $archive_layout_default;

    $offer_toggle = (count($available_modes) > 1);

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
    $thumb_px = $thumb_size_map[$archive_layout][$thumb_step] ?? $thumb_size_map['square']['m'];

    // Justified row target height for masonry layout
    $justified_row_height = (int)($settings['justified_row_height'] ?? 180);

    // --- FILTER PARAMETERS ---
    // Extract category or album ID from query string
    $cat_filter    = isset($_GET['cat']) ? (int)$_GET['cat'] : null;
    $album_filter  = isset($_GET['album']) ? (int)$_GET['album'] : null;
    $search_query  = trim($_GET['q'] ?? '');
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
    } elseif ($date_filter) {
        // Calendar day browse — all posts published on a specific date.
        $where_clauses[] = "DATE(i.img_date) = ?";
        $params[] = $date_filter;
    } elseif ($cat_filter) {
        // Direct category browse — show images for this specific category
        // regardless of its show_in_archive flag (URL is direct, not surfaced in UI).
        $sql .= "INNER JOIN snap_image_cat_map c ON i.id = c.image_id ";
        $where_clauses[] = "c.cat_id = ?";
        $params[] = $cat_filter;
    } elseif ($album_filter) {
        $sql .= "INNER JOIN snap_image_album_map a ON i.id = a.image_id ";
        $where_clauses[] = "a.album_id = ?";
        $params[] = $album_filter;
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
    // Fetch all categories and albums for filter dropdowns
    // Only surface categories that are set to visible in the archive.
    $all_cats   = $pdo->query("SELECT * FROM snap_categories WHERE show_in_archive = 1 ORDER BY cat_name ASC")->fetchAll();
    $all_albums = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();

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
                    <a href="archive.php" class="<?php echo !$cat_filter && !$album_filter ? 'active' : 'inactive'; ?>">
                        [ SHOW ALL ]
                    </a>
                    <span class="sep">/</span>

                    <div class="filter-group">
                        <label class="dim">REGISTRY:</label>
                        <select onchange="location = this.value;">
                            <option value="archive.php">-- ALL CATEGORIES --</option>
                            <?php foreach($all_cats as $c): ?>
                                <option value="?cat=<?php echo $c['id']; ?>" <?php echo $cat_filter == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['cat_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <span class="sep">|</span>

                    <div class="filter-group">
                        <label class="dim">ALBUMS:</label>
                        <select onchange="location = this.value;">
                            <option value="archive.php">-- ALL ALBUMS --</option>
                            <?php foreach($all_albums as $a): ?>
                                <option value="?album=<?php echo $a['id']; ?>" <?php echo $album_filter == $a['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($a['album_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <span class="sep">|</span>

                    <div class="filter-group">
                        <form method="GET" action="archive.php" class="archive-search-form">
                            <input type="search" name="q" placeholder="Search or #tag…"
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
            $toggle_labels = ['square' => 'Grid', 'cropped' => 'Crop', 'masonry' => 'Flow'];
            foreach ($available_modes as $mode):
                $is_active = ($mode === $archive_layout);
                // Build URL preserving existing query params except layout.
                $qp = $_GET;
                $qp['layout'] = $mode;
                unset($qp['q']); // don't conflict with search — reset to all on layout switch
                unset($qp['cat']); unset($qp['album']); unset($qp['date']);
                $qs = http_build_query($qp);
            ?>
                <a href="archive.php?<?php echo $qs; ?>"
                   class="alt-btn<?php echo $is_active ? ' alt-btn--active' : ''; ?>"
                   data-layout="<?php echo htmlspecialchars($mode); ?>">
                    <?php echo $toggle_labels[$mode] ?? strtoupper($mode); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <script>
        // Persist layout preference in localStorage so it survives navigation.
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
        <?php endif; ?>

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

    <?php include __DIR__ . '/core/footer-scripts.php'; ?>


</body>
</html>
