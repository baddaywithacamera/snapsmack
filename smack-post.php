<?php
/**
 * SNAPSMACK - New Post (Smack Engine)
 * Version: 15.4 - Clean Architecture (Styles Moved to CSS)
 * MASTER DIRECTIVE: Full file return. 
 */

require_once 'core/auth.php';
require_once 'core/fix-exif.php';

// --- 1. SETTINGS & TIMEZONE ---
$settings = [];
$s_rows = $pdo->query("SELECT * FROM snap_settings")->fetchAll();
foreach ($s_rows as $row) { 
    $settings[$row['setting_key']] = $row['setting_val']; 
}
date_default_timezone_set($settings['timezone'] ?? 'America/Edmonton');

// --- 2. THE SMACK ENGINE (POST PROCESSING) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file'])) {
    if ($_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
        
        $tmp = $_FILES['image_file']['tmp_name'];
        $title = $_POST['title'] ?? 'Untitled';
        $desc = $_POST['desc'] ?? '';
        
        $status = $_POST['img_status'] ?? 'published';
        $orientation = (int)($_POST['img_orientation'] ?? 0); 
        $publish_date = !empty($_POST['custom_date']) ? $_POST['custom_date'] : date('Y-m-d H:i:s');

        $camera_manual    = $_POST['camera_model'] ?? '';
        $lens_manual      = $_POST['lens_info'] ?? '';
        $focal_manual     = $_POST['focal_length'] ?? '';
        $film_manual      = $_POST['film_stock'] ?? '';
        if (isset($_POST['film_na'])) { $film_manual = 'N/A'; }

        $iso_manual        = $_POST['iso_speed'] ?? '';
        $aperture_manual  = $_POST['aperture'] ?? '';
        $shutter_manual   = $_POST['shutter_speed'] ?? '';
        $flash_manual     = $_POST['flash_fire'] ?? 'No';

        $selected_cats = $_POST['cat_ids'] ?? [];
        $selected_albums = $_POST['album_ids'] ?? []; 
        $mime = mime_content_type($tmp);

        // --- 3. IMAGE PREP & CROP MATH ---
        list($orig_w, $orig_h) = getimagesize($tmp);
        
        $thumb_size = 400; 
        $crop_size = min($orig_w, $orig_h);
        $cx = ($orig_w - $crop_size) / 2;
        $cy = ($orig_h - $crop_size) / 2;

        $wall_h = 500;
        $wall_w = round($orig_w * ($wall_h / $orig_h));

        // --- 4. EXIF HARVESTING ---
        $raw_harvest = [];
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $raw_exif = @exif_read_data($tmp);
            $fields = explode(',', $settings['exif_fields'] ?? 'Model,ExposureTime,FNumber,ISOSpeedRatings,FocalLength');
            foreach ($fields as $f) { 
                $fn = trim($f);
                $raw_harvest[$fn] = $raw_exif[$fn] ?? 'N/A'; 
            }
        }

        if (!empty($camera_manual))   { $raw_harvest['Model'] = strtoupper($camera_manual); }
        if (!empty($focal_manual))    { $raw_harvest['FocalLength'] = $focal_manual; }
        if (!empty($iso_manual))      { $raw_harvest['ISOSpeedRatings'] = $iso_manual; }
        if (!empty($aperture_manual)) { $raw_harvest['FNumber'] = $aperture_manual; }
        if (!empty($shutter_manual))  { $raw_harvest['ExposureTime'] = $shutter_manual; }

        $final_exif = get_smack_exif($raw_harvest);
        $final_exif['lens'] = $lens_manual;
        $final_exif['film'] = $film_manual;
        $final_exif['flash'] = $flash_manual;

        // --- 5. IMAGE RESOURCE CREATION ---
        if ($mime == 'image/jpeg') { $src = @imagecreatefromjpeg($tmp); } 
        elseif ($mime == 'image/png') { $src = @imagecreatefrompng($tmp); } 
        elseif ($mime == 'image/webp') { $src = @imagecreatefromwebp($tmp); } 
        else { die("Error: Unsupported file type."); }

        $t_dst = imagecreatetruecolor($thumb_size, $thumb_size);
        $w_dst = imagecreatetruecolor($wall_w, $wall_h);

        if ($mime != 'image/jpeg') {
            imagealphablending($t_dst, false); imagesavealpha($t_dst, true);
            imagealphablending($w_dst, false); imagesavealpha($w_dst, true);
        }
        
        imagecopyresampled($t_dst, $src, 0, 0, $cx, $cy, $thumb_size, $thumb_size, $crop_size, $crop_size);
        imagecopyresampled($w_dst, $src, 0, 0, 0, 0, $wall_w, $wall_h, $orig_w, $orig_h);

        // --- 6. FILE SYSTEM STORAGE ---
        $rel_dir = 'img_uploads/' . date('Y/m');
        $full_dir = __DIR__ . '/' . $rel_dir;
        $thumb_dir = $full_dir . '/thumbs';
        
        if (!is_dir($full_dir)) { mkdir($full_dir, 0755, true); }
        if (!is_dir($thumb_dir)) { mkdir($thumb_dir, 0755, true); }
        
        $clean_title = preg_replace("/[^a-zA-Z0-9]/", "", $title);
        $base_fn = time() . '_' . $clean_title;
        $ext = ($mime == 'image/png') ? '.png' : (($mime == 'image/webp') ? '.webp' : '.jpg');
        
        $db_save_path = $rel_dir . '/' . $base_fn . $ext;
        $save_to = $full_dir . '/' . $base_fn . $ext;
        $save_thumb = $thumb_dir . '/t_' . $base_fn . $ext;
        $save_wall  = $thumb_dir . '/wall_' . $base_fn . $ext;

        if (move_uploaded_file($tmp, $save_to)) {
            if ($mime == 'image/png') { 
                imagepng($t_dst, $save_thumb, 8); 
                imagepng($w_dst, $save_wall, 8);
            } elseif ($mime == 'image/webp') { 
                imagewebp($t_dst, $save_thumb, 60); 
                imagewebp($w_dst, $save_wall, 60);
            } else { 
                imagejpeg($t_dst, $save_thumb, 75); 
                imagejpeg($w_dst, $save_wall, 80);
            }

            imagedestroy($src); imagedestroy($t_dst); imagedestroy($w_dst);

            // --- 7. DATABASE RECORDING ---
            try {
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
                $sql = "INSERT INTO snap_images (img_title, img_film, img_slug, img_description, img_date, img_file, img_exif, img_width, img_height, img_status, img_orientation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$title, $film_manual, $slug, $desc, $publish_date, $db_save_path, json_encode($final_exif), $orig_w, $orig_h, $status, $orientation]);
                
                $new_id = $pdo->lastInsertId();

                if (!empty($selected_cats)) {
                    foreach ($selected_cats as $cid) {
                        $pdo->prepare("INSERT INTO snap_image_cat_map (image_id, cat_id) VALUES (?, ?)")->execute([$new_id, (int)$cid]);
                    }
                }

                if (!empty($selected_albums)) {
                    foreach ($selected_albums as $aid) {
                        $pdo->prepare("INSERT INTO snap_image_album_map (image_id, album_id) VALUES (?, ?)")->execute([$new_id, (int)$aid]);
                    }
                }

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { exit("success"); }
                header("Location: smack-manage.php?msg=success");
                exit;

            } catch (PDOException $e) {
                die("DATABASE CRASH: " . $e->getMessage());
            }
        } else {
            die("Error: Failed to move uploaded file.");
        }
    }
}

