<?php
/**
 * SNAPSMACK - Mission entry portal.
 * Handles primary asset uploads, automated Spec/EXIF extraction, 
 * and multi-tier thumbnail generation (Square + Aspect-Preserved).
 * 
 * Thumb outputs:
 *   t_  — 400x400 center-cropped square (archive square grid)
 *   a_  — 400px on the long side, native aspect (archive cropped & masonry)
 *
 * Git Version Official Alpha 0.5
 */

require_once 'core/auth.php';

// Extend limits for high-resolution asset processing.
set_time_limit(300);
ini_set('memory_limit', '512M');

$msg = "";

// --------------------------------------------------------------------------
// 1. DATA INGESTION HANDLER
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['img_file'])) {
    
    $title = trim($_POST['title'] ?? 'Untitled Transmission');
    $desc = trim($_POST['desc'] ?? '');
    $status = $_POST['img_status'] ?? 'published';
    
    // CALENDAR FIX: Convert HTML5 'T' separator to standard SQL space.
    $raw_date = $_POST['img_date'] ?? '';
    $custom_date = !empty($raw_date) ? str_replace('T', ' ', $raw_date) : date('Y-m-d H:i:s');

    $allow_comments = (int)($_POST['allow_comments'] ?? 1);
    $selected_cats = $_POST['cat_ids'] ?? [];
    $selected_albums = $_POST['album_ids'] ?? [];
    
    // Handle Film Stock N/A toggle.
    $film_val = $_POST['film_stock'] ?? '';
    if (isset($_POST['film_na'])) { 
        $film_val = 'N/A'; 
    }

    $upload_dir = 'img_uploads/';
    if (!is_dir($upload_dir)) { 
        mkdir($upload_dir, 0755, true); 
    }
    if (!is_dir($upload_dir . 'thumbs/')) { 
        mkdir($upload_dir . 'thumbs/', 0755, true); 
    }

    $file_ext = strtolower(pathinfo($_FILES['img_file']['name'], PATHINFO_EXTENSION));
    $slug = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)) . '-' . time();
    $new_file_name = $slug . '.' . $file_ext;
    $target_path = $upload_dir . $new_file_name;

    if (move_uploaded_file($_FILES['img_file']['tmp_name'], $target_path)) {
        
        // --- TECHNICAL SPEC EXTRACTION (EXIF) ---
        $camera = $lens = $focal = $iso = $aperture = $shutter = "";
        $flash = "No";

        if (in_array($file_ext, ['jpg', 'jpeg'])) {
            $exif_data = @exif_read_data($target_path);
            if ($exif_data) {
                $camera   = $exif_data['Model'] ?? "";
                $focal    = $exif_data['FocalLength'] ?? "";
                $iso      = $exif_data['ISOSpeedRatings'] ?? "";
                $aperture = $exif_data['COMPUTED']['ApertureFNumber'] ?? "";
                $shutter  = $exif_data['ExposureTime'] ?? "";
                if (isset($exif_data['Flash'])) {
                    $flash = ($exif_data['Flash'] & 1) ? "Yes" : "No";
                }
            }
        }

        $exif_json = json_encode([
            'camera'   => !empty($_POST['camera_model'])  ? strtoupper(trim($_POST['camera_model']))  : strtoupper($camera),
            'lens'     => !empty($_POST['lens_info'])      ? trim($_POST['lens_info'])                 : $lens,
            'focal'    => !empty($_POST['focal_length'])   ? trim($_POST['focal_length'])              : $focal,
            'iso'      => !empty($_POST['iso_speed'])      ? trim($_POST['iso_speed'])                 : $iso,
            'aperture' => !empty($_POST['aperture'])       ? trim($_POST['aperture'])                  : $aperture,
            'shutter'  => !empty($_POST['shutter_speed'])  ? trim($_POST['shutter_speed'])             : $shutter,
            'flash'    => !empty($_POST['flash_fire'])     ? $_POST['flash_fire']                      : $flash
        ]);

        // --- THUMBNAIL GENERATION ENGINE ---
        list($orig_w, $orig_h) = getimagesize($target_path);
        $mime = mime_content_type($target_path);
        
        $src = null;
        if ($mime == 'image/jpeg') { $src = imagecreatefromjpeg($target_path); }
        elseif ($mime == 'image/png') { $src = imagecreatefrompng($target_path); }
        elseif ($mime == 'image/webp') { $src = imagecreatefromwebp($target_path); }

        if ($src) {
            $thumb_dir = $upload_dir . 'thumbs/';

            // =============================================================
            // 1. SQUARE THUMBNAIL (t_ prefix) — 400x400 center-cropped
            // =============================================================
            $sq_size = 400;
            $sq_thumb = imagecreatetruecolor($sq_size, $sq_size);
            $min_dim = min($orig_w, $orig_h);
            $off_x = ($orig_w - $min_dim) / 2;
            $off_y = ($orig_h - $min_dim) / 2;

            if ($mime == 'image/png' || $mime == 'image/webp') {
                imagealphablending($sq_thumb, false);
                imagesavealpha($sq_thumb, true);
            }

            imagecopyresampled($sq_thumb, $src, 0, 0, $off_x, $off_y, $sq_size, $sq_size, $min_dim, $min_dim);
            
            if ($mime === 'image/jpeg') {
                imagejpeg($sq_thumb, $thumb_dir . 't_' . $new_file_name, 82);
            } elseif ($mime === 'image/png') {
                imagepng($sq_thumb, $thumb_dir . 't_' . $new_file_name, 8);
            } else {
                imagewebp($sq_thumb, $thumb_dir . 't_' . $new_file_name, 78);
            }
            imagedestroy($sq_thumb);

            // =============================================================
            // 2. ASPECT-PRESERVED THUMBNAIL (a_ prefix) — 400px long side
            // =============================================================
            $aspect_long = 400;

            if ($orig_w >= $orig_h) {
                $a_w = $aspect_long;
                $a_h = round($orig_h * ($aspect_long / $orig_w));
            } else {
                $a_h = $aspect_long;
                $a_w = round($orig_w * ($aspect_long / $orig_h));
            }

            // Don't upscale tiny images
            if ($orig_w < $aspect_long && $orig_h < $aspect_long) {
                $a_w = $orig_w;
                $a_h = $orig_h;
            }

            $a_thumb = imagecreatetruecolor($a_w, $a_h);

            if ($mime == 'image/png' || $mime == 'image/webp') {
                imagealphablending($a_thumb, false);
                imagesavealpha($a_thumb, true);
            }

            imagecopyresampled($a_thumb, $src, 0, 0, 0, 0, $a_w, $a_h, $orig_w, $orig_h);

            if ($mime === 'image/jpeg') {
                imagejpeg($a_thumb, $thumb_dir . 'a_' . $new_file_name, 82);
            } elseif ($mime === 'image/png') {
                imagepng($a_thumb, $thumb_dir . 'a_' . $new_file_name, 8);
            } else {
                imagewebp($a_thumb, $thumb_dir . 'a_' . $new_file_name, 78);
            }
            imagedestroy($a_thumb);
            
            imagedestroy($src);
        }

        // --- AUTO-DETECT ORIENTATION ---
        // Set img_orientation based on actual image dimensions
        $auto_orientation = 0; // landscape
        if ($orig_w == $orig_h) {
            $auto_orientation = 2; // square
        } elseif ($orig_h > $orig_w) {
            $auto_orientation = 1; // portrait
        }

        // --- DATABASE PERSISTENCE ---
        $stmt = $pdo->prepare("
            INSERT INTO snap_images (
                img_title, 
                img_slug, 
                img_file, 
                img_description, 
                img_film, 
                img_exif, 
                img_status, 
                img_date, 
                img_orientation,
                allow_comments
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $title, 
            $slug, 
            $target_path, 
            $desc, 
            $film_val, 
            $exif_json, 
            $status, 
            $custom_date, 
            $auto_orientation,
            $allow_comments
        ]);
        
        $new_img_id = $pdo->lastInsertId();

        // Map Categories.
        foreach ($selected_cats as $cid) {
            $pdo->prepare("INSERT INTO snap_image_cat_map (image_id, cat_id) VALUES (?, ?)")->execute([$new_img_id, (int)$cid]);
        }

        // Map Missions (Albums).
        foreach ($selected_albums as $aid) {
            $pdo->prepare("INSERT INTO snap_image_album_map (image_id, album_id) VALUES (?, ?)")->execute([$new_img_id, (int)$aid]);
        }

        header("Location: smack-manage.php?msg=TRANSMISSION_LIVE");
        exit;
    }
}

