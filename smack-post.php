<?php
/**
 * SNAPSMACK - Post upload and image processing
 * Alpha v0.7.4
 *
 * Handles image uploads, automatic EXIF extraction and metadata handling,
 * orientation correction, and thumbnail generation in multiple formats.
 */

require_once 'core/auth.php';
require_once 'core/palette-extract.php';
require_once 'core/snap-tags.php';

// Extend limits for high-resolution image processing.
set_time_limit(300);
ini_set('memory_limit', '512M');

$msg = "";

// Load site settings for image engine configuration.
$settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// --- POST-PAGE ROUTING ---
// If the active skin declares a custom posting page via manifest 'post_page',
// delegate entirely to the matching smack-post-{value}.php file.
// The override file handles both GET (render form) and POST (process upload).
// Falls through silently if the key is absent or the override file is missing.
$active_skin = $settings['active_skin'] ?? '';
if ($active_skin) {
    $manifest_path = __DIR__ . '/skins/' . $active_skin . '/manifest.php';
    if (is_file($manifest_path)) {
        $manifest = [];
        include $manifest_path;
        $post_page_override = $manifest['post_page'] ?? '';
        if ($post_page_override) {
            $override_file = __DIR__ . '/smack-post-' . $post_page_override . '.php';
            if (is_file($override_file)) {
                include $override_file;
                exit;
            }
        }
    }
}

