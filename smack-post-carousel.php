<?php
/**
 * SNAPSMACK - Carousel Post Composer
 * Alpha v0.7.4
 *
 * Multi-image posting page. Invoked automatically when the active skin's
 * manifest declares 'post_page' => 'carousel'. Also accessible directly
 * at smack-post-carousel.php for testing.
 *
 * Accepts 1–20 images per post. Creates a snap_posts record (single,
 * carousel, or panorama), processes each image through the standard
 * pipeline (EXIF, resize, thumbs, checksum, palette), and inserts
 * snap_post_images pivot rows in the user-specified sort order.
 *
 * Panorama splitting (server-side GD slice) is stubbed — the UI captures
 * the post type and panorama_rows value, but the actual tile-slicing runs
 * in a follow-up build once the snap_posts migration is live on production.
 *
 * Requires: snap_posts, snap_post_images, snap_post_cat_map,
 *           snap_post_album_map tables (migrate-posts.sql).
 */

require_once 'core/auth.php';
require_once 'core/palette-extract.php';
require_once 'core/snap-tags.php';

set_time_limit(600);
ini_set('memory_limit', '512M');

$settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$settings      = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Frame customisation level from the active skin. Defaults to per_grid (no
// per-post or per-image controls rendered) if setting is not present.
$customize_level = $settings['tg_customize_level'] ?? 'per_grid';

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// =============================================================================
// POST HANDLER
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['img_files'])) {

    $title        = trim($_POST['title'] ?? 'Untitled Transmission');
    $desc         = trim($_POST['desc']  ?? '');
    $status       = $_POST['img_status']   ?? 'published';
    $post_type    = $_POST['post_type']    ?? 'single';
    $pano_rows    = max(1, min(3, (int)($_POST['panorama_rows'] ?? 1)));
    $allow_cmt    = (int)($_POST['allow_comments'] ?? 1);
    $allow_dl     = (int)($_POST['allow_download']  ?? 0);
    $dl_url       = trim($_POST['download_url'] ?? '');
    $selected_cats   = $_POST['cat_ids']   ?? [];
    $selected_albums = $_POST['album_ids'] ?? [];

    $raw_date   = $_POST['img_date'] ?? '';
    $post_date  = !empty($raw_date) ? str_replace('T', ' ', $raw_date) : date('Y-m-d H:i:s');

    // Sort order from JS: parallel array of original file indices in desired order.
    // Since JS re-appends img_files[] in strip order, PHP receives them already sorted.
    // sort_order[] is a confirmation; we trust the file array order.
    $sort_order  = $_POST['sort_order'] ?? [];
    $exif_manual = $_POST['exif']       ?? [];

    // --- FRAME STYLE ---
    // per_carousel: one style set for the whole post.
    $post_style_size   = max(75, min(100, (int)($_POST['post_img_size_pct'] ?? 100)));
    $post_style_bpx    = max(0,  min(20,  (int)($_POST['post_border_px']    ?? 0)));
    $post_style_bc     = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['post_border_color'] ?? '')
                             ? $_POST['post_border_color'] : '#000000';
    $post_style_bg     = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['post_bg_color'] ?? '')
                             ? $_POST['post_bg_color'] : '#ffffff';
    $post_style_shadow = max(0, min(3, (int)($_POST['post_shadow'] ?? 0)));

    // per_image: parallel arrays indexed by file position.
    $per_img_sizes   = $_POST['img_size_pct']     ?? [];
    $per_img_bpx     = $_POST['img_border_px']    ?? [];
    $per_img_bc      = $_POST['img_border_color'] ?? [];
    $per_img_bg      = $_POST['img_bg_color']     ?? [];
    $per_img_shadow  = $_POST['img_shadow']       ?? [];

    // --- IMAGE SETTINGS ---
    $max_w  = (int)($settings['max_width_landscape']  ?? 2500);
    $max_h  = (int)($settings['max_height_portrait']  ?? 1850);
    $jpeg_q = (int)($settings['jpeg_quality']         ?? 85);

    // --- PROCESS EACH FILE ---
    $processed_images = [];
    $errors           = [];

    $files_count = count($_FILES['img_files']['tmp_name']);

    for ($i = 0; $i < $files_count; $i++) {
        if ($_FILES['img_files']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = 'File ' . ($i + 1) . ': upload error code ' . $_FILES['img_files']['error'][$i];
            continue;
        }

        $tmp_name = $_FILES['img_files']['tmp_name'][$i];
        $orig_name = $_FILES['img_files']['name'][$i];
        $mime     = mime_content_type($tmp_name);

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
            $errors[] = 'File ' . ($i + 1) . ': unsupported type ' . $mime;
            continue;
        }

        $file_ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $slug_base = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title));

        // For multi-image posts, append image index to slug for uniqueness.
        $img_slug  = $files_count > 1
            ? $slug_base . '-' . ($i + 1) . '-' . time()
            : $slug_base . '-' . time();

        $rel_dir  = 'img_uploads/' . date('Y') . '/' . date('m');
        $full_dir = __DIR__ . '/' . $rel_dir;
        $thumb_dir_full = $full_dir . '/thumbs';

        if (!is_dir($full_dir))       mkdir($full_dir,       0755, true);
        if (!is_dir($thumb_dir_full)) mkdir($thumb_dir_full, 0755, true);

        $new_file_name = $img_slug . '.' . $file_ext;
        $target_path   = $full_dir . '/' . $new_file_name;
        $db_path       = $rel_dir  . '/' . $new_file_name;

        if (!move_uploaded_file($tmp_name, $target_path)) {
            $errors[] = 'File ' . ($i + 1) . ': failed to move uploaded file.';
            continue;
        }

        // --- EXIF EXTRACTION ---
        $camera = $lens = $focal = $iso = $aperture = $shutter = '';
        $flash  = 'No';

        if (in_array($file_ext, ['jpg', 'jpeg'])) {
            $exif_data = @exif_read_data($target_path);
            if ($exif_data) {
                $camera   = $exif_data['Model']                     ?? '';
                $focal    = $exif_data['FocalLength']               ?? '';
                $iso      = $exif_data['ISOSpeedRatings']           ?? '';
                $aperture = $exif_data['COMPUTED']['ApertureFNumber'] ?? '';
                $shutter  = $exif_data['ExposureTime']              ?? '';
                if (isset($exif_data['Flash'])) {
                    $flash = ($exif_data['Flash'] & 1) ? 'Yes' : 'No';
                }
            }
        }

        $override = $exif_manual[$i] ?? [];
        $exif_json = json_encode([
            'camera'   => !empty($override['camera'])   ? strtoupper(trim($override['camera']))   : strtoupper($camera),
            'lens'     => !empty($override['lens'])     ? trim($override['lens'])                  : $lens,
            'focal'    => !empty($override['focal'])    ? trim($override['focal'])                 : $focal,
            'film'     => !empty($override['film'])     ? trim($override['film'])                  : '',
            'iso'      => !empty($override['iso'])      ? trim($override['iso'])                   : $iso,
            'aperture' => !empty($override['aperture']) ? trim($override['aperture'])              : $aperture,
            'shutter'  => !empty($override['shutter'])  ? trim($override['shutter'])              : $shutter,
            'flash'    => !empty($override['flash'])    ? $override['flash']                       : $flash,
        ]);

        // --- IMAGE PROCESSING ---
        list($orig_w, $orig_h) = getimagesize($target_path);

        $src = null;
        if ($mime === 'image/jpeg') $src = imagecreatefromjpeg($target_path);
        elseif ($mime === 'image/png')  $src = imagecreatefrompng($target_path);
        elseif ($mime === 'image/webp') $src = imagecreatefromwebp($target_path);

        $db_thumb_square = null;
        $db_thumb_aspect = null;
        $db_checksum     = null;
        $palette_json    = null;

        if ($src) {
            // Orientation correction
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif_orient = @exif_read_data($target_path, 'IFD0');
                $orientation = $exif_orient ? ($exif_orient['Orientation'] ?? 1) : 1;
                if ($orientation == 3) $src = imagerotate($src, 180, 0);
                elseif ($orientation == 6) $src = imagerotate($src, -90, 0);
                elseif ($orientation == 8) $src = imagerotate($src, 90, 0);
                imagejpeg($src, $target_path, $jpeg_q);
                imagedestroy($src);
                $src = imagecreatefromjpeg($target_path);
            }

            $orig_w = imagesx($src);
            $orig_h = imagesy($src);

            // Conditional resize
            $d_w = $orig_w;
            $d_h = $orig_h;
            $needs_resize = false;
            if ($orig_w >= $orig_h && $orig_w > $max_w) {
                $d_w = $max_w; $d_h = round($orig_h * ($max_w / $orig_w)); $needs_resize = true;
            } elseif ($orig_h > $orig_w && $orig_h > $max_h) {
                $d_h = $max_h; $d_w = round($orig_w * ($max_h / $orig_h)); $needs_resize = true;
            }

            if ($needs_resize) {
                $d_img = imagecreatetruecolor($d_w, $d_h);
                if ($mime === 'image/png' || $mime === 'image/webp') {
                    imagealphablending($d_img, false);
                    imagesavealpha($d_img, true);
                }
                imagecopyresampled($d_img, $src, 0, 0, 0, 0, $d_w, $d_h, $orig_w, $orig_h);
                if ($mime === 'image/jpeg') imagejpeg($d_img, $target_path, $jpeg_q);
                elseif ($mime === 'image/png') imagepng($d_img, $target_path, 8);
                else imagewebp($d_img, $target_path, $jpeg_q);
                imagedestroy($d_img);
                imagedestroy($src);
                if ($mime === 'image/jpeg') $src = imagecreatefromjpeg($target_path);
                elseif ($mime === 'image/png') $src = imagecreatefrompng($target_path);
                else $src = imagecreatefromwebp($target_path);
                $orig_w = $d_w; $orig_h = $d_h;
            }

            // Square thumbnail (t_)
            $sq_size = 400;
            $sq_thumb = imagecreatetruecolor($sq_size, $sq_size);
            $min_dim  = min($orig_w, $orig_h);
            $off_x    = ($orig_w - $min_dim) / 2;
            $off_y    = ($orig_h - $min_dim) / 2;
            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($sq_thumb, false); imagesavealpha($sq_thumb, true);
            }
            imagecopyresampled($sq_thumb, $src, 0, 0, $off_x, $off_y, $sq_size, $sq_size, $min_dim, $min_dim);
            $t_path = $thumb_dir_full . '/t_' . $new_file_name;
            if ($mime === 'image/jpeg') imagejpeg($sq_thumb, $t_path, 82);
            elseif ($mime === 'image/png') imagepng($sq_thumb, $t_path, 8);
            else imagewebp($sq_thumb, $t_path, 78);
            imagedestroy($sq_thumb);
            $db_thumb_square = $rel_dir . '/thumbs/t_' . $new_file_name;

            // Aspect thumbnail (a_)
            $al = 400;
            if ($orig_w >= $orig_h) { $a_w = $al; $a_h = round($orig_h * ($al / $orig_w)); }
            else                    { $a_h = $al; $a_w = round($orig_w * ($al / $orig_h)); }
            if ($orig_w < $al && $orig_h < $al) { $a_w = $orig_w; $a_h = $orig_h; }
            $a_thumb = imagecreatetruecolor($a_w, $a_h);
            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($a_thumb, false); imagesavealpha($a_thumb, true);
            }
            imagecopyresampled($a_thumb, $src, 0, 0, 0, 0, $a_w, $a_h, $orig_w, $orig_h);
            $a_path = $thumb_dir_full . '/a_' . $new_file_name;
            if ($mime === 'image/jpeg') imagejpeg($a_thumb, $a_path, 82);
            elseif ($mime === 'image/png') imagepng($a_thumb, $a_path, 8);
            else imagewebp($a_thumb, $a_path, 78);
            imagedestroy($a_thumb);
            $db_thumb_aspect = $rel_dir . '/thumbs/a_' . $new_file_name;

            imagedestroy($src);

            $db_checksum = hash_file('sha256', $target_path);
            $palette     = snapsmack_extract_palette($target_path, 5);
            $palette_json = !empty($palette) ? json_encode(['palette' => $palette]) : null;
        }

        // Orientation class
        $auto_orient = 0;
        if ($orig_w == $orig_h)   $auto_orient = 2;
        elseif ($orig_h > $orig_w) $auto_orient = 1;

        // Insert into snap_images
        $img_stmt = $pdo->prepare("
            INSERT INTO snap_images (
                img_title, img_slug, img_file, img_description, img_film, img_exif,
                img_status, img_date, img_orientation, img_width, img_height,
                allow_comments, allow_download, download_url,
                img_thumb_square, img_thumb_aspect, img_checksum, img_display_options
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $img_stmt->execute([
            $title, $img_slug, $db_path, $desc,
            $override['film'] ?? '',
            $exif_json, $status, $post_date, $auto_orient,
            $orig_w, $orig_h, $allow_cmt, $allow_dl, $dl_url,
            $db_thumb_square, $db_thumb_aspect, $db_checksum, $palette_json
        ]);

        $processed_images[] = [
            'image_id'      => (int)$pdo->lastInsertId(),
            'sort_position' => $i,
        ];

        // Clean up GD memory between images
        gc_collect_cycles();
    }

    if (empty($processed_images)) {
        $err_msg = 'TRANSMISSION_FAILURE: no images processed.';
        if (!empty($errors)) $err_msg .= ' ' . implode(' ', $errors);
        if ($is_ajax) { echo $err_msg; exit; }
        $msg = $err_msg;
    } else {

        // Determine final post_type: downgrade to 'single' if only one image ended up processed.
        if (count($processed_images) === 1) $post_type = 'single';

        // Build post slug from title
        $post_slug = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)) . '-' . time();

        // Create snap_posts record
        $post_stmt = $pdo->prepare("
            INSERT INTO snap_posts
                (title, slug, description, post_type, status, created_at,
                 allow_comments, allow_download, download_url, panorama_rows,
                 post_img_size_pct, post_border_px, post_border_color,
                 post_bg_color, post_shadow)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $post_stmt->execute([
            $title, $post_slug, $desc, $post_type, $status, $post_date,
            $allow_cmt, $allow_dl, $dl_url, $pano_rows,
            $post_style_size, $post_style_bpx, $post_style_bc,
            $post_style_bg,   $post_style_shadow,
        ]);
        $post_id = (int)$pdo->lastInsertId();

        // Insert pivot rows and update snap_images.post_id
        foreach ($processed_images as $pos => $img) {
            $is_cover = ($pos === 0) ? 1 : 0;

            // Resolve per-image style for this pivot row.
            if ($customize_level === 'per_image') {
                $pi_sz  = max(75, min(100, (int)($per_img_sizes[$pos]  ?? 100)));
                $pi_bpx = max(0,  min(20,  (int)($per_img_bpx[$pos]   ?? 0)));
                $pi_bc  = preg_match('/^#[0-9a-fA-F]{6}$/', $per_img_bc[$pos]  ?? '')
                              ? $per_img_bc[$pos]  : '#000000';
                $pi_bg  = preg_match('/^#[0-9a-fA-F]{6}$/', $per_img_bg[$pos]  ?? '')
                              ? $per_img_bg[$pos]  : '#ffffff';
                $pi_sh  = max(0, min(3, (int)($per_img_shadow[$pos]   ?? 0)));
            } elseif ($customize_level === 'per_carousel') {
                $pi_sz  = $post_style_size;   $pi_bpx = $post_style_bpx;
                $pi_bc  = $post_style_bc;     $pi_bg  = $post_style_bg;
                $pi_sh  = $post_style_shadow;
            } else {
                // per_grid: store defaults; layout resolves from skin settings at render time.
                $pi_sz = 100; $pi_bpx = 0; $pi_bc = '#000000'; $pi_bg = '#ffffff'; $pi_sh = 0;
            }

            $pdo->prepare("
                INSERT INTO snap_post_images
                    (post_id, image_id, sort_position, is_cover,
                     img_size_pct, img_border_px, img_border_color, img_bg_color, img_shadow)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$post_id, $img['image_id'], $pos, $is_cover,
                         $pi_sz, $pi_bpx, $pi_bc, $pi_bg, $pi_sh]);

            $pdo->prepare("UPDATE snap_images SET post_id = ? WHERE id = ?")
                ->execute([$post_id, $img['image_id']]);
        }

        // Sync hashtags from description to snap_tags + snap_image_tags (cover image)
        snap_sync_tags($pdo, $processed_images[0]['image_id'], $desc ?? '');

        // Category mappings (image-level for backward compat + post-level)
        $cover_image_id = $processed_images[0]['image_id'];
        foreach ($selected_cats as $cid) {
            $cid = (int)$cid;
            $pdo->prepare("INSERT INTO snap_image_cat_map (image_id, cat_id) VALUES (?, ?)")
                ->execute([$cover_image_id, $cid]);
            $pdo->prepare("INSERT IGNORE INTO snap_post_cat_map (post_id, cat_id) VALUES (?, ?)")
                ->execute([$post_id, $cid]);
        }

        // Album mappings
        foreach ($selected_albums as $aid) {
            $aid = (int)$aid;
            $pdo->prepare("INSERT INTO snap_image_album_map (image_id, album_id) VALUES (?, ?)")
                ->execute([$cover_image_id, $aid]);
            $pdo->prepare("INSERT IGNORE INTO snap_post_album_map (post_id, album_id) VALUES (?, ?)")
                ->execute([$post_id, $aid]);
        }

        if ($is_ajax) { echo 'success'; exit; }
        header('Location: smack-manage.php?msg=CAROUSEL_LIVE');
        exit;
    }
}