// Data discovery for UI selectors.
$all_cats = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
$all_albums = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();

$page_title = "Initialize Smack";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>INITIALIZE NEW TRANSMISSION</h2>
    </div>

    <form id="smack-form-post" method="POST" enctype="multipart/form-data">
        
        <div class="box">
            <div class="post-layout-grid">
                
                <div class="post-col-left">
                    <div class="lens-input-wrapper">
                        <label>IMAGE TITLE</label>
                        <input type="text" name="title" placeholder="Transmission Identifier..." required autofocus>
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
                                        <div class="dropdown-search-wrapper"><input type="text" placeholder="Filter..." onkeyup="filterRegistry(this, 'cat-list-box')"></div>
                                        <div class="dropdown-list" id="cat-list-box">
                                            <?php foreach($all_cats as $c): ?>
                                                <label class="multi-cat-item">
                                                    <input type="checkbox" name="cat_ids[]" value="<?php echo $c['id']; ?>" onchange="updateLabel('cat')">
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
                                        <div class="dropdown-search-wrapper"><input type="text" placeholder="Filter..." onkeyup="filterRegistry(this, 'album-list-box')"></div>
                                        <div class="dropdown-list" id="album-list-box">
                                            <?php foreach($all_albums as $a): ?>
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
                    </div>

                    <div class="lens-input-wrapper mt-30">
                        <label>DESCRIPTION / STORY</label>
                        <textarea name="desc" placeholder="Technical context or artistic narrative..." rows="12"></textarea>
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
                        <label>ALLOW PUBLIC TRANSMISSIONS?</label>
                        <select name="allow_comments" class="full-width-select">
                            <option value="1">ENABLED</option>
                            <option value="0">DISABLED</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>SOURCE ASSET (.JPG, .PNG, .WEBP)</label>
                        <div class="file-upload-wrapper" onclick="document.getElementById('post-file-input').click()">
                            <div class="file-custom-btn">INJECT ASSET</div>
                            <span id="post-file-name" class="file-name-display">No file selected...</span>
                            <input type="file" name="img_file" id="post-file-input" accept="image/*" style="display:none;" required 
                                   onchange="document.getElementById('post-file-name').innerText = this.files[0].name;">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="box">
            <h3>TECHNICAL SPECIFICATIONS (EXIF OVERRIDES)</h3>
            <p class="dim" style="margin: -5px 0 15px 0; font-size: 0.75em;">JPEG EXIF is auto-extracted on upload. Manual entries below override auto-detected values. Leave blank to use auto-detected data.</p>
            
            <div class="meta-grid">
                <div class="lens-input-wrapper">
                    <label>CAMERA MODEL</label>
                    <input type="text" name="camera_model" placeholder="Auto-detected from EXIF...">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>LENS INFO</label>
                    <div class="input-control-row" style="display:flex; gap:10px; align-items:center;">
                        <input type="text" name="lens_info" id="meta-lens" placeholder="Auto-detected from EXIF..." style="flex-grow:1;">
                        <label class="built-in-label" style="margin:0; white-space:nowrap;"><input type="checkbox" id="fixed-lens-check"> Built-in</label>
                    </div>
                </div>
                
                <div class="lens-input-wrapper">
                    <label>FOCAL LENGTH</label>
                    <input type="text" name="focal_length" placeholder="Auto-detected from EXIF...">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>FILM STOCK</label>
                    <div class="input-control-row" style="display:flex; gap:10px; align-items:center;">
                        <input type="text" name="film_stock" id="meta-film" placeholder="e.g. Kodak Portra 400" style="flex-grow:1;">
                        <label class="built-in-label" style="margin:0; white-space:nowrap;"><input type="checkbox" name="film_na" id="film-na-check"> N/A</label>
                    </div>
                </div>
                
                <div class="lens-input-wrapper">
                    <label>ISO</label>
                    <input type="text" name="iso_speed" placeholder="Auto-detected from EXIF...">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>APERTURE</label>
                    <input type="text" name="aperture" placeholder="Auto-detected from EXIF...">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>SHUTTER SPEED</label>
                    <input type="text" name="shutter_speed" placeholder="Auto-detected from EXIF...">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>FLASH FIRED</label>
                    <select name="flash_fire" class="full-width-select">
                        <option value="">Auto-detect</option>
                        <option value="No">No</option>
                        <option value="Yes">Yes</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-action-row">
            <button type="submit" class="master-update-btn">COMMIT TRANSMISSION</button>
        </div>

    </form>
</div>

<script src="assets/js/ss-engine-admin-ui.js?v=<?php echo time(); ?>"></script>
<?php include 'core/admin-footer.php'; ?>
