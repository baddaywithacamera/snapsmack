<?php
/**
 * SNAPSMACK - Carousel Post Editor
 * Alpha v0.7.1
 *
 * Edit page for carousel and single posts created via the post-layer schema.
 * Invoked automatically when the active skin's manifest declares
 * 'edit_page' => 'carousel' and smack-edit.php receives a valid ?id=.
 *
 * Resolves the incoming image ID to its parent snap_posts record, then
 * renders a full edit form for: post metadata, image reordering, per-image
 * EXIF overrides, cover selection, image removal, and adding new images.
 *
 * POST operations:
 *   - Updates snap_posts (title, description, status, date, panorama_rows,
 *     allow_comments, allow_download, download_url)
 *   - Rebuilds snap_post_cat_map and snap_post_album_map
 *   - Reorders snap_post_images.sort_position from sort_order[] array
 *   - Promotes cover via is_cover flag on snap_post_images
 *   - Removes images from snap_post_images (files/rows kept in snap_images)
 *   - Processes and attaches new uploaded images (full pipeline)
 *   - Updates per-image EXIF in snap_images.img_exif
 *
 * Requires: snap_posts, snap_post_images tables (migrate-posts.sql).
 */

require_once 'core/auth.php';
require_once 'core/palette-extract.php';
require_once 'core/snap-tags.php';

// $id is already validated by smack-edit.php before this file is included.
// Resolve image ID → post ID.
$img_row = $pdo->prepare("SELECT post_id FROM snap_images WHERE id = ?");
$img_row->execute([$id]);
$post_id = (int)($img_row->fetchColumn() ?? 0);

if (!$post_id) {
    // Image has no post container — fall back silently to standard edit page.
    // This can happen for orphaned legacy images before migration, or if the
    // user navigates directly. Reset include guards and load the standard page.
    include __DIR__ . '/smack-edit.php';
    // smack-edit.php will re-run the routing hook; prevent infinite recursion
    // by letting execution fall through to the standard handler after the hook.
    return;
}

set_time_limit(600);
ini_set('memory_limit', '512M');

$settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$settings      = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$customize_level = $settings['tg_customize_level'] ?? 'per_grid';
$max_w  = (int)($settings['max_width_landscape']  ?? 2500);
$max_h  = (int)($settings['max_height_portrait']  ?? 1850);
$jpeg_q = (int)($settings['jpeg_quality']         ?? 85);

$msg  = '';
$errs = [];