// --- FORM SUBMISSION HANDLER ---
// Processes file uploads, generates thumbnails, extracts EXIF data,
// and stores the image record with metadata in the database.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['img_file'])) {

    $title = trim($_POST['title'] ?? 'Untitled Transmission');
    $desc = trim($_POST['desc'] ?? '');
    $status = $_POST['img_status'] ?? 'published';

    // HTML5 datetime-local inputs use 'T' separator; convert to SQL-compatible space.
    $raw_date = $_POST['img_date'] ?? '';
    $custom_date = !empty($raw_date) ? str_replace('T', ' ', $raw_date) : date('Y-m-d H:i:s');

    $allow_comments = (int)($_POST['allow_comments'] ?? 1);
    $allow_download = (int)($_POST['allow_download'] ?? 0);
    $download_url = trim($_POST['download_url'] ?? '');
    $selected_cats = $_POST['cat_ids'] ?? [];
    $selected_albums = $_POST['album_ids'] ?? [];

    // Film stock field supports explicit "N/A" via checkbox override.
    $film_val = $_POST['film_stock'] ?? '';
    if (isset($_POST['film_na'])) {
        $film_val = 'N/A';
    }

    $rel_dir = 'img_uploads/' . date('Y') . '/' . date('m');
    $full_dir = __DIR__ . '/' . $rel_dir;
    $thumb_full = $full_dir . '/thumbs';
    if (!is_dir($full_dir)) {
        mkdir($full_dir, 0755, true);
    }
    if (!is_dir($thumb_full)) {
        mkdir($thumb_full, 0755, true);
    }

    $file_ext = strtolower(pathinfo($_FILES['img_file']['name'], PATHINFO_EXTENSION));
    $slug = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)) . '-' . time();
    $new_file_name = $slug . '.' . $file_ext;
    $target_path = $full_dir . '/' . $new_file_name;
    $db_path = $rel_dir . '/' . $new_file_name;

    if (move_uploaded_file($_FILES['img_file']['tmp_name'], $target_path)) {

        // --- EXIF METADATA EXTRACTION ---
        // Reads camera and shot settings from JPEG EXIF data where available.
        // User-provided values take precedence over auto-detected metadata.
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

        // --- IMAGE PROCESSING ENGINE ---
        // Multi-stage pipeline: orientation correction, conditional resizing,
        // and thumbnail generation. Generates two thumbnail variants:
        //   t_ = 400x400 center-cropped square
        //   a_ = 400px long side, aspect-ratio preserved

        list($orig_w, $orig_h) = getimagesize($target_path);
        $mime = mime_content_type($target_path);
        $max_w = (int)($settings['max_width_landscape'] ?? 2500);
        $max_h = (int)($settings['max_height_portrait'] ?? 1850);
        $jpeg_q = (int)($settings['jpeg_quality'] ?? 85);

        $src = null;
        $db_thumb_square = null;
        $db_thumb_aspect = null;
        $db_checksum     = null;
        $display_options_json = null;

        if ($mime == 'image/jpeg') { $src = imagecreatefromjpeg($target_path); }
        elseif ($mime == 'image/png') { $src = imagecreatefrompng($target_path); }
        elseif ($mime == 'image/webp') { $src = imagecreatefromwebp($target_path); }

        if ($src) {
            // --- EXIF ORIENTATION CORRECTION ---
            // GD library drops EXIF rotation on save. Detect and apply rotation
            // before any resizing to ensure pixel data matches original orientation.
            $exif_orientation = 1;
            if ($mime == 'image/jpeg' && function_exists('exif_read_data')) {
                $exif_orient = @exif_read_data($target_path, 'IFD0');
                if ($exif_orient !== false) {
                    $exif_orientation = $exif_orient['Orientation'] ?? $exif_orient['orientation'] ?? 1;
                }
            }

            if ($exif_orientation == 3) {
                $src = imagerotate($src, 180, 0);
            } elseif ($exif_orientation == 6) {
                $src = imagerotate($src, -90, 0);
            } elseif ($exif_orientation == 8) {
                $src = imagerotate($src, 90, 0);
            }

            // Recalculate dimensions after rotation.
            $orig_w = imagesx($src);
            $orig_h = imagesy($src);

            // Persist the corrected orientation by saving the GD resource.
            if ($mime === 'image/jpeg') { imagejpeg($src, $target_path, $jpeg_q); }
            elseif ($mime === 'image/png') { imagepng($src, $target_path, 8); }
            else { imagewebp($src, $target_path, $jpeg_q); }

            // Reload the corrected file.
            imagedestroy($src);
            if ($mime == 'image/jpeg') { $src = imagecreatefromjpeg($target_path); }
            elseif ($mime == 'image/png') { $src = imagecreatefrompng($target_path); }
            else { $src = imagecreatefromwebp($target_path); }

            $thumb_dir = $thumb_full . '/';

            // --- CONDITIONAL RESIZE ---
            // If the image exceeds config limits, scale it down while preserving aspect ratio.
            $needs_resize = false;
            $d_w = $orig_w;
            $d_h = $orig_h;

            if ($orig_w >= $orig_h) {
                if ($orig_w > $max_w) {
                    $d_w = $max_w;
                    $d_h = round($orig_h * ($max_w / $orig_w));
                    $needs_resize = true;
                }
            } else {
                if ($orig_h > $max_h) {
                    $d_h = $max_h;
                    $d_w = round($orig_w * ($max_h / $orig_h));
                    $needs_resize = true;
                }
            }

            if ($needs_resize) {
                $d_img = imagecreatetruecolor($d_w, $d_h);
                if ($mime == 'image/png' || $mime == 'image/webp') {
                    imagealphablending($d_img, false);
                    imagesavealpha($d_img, true);
                }
                imagecopyresampled($d_img, $src, 0, 0, 0, 0, $d_w, $d_h, $orig_w, $orig_h);

                // Replace original with resized version.
                if ($mime === 'image/jpeg') { imagejpeg($d_img, $target_path, $jpeg_q); }
                elseif ($mime === 'image/png') { imagepng($d_img, $target_path, 8); }
                else { imagewebp($d_img, $target_path, $jpeg_q); }
                imagedestroy($d_img);

                // Reload for thumbnail generation.
                imagedestroy($src);
                if ($mime == 'image/jpeg') { $src = imagecreatefromjpeg($target_path); }
                elseif ($mime == 'image/png') { $src = imagecreatefrompng($target_path); }
                else { $src = imagecreatefromwebp($target_path); }

                $orig_w = $d_w;
                $orig_h = $d_h;
            }

            // --- SQUARE THUMBNAIL (t_ prefix) ---
            // 400x400 center-cropped square for grid display.
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

            if ($mime === 'image/jpeg') { imagejpeg($sq_thumb, $thumb_dir . 't_' . $new_file_name, 82); }
            elseif ($mime === 'image/png') { imagepng($sq_thumb, $thumb_dir . 't_' . $new_file_name, 8); }
            else { imagewebp($sq_thumb, $thumb_dir . 't_' . $new_file_name, 78); }
            imagedestroy($sq_thumb);

            // --- ASPECT-PRESERVED THUMBNAIL (a_ prefix) ---
            // 400px on the long side, native aspect ratio for masonry layouts.
            $aspect_long = 400;

            if ($orig_w >= $orig_h) {
                $a_w = $aspect_long;
                $a_h = round($orig_h * ($aspect_long / $orig_w));
            } else {
                $a_h = $aspect_long;
                $a_w = round($orig_w * ($aspect_long / $orig_h));
            }

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

            if ($mime === 'image/jpeg') { imagejpeg($a_thumb, $thumb_dir . 'a_' . $new_file_name, 82); }
            elseif ($mime === 'image/png') { imagepng($a_thumb, $thumb_dir . 'a_' . $new_file_name, 8); }
            else { imagewebp($a_thumb, $thumb_dir . 'a_' . $new_file_name, 78); }
            imagedestroy($a_thumb);

            imagedestroy($src);

            // --- RECOVERY METADATA ---
            // Compute SHA-256 checksum and store relative thumb paths for disaster recovery.
            $db_thumb_square = $rel_dir . '/thumbs/t_' . $new_file_name;
            $db_thumb_aspect = $rel_dir . '/thumbs/a_' . $new_file_name;
            $db_checksum     = hash_file('sha256', $target_path);

            // --- COLOUR PALETTE EXTRACTION ---
            // Extract dominant colours from the image for Galleria frame customisation.
            $palette = snapsmack_extract_palette($target_path, 5);
            $display_options_json = !empty($palette) ? json_encode(['palette' => $palette]) : null;
        }

        // --- ORIENTATION DETECTION ---
        // Classifies image as landscape, portrait, or square for archive display.
        // User override takes precedence; otherwise auto-detect from final dimensions.
        $orient_override = $_POST['orientation_override'] ?? 'auto';
        if ($orient_override !== 'auto') {
            $auto_orientation = (int)$orient_override;
        } else {
            $auto_orientation = 0; // landscape
            if ($orig_w == $orig_h) {
                $auto_orientation = 2; // square
            } elseif ($orig_h > $orig_w) {
                $auto_orientation = 1; // portrait
            }
        }

        // --- DATABASE RECORD CREATION ---
        // Stores image metadata, dimensions, processing flags, and category/album mappings.
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
                img_width,
                img_height,
                allow_comments,
                allow_download,
                download_url,
                img_thumb_square,
                img_thumb_aspect,
                img_checksum,
                img_display_options
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $title,
            $slug,
            $db_path,
            $desc,
            $film_val,
            $exif_json,
            $status,
            $custom_date,
            $auto_orientation,
            $orig_w,
            $orig_h,
            $allow_comments,
            $allow_download,
            $download_url,
            $db_thumb_square ?? null,
            $db_thumb_aspect ?? null,
            $db_checksum ?? null,
            $display_options_json ?? null
        ]);

        $new_img_id = $pdo->lastInsertId();

        // Associate image with selected categories.
        foreach ($selected_cats as $cid) {
            $pdo->prepare("INSERT INTO snap_image_cat_map (image_id, cat_id) VALUES (?, ?)")->execute([$new_img_id, (int)$cid]);
        }

        // Associate image with selected albums.
        foreach ($selected_albums as $aid) {
            $pdo->prepare("INSERT INTO snap_image_album_map (image_id, album_id) VALUES (?, ?)")->execute([$new_img_id, (int)$aid]);
        }

        // Sync hashtags from title + description.
        snap_sync_tags($pdo, (int)$new_img_id, $title . ' ' . $desc);

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo "success";
            exit;
        }
        header("Location: smack-manage.php?msg=TRANSMISSION_LIVE");
        exit;
    }
}