// =============================================================================
// PAGE RENDER
// =============================================================================

$all_cats   = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
$all_albums = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();

$page_title = "New Carousel Post";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>INITIALIZE NEW TRANSMISSION</h2>
        <span class="dim" style="font-size:12px; letter-spacing:1px;">CAROUSEL / MULTI-IMAGE MODE</span>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="notice notice-error"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <div id="cp-error" class="notice notice-error" style="display:none;"></div>

    <form id="cp-form" method="POST" enctype="multipart/form-data"
          data-customize-level="<?php echo htmlspecialchars($customize_level); ?>">

        <!-- =================================================================
             SECTION 1: POST METADATA
             ================================================================= -->
        <div class="box">
            <div class="post-layout-grid">

                <div class="post-col-left">
                    <div class="lens-input-wrapper">
                        <label>POST TITLE</label>
                        <input type="text" id="cp-title" name="title"
                               placeholder="Transmission Identifier..." required autofocus>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>POST TYPE</label>
                        <select id="cp-post-type" name="post_type" class="full-width-select">
                            <option value="single">Single Image</option>
                            <option value="carousel" selected>Carousel (up to 20 images)</option>
                            <option value="panorama">Panorama Split</option>
                        </select>
                        <p id="cp-type-hint" class="skin-desc-text" style="margin-top:6px;">
                            Multi-image post. Viewers swipe through up to 20 images.
                        </p>
                    </div>

                    <div id="cp-panorama-rows-row" class="lens-input-wrapper" style="display:none;">
                        <label>PANORAMA ROWS</label>
                        <select name="panorama_rows" class="full-width-select">
                            <option value="1">1 Row — 3 tiles (wide banner)</option>
                            <option value="2">2 Rows — 6 tiles</option>
                            <option value="3">3 Rows — 9 tiles (full grid takeover)</option>
                        </select>
                    </div>

                    <div class="post-layout-grid">
                        <div class="flex-1">
                            <div class="lens-input-wrapper">
                                <label>REGISTRY (CATEGORIES)</label>
                                <div class="custom-multiselect">
                                    <div class="select-box" onclick="toggleDropdown('cat-items')">
                                        <span id="cat-label">Select Categories...</span><span class="arrow">▼</span>
                                    </div>
                                    <div class="dropdown-content" id="cat-items">
                                        <div class="dropdown-search-wrapper">
                                            <input type="text" placeholder="Filter..."
                                                   onkeyup="filterRegistry(this, 'cat-list-box')">
                                        </div>
                                        <div class="dropdown-list" id="cat-list-box">
                                            <?php foreach ($all_cats as $c): ?>
                                                <label class="multi-cat-item">
                                                    <input type="checkbox" name="cat_ids[]"
                                                           value="<?php echo $c['id']; ?>"
                                                           onchange="updateLabel('cat')">
                                                    <span class="cat-name-text">
                                                        <?php echo htmlspecialchars($c['cat_name']); ?>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="flex-1">
                            <div class="lens-input-wrapper">
                                <label>MISSIONS (ALBUMS)</label>
                                <div class="custom-multiselect">
                                    <div class="select-box" onclick="toggleDropdown('album-items')">
                                        <span id="album-label">Select Albums...</span><span class="arrow">▼</span>
                                    </div>
                                    <div class="dropdown-content" id="album-items">
                                        <div class="dropdown-search-wrapper">
                                            <input type="text" placeholder="Filter..."
                                                   onkeyup="filterRegistry(this, 'album-list-box')">
                                        </div>
                                        <div class="dropdown-list" id="album-list-box">
                                            <?php foreach ($all_albums as $a): ?>
                                                <label class="multi-cat-item">
                                                    <input type="checkbox" name="album_ids[]"
                                                           value="<?php echo $a['id']; ?>"
                                                           onchange="updateLabel('album')">
                                                    <span class="cat-name-text">
                                                        <?php echo htmlspecialchars($a['album_name']); ?>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="lens-input-wrapper post-description-wrap">
                        <label>CAPTION / STORY</label>
                        <div class="sc-toolbar" data-target="desc">
                            <div class="sc-row">
                                <button type="button" class="sc-btn" data-action="bold">B</button>
                                <button type="button" class="sc-btn" data-action="italic">I</button>
                                <button type="button" class="sc-btn" data-action="underline">U</button>
                                <button type="button" class="sc-btn" data-action="link">LINK</button>
                                <span class="sc-sep"></span>
                                <button type="button" class="sc-btn" data-action="h2">H2</button>
                                <button type="button" class="sc-btn" data-action="h3">H3</button>
                                <button type="button" class="sc-btn" data-action="blockquote">BQ</button>
                                <button type="button" class="sc-btn" data-action="hr">HR</button>
                                <span class="sc-sep"></span>
                                <button type="button" class="sc-btn" data-action="ul">UL</button>
                                <button type="button" class="sc-btn" data-action="ol">OL</button>
                            </div>
                        </div>
                        <textarea id="desc" name="desc"
                                  placeholder="Applies to the whole post. Individual image notes go in EXIF panels."></textarea>
                    </div>
                </div>

                <div class="post-col-right">
                    <div class="lens-input-wrapper">
                        <label>PUBLICATION STATUS</label>
                        <select name="img_status" class="full-width-select">
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>INTERNAL TIMESTAMP</label>
                        <input type="datetime-local" name="img_date" class="full-width-select edit-timestamp"
                               onclick="this.showPicker()"
                               value="<?php echo date('Y-m-d\TH:i'); ?>">
                    </div>

                    <div class="lens-input-wrapper">
                        <label>ALLOW PUBLIC SIGNALS?</label>
                        <select name="allow_comments" class="full-width-select">
                            <option value="1">ENABLED</option>
                            <option value="0">DISABLED</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>ALLOW DOWNLOAD?</label>
                        <select name="allow_download" class="full-width-select">
                            <option value="0">DISABLED</option>
                            <option value="1">ENABLED</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>DOWNLOAD URL (EXTERNAL)</label>
                        <input type="text" name="download_url"
                               placeholder="Google Drive, Dropbox, etc. Leave blank for local.">
                    </div>
                </div>
            </div>
        </div>

        <!-- =================================================================
             SECTION 1b: PER-CAROUSEL FRAME STYLE
             Only shown when The Grid skin is active with customize_level = per_carousel.
             Per-image style controls appear in each strip item's FRAME panel (JS-built).
             ================================================================= -->
        <?php if ($customize_level === 'per_carousel'): ?>
        <div class="box mt-30">
            <h3 style="margin:0 0 6px;">IMAGE FRAME STYLE</h3>
            <p class="skin-desc-text" style="margin-bottom:16px;">
                This style applies to every image in the post. Adjust per image by switching to Per Image mode in Skin Admin.
            </p>
            <div class="post-layout-grid" style="gap:16px;">
                <div class="flex-1">
                    <div class="lens-input-wrapper">
                        <label>IMAGE SIZE</label>
                        <select name="post_img_size_pct" class="full-width-select">
                            <option value="100">100% — edge to edge</option>
                            <option value="95">95%</option>
                            <option value="90">90%</option>
                            <option value="85">85%</option>
                            <option value="80">80%</option>
                            <option value="75">75%</option>
                        </select>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>BORDER THICKNESS</label>
                        <select name="post_border_px" class="full-width-select">
                            <option value="0">None</option>
                            <option value="1">1px</option>
                            <option value="2">2px</option>
                            <option value="3">3px</option>
                            <option value="5">5px</option>
                            <option value="8">8px</option>
                            <option value="10">10px</option>
                            <option value="15">15px</option>
                            <option value="20">20px</option>
                        </select>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>DROP SHADOW</label>
                        <select name="post_shadow" class="full-width-select">
                            <option value="0">None</option>
                            <option value="1">Soft</option>
                            <option value="2">Medium</option>
                            <option value="3">Heavy</option>
                        </select>
                    </div>
                </div>
                <div class="flex-1">
                    <div class="lens-input-wrapper">
                        <label>BORDER COLOUR</label>
                        <input type="color" name="post_border_color" value="#000000"
                               style="width:100%; height:38px; padding:2px 4px;">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>BACKGROUND COLOUR</label>
                        <input type="color" name="post_bg_color" value="#ffffff"
                               style="width:100%; height:38px; padding:2px 4px;">
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- =================================================================
             SECTION 2: IMAGE DROP ZONE + PREVIEW STRIP
             ================================================================= -->
        <div class="box mt-30">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                <h3 style="margin:0;">SOURCE ASSETS</h3>
                <span id="cp-file-count" class="dim" style="font-size:12px; letter-spacing:1px;">
                    0 / 10 images
                </span>
            </div>

            <div id="cp-drop-zone" class="cp-drop-zone">
                <input type="file" id="cp-file-input" accept="image/jpeg,image/png,image/webp"
                       multiple style="display:none;">
                <div class="cp-drop-icon">⊕</div>
                <p class="cp-drop-label">DROP IMAGES HERE or click to browse</p>
                <p class="cp-drop-sub dim">JPG · PNG · WebP &nbsp;·&nbsp; Up to 20 images per post</p>
            </div>

            <div id="cp-strip" class="cp-strip"></div>

            <p class="skin-desc-text" style="margin-top:12px;">
                Drag thumbnails to reorder. First image is the cover shown on the grid.
            </p>
        </div>

        <!-- =================================================================
             SECTION 3: PROGRESS + SUBMIT
             ================================================================= -->
        <div id="cp-progress-wrap" class="progress-container" style="display:none;">
            <div id="cp-progress-bar" class="progress-bar"></div>
        </div>

        <div class="form-action-row">
            <button type="submit" id="cp-submit" class="master-update-btn" disabled>
                COMMIT TRANSMISSION
            </button>
        </div>

    </form>