// =============================================================================
// POST HANDLER
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title        = trim($_POST['title']          ?? 'Untitled Transmission');
    $desc         = trim($_POST['desc']            ?? '');
    $status       = $_POST['img_status']           ?? 'published';
    $pano_rows    = max(1, min(3, (int)($_POST['panorama_rows'] ?? 1)));
    $allow_cmt    = (int)($_POST['allow_comments'] ?? 1);
    $allow_dl     = (int)($_POST['allow_download'] ?? 0);
    $dl_url       = trim($_POST['download_url']    ?? '');
    $selected_cats   = $_POST['cat_ids']           ?? [];
    $selected_albums = $_POST['album_ids']         ?? [];
    $raw_date     = $_POST['img_date']             ?? '';
    $post_date    = !empty($raw_date) ? str_replace('T', ' ', $raw_date) : date('Y-m-d H:i:s');
    $sort_order   = $_POST['sort_order']           ?? [];      // array of image IDs in new order
    $cover_img_id = (int)($_POST['cover_image_id'] ?? 0);
    $remove_ids   = array_map('intval', $_POST['remove_image_ids'] ?? []);
    $exif_overrides = $_POST['exif']               ?? [];      // [image_id][field] = value

    // --- Frame style (per_carousel: one set for the whole post) ---
    $post_style_size   = max(75, min(100, (int)($_POST['post_img_size_pct'] ?? 100)));
    $post_style_bpx    = max(0,  min(20,  (int)($_POST['post_border_px']    ?? 0)));
    $post_style_bc     = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['post_border_color'] ?? '')
                             ? $_POST['post_border_color'] : '#000000';
    $post_style_bg     = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['post_bg_color'] ?? '')
                             ? $_POST['post_bg_color'] : '#ffffff';
    $post_style_shadow = max(0, min(3, (int)($_POST['post_shadow'] ?? 0)));

    // per_image: arrays keyed by image_id (existing) and position (new uploads).
    $pi_style_sizes  = $_POST['img_size_pct']     ?? [];   // [image_id => value]
    $pi_style_bpx    = $_POST['img_border_px']    ?? [];
    $pi_style_bc     = $_POST['img_border_color'] ?? [];
    $pi_style_bg     = $_POST['img_bg_color']     ?? [];
    $pi_style_shadow = $_POST['img_shadow']       ?? [];
    // New-image parallel style arrays (position-indexed, parallel to new_img_files[])
    $new_style_sizes  = $_POST['new_img_size_pct']     ?? [];
    $new_style_bpx    = $_POST['new_img_border_px']    ?? [];
    $new_style_bc     = $_POST['new_img_border_color'] ?? [];
    $new_style_bg     = $_POST['new_img_bg_color']     ?? [];
    $new_style_shadow = $_POST['new_img_shadow']       ?? [];

    // --- Update snap_posts ---
    $pdo->prepare("
        UPDATE snap_posts
        SET title = ?, description = ?, status = ?, created_at = ?,
            panorama_rows = ?, allow_comments = ?, allow_download = ?, download_url = ?,
            post_img_size_pct = ?, post_border_px = ?, post_border_color = ?,
            post_bg_color = ?, post_shadow = ?
        WHERE id = ?
    ")->execute([$title, $desc, $status, $post_date,
                 $pano_rows, $allow_cmt, $allow_dl, $dl_url,
                 $post_style_size, $post_style_bpx, $post_style_bc,
                 $post_style_bg,   $post_style_shadow,
                 $post_id]);

    // --- Rebuild category / album maps ---
    $pdo->prepare("DELETE FROM snap_post_cat_map WHERE post_id = ?")->execute([$post_id]);
    foreach ($selected_cats as $cid) {
        $pdo->prepare("INSERT IGNORE INTO snap_post_cat_map (post_id, cat_id) VALUES (?,?)")
            ->execute([$post_id, (int)$cid]);
    }
    $pdo->prepare("DELETE FROM snap_post_album_map WHERE post_id = ?")->execute([$post_id]);
    foreach ($selected_albums as $aid) {
        $pdo->prepare("INSERT IGNORE INTO snap_post_album_map (post_id, album_id) VALUES (?,?)")
            ->execute([$post_id, (int)$aid]);
    }

    // --- Remove images from post (unlink only; snap_images row is preserved) ---
    foreach ($remove_ids as $rm_id) {
        $pdo->prepare("DELETE FROM snap_post_images WHERE post_id = ? AND image_id = ?")
            ->execute([$post_id, $rm_id]);
        // Clear the FK on snap_images so the image appears as a standalone orphan
        $pdo->prepare("UPDATE snap_images SET post_id = NULL WHERE id = ?")
            ->execute([$rm_id]);
    }

    // --- Reorder: apply sort_order[] → sort_position ---
    // sort_order[] contains image IDs in the desired order, submitted by the JS engine.
    if (!empty($sort_order)) {
        foreach ($sort_order as $pos => $img_id) {
            $img_id   = (int)$img_id;
            $is_cover = ($img_id === $cover_img_id || ($pos === 0 && !$cover_img_id)) ? 1 : 0;
            $pdo->prepare("
                UPDATE snap_post_images
                SET sort_position = ?, is_cover = ?
                WHERE post_id = ? AND image_id = ?
            ")->execute([$pos, $is_cover, $post_id, $img_id]);
        }
    } elseif ($cover_img_id) {
        // No explicit sort order but cover changed: update is_cover flags
        $pdo->prepare("UPDATE snap_post_images SET is_cover = 0 WHERE post_id = ?")
            ->execute([$post_id]);
        $pdo->prepare("UPDATE snap_post_images SET is_cover = 1 WHERE post_id = ? AND image_id = ?")
            ->execute([$post_id, $cover_img_id]);
    }

    // --- Update per-image frame style on snap_post_images ---
    if ($customize_level === 'per_image') {
        foreach ($pi_style_sizes as $img_id => $sz) {
            $img_id = (int)$img_id;
            $sz     = max(75, min(100, (int)$sz));
            $bpx    = max(0,  min(20,  (int)($pi_style_bpx[$img_id]    ?? 0)));
            $bc     = preg_match('/^#[0-9a-fA-F]{6}$/', $pi_style_bc[$img_id]    ?? '')
                          ? $pi_style_bc[$img_id]    : '#000000';
            $bg     = preg_match('/^#[0-9a-fA-F]{6}$/', $pi_style_bg[$img_id]    ?? '')
                          ? $pi_style_bg[$img_id]    : '#ffffff';
            $sh     = max(0, min(3, (int)($pi_style_shadow[$img_id]    ?? 0)));
            $pdo->prepare("
                UPDATE snap_post_images
                SET img_size_pct = ?, img_border_px = ?, img_border_color = ?,
                    img_bg_color = ?, img_shadow = ?
                WHERE post_id = ? AND image_id = ?
            ")->execute([$sz, $bpx, $bc, $bg, $sh, $post_id, $img_id]);
        }
    }

    // --- Update per-image EXIF overrides ---
    foreach ($exif_overrides as $img_id => $fields) {
        $img_id = (int)$img_id;
        $existing_exif_raw = $pdo->prepare("SELECT img_exif FROM snap_images WHERE id = ?");
        $existing_exif_raw->execute([$img_id]);
        $existing_exif = json_decode($existing_exif_raw->fetchColumn() ?: '{}', true) ?: [];

        $merged = array_merge($existing_exif, [
            'camera'   => strtoupper(trim($fields['camera']   ?? ($existing_exif['camera']   ?? ''))),
            'lens'     => trim($fields['lens']     ?? ($existing_exif['lens']     ?? '')),
            'focal'    => trim($fields['focal']    ?? ($existing_exif['focal']    ?? '')),
            'film'     => trim($fields['film']     ?? ($existing_exif['film']     ?? '')),
            'iso'      => trim($fields['iso']      ?? ($existing_exif['iso']      ?? '')),
            'aperture' => trim($fields['aperture'] ?? ($existing_exif['aperture'] ?? '')),
            'shutter'  => trim($fields['shutter']  ?? ($existing_exif['shutter']  ?? '')),
            'flash'    => $fields['flash']         ?? ($existing_exif['flash']    ?? 'No'),
        ]);

        $pdo->prepare("UPDATE snap_images SET img_exif = ? WHERE id = ?")
            ->execute([json_encode($merged), $img_id]);
    }

    // --- Process new uploaded images (if any) ---
    if (!empty($_FILES['new_img_files']['tmp_name'])) {
        $files_count = count($_FILES['new_img_files']['tmp_name']);
        // Check how many images the post currently has after removes
        $current_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_post_images WHERE post_id = ?");
        $current_count_stmt->execute([$post_id]);
        $current_count = (int)$current_count_stmt->fetchColumn();

        // Get max existing sort_position so we append after it
        $max_pos_stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_position), -1) FROM snap_post_images WHERE post_id = ?");
        $max_pos_stmt->execute([$post_id]);
        $next_pos = (int)$max_pos_stmt->fetchColumn() + 1;

        for ($i = 0; $i < $files_count && $current_count < 20; $i++) {
            if ($_FILES['new_img_files']['error'][$i] !== UPLOAD_ERR_OK) continue;

            $tmp_name  = $_FILES['new_img_files']['tmp_name'][$i];
            $orig_name = $_FILES['new_img_files']['name'][$i];
            $mime      = mime_content_type($tmp_name);

            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) continue;

            $file_ext      = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $slug_base     = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title));
            $img_slug      = $slug_base . '-add-' . ($i + 1) . '-' . time();
            $rel_dir       = 'img_uploads/' . date('Y') . '/' . date('m');
            $full_dir      = __DIR__ . '/' . $rel_dir;
            $thumb_dir_full = $full_dir . '/thumbs';

            if (!is_dir($full_dir))       mkdir($full_dir,       0755, true);
            if (!is_dir($thumb_dir_full)) mkdir($thumb_dir_full, 0755, true);

            $new_file_name = $img_slug . '.' . $file_ext;
            $target_path   = $full_dir . '/' . $new_file_name;
            $db_path       = $rel_dir  . '/' . $new_file_name;

            if (!move_uploaded_file($tmp_name, $target_path)) continue;

            // EXIF extraction
            $camera = $lens = $focal = $iso = $aperture = $shutter = '';
            $flash  = 'No';
            if (in_array($file_ext, ['jpg', 'jpeg'])) {
                $exif_data = @exif_read_data($target_path);
                if ($exif_data) {
                    $camera   = $exif_data['Model']                      ?? '';
                    $focal    = $exif_data['FocalLength']                ?? '';
                    $iso      = $exif_data['ISOSpeedRatings']            ?? '';
                    $aperture = $exif_data['COMPUTED']['ApertureFNumber'] ?? '';
                    $shutter  = $exif_data['ExposureTime']               ?? '';
                    if (isset($exif_data['Flash'])) {
                        $flash = ($exif_data['Flash'] & 1) ? 'Yes' : 'No';
                    }
                }
            }
            $exif_json = json_encode([
                'camera' => strtoupper($camera), 'lens' => $lens, 'focal' => $focal,
                'film' => '', 'iso' => $iso, 'aperture' => $aperture,
                'shutter' => $shutter, 'flash' => $flash,
            ]);

            // Image processing (orientation + resize + thumbs)
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
                $orig_w = imagesx($src); $orig_h = imagesy($src);

                $d_w = $orig_w; $d_h = $orig_h; $needs_resize = false;
                if ($orig_w >= $orig_h && $orig_w > $max_w) { $d_w = $max_w; $d_h = round($orig_h * ($max_w / $orig_w)); $needs_resize = true; }
                elseif ($orig_h > $orig_w && $orig_h > $max_h) { $d_h = $max_h; $d_w = round($orig_w * ($max_h / $orig_h)); $needs_resize = true; }

                if ($needs_resize) {
                    $d_img = imagecreatetruecolor($d_w, $d_h);
                    if ($mime === 'image/png' || $mime === 'image/webp') { imagealphablending($d_img, false); imagesavealpha($d_img, true); }
                    imagecopyresampled($d_img, $src, 0, 0, 0, 0, $d_w, $d_h, $orig_w, $orig_h);
                    if ($mime === 'image/jpeg') imagejpeg($d_img, $target_path, $jpeg_q);
                    elseif ($mime === 'image/png') imagepng($d_img, $target_path, 8);
                    else imagewebp($d_img, $target_path, $jpeg_q);
                    imagedestroy($d_img); imagedestroy($src);
                    if ($mime === 'image/jpeg') $src = imagecreatefromjpeg($target_path);
                    elseif ($mime === 'image/png') $src = imagecreatefrompng($target_path);
                    else $src = imagecreatefromwebp($target_path);
                    $orig_w = $d_w; $orig_h = $d_h;
                }

                $sq_size = 400; $sq_thumb = imagecreatetruecolor($sq_size, $sq_size);
                $min_dim = min($orig_w, $orig_h); $off_x = ($orig_w - $min_dim) / 2; $off_y = ($orig_h - $min_dim) / 2;
                if ($mime === 'image/png' || $mime === 'image/webp') { imagealphablending($sq_thumb, false); imagesavealpha($sq_thumb, true); }
                imagecopyresampled($sq_thumb, $src, 0, 0, $off_x, $off_y, $sq_size, $sq_size, $min_dim, $min_dim);
                $t_path = $thumb_dir_full . '/t_' . $new_file_name;
                if ($mime === 'image/jpeg') imagejpeg($sq_thumb, $t_path, 82);
                elseif ($mime === 'image/png') imagepng($sq_thumb, $t_path, 8);
                else imagewebp($sq_thumb, $t_path, 78);
                imagedestroy($sq_thumb);
                $db_thumb_square = $rel_dir . '/thumbs/t_' . $new_file_name;

                $al = 400;
                if ($orig_w >= $orig_h) { $a_w = $al; $a_h = round($orig_h * ($al / $orig_w)); }
                else { $a_h = $al; $a_w = round($orig_w * ($al / $orig_h)); }
                if ($orig_w < $al && $orig_h < $al) { $a_w = $orig_w; $a_h = $orig_h; }
                $a_thumb = imagecreatetruecolor($a_w, $a_h);
                if ($mime === 'image/png' || $mime === 'image/webp') { imagealphablending($a_thumb, false); imagesavealpha($a_thumb, true); }
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

            $auto_orient = 0;
            if ($orig_w == $orig_h) $auto_orient = 2;
            elseif ($orig_h > $orig_w) $auto_orient = 1;

            $pdo->prepare("
                INSERT INTO snap_images
                    (img_title, img_slug, img_file, img_description, img_film, img_exif,
                     img_status, img_date, img_orientation, img_width, img_height,
                     allow_comments, allow_download, download_url,
                     img_thumb_square, img_thumb_aspect, img_checksum, img_display_options, post_id)
                VALUES (?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $title, $img_slug, $db_path, $desc, $exif_json,
                $status, $post_date, $auto_orient, $orig_w, $orig_h,
                $allow_cmt, $allow_dl, $dl_url,
                $db_thumb_square, $db_thumb_aspect, $db_checksum, $palette_json,
                $post_id
            ]);

            $new_img_id = (int)$pdo->lastInsertId();

            // Resolve style for this new image.
            if ($customize_level === 'per_image') {
                $ni_sz  = max(75, min(100, (int)($new_style_sizes[$i]  ?? 100)));
                $ni_bpx = max(0,  min(20,  (int)($new_style_bpx[$i]   ?? 0)));
                $ni_bc  = preg_match('/^#[0-9a-fA-F]{6}$/', $new_style_bc[$i]  ?? '')
                              ? $new_style_bc[$i]  : '#000000';
                $ni_bg  = preg_match('/^#[0-9a-fA-F]{6}$/', $new_style_bg[$i]  ?? '')
                              ? $new_style_bg[$i]  : '#ffffff';
                $ni_sh  = max(0, min(3, (int)($new_style_shadow[$i]   ?? 0)));
            } elseif ($customize_level === 'per_carousel') {
                $ni_sz = $post_style_size; $ni_bpx = $post_style_bpx;
                $ni_bc = $post_style_bc;   $ni_bg  = $post_style_bg;
                $ni_sh = $post_style_shadow;
            } else {
                $ni_sz = 100; $ni_bpx = 0; $ni_bc = '#000000'; $ni_bg = '#ffffff'; $ni_sh = 0;
            }

            $pdo->prepare("
                INSERT INTO snap_post_images
                    (post_id, image_id, sort_position, is_cover,
                     img_size_pct, img_border_px, img_border_color, img_bg_color, img_shadow)
                VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?)
            ")->execute([$post_id, $new_img_id, $next_pos,
                         $ni_sz, $ni_bpx, $ni_bc, $ni_bg, $ni_sh]);

            $next_pos++;
            $current_count++;
            gc_collect_cycles();
        }
    }

    // --- Update post_type to match actual image count after edits ---
    $final_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_post_images WHERE post_id = ?");
    $final_count_stmt->execute([$post_id]);
    $final_count = (int)$final_count_stmt->fetchColumn();
    if ($final_count <= 1) {
        $pdo->prepare("UPDATE snap_posts SET post_type = 'single' WHERE id = ? AND post_type = 'carousel'")
            ->execute([$post_id]);
    } elseif ($final_count > 1) {
        $pdo->prepare("UPDATE snap_posts SET post_type = 'carousel' WHERE id = ? AND post_type = 'single'")
            ->execute([$post_id]);
    }

    // Sync hashtags from description (cover image carries the post's tags)
    $cover_stmt = $pdo->prepare(
        "SELECT image_id FROM snap_post_images WHERE post_id = ? AND is_cover = 1 LIMIT 1"
    );
    $cover_stmt->execute([$post_id]);
    $cover_img_id = (int)$cover_stmt->fetchColumn();
    if ($cover_img_id) {
        snap_sync_tags($pdo, $cover_img_id, $desc ?? '');
    }

    $msg = 'Success: Post updated.';
}