// Load categories and albums for form selectors.
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

                    <div class="lens-input-wrapper post-description-wrap">
                        <label>DESCRIPTION / STORY</label>
                        <div class="sc-toolbar" data-target="desc">
                            <div class="sc-row">
                                <button type="button" class="sc-btn" data-action="bold" title="Bold (Ctrl+B)">B</button>
                                <button type="button" class="sc-btn" data-action="italic" title="Italic (Ctrl+I)">I</button>
                                <button type="button" class="sc-btn" data-action="underline" title="Underline (Ctrl+U)">U</button>
                                <button type="button" class="sc-btn" data-action="link" title="Insert Link">LINK</button>
                                <span class="sc-sep"></span>
                                <button type="button" class="sc-btn" data-action="h2" title="Heading 2">H2</button>
                                <button type="button" class="sc-btn" data-action="h3" title="Heading 3">H3</button>
                                <button type="button" class="sc-btn" data-action="blockquote" title="Blockquote">BQ</button>
                                <button type="button" class="sc-btn" data-action="hr" title="Horizontal Rule">HR</button>
                                <span class="sc-sep"></span>
                                <button type="button" class="sc-btn" data-action="ul" title="Bullet List">UL</button>
                                <button type="button" class="sc-btn" data-action="ol" title="Numbered List">OL</button>
                            </div>
                            <div class="sc-row">
                                <button type="button" class="sc-btn" data-action="img" title="Insert Image Shortcode">IMG</button>
                                <button type="button" class="sc-btn" data-action="col2" title="2-Column Layout">COL 2</button>
                                <button type="button" class="sc-btn" data-action="col3" title="3-Column Layout">COL 3</button>
                                <button type="button" class="sc-btn" data-action="dropcap" title="Dropcap">DROP</button>
                                <button type="button" class="sc-btn" data-action="spacer" title="Vertical Spacer (1-100px)">SPACER</button>
                                <button type="button" class="sc-btn sc-btn-preview" data-action="preview" title="Preview in New Tab">PREVIEW</button>
                            </div>
                        </div>
                        <textarea id="desc" name="desc" placeholder="Plain text. Blank lines become paragraph breaks."></textarea>
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
                        <label>ORIENTATION OVERRIDE</label>
                        <select name="orientation_override" class="full-width-select">
                            <option value="auto">AUTO-DETECT FROM IMAGE</option>
                            <option value="0">LANDSCAPE</option>
                            <option value="1">PORTRAIT</option>
                            <option value="2">SQUARE</option>
                        </select>
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
                        <input type="text" name="download_url" placeholder="Google Drive, Dropbox, etc. Leave blank for local file.">
                    </div>

                    <div class="lens-input-wrapper">
                        <label>SOURCE ASSET (.JPG, .PNG, .WEBP)</label>
                        <div class="file-upload-wrapper">
                            <div class="file-custom-btn">INJECT ASSET</div>
                            <span id="post-file-name" class="file-name-display">No file selected...</span>
                            <input type="file" name="img_file" id="post-file-input" accept="image/*" class="file-input-hidden">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="box">
            <h3>TECHNICAL SPECIFICATIONS (EXIF OVERRIDES)</h3>
            <p class="dim exif-hint">JPEG EXIF is auto-extracted on upload. Manual entries below override auto-detected values. Leave blank to use auto-detected data.</p>
            
            <div class="meta-grid">
                <div class="lens-input-wrapper">
                    <label>CAMERA MODEL</label>
                    <input type="text" name="camera_model" placeholder="Auto-detected from EXIF...">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>LENS INFO</label>
                    <div class="input-control-row">
                        <input type="text" name="lens_info" id="meta-lens" placeholder="Auto-detected from EXIF...">
                        <label class="built-in-label"><input type="checkbox" id="fixed-lens-check"> Built-in</label>
                    </div>
                </div>
                
                <div class="lens-input-wrapper">
                    <label>FOCAL LENGTH</label>
                    <input type="text" name="focal_length" placeholder="Auto-detected from EXIF...">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>FILM STOCK</label>
                    <div class="input-control-row">
                        <input type="text" name="film_stock" id="meta-film" placeholder="e.g. Kodak Portra 400">
                        <label class="built-in-label"><input type="checkbox" name="film_na" id="film-na-check"> N/A</label>
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

        <div id="progress-container" class="progress-container">
            <div id="progress-bar" class="progress-bar"></div>
        </div>

        <div class="form-action-row">
            <button type="submit" class="master-update-btn">COMMIT TRANSMISSION</button>
        </div>

    </form>
</div>

<script src="assets/js/ss-engine-admin-ui.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/shortcode-toolbar.js"></script>
<?php include 'core/admin-footer.php'; ?>