</div>

<?php
// CSS for the carousel-specific UI elements (drop zone, strip, EXIF panels)
// Injected as a <style> block here because these styles are admin-only and
// specific to this page. Per CLAUDE.md, inline styles are only forbidden in
// skin files — admin tooling may use them for page-specific overrides.
?>
<style>
/* --- Drop Zone --- */
.cp-drop-zone {
    border: 2px dashed var(--border-color, #ccc);
    border-radius: 4px;
    padding: 40px 20px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
    user-select: none;
}
.cp-drop-zone.is-over {
    border-color: var(--accent-color, #555);
    background: rgba(0,0,0,0.03);
}
.cp-drop-icon { font-size: 2rem; opacity: 0.4; margin-bottom: 8px; }
.cp-drop-label { font-weight: bold; letter-spacing: 2px; font-size: 0.85rem; margin: 0 0 4px; }
.cp-drop-sub { font-size: 0.75rem; margin: 0; }

/* --- Preview Strip --- */
.cp-strip {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-top: 20px;
}
.cp-strip-item {
    width: 140px;
    flex-shrink: 0;
    position: relative;
    cursor: grab;
}
.cp-strip-item.is-dragging { opacity: 0.4; }
.cp-strip-item.drag-over { outline: 2px dashed var(--accent-color, #555); }
.cp-thumb-wrap {
    position: relative;
    width: 140px;
    height: 140px;
    background: #eee;
    overflow: hidden;
}
.cp-thumb {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
}
.cp-cover-badge {
    position: absolute; top: 6px; left: 6px;
    background: rgba(0,0,0,0.7); color: #fff;
    font-size: 9px; letter-spacing: 1px;
    padding: 2px 5px;
}
.cp-pos-badge {
    position: absolute; top: 6px; right: 6px;
    background: rgba(0,0,0,0.5); color: #fff;
    font-size: 10px; width: 18px; height: 18px;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
}
.cp-remove-btn {
    position: absolute; bottom: 6px; right: 6px;
    background: rgba(0,0,0,0.6); color: #fff;
    border: none; cursor: pointer;
    width: 22px; height: 22px; border-radius: 50%;
    font-size: 10px; line-height: 1;
    display: flex; align-items: center; justify-content: center;
}
.cp-remove-btn:hover { background: rgba(180,0,0,0.8); }
.cp-item-label {
    font-size: 10px; color: #888;
    margin-top: 4px; word-break: break-all;
    max-height: 28px; overflow: hidden;
}
.cp-exif-toggle {
    margin-top: 6px;
    background: transparent;
    border: 1px dashed #bbb;
    padding: 3px 8px;
    font-size: 10px;
    letter-spacing: 1px;
    cursor: pointer;
    width: 100%;
}
.cp-exif-toggle:hover { border-color: #888; }
.cp-exif-panel { margin-top: 8px; }
.cp-exif-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 4px;
}
.cp-exif-grid .lens-input-wrapper { margin-bottom: 0; }
.cp-exif-grid input, .cp-exif-grid select {
    font-size: 11px !important;
    padding: 4px 6px !important;
}
.cp-exif-grid label { font-size: 9px !important; letter-spacing: 1px; }
</style>

<script src="assets/js/ss-engine-admin-ui.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/shortcode-toolbar.js"></script>
<script src="assets/js/ss-engine-carousel-post.js?v=<?php echo time(); ?>"></script>
<?php include 'core/admin-footer.php'; ?>
