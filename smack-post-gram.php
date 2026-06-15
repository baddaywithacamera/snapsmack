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

    $title        = trim($_POST['title']        ?? 'Untitled');
    $desc         = trim($_POST['desc']         ?? '');
    $status       = $_POST['img_status']        ?? 'published';
    $post_type    = $_POST['post_type']         ?? 'single';
    $pano_rows    = max(1, min(3, (int)($_POST['panorama_rows'] ?? 1)));
    $allow_cmt    = (int)($_POST['allow_comments']  ?? 1);
    $allow_dl     = (int)($_POST['allow_download']  ?? 0);
    $dl_url       = trim($_POST['download_url'] ?? '');
    $manual_tags     = trim($_POST['tags'] ?? '');

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

        $file_ext  = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $slug_base = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title));
        $slug_base = preg_replace('/-{2,}/', '-', $slug_base);
        $slug_base = trim($slug_base, '-') ?: 'untitled';

        $img_slug = $files_count > 1
            ? $slug_base . '-' . ($i + 1) . '-' . time()
            : $slug_base . '-' . time();

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
            $title, $img_slug, $db_path, $desc,
            $status, $post_date, $auto_orient, $orig_w, $orig_h,
            $allow_cmt, $allow_dl, $dl_url,
            $db_thumb_square, $db_thumb_aspect, $db_checksum, $palette_json,
        ]);

        $processed_images[] = [
            'image_id'      => (int)$pdo->lastInsertId(),
            'sort_position' => $i,
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

        $post_slug = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)) . '-' . time();
        $post_slug = preg_replace('/-{2,}/', '-', $post_slug);

        $post_stmt = $pdo->prepare("
            INSERT INTO snap_posts
                (title, slug, description, post_type, status, created_at,
                 allow_comments, allow_download, download_url, panorama_rows,
                 post_img_size_pct, post_border_px, post_border_color,
                 post_bg_color, post_shadow)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 100, 0, '#000000', '#ffffff', 0)
        ");
        $post_stmt->execute([
            $title, $post_slug, $desc, $post_type, $status, $post_date,
            $allow_cmt, $allow_dl, $dl_url, $pano_rows,
        ]);
        $post_id = (int)$pdo->lastInsertId();

        // Pivot rows — frame style defaults (resolved from skin settings at render time)
        $pi_stmt = $pdo->prepare("
            INSERT INTO snap_post_images
                (post_id, image_id, sort_position, is_cover,
                 img_size_pct, img_border_px, img_border_color, img_bg_color, img_shadow)
            VALUES (?, ?, ?, ?, 100, 0, '#000000', '#ffffff', 0)
        ");
        foreach ($processed_images as $pos => $img) {
            $pi_stmt->execute([$post_id, $img['image_id'], $pos, ($pos === 0 ? 1 : 0)]);
            $pdo->prepare("UPDATE snap_images SET post_id = ? WHERE id = ?")
                ->execute([$post_id, $img['image_id']]);
        }

        // Tags (title + caption + manual tags field, applied to cover image)
        $cover_id   = $processed_images[0]['image_id'];
        $tag_source = $title . ' ' . $desc . ' ' . $manual_tags;
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

    <form id="gp-form" method="POST" enctype="multipart/form-data">

        <!-- =====================================================================
             SECTION 1: POST METADATA
             ===================================================================== -->
        <div class="box">
            <div class="post-layout-grid">

                <div class="post-col-left">
                    <div class="lens-input-wrapper">
                        <label>TITLE</label>
                        <input type="text" id="gp-title" name="title"
                               placeholder="Transmission Identifier..." required autofocus>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>POST TYPE</label>
                        <select id="gp-post-type" name="post_type" class="full-width-select">
                            <option value="single">Single Image</option>
                            <option value="carousel" selected>Carousel (up to 10 images)</option>
                            <option value="panorama">Panorama Split</option>
                        </select>
                        <p id="gp-type-hint" class="skin-desc-text" style="margin-top:6px;">
                            Multi-image post. Viewers swipe through up to 10 images.
                        </p>
                    </div>

                    <div id="gp-panorama-rows-row" class="lens-input-wrapper" style="display:none;">
                        <label>PANORAMA ROWS</label>
                        <select name="panorama_rows" class="full-width-select">
                            <option value="1">1 Row — 3 tiles (wide banner)</option>
                            <option value="2">2 Rows — 6 tiles</option>
                            <option value="3">3 Rows — 9 tiles (full grid takeover)</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper post-description-wrap">
                        <label>CAPTION</label>
                        <div class="sc-toolbar" data-target="desc">
                            <div class="sc-row">
                                <button type="button" class="sc-btn" data-action="bold" title="Bold">B</button>
                                <button type="button" class="sc-btn" data-action="italic" title="Italic">I</button>
                                <button type="button" class="sc-btn" data-action="link" title="Insert Link">LINK</button>
                                <button type="button" class="sc-btn sc-btn-preview" data-action="preview" title="Preview">PREVIEW</button>
                            </div>
                        </div>
                        <textarea id="desc" name="desc"
                                  placeholder="Caption. Blank lines become paragraph breaks."
                                  rows="4"></textarea>
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
<script src="assets/js/shortcode-toolbar.js"></script>
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
    const postTypeSelect = document.getElementById('gp-post-type');
    const typeHint     = document.getElementById('gp-type-hint');
    const panoRowsRow  = document.getElementById('gp-panorama-rows-row');

    let fileList = []; // [{file, objectUrl}]
    let dragSrc  = null;

    // Post type hint text
    const hints = {
        single:   'Single image. Only the first image will be used if multiple are added.',
        carousel: 'Multi-image post. Viewers swipe through up to 10 images.',
        panorama: 'Wide image split into grid tiles. Upload one wide image.',
    };

    postTypeSelect.addEventListener('change', () => {
        typeHint.textContent = hints[postTypeSelect.value] || '';
        panoRowsRow.style.display = postTypeSelect.value === 'panorama' ? '' : 'none';
    });

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
            fileList.push({ file: f, objectUrl: URL.createObjectURL(f) });
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

            el.innerHTML =
                '<div class="cp-thumb-wrap">' +
                    (idx === 0 ? '<span class="cp-cover-badge">COVER</span>' : '') +
                    '<span class="cp-pos-badge">' + (idx + 1) + '</span>' +
                    '<img class="cp-thumb" src="' + item.objectUrl + '" alt="">' +
                    '<button type="button" class="cp-remove-btn" data-idx="' + idx + '">✕</button>' +
                '</div>' +
                '<div class="cp-item-label">' + escHtml(item.file.name) + '</div>';

            el.querySelector('.cp-remove-btn').addEventListener('click', e => {
                e.stopPropagation();
                removeFile(parseInt(e.currentTarget.dataset.idx));
            });

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
        });
    }

    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Form submit — rebuild file input in correct order then XHR
    form.addEventListener('submit', e => {
        e.preventDefault();
        if (fileList.length === 0) return;

        errorDiv.style.display = 'none';
        submitBtn.disabled = true;
        progressWrap.style.display = '';
        progressBar.style.width = '0%';

        const data = new FormData(form);

        // Remove any stale img_files entries and re-add in strip order
        data.delete('img_files');
        fileList.forEach((item, pos) => {
            data.append('img_files[]', item.file);
            data.append('sort_order[]', pos);
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
