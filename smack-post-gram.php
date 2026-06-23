<?php
/**
 * SNAPSMACK - GramOfSmack Post Composer
 *
 * Posting page for GramOfSmack (mode 2.0 / The Grid) installs.
 * Invoked automatically when the active skin's manifest declares
 * 'post_page' => 'gram'. Also accessible directly for testing.
 *
 * Supports single-image, carousel (up to 10 images), and panorama posts.
 * Writes to snap_posts + snap_post_images (same schema as smack-post-carousel.php).
 * Intentionally stripped of EXIF panels and frame style controls — those
 * are skin-admin concerns, not per-post concerns in GramOfSmack.
 *
 * GRAMOFSMACK uses this file. SmackOneOut uses smack-post-solo.php.
 * SmackTalk uses smack-post-long.php.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once 'core/auth-smack.php';
require_once 'core/palette-extract.php';
require_once 'core/snap-tags.php';

set_time_limit(600);
ini_set('memory_limit', '512M');

$settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$settings      = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// =============================================================================
// POST HANDLER
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['img_files'])) {

    $desc         = trim($_POST['desc']         ?? '');
    $status       = $_POST['img_status']        ?? 'published';
    $post_type    = $_POST['post_type']         ?? 'single';
    $pano_rows    = max(1, min(3, (int)($_POST['panorama_rows'] ?? 1)));
    $allow_cmt    = (int)($_POST['allow_comments']  ?? 1);
    $allow_dl     = (int)($_POST['allow_download']  ?? 0);
    $dl_url       = trim($_POST['download_url'] ?? '');
    $manual_tags  = trim($_POST['tags'] ?? '');

    // GramOfSmack has no per-post title (classic IG). Slug is timestamp-based,
    // matching the importer's ig-<ts> convention; caption + tags drive discovery.
    $slug_base = 'ig-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);

    // Per-image styling arrays (parallel to img_files[], in strip order). Each
    // image is decided individually: either square-cropped (fill) or fit inside
    // the 1:1 panel with an optional matte / border / shadow.
    $st_crop   = $_POST['crop_mode']        ?? [];
    $st_size   = $_POST['img_size_pct']     ?? [];
    $st_bpx    = $_POST['img_border_px']    ?? [];
    $st_bcol   = $_POST['img_border_color'] ?? [];
    $st_bg     = $_POST['img_bg_color']     ?? [];
    $st_shadow = $_POST['img_shadow']       ?? [];

    $hexok = function ($v, $def) {
        return (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) ? $v : $def;
    };
    $img_style_at = function ($i) use ($st_crop,$st_size,$st_bpx,$st_bcol,$st_bg,$st_shadow,$hexok) {
        $crop = (($st_crop[$i] ?? 'fit') === 'fill') ? 'fill' : 'fit';
        return [
            'crop'   => $crop,
            'size'   => max(10, min(100, (int)($st_size[$i]   ?? 100))),
            'bpx'    => max(0,  min(50,  (int)($st_bpx[$i]    ?? 0))),
            'bcol'   => $hexok($st_bcol[$i] ?? '', '#000000'),
            'bg'     => $hexok($st_bg[$i]   ?? '', '#ffffff'),
            'shadow' => max(0,  min(3,   (int)($st_shadow[$i] ?? 0))),
        ];
    };

    // Defensive: ensure the per-image crop column exists (canonical adds it on
    // update; this catches an install caught mid-migration). Pure structural add.
    try {
        $pdo->exec("ALTER TABLE snap_post_images
                    ADD COLUMN IF NOT EXISTS img_crop_mode
                    ENUM('fit','fill') NOT NULL DEFAULT 'fit' AFTER img_shadow");
    } catch (Throwable $e) { /* already present, or engine lacks IF NOT EXISTS */ }

    $raw_date  = $_POST['img_date'] ?? '';
    $post_date = !empty($raw_date) ? str_replace('T', ' ', $raw_date) : date('Y-m-d H:i:s');

    // Image settings from site config
    $max_w  = (int)($settings['max_width_landscape']  ?? 2500);
    $max_h  = (int)($settings['max_height_portrait']  ?? 1850);
    $jpeg_q = (int)($settings['jpeg_quality']         ?? 85);

    // Process each uploaded file
    $processed_images = [];
    $errors           = [];
    $files_count      = count($_FILES['img_files']['tmp_name']);

    // Cap at 10 images
    $files_count = min($files_count, 10);

    for ($i = 0; $i < $files_count; $i++) {
        if ($_FILES['img_files']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = 'File ' . ($i + 1) . ': upload error code ' . $_FILES['img_files']['error'][$i];
            continue;
        }

        $tmp_name  = $_FILES['img_files']['tmp_name'][$i];
        $orig_name = $_FILES['img_files']['name'][$i];
        $mime      = mime_content_type($tmp_name);

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
            $errors[] = 'File ' . ($i + 1) . ': unsupported type ' . $mime;
            continue;
        }

        $file_ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $img_slug = $files_count > 1 ? $slug_base . '-' . ($i + 1) : $slug_base;

        $rel_dir        = 'img_uploads/' . date('Y') . '/' . date('m');
        $full_dir       = __DIR__ . '/' . $rel_dir;
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
            $sq_size  = 400;
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

            $db_checksum  = hash_file('sha256', $target_path);
            $palette      = snapsmack_extract_palette($target_path, 5);
            $palette_json = !empty($palette) ? json_encode(['palette' => $palette]) : null;
        }

        // Orientation class
        $auto_orient = 0;
        if ($orig_w == $orig_h)    $auto_orient = 2;
        elseif ($orig_h > $orig_w) $auto_orient = 1;

        // Insert snap_images record (no EXIF data in gram mode)
        $img_stmt = $pdo->prepare("
            INSERT INTO snap_images (
                img_title, img_slug, img_file, img_description,
                img_status, img_date, img_orientation, img_width, img_height,
                allow_comments, allow_download, download_url,
                img_thumb_square, img_thumb_aspect, img_checksum, img_display_options
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $img_stmt->execute([
            '', $img_slug, $db_path, $desc,
            $status, $post_date, $auto_orient, $orig_w, $orig_h,
            $allow_cmt, $allow_dl, $dl_url,
            $db_thumb_square, $db_thumb_aspect, $db_checksum, $palette_json,
        ]);

        $processed_images[] = [
            'image_id'      => (int)$pdo->lastInsertId(),
            'sort_position' => $i,
            'style'         => $img_style_at($i),
        ];

        gc_collect_cycles();
    }

    if (empty($processed_images)) {
        $err_msg = 'TRANSMISSION_FAILURE: no images processed.';
        if (!empty($errors)) $err_msg .= ' ' . implode(' ', $errors);
        if ($is_ajax) { echo $err_msg; exit; }
        $msg = $err_msg;
    } else {
        // Downgrade to single if only one image
        if (count($processed_images) === 1) $post_type = 'single';

        $post_slug = $slug_base;

        $post_stmt = $pdo->prepare("
            INSERT INTO snap_posts
                (title, slug, description, post_type, status, created_at,
                 allow_comments, allow_download, download_url, panorama_rows,
                 post_img_size_pct, post_border_px, post_border_color,
                 post_bg_color, post_shadow)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 100, 0, '#000000', '#ffffff', 0)
        ");
        $post_stmt->execute([
            '', $post_slug, $desc, $post_type, $status, $post_date,
            $allow_cmt, $allow_dl, $dl_url, $pano_rows,
        ]);
        $post_id = (int)$pdo->lastInsertId();

        // Pivot rows — per-image styling decided individually in the composer.
        // Square-crop (fill) ignores the matte/border/shadow; fit keeps them.
        $pi_stmt = $pdo->prepare("
            INSERT INTO snap_post_images
                (post_id, image_id, sort_position, is_cover,
                 img_size_pct, img_border_px, img_border_color, img_bg_color,
                 img_shadow, img_crop_mode)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $upd_post = $pdo->prepare("UPDATE snap_images SET post_id = ? WHERE id = ?");
        foreach ($processed_images as $pos => $img) {
            $s = $img['style'];
            if ($s['crop'] === 'fill') {
                $pi_stmt->execute([$post_id, $img['image_id'], $pos, ($pos === 0 ? 1 : 0),
                                   100, 0, '#000000', '#ffffff', 0, 'fill']);
            } else {
                $pi_stmt->execute([$post_id, $img['image_id'], $pos, ($pos === 0 ? 1 : 0),
                                   $s['size'], $s['bpx'], $s['bcol'], $s['bg'], $s['shadow'], 'fit']);
            }
            $upd_post->execute([$post_id, $img['image_id']]);
        }

        // Tags from caption + manual tags (no title in gram), applied to cover image.
        $cover_id   = $processed_images[0]['image_id'];
        $tag_source = $desc . ' ' . $manual_tags;
        snap_sync_tags($pdo, $cover_id, $tag_source);

        // New content is live — flush the page cache so it appears immediately.
        require_once __DIR__ . '/core/page-cache.php';
        page_cache_purge_all();

        if ($is_ajax) { echo 'success'; exit; }
        header('Location: smack-manage.php?msg=TRANSMISSION_LIVE');
        exit;
    }
}

// =============================================================================
// PAGE RENDER
// =============================================================================

$page_title = "New Post";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>NEW TRANSMISSION</h2>
        <div class="header-actions">
            <span class="dim" style="font-size:12px; letter-spacing:1px;">GRAMOFSMACK</span>
        </div>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="notice notice-error"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <div id="gp-error" class="notice notice-error" style="display:none;"></div>

    <style>
    /* Per-image styling panel under each strip thumbnail. */
    .gp-style { margin-top:8px; padding:8px;
        background:rgba(255,255,255,0.03);
        border:1px solid var(--border-color,#333); border-radius:4px;
        font-size:11px; line-height:1.2; }
    .gp-sqr { display:flex; align-items:center; gap:6px; cursor:pointer;
        font-weight:600; letter-spacing:.4px; margin-bottom:6px; }
    .gp-sqr input { cursor:pointer; }
    .gp-fit { display:flex; flex-direction:column; gap:5px; }
    .gp-ctl { display:flex; align-items:center; gap:6px; }
    .gp-ctl > span { flex:0 0 44px; opacity:.7; }
    .gp-ctl input[type=range] { flex:1; min-width:0; }
    .gp-ctl > b { flex:0 0 36px; text-align:right; font-family:monospace;
        font-weight:400; opacity:.85; }
    .gp-ctl-row { gap:12px; }
    .gp-swatch { display:flex; align-items:center; gap:5px; cursor:pointer; opacity:.8; }
    .gp-swatch input[type=color] { width:24px; height:20px; border:none;
        background:none; padding:0; cursor:pointer; }
    .gp-shadow { flex:1; min-width:0; }

    /* ---------------------------------------------------------------------
       UPLOAD STRIP — drop zone + preview thumbnails. These .cp-* classes were
       referenced by the markup/JS but defined in no stylesheet, so dropped
       images rendered at full natural size and overflowed the page. Scoped
       here (page-local) so it can't affect any other admin screen.
       --------------------------------------------------------------------- */
    .cp-drop-zone { display:flex; flex-direction:column; align-items:center;
        justify-content:center; gap:6px; padding:34px 20px; cursor:pointer;
        text-align:center; border:2px dashed var(--border-color,#3a3a3a);
        border-radius:8px; background:rgba(255,255,255,0.02);
        transition:border-color .15s, background .15s; }
    .cp-drop-zone:hover { border-color:var(--accent,#b6ff1a);
        background:rgba(255,255,255,0.04); }
    .cp-drop-zone.is-over { border-color:var(--accent,#b6ff1a);
        background:rgba(182,255,26,0.06); }
    .cp-drop-icon { font-size:34px; line-height:1; opacity:.55; }
    .cp-drop-label { margin:0; font-weight:600; letter-spacing:.5px; }
    .cp-drop-sub { margin:0; font-size:12px; }

    .cp-strip { display:flex; flex-wrap:wrap; gap:14px; margin-top:16px; }
    .cp-strip:empty { margin-top:0; }
    .cp-strip-item { width:170px; flex:0 0 170px; padding:8px;
        background:rgba(255,255,255,0.03);
        border:1px solid var(--border-color,#333); border-radius:6px;
        cursor:grab; transition:opacity .15s, border-color .15s, transform .1s; }
    .cp-strip-item.is-dragging { opacity:.4; cursor:grabbing; }
    .cp-strip-item.drag-over { border-color:var(--accent,#b6ff1a);
        transform:translateY(-2px); }

    .cp-thumb-wrap { position:relative; width:100%; aspect-ratio:1/1;
        border-radius:4px; overflow:hidden; background:#111; }
    .cp-thumb { width:100%; height:100%; object-fit:cover; display:block; }

    .cp-cover-badge { position:absolute; top:5px; left:5px; z-index:2;
        font-size:9px; font-weight:700; letter-spacing:.6px; padding:2px 6px;
        border-radius:3px; background:var(--accent,#b6ff1a); color:#111; }
    .cp-pos-badge { position:absolute; bottom:5px; right:5px; z-index:2;
        font-size:10px; font-weight:600; min-width:18px; text-align:center;
        padding:1px 5px; border-radius:10px; background:rgba(0,0,0,.7);
        color:#fff; }
    .cp-remove-btn { position:absolute; top:4px; right:4px; z-index:3;
        width:22px; height:22px; padding:0; line-height:20px; text-align:center;
        font-size:12px; border:none; border-radius:50%; cursor:pointer;
        background:rgba(0,0,0,.65); color:#fff; opacity:0; transition:opacity .15s; }
    .cp-thumb-wrap:hover .cp-remove-btn { opacity:1; }
    .cp-remove-btn:hover { background:#c0392b; }

    .cp-item-label { margin-top:6px; font-size:11px; opacity:.7;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    </style>

    <form id="gp-form" method="POST" enctype="multipart/form-data">

        <!-- =====================================================================
             SECTION 1: POST METADATA
             ===================================================================== -->
        <div class="box">
            <div class="post-layout-grid">

                <div class="post-col-left">
                    <!-- All gram posts are carousel posts (spec: The Grid posting UI).
                         A single image auto-downgrades to 'single' in the handler.
                         Panorama splitting lives in the dedicated slicer tool, not
                         here — it was confusing inside the composer. -->
                    <input type="hidden" name="post_type" value="carousel">

                    <div class="lens-input-wrapper post-description-wrap">
                        <label>CAPTION</label>
                        <textarea id="desc" name="desc" autofocus
                                  placeholder="Write a caption… blank lines become paragraph breaks. #hashtags become tags."
                                  rows="8"></textarea>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>TAGS</label>
                        <input type="text" name="tags" placeholder="#street #toronto — space-separated">
                    </div>
                </div>

                <div class="post-col-right">
                    <div class="lens-input-wrapper">
                        <label>STATUS</label>
                        <select name="img_status" class="full-width-select">
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>TIMESTAMP</label>
                        <input type="datetime-local" name="img_date" class="full-width-select edit-timestamp"
                               onclick="this.showPicker()"
                               value="<?php echo date('Y-m-d\TH:i'); ?>">
                    </div>

                    <div class="lens-input-wrapper">
                        <label>ALLOW SIGNALS?</label>
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
                               placeholder="Google Drive, Dropbox, etc.">
                    </div>
                </div>
            </div>
        </div>

        <!-- =====================================================================
             SECTION 2: IMAGE DROP ZONE + PREVIEW STRIP
             ===================================================================== -->
        <div class="box mt-30">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                <h3 style="margin:0;">IMAGES</h3>
                <span id="gp-file-count" class="dim" style="font-size:12px; letter-spacing:1px;">
                    0 / 10 images
                </span>
            </div>

            <div id="gp-drop-zone" class="cp-drop-zone">
                <input type="file" id="gp-file-input" accept="image/jpeg,image/png,image/webp"
                       multiple style="display:none;">
                <div class="cp-drop-icon">⊕</div>
                <p class="cp-drop-label">DROP IMAGES HERE or click to browse</p>
                <p class="cp-drop-sub dim">JPG · PNG · WebP &nbsp;·&nbsp; Up to 10 images per post</p>
            </div>

            <div id="gp-strip" class="cp-strip"></div>

            <p class="skin-desc-text" style="margin-top:12px;">
                Drag thumbnails to reorder. First image is the cover shown on the grid.
            </p>
        </div>

        <!-- =====================================================================
             SECTION 3: PROGRESS + SUBMIT
             ===================================================================== -->
        <div id="gp-progress-wrap" class="progress-container" style="display:none;">
            <div id="gp-progress-bar" class="progress-bar"></div>
        </div>

        <div class="form-action-row">
            <button type="submit" id="gp-submit" class="master-update-btn" disabled>
                SMACK THAT @#$% UP!
            </button>
        </div>

    </form>
</div>

<script src="assets/js/ss-engine-admin-ui.js?v=<?php echo time(); ?>"></script>
<?php
// INSTANT CAMERA only — load the Scan Align engine and pass it the tile aspect,
// so the gram composer can straighten + crop-to-fill each print before publish.
// Gated to instant-camera: every other GRAMOFSMACK skin's poster is untouched.
if (($settings['active_skin'] ?? '') === 'instant-camera') {
    $_ic_fmt = $settings['instant-camera__ic_format'] ?? 'instax_square';
    $_ic_map = ['polaroid'=>'79/97','sx70'=>'1/1','go'=>'47/60','instax_mini'=>'62/46','instax_wide'=>'99/62','instax_square'=>'1/1'];
    if ($_ic_fmt === 'custom') {
        $_ic_raw = trim($settings['instant-camera__ic_custom_ratio'] ?? '1:1');
        $_ic_asp = (preg_match('/^\s*(\d{1,4})\s*[:\/xX]\s*(\d{1,4})\s*$/', $_ic_raw, $_m) && (int)$_m[2] > 0)
                 ? ((int)$_m[1] . '/' . (int)$_m[2]) : '1/1';
    } else {
        $_ic_asp = $_ic_map[$_ic_fmt] ?? '1/1';
    }
    echo '<script>window.__IC_SCAN=' . json_encode(['aspect' => $_ic_asp]) . ';</script>' . "\n";
    echo '<script src="' . BASE_URL . 'assets/js/ss-engine-scan-align.js?v=' . SNAPSMACK_VERSION_SHORT . '"></script>' . "\n";
}
?>
<script>
// GramOfSmack post composer — drop zone, preview strip, drag-reorder, submit.
// Mirrors ss-engine-carousel-post.js but scoped to #gp-* IDs, no EXIF panels,
// 10-image cap instead of 20.
(function () {
    'use strict';

    const MAX_IMAGES   = 10;
    const dropZone     = document.getElementById('gp-drop-zone');
    const fileInput    = document.getElementById('gp-file-input');
    const strip        = document.getElementById('gp-strip');
    const fileCount    = document.getElementById('gp-file-count');
    const submitBtn    = document.getElementById('gp-submit');
    const progressWrap = document.getElementById('gp-progress-wrap');
    const progressBar  = document.getElementById('gp-progress-bar');
    const errorDiv     = document.getElementById('gp-error');
    const form         = document.getElementById('gp-form');

    let fileList = []; // [{file, objectUrl}]
    let dragSrc  = null;

    // Drop zone click
    dropZone.addEventListener('click', () => fileInput.click());

    // Drag-over styling
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('is-over'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('is-over'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('is-over');
        addFiles(Array.from(e.dataTransfer.files));
    });

    fileInput.addEventListener('change', () => {
        addFiles(Array.from(fileInput.files));
        fileInput.value = '';
    });

    function addFiles(files) {
        const allowed = ['image/jpeg', 'image/png', 'image/webp'];
        files.forEach(f => {
            if (!allowed.includes(f.type)) return;
            if (fileList.length >= MAX_IMAGES) return;
            // Per-image styling defaults (decided individually in the strip).
            fileList.push({
                file: f, objectUrl: URL.createObjectURL(f),
                crop: 'fit', size: 100, bpx: 0,
                bcol: '#000000', bg: '#ffffff', shadow: 0,
            });
        });
        renderStrip();
    }

    function removeFile(idx) {
        URL.revokeObjectURL(fileList[idx].objectUrl);
        fileList.splice(idx, 1);
        renderStrip();
    }

    function renderStrip() {
        strip.innerHTML = '';
        fileCount.textContent = fileList.length + ' / ' + MAX_IMAGES + ' images';
        submitBtn.disabled = fileList.length === 0;

        fileList.forEach((item, idx) => {
            const el = document.createElement('div');
            el.className = 'cp-strip-item';
            el.draggable  = true;
            el.dataset.idx = idx;

            const shadowOpts = [0,1,2,3].map(n =>
                '<option value="' + n + '"' + (item.shadow === n ? ' selected' : '') + '>' +
                ['None','Soft','Medium','Heavy'][n] + '</option>').join('');

            el.innerHTML =
                '<div class="cp-thumb-wrap">' +
                    (idx === 0 ? '<span class="cp-cover-badge">COVER</span>' : '') +
                    '<span class="cp-pos-badge">' + (idx + 1) + '</span>' +
                    '<img class="cp-thumb" src="' + item.objectUrl + '" alt="">' +
                    '<button type="button" class="cp-remove-btn" data-idx="' + idx + '">✕</button>' +
                '</div>' +
                '<div class="cp-item-label">' + escHtml(item.file.name) + '</div>' +
                '<div class="gp-style">' +
                    '<label class="gp-sqr"><input type="checkbox" class="gp-crop"' +
                        (item.crop === 'fill' ? ' checked' : '') + '> Square crop (IG)</label>' +
                    '<div class="gp-fit"' + (item.crop === 'fill' ? ' style="display:none;"' : '') + '>' +
                        '<div class="gp-ctl"><span>Size</span>' +
                            '<input type="range" class="gp-size" min="10" max="100" value="' + item.size + '">' +
                            '<b class="gp-size-v">' + item.size + '%</b></div>' +
                        '<div class="gp-ctl"><span>Border</span>' +
                            '<input type="range" class="gp-bpx" min="0" max="50" value="' + item.bpx + '">' +
                            '<b class="gp-bpx-v">' + item.bpx + 'px</b></div>' +
                        '<div class="gp-ctl gp-ctl-row">' +
                            '<label class="gp-swatch"><input type="color" class="gp-bcol" value="' + item.bcol + '"> Border</label>' +
                            '<label class="gp-swatch"><input type="color" class="gp-bg" value="' + item.bg + '"> Matte</label>' +
                        '</div>' +
                        '<div class="gp-ctl"><span>Shadow</span>' +
                            '<select class="gp-shadow">' + shadowOpts + '</select></div>' +
                    '</div>' +
                '</div>';

            el.querySelector('.cp-remove-btn').addEventListener('click', e => {
                e.stopPropagation();
                removeFile(parseInt(e.currentTarget.dataset.idx));
            });

            // Per-image style wiring — updates the item in place (no re-render, so
            // focus/value survive). stopPropagation keeps clicks off drag-reorder.
            const styleEl = el.querySelector('.gp-style');
            const cropCb  = styleEl.querySelector('.gp-crop');
            const fitBox  = styleEl.querySelector('.gp-fit');
            styleEl.querySelectorAll('input, select, label').forEach(n => {
                n.addEventListener('click',     e => e.stopPropagation());
                n.addEventListener('mousedown', e => e.stopPropagation());
            });
            cropCb.addEventListener('change', () => {
                item.crop = cropCb.checked ? 'fill' : 'fit';
                fitBox.style.display = cropCb.checked ? 'none' : '';
            });
            const sizeR = styleEl.querySelector('.gp-size'),  sizeV = styleEl.querySelector('.gp-size-v');
            sizeR.addEventListener('input', () => { item.size = parseInt(sizeR.value); sizeV.textContent = item.size + '%'; });
            const bpxR = styleEl.querySelector('.gp-bpx'),    bpxV  = styleEl.querySelector('.gp-bpx-v');
            bpxR.addEventListener('input', () => { item.bpx = parseInt(bpxR.value); bpxV.textContent = item.bpx + 'px'; });
            styleEl.querySelector('.gp-bcol').addEventListener('input', e => { item.bcol = e.target.value; });
            styleEl.querySelector('.gp-bg').addEventListener('input',   e => { item.bg   = e.target.value; });
            styleEl.querySelector('.gp-shadow').addEventListener('change', e => { item.shadow = parseInt(e.target.value); });

            // Drag-reorder
            el.addEventListener('dragstart', e => {
                dragSrc = el;
                el.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            el.addEventListener('dragend', () => {
                el.classList.remove('is-dragging');
                strip.querySelectorAll('.cp-strip-item').forEach(i => i.classList.remove('drag-over'));
            });
            el.addEventListener('dragover', e => { e.preventDefault(); el.classList.add('drag-over'); });
            el.addEventListener('dragleave', () => el.classList.remove('drag-over'));
            el.addEventListener('drop', e => {
                e.preventDefault();
                el.classList.remove('drag-over');
                if (!dragSrc || dragSrc === el) return;
                const fromIdx = parseInt(dragSrc.dataset.idx);
                const toIdx   = parseInt(el.dataset.idx);
                const moved   = fileList.splice(fromIdx, 1)[0];
                fileList.splice(toIdx, 0, moved);
                renderStrip();
            });

            strip.appendChild(el);

            if (window.__IC_SCAN) addRotateControl(el, item);
        });
    }

    // INSTANT CAMERA: per-thumb straighten slider (±5°, 0.1°). Sets item.angle
    // and shows a quick rotated/zoomed preview; the true crop-to-fill bake runs
    // on submit via ScanAlign.bakeFile. Gated — only added when __IC_SCAN is set.
    function addRotateControl(el, item) {
        const wrap  = el.querySelector('.cp-thumb-wrap');
        const thumb = el.querySelector('.cp-thumb');
        if (!wrap || !thumb) return;
        wrap.style.overflow = 'hidden';
        const row = document.createElement('div');
        row.style.cssText = 'display:flex;align-items:center;gap:6px;margin-top:6px;';
        const s = document.createElement('input');
        s.type = 'range'; s.min = '-5'; s.max = '5'; s.step = '0.1';
        s.value = String(item.angle || 0); s.style.flex = '1'; s.title = 'Straighten ±5°';
        const lab = document.createElement('span');
        lab.style.cssText = 'font:11px/1 monospace;min-width:42px;text-align:right;opacity:.75;';
        lab.textContent = (item.angle || 0).toFixed(1) + '°';
        const applyPreview = () => { thumb.style.transform = 'scale(1.18) rotate(' + (item.angle || 0) + 'deg)'; };
        s.addEventListener('input', () => {
            item.angle = parseFloat(s.value) || 0;
            lab.textContent = item.angle.toFixed(1) + '°';
            applyPreview();
        });
        // Keep the slider from starting a thumbnail drag-reorder.
        s.addEventListener('click', e => e.stopPropagation());
        s.addEventListener('mousedown', e => e.stopPropagation());
        if (item.angle) applyPreview();
        row.appendChild(s); row.appendChild(lab);
        el.appendChild(row);
    }

    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Form submit — rebuild file input in correct order then XHR
    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (fileList.length === 0) return;

        errorDiv.style.display = 'none';
        submitBtn.disabled = true;
        progressWrap.style.display = '';
        progressBar.style.width = '0%';

        // INSTANT CAMERA scan-align: bake any per-image straighten into the
        // uploaded file. Gated to instant-camera + defensive — on any failure
        // the original file stands, so a post can never be blocked.
        if (window.__IC_SCAN && window.ScanAlign && ScanAlign.bakeFile) {
            try {
                await Promise.all(fileList.map(async (item) => {
                    if (item.angle) item.file = await ScanAlign.bakeFile(item.file, item.angle, window.__IC_SCAN.aspect);
                }));
            } catch (err) { /* leave originals */ }
        }

        const data = new FormData(form);

        // Remove any stale img_files entries and re-add in strip order
        data.delete('img_files');
        fileList.forEach((item, pos) => {
            data.append('img_files[]', item.file);
            data.append('sort_order[]', pos);
            data.append('crop_mode[]',        item.crop || 'fit');
            data.append('img_size_pct[]',     item.size   != null ? item.size   : 100);
            data.append('img_border_px[]',    item.bpx    != null ? item.bpx    : 0);
            data.append('img_border_color[]', item.bcol || '#000000');
            data.append('img_bg_color[]',     item.bg   || '#ffffff');
            data.append('img_shadow[]',       item.shadow != null ? item.shadow : 0);
        });

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action || window.location.href);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.addEventListener('progress', evt => {
            if (evt.lengthComputable) {
                progressBar.style.width = Math.round((evt.loaded / evt.total) * 100) + '%';
            }
        });

        xhr.addEventListener('load', () => {
            progressBar.style.width = '100%';
            if (xhr.responseText.trim() === 'success') {
                window.location.href = 'smack-manage.php?msg=TRANSMISSION_LIVE';
            } else {
                errorDiv.textContent = xhr.responseText || 'Transmission failed.';
                errorDiv.style.display = '';
                submitBtn.disabled = false;
                progressWrap.style.display = 'none';
            }
        });

        xhr.addEventListener('error', () => {
            errorDiv.textContent = 'Network error during upload.';
            errorDiv.style.display = '';
            submitBtn.disabled = false;
            progressWrap.style.display = 'none';
        });

        xhr.send(data);
    });

}());
</script>
<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
