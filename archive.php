<?php
/**
 * SnapSmack - Archive Browser
 * Version: PRO-5.0 - Multi-Layout Engine
 * Supports three display modes driven by skin manifest:
 *   - square:  400x400 center-cropped grid (t_ prefix)
 *   - cropped: max 3:2 / 2:3 aspect, center-cropped (a_ prefix)
 *   - masonry: full native aspect, Pinterest-style columns (a_ prefix)
 * MASTER DIRECTIVE: Full file return. Standardized junctions & scope safety.
 */

// 1. Error Reporting (Safety Valve)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 2. Bootstrap Environment
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/parser.php';

// INITIALIZE SCOPE (Prevents Skin/Footer Crashes)
$settings = [];
$site_name = 'ISWA.CA';
$active_skin = 'smackdown';

try {
    $snapsmack = new SnapSmack($pdo);

    // Fetch Global Settings
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // THE PENCIL: Define BASE_URL (Force Trailing Slash)
    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? 'https://iswa.ca/';
        define('BASE_URL', rtrim($db_url, '/') . '/'); 
    }

    $active_skin = $settings['active_skin'] ?? 'smackdown';
    $site_name = $settings['site_name'] ?? $site_name;

    // --- ARCHIVE LAYOUT MODE ---
    // Resolved from skin manifest via settings. Falls back to 'square'.
    $archive_layout = $settings['archive_layout'] ?? 'square';
    if (!in_array($archive_layout, ['square', 'cropped', 'masonry'])) {
        $archive_layout = 'square';
    }

    // --- THUMB SIZE RESOLUTION ---
    // Abstract 5-step scale → layout-specific pixel values.
    // Cropped values are ~25% larger than square so visual weight matches.
    // Justified ignores this (uses flex-grow + row height instead).
    $thumb_size_map = [
        'square' => ['xs' => 120, 's' => 150, 'm' => 200, 'l' => 250, 'xl' => 300],
        'cropped' => ['xs' => 150, 's' => 190, 'm' => 250, 'l' => 310, 'xl' => 375],
    ];
    $thumb_step = $settings['thumb_size'] ?? 'm';
    // Backwards compat: if old pixel value, map to closest step
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

    // Justified row target height (skin-configurable, default 280)
    $justified_row_height = (int)($settings['justified_row_height'] ?? 280);

    // 4. Filter Logic (GET params)
    $cat_filter   = isset($_GET['cat']) ? (int)$_GET['cat'] : null;
    $album_filter = isset($_GET['album']) ? (int)$_GET['album'] : null;

    // 5. Build Query
    // Use PHP timezone-aware datetime instead of MySQL NOW()
    // to prevent timezone mismatch hiding recent posts
    $tz = $settings['timezone'] ?? 'America/Edmonton';
    date_default_timezone_set($tz);
    $now_local = date('Y-m-d H:i:s');

    $sql = "SELECT i.* FROM snap_images i ";
    $where_clauses = ["i.img_status = 'published'", "i.img_date <= ?"];
    $params = [$now_local];

    if ($cat_filter) {
        $sql .= "INNER JOIN snap_image_cat_map c ON i.id = c.image_id ";
        $where_clauses[] = "c.cat_id = ?";
        $params[] = $cat_filter;
    } elseif ($album_filter) {
        $sql .= "INNER JOIN snap_image_album_map a ON i.id = a.image_id ";
        $where_clauses[] = "a.album_id = ?";
        $params[] = $album_filter;
    }

    $sql .= " WHERE " . implode(" AND ", $where_clauses);
    $sql .= " ORDER BY i.img_date DESC, i.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $images = $stmt->fetchAll();

    // 6. Fetch Meta for Dropdowns
    $all_cats   = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
    $all_albums = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();

} catch (Exception $e) {
    die("<div style='background:#300;color:#f99;padding:20px;border:1px solid red;font-family:monospace;'><h3>ARCHIVE_TRANSMISSION_ERROR</h3>" . $e->getMessage() . "</div>");
}

$page_title = "Archive";
$skin_path  = 'skins/' . $active_skin;

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
                </div>
            </div>
        </div>

        <div id="scroll-stage">

            <?php if ($archive_layout === 'masonry'): ?>
            <!-- ============================================================
                 JUSTIFIED LAYOUT — Flickr-style row-fill, full aspect ratio
                 PHP groups images into rows using a reference width for
                 row-break decisions. Actual sizing is 100% CSS flexbox —
                 each item gets flex-grow equal to its aspect ratio so the
                 browser fills the row perfectly at any container width.
                 ============================================================ -->
            <?php
                // Target row height — used for row-break math only
                $target_row_h = (int)($settings['justified_row_height'] ?? 280);
                $gap          = (int)($settings['justified_gap'] ?? 4);

                // Reference width for row-break decisions.
                // This doesn't set the rendered width — CSS handles that.
                // We use main_canvas_width as an approximation; flex handles the rest.
                $ref_w = (int)($settings['main_canvas_width'] ?? 1280);
            ?>
            <div id="justified-grid" style="--justified-gap: <?php echo $gap; ?>px; --justified-row-height: <?php echo $target_row_h; ?>px;">
                <?php if ($images): ?>
                    <?php
                    // Build rows: accumulate images until estimated row is full
                    $rows = [];
                    $current_row = [];
                    $current_row_width = 0;

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
                    // Last partial row — marked so CSS doesn't over-stretch it
                    if (!empty($current_row)) {
                        $rows[] = ['images' => $current_row, 'full' => false];
                    }
                    ?>
                    <?php foreach ($rows as $row_data):
                        $row = $row_data['images'];
                        $is_full = $row_data['full'];
                        $row_class = 'justified-row' . (!$is_full ? ' justified-row-last' : '');
                    ?>
                        <div class="<?php echo $row_class; ?>">
                            <?php foreach ($row as $img): 
                                $link = BASE_URL . htmlspecialchars($img['img_slug']);
                                $full_img_path = ltrim($img['img_file'], '/');
                                $filename = basename($full_img_path);
                                $folder = str_replace($filename, '', $full_img_path);
                                $thumb_url = BASE_URL . $folder . 'thumbs/a_' . $filename;
                                // Aspect ratio * 100 for flex-grow (avoids sub-1 decimals)
                                $flex_grow = round($img['_aspect'] * 100);
                            ?>
                                <a href="<?php echo $link; ?>" class="justified-item" title="<?php echo htmlspecialchars($img['img_title']); ?>" style="flex-grow: <?php echo $flex_grow; ?>; aspect-ratio: <?php echo round($img['_aspect'], 4); ?>;">
                                    <img src="<?php echo $thumb_url; ?>" alt="<?php echo htmlspecialchars($img['img_title']); ?>" loading="lazy">
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-sector-msg">NO SIGNALS RECORDED IN THIS SECTOR.</div>
                <?php endif; ?>
            </div>

            <?php elseif ($archive_layout === 'cropped'): ?>
            <!-- ============================================================
                 CROPPED LAYOUT — Max 3:2 / 2:3 aspect, center-cropped
                 ============================================================ -->
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
                                // CSS handles the 3:2 max crop via object-fit
                                $thumb_url = BASE_URL . $folder . 'thumbs/a_' . $filename;

                                // Determine orientation class for aspect-ratio clamping
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
                    <div class="empty-sector-msg">NO SIGNALS RECORDED IN THIS SECTOR.</div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <!-- ============================================================
                 SQUARE LAYOUT — Classic 1:1 center-cropped grid (default)
                 ============================================================ -->
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
                    <div class="empty-sector-msg">NO SIGNALS RECORDED IN THIS SECTOR.</div>
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