// =============================================================================
// DATA RETRIEVAL
// =============================================================================

$post_stmt = $pdo->prepare("SELECT * FROM snap_posts WHERE id = ?");
$post_stmt->execute([$post_id]);
$post = $post_stmt->fetch();
if (!$post) die('Post not found.');

// Load all images in this post, ordered by sort_position (including frame style columns)
$images_stmt = $pdo->prepare("
    SELECT i.*, pi.sort_position, pi.is_cover, pi.id AS pivot_id,
           pi.img_size_pct, pi.img_border_px, pi.img_border_color,
           pi.img_bg_color, pi.img_shadow
    FROM snap_post_images pi
    JOIN snap_images i ON i.id = pi.image_id
    WHERE pi.post_id = ? AND pi.sort_position >= 0
    ORDER BY pi.sort_position ASC
");
$images_stmt->execute([$post_id]);
$post_images = $images_stmt->fetchAll();

// Load categories and albums
$mapped_cats = $pdo->prepare("SELECT cat_id FROM snap_post_cat_map WHERE post_id = ?");
$mapped_cats->execute([$post_id]);
$mapped_cats = $mapped_cats->fetchAll(PDO::FETCH_COLUMN);

$mapped_albums = $pdo->prepare("SELECT album_id FROM snap_post_album_map WHERE post_id = ?");
$mapped_albums->execute([$post_id]);
$mapped_albums = $mapped_albums->fetchAll(PDO::FETCH_COLUMN);

$all_cats   = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
$all_albums = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();

// Build EXIF map for JS (image_id → exif object)
$exif_map = [];
foreach ($post_images as $pimg) {
    $exif_map[$pimg['id']] = json_decode($pimg['img_exif'] ?: '{}', true) ?: [];
}

$page_title = 'Edit Post: ' . htmlspecialchars($post['title']);
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>EDIT POST: <?php echo htmlspecialchars($post['title']); ?></h2>
        <span class="dim" style="font-size:12px; letter-spacing:1px;">
            <?php echo strtoupper($post['post_type']); ?>
            &nbsp;·&nbsp; <?php echo count($post_images); ?> IMAGE<?php echo count($post_images) !== 1 ? 'S' : ''; ?>
        </span>
    </div>

    <?php if ($msg): ?>
        <div class="alert box alert-success"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <form id="ce-form" method="POST" enctype="multipart/form-data"
          data-customize-level="<?php echo htmlspecialchars($customize_level); ?>">

        <!-- New file input (populated by JS via DataTransfer before submit) -->
        <input type="file" name="new_img_files[]" multiple style="display:none;" id="ce-new-file-hidden">

        <!-- ===================================================================
             SECTION 1: POST METADATA
             =================================================================== -->
        <div class="box">
            <div class="post-layout-grid">

                <div class="post-col-left">
                    <div class="lens-input-wrapper">
                        <label>POST TITLE</label>
                        <input type="text" name="title" value="<?php echo htmlspecialchars($post['title']); ?>" required autofocus>
                    </div>

                    <?php if ($post['post_type'] === 'panorama'): ?>
                    <div class="lens-input-wrapper">
                        <label>PANORAMA ROWS</label>
                        <select name="panorama_rows" class="full-width-select">
                            <option value="1" <?php echo $post['panorama_rows'] == 1 ? 'selected' : ''; ?>>1 Row — 3 tiles</option>
                            <option value="2" <?php echo $post['panorama_rows'] == 2 ? 'selected' : ''; ?>>2 Rows — 6 tiles</option>
                            <option value="3" <?php echo $post['panorama_rows'] == 3 ? 'selected' : ''; ?>>3 Rows — 9 tiles</option>
                        </select>
                    </div>
                    <?php endif; ?>

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
                                            <input type="text" placeholder="Filter..." onkeyup="filterRegistry(this, 'cat-list-box')">
                                        </div>
                                        <div class="dropdown-list" id="cat-list-box">
                                            <?php foreach ($all_cats as $c): ?>
                                                <label class="multi-cat-item">
                                                    <input type="checkbox" name="cat_ids[]"
                                                           value="<?php echo $c['id']; ?>"
                                                           <?php echo in_array($c['id'], $mapped_cats) ? 'checked' : ''; ?>
                                                           onchange="updateLabel('cat')">
                                                    <span class="cat-name-text"><?php echo htmlspecialchars($c['cat_name']); ?></span>
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
                                            <input type="text" placeholder="Filter..." onkeyup="filterRegistry(this, 'album-list-box')">
                                        </div>
                                        <div class="dropdown-list" id="album-list-box">
                                            <?php foreach ($all_albums as $a): ?>
                                                <label class="multi-cat-item">
                                                    <input type="checkbox" name="album_ids[]"
                                                           value="<?php echo $a['id']; ?>"
                                                           <?php echo in_array($a['id'], $mapped_albums) ? 'checked' : ''; ?>
                                                           onchange="updateLabel('album')">
                                                    <span class="cat-name-text"><?php echo htmlspecialchars($a['album_name']); ?></span>
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
                                <span class="sc-sep"></span>
                                <button type="button" class="sc-btn" data-action="ul">UL</button>
                                <button type="button" class="sc-btn" data-action="ol">OL</button>
                            </div>
                        </div>
                        <textarea id="desc" name="desc" placeholder="Post-level description. EXIF notes go per image below."><?php echo htmlspecialchars($post['description']); ?></textarea>
                    </div>
                </div>

                <div class="post-col-right">
                    <div class="lens-input-wrapper">
                        <label>PUBLICATION STATUS</label>
                        <select name="img_status" class="full-width-select">
                            <option value="published" <?php echo $post['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
                            <option value="draft" <?php echo $post['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>INTERNAL TIMESTAMP</label>
                        <input type="datetime-local" name="img_date" class="full-width-select edit-timestamp"
                               onclick="this.showPicker()"
                               value="<?php echo date('Y-m-d\TH:i', strtotime($post['created_at'])); ?>">
                    </div>

                    <div class="lens-input-wrapper">
                        <label>ALLOW PUBLIC SIGNALS?</label>
                        <select name="allow_comments" class="full-width-select">
                            <option value="1" <?php echo $post['allow_comments'] ? 'selected' : ''; ?>>ENABLED</option>
                            <option value="0" <?php echo !$post['allow_comments'] ? 'selected' : ''; ?>>DISABLED</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>ALLOW DOWNLOAD?</label>
                        <select name="allow_download" class="full-width-select">
                            <option value="0" <?php echo !$post['allow_download'] ? 'selected' : ''; ?>>DISABLED</option>
                            <option value="1" <?php echo $post['allow_download'] ? 'selected' : ''; ?>>ENABLED</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>DOWNLOAD URL (EXTERNAL)</label>
                        <input type="text" name="download_url" value="<?php echo htmlspecialchars($post['download_url'] ?? ''); ?>" placeholder="Google Drive, Dropbox, etc.">
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================================================================
             SECTION 1b: PER-CAROUSEL FRAME STYLE
             =================================================================== -->
        <?php if ($customize_level === 'per_carousel'): ?>
        <div class="box mt-30">
            <h3 style="margin:0 0 6px;">IMAGE FRAME STYLE</h3>
            <p class="skin-desc-text" style="margin-bottom:16px;">
                Applied to every image in this post.
            </p>
            <div class="post-layout-grid" style="gap:16px;">
                <div class="flex-1">
                    <div class="lens-input-wrapper">
                        <label>IMAGE SIZE</label>
                        <select name="post_img_size_pct" class="full-width-select">
                            <?php foreach ([100=>'100% — edge to edge',95=>'95%',90=>'90%',85=>'85%',80=>'80%',75=>'75%'] as $v=>$l): ?>
                            <option value="<?php echo $v; ?>" <?php echo ($post['post_img_size_pct']??100)==$v?'selected':''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>BORDER THICKNESS</label>
                        <select name="post_border_px" class="full-width-select">
                            <?php foreach ([0=>'None',1=>'1px',2=>'2px',3=>'3px',5=>'5px',8=>'8px',10=>'10px',15=>'15px',20=>'20px'] as $v=>$l): ?>
                            <option value="<?php echo $v; ?>" <?php echo ($post['post_border_px']??0)==$v?'selected':''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>DROP SHADOW</label>
                        <select name="post_shadow" class="full-width-select">
                            <?php foreach ([0=>'None',1=>'Soft',2=>'Medium',3=>'Heavy'] as $v=>$l): ?>
                            <option value="<?php echo $v; ?>" <?php echo ($post['post_shadow']??0)==$v?'selected':''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="flex-1">
                    <div class="lens-input-wrapper">
                        <label>BORDER COLOUR</label>
                        <input type="color" name="post_border_color"
                               value="<?php echo htmlspecialchars($post['post_border_color'] ?? '#000000'); ?>"
                               style="width:100%; height:38px; padding:2px 4px;">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>BACKGROUND COLOUR</label>
                        <input type="color" name="post_bg_color"
                               value="<?php echo htmlspecialchars($post['post_bg_color'] ?? '#ffffff'); ?>"
                               style="width:100%; height:38px; padding:2px 4px;">
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
            <?php /* per_grid: no form controls — style is resolved from Skin Admin at render time */ ?>
            <input type="hidden" name="post_img_size_pct" value="<?php echo (int)($post['post_img_size_pct'] ?? 100); ?>">
            <input type="hidden" name="post_border_px"    value="<?php echo (int)($post['post_border_px']    ?? 0); ?>">
            <input type="hidden" name="post_border_color" value="<?php echo htmlspecialchars($post['post_border_color'] ?? '#000000'); ?>">
            <input type="hidden" name="post_bg_color"     value="<?php echo htmlspecialchars($post['post_bg_color']     ?? '#ffffff'); ?>">
            <input type="hidden" name="post_shadow"       value="<?php echo (int)($post['post_shadow'] ?? 0); ?>">
        <?php endif; ?>

        <!-- ===================================================================
             SECTION 2: IMAGE STRIP
             =================================================================== -->
        <div class="box mt-30">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                <h3 style="margin:0;">SOURCE ASSETS</h3>
                <div style="display:flex; gap:12px; align-items:center;">
                    <span class="dim" style="font-size:12px; letter-spacing:1px;">
                        <?php echo count($post_images); ?> / 20 images
                    </span>
                    <button type="button" id="ce-add-toggle" class="btn-secondary">+ ADD MORE IMAGES</button>
                </div>
            </div>

            <p class="skin-desc-text">Drag thumbnails to reorder. Click COVER badge to promote. First image is always the grid cover.</p>

            <!-- Existing image strip -->
            <div id="ce-strip" class="cp-strip" style="margin-bottom:20px;">
                <?php foreach ($post_images as $pimg):
                    $exif = json_decode($pimg['img_exif'] ?: '{}', true) ?: [];
                    $is_cover = $pimg['is_cover'] ? true : false;
                    $thumb_src = $pimg['img_thumb_square'] ?: $pimg['img_file'];
                ?>
                <div class="ce-strip-item cp-strip-item"
                     data-image-id="<?php echo $pimg['id']; ?>"
                     data-thumb="<?php echo htmlspecialchars($thumb_src); ?>">
                    <div class="ce-thumb-wrap cp-thumb-wrap">
                        <img src="<?php echo htmlspecialchars($thumb_src); ?>" class="ce-thumb cp-thumb" alt="">
                        <span class="ce-cover-badge cp-cover-badge" title="Click to make this the cover image"
                              style="display:<?php echo $is_cover ? 'flex' : 'none'; ?>; cursor:pointer;">
                            COVER
                        </span>
                        <span class="ce-pos-badge cp-pos-badge"><?php echo $pimg['sort_position'] + 1; ?></span>
                        <button type="button" class="ce-remove-btn cp-remove-btn" title="Remove from post">×</button>
                    </div>
                    <div class="cp-item-label"><?php echo htmlspecialchars(basename($pimg['img_file'])); ?></div>

                    <!-- EXIF toggle + panel -->
                    <button type="button" class="ce-exif-toggle cp-exif-toggle">EXIF ▸</button>
                    <div class="ce-exif-panel cp-exif-panel" style="display:none;">
                        <div class="cp-exif-grid">
                            <?php
                            $exif_fields = [
                                'camera'   => 'CAMERA',
                                'lens'     => 'LENS',
                                'focal'    => 'FOCAL',
                                'film'     => 'FILM',
                                'iso'      => 'ISO',
                                'aperture' => 'APERTURE',
                                'shutter'  => 'SHUTTER',
                            ];
                            foreach ($exif_fields as $field => $label):
                                $val = htmlspecialchars($exif[$field] ?? '');
                            ?>
                            <div class="lens-input-wrapper">
                                <label><?php echo $label; ?></label>
                                <input type="text" name="exif[<?php echo $pimg['id']; ?>][<?php echo $field; ?>]" value="<?php echo $val; ?>">
                            </div>
                            <?php endforeach; ?>
                            <div class="lens-input-wrapper">
                                <label>FLASH</label>
                                <select name="exif[<?php echo $pimg['id']; ?>][flash]">
                                    <option value="No" <?php echo ($exif['flash'] ?? 'No') === 'No' ? 'selected' : ''; ?>>No</option>
                                    <option value="Yes" <?php echo ($exif['flash'] ?? 'No') === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <?php if ($customize_level === 'per_image'): ?>
                    <!-- Frame style toggle + panel (per_image mode only) -->
                    <button type="button" class="ce-style-toggle cp-exif-toggle" style="margin-top:4px;">FRAME ▸</button>
                    <div class="ce-style-panel cp-exif-panel" style="display:none;">
                        <div class="cp-exif-grid">
                            <div class="lens-input-wrapper">
                                <label>IMAGE SIZE</label>
                                <select name="img_size_pct[<?php echo $pimg['id']; ?>]" class="full-width-select">
                                    <?php foreach ([100=>'100%',95=>'95%',90=>'90%',85=>'85%',80=>'80%',75=>'75%'] as $v=>$l): ?>
                                    <option value="<?php echo $v; ?>" <?php echo ($pimg['img_size_pct']??100)==$v?'selected':''; ?>><?php echo $l; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="lens-input-wrapper">
                                <label>BORDER</label>
                                <select name="img_border_px[<?php echo $pimg['id']; ?>]" class="full-width-select">
                                    <?php foreach ([0=>'None',1=>'1px',2=>'2px',3=>'3px',5=>'5px',8=>'8px',10=>'10px',15=>'15px',20=>'20px'] as $v=>$l): ?>
                                    <option value="<?php echo $v; ?>" <?php echo ($pimg['img_border_px']??0)==$v?'selected':''; ?>><?php echo $l; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="lens-input-wrapper">
                                <label>BORDER COLOUR</label>
                                <input type="color" name="img_border_color[<?php echo $pimg['id']; ?>]"
                                       value="<?php echo htmlspecialchars($pimg['img_border_color'] ?? '#000000'); ?>"
                                       style="height:30px; width:100%; padding:2px 4px;">
                            </div>
                            <div class="lens-input-wrapper">
                                <label>BG COLOUR</label>
                                <input type="color" name="img_bg_color[<?php echo $pimg['id']; ?>]"
                                       value="<?php echo htmlspecialchars($pimg['img_bg_color'] ?? '#ffffff'); ?>"
                                       style="height:30px; width:100%; padding:2px 4px;">
                            </div>
                            <div class="lens-input-wrapper">
                                <label>SHADOW</label>
                                <select name="img_shadow[<?php echo $pimg['id']; ?>]" class="full-width-select">
                                    <?php foreach ([0=>'None',1=>'Soft',2=>'Medium',3=>'Heavy'] as $v=>$l): ?>
                                    <option value="<?php echo $v; ?>" <?php echo ($pimg['img_shadow']??0)==$v?'selected':''; ?>><?php echo $l; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Add-more section (collapsed by default) -->
            <div id="ce-add-zone" style="display:none; border-top:1px dashed #444; padding-top:20px; margin-top:4px;">
                <input type="file" id="ce-file-input" accept="image/jpeg,image/png,image/webp" multiple style="display:none;">
                <div id="ce-drop-zone" class="cp-drop-zone">
                    <div class="cp-drop-icon">⊕</div>
                    <p class="cp-drop-label">DROP NEW IMAGES HERE or click to browse</p>
                    <p class="cp-drop-sub dim">JPG · PNG · WebP &nbsp;·&nbsp; Added to end of post</p>
                </div>
            </div>
        </div>

        <!-- ===================================================================
             SECTION 3: SUBMIT
             =================================================================== -->
        <div class="form-action-row">
            <button type="submit" class="master-update-btn">SAVE CHANGES</button>
        </div>

    </form>
</div>

<style>
/* Reuse cp- classes from smack-post-carousel.php for the strip UI.
   Additional overrides for the edit-specific elements. */
.ce-cover-badge {
    position: absolute; bottom: 6px; left: 6px;
    background: rgba(220, 160, 0, 0.85); color: #fff;
    font-size: 9px; letter-spacing: 1px;
    padding: 2px 5px; cursor: pointer;
    align-items: center; justify-content: center;
}
.ce-cover-badge:hover { background: rgba(220, 160, 0, 1); }
.btn-secondary {
    background: #333; color: #aaa;
    border: 1px solid #555; padding: 6px 14px;
    cursor: pointer; font-size: 0.8em; text-transform: uppercase;
}
.btn-secondary:hover { background: #444; color: #fff; }
</style>

<script src="assets/js/ss-engine-admin-ui.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/shortcode-toolbar.js"></script>
<script src="assets/js/ss-engine-carousel-edit.js?v=<?php echo time(); ?>"></script>
<script>
window.addEventListener('DOMContentLoaded', function () {
    if (typeof updateLabel === 'function') {
        updateLabel('cat');
        updateLabel('album');
    }
    // Wire FRAME toggle buttons for per_image style panels (PHP-rendered strip items).
    document.querySelectorAll('.ce-style-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var panel = this.nextElementSibling;
            if (!panel || !panel.classList.contains('ce-style-panel')) return;
            var open = panel.style.display !== 'none';
            panel.style.display = open ? 'none' : 'block';
            this.textContent = open ? 'FRAME ▸' : 'FRAME ▾';
        });
    });
});
</script>
<?php include 'core/admin-footer.php'; ?>