$cats = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
$albums = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();

include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>New Post</h2>
    <div class="box">
        <form id="smack-form" method="POST" enctype="multipart/form-data">
            <div class="post-layout-grid">
                <div class="post-col-left">
                    <label>Select Photo</label>
                    <div class="file-upload-wrapper">
                        <div class="file-custom-btn">Choose File</div>
                        <div class="file-name-display" id="file-name-text">No file selected...</div>
                        <input type="file" name="image_file" id="file-input" required>
                    </div>

                    <label>Image Title</label>
                    <input type="text" name="title" id="title-input" required>

                    <label>Description</label>
                    <textarea name="desc" placeholder="Tell the story..."></textarea>
                </div>

                <div class="post-col-right">
                    <label>Publication Status</label>
                    <select name="img_status" class="full-width-select mb-25">
                        <option value="published" selected>Published (Visible)</option>
                        <option value="draft">Draft (Hidden)</option>
                    </select>

                    <label>Manual Orientation (Mobile Override)</label>
                    <select name="img_orientation" class="full-width-select mb-25">
                        <option value="0" selected>Landscape (Default)</option>
                        <option value="1">Portrait (Vertical Priority)</option>
                        <option value="2">Square (Standard View)</option>
                    </select>

                    <label>Scheduled Date (Optional)</label>
                    <input type="datetime-local" name="custom_date" class="full-width-select mb-5">
                    <p class="field-hint mb-25">Leave empty to publish now.</p>

                    <label>Registry (Categories)</label>
                    <div class="custom-multiselect mb-25">
                        <div class="select-box" onclick="toggleDropdown('cat-items')">
                            <span id="cat-label">Select...</span>
                            <span class="arrow">▼</span>
                        </div>
                        <div class="dropdown-content" id="cat-items">
                            <div class="dropdown-search-wrapper"><input type="text" placeholder="Filter..." onkeyup="filterRegistry(this, 'cat-list-box')"></div>
                            <div class="dropdown-list" id="cat-list-box">
                                <?php foreach($cats as $c): ?>
                                    <label class="multi-cat-item">
                                        <input type="checkbox" name="cat_ids[]" value="<?php echo $c['id']; ?>" onchange="updateLabel('cat')">
                                        <span class="cat-name-text"><?php echo htmlspecialchars($c['cat_name']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <label>Missions (Albums)</label>
                    <div class="custom-multiselect">
                        <div class="select-box" onclick="toggleDropdown('album-items')">
                            <span id="album-label">Select...</span>
                            <span class="arrow">▼</span>
                        </div>
                        <div class="dropdown-content" id="album-items">
                            <div class="dropdown-search-wrapper"><input type="text" placeholder="Filter..." onkeyup="filterRegistry(this, 'album-list-box')"></div>
                            <div class="dropdown-list" id="album-list-box">
                                <?php foreach($albums as $a): ?>
                                    <label class="multi-cat-item">
                                        <input type="checkbox" name="album_ids[]" value="<?php echo $a['id']; ?>" onchange="updateLabel('album')">
                                        <span class="cat-name-text"><?php echo htmlspecialchars($a['album_name']); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="section-divider">

            <label>Technical Specifications</label>
            <div class="meta-grid">
                <div class="lens-input-wrapper"><label>Camera Model</label><input type="text" name="camera_model" id="meta-camera"></div>
                <div class="lens-input-wrapper">
                    <label>Lens Info</label>
                    <div class="input-control-row">
                        <input type="text" name="lens_info" id="meta-lens">
                        <label class="built-in-label"><input type="checkbox" id="fixed-lens-check"> Built-in</label>
                    </div>
                </div>
                <div class="lens-input-wrapper"><label>Focal Length</label><input type="text" name="focal_length" id="meta-focal"></div>
                <div class="lens-input-wrapper">
                    <label>Film Stock</label>
                    <div class="input-control-row">
                        <input type="text" name="film_stock" id="meta-film">
                        <label class="built-in-label"><input type="checkbox" name="film_na" id="film-na-check"> N/A</label>
                    </div>
                </div>
                <div class="lens-input-wrapper"><label>ISO</label><input type="text" name="iso_speed" id="meta-iso"></div>
                <div class="lens-input-wrapper"><label>Aperture</label><input type="text" name="aperture" id="meta-aperture"></div>
                <div class="lens-input-wrapper"><label>Shutter Speed</label><input type="text" name="shutter_speed" id="meta-shutter"></div>
                <div class="lens-input-wrapper">
                    <label>Flash Fired</label>
                    <select name="flash_fire" id="meta-flash">
                        <option value="No">No</option>
                        <option value="Yes">Yes</option>
                    </select>
                </div>
            </div>

            <div class="progress-container" id="progress-container"><div class="progress-bar" id="progress-bar"></div></div>
            <button type="submit" id="submit-btn">SMACK THAT #$%& UP!!</button>
        </form>
    </div>
</div>

<script src="assets/js/smack-ui.js?v=<?php echo time(); ?>"></script>
<?php include 'core/admin-footer.php'; ?>