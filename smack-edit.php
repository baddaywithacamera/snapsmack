<?php
/**
 * SNAPSMACK - Image metadata editor
 * Alpha v0.7.3a
 *
 * Allows modification of image titles, descriptions, EXIF metadata overrides,
 * publication status, and category/album associations.
 */

require_once 'core/auth.php';
require_once 'core/snap-tags.php';

// --- REQUEST VALIDATION ---
// Requires an image ID parameter to load the correct record.
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header("Location: smack-manage.php");
    exit;
}

$msg = "";

// --- EDIT-PAGE ROUTING ---
// If the active skin declares a custom edit page via manifest 'edit_page',
// delegate entirely to smack-edit-{value}.php. That file has access to $id
// (already validated above) and handles both GET and POST.
// Falls through silently if the key is absent or the file is missing.
$_ss_settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$_ss_settings = $_ss_settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$_ss_active_skin = $_ss_settings['active_skin'] ?? '';
if ($_ss_active_skin) {
    $_ss_manifest_path = __DIR__ . '/skins/' . $_ss_active_skin . '/manifest.php';
    if (is_file($_ss_manifest_path)) {
        $_ss_manifest = [];
        include $_ss_manifest_path;
        $_ss_edit_override = $_ss_manifest['edit_page'] ?? '';
        if ($_ss_edit_override) {
            $_ss_edit_file = __DIR__ . '/smack-edit-' . $_ss_edit_override . '.php';
            if (is_file($_ss_edit_file)) {
                include $_ss_edit_file;
                exit;
            }
        }
    }
}
unset($_ss_settings_stmt, $_ss_settings, $_ss_active_skin,
      $_ss_manifest_path, $_ss_manifest, $_ss_edit_override, $_ss_edit_file);

// --- FORM SUBMISSION HANDLER ---
// Processes metadata updates and category/album reassignments.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $desc = $_POST['desc'];
    $status = $_POST['img_status'] ?? 'published';
    $orientation = (int)($_POST['img_orientation'] ?? 0);

    // HTML5 datetime-local inputs use 'T' as separator; convert to SQL format.
    $raw_date = $_POST['img_date'] ?? '';
    $custom_date = !empty($raw_date) ? str_replace('T', ' ', $raw_date) : date('Y-m-d H:i:s');

    $allow_comments = (int)($_POST['allow_comments'] ?? 1);
    $allow_download = (int)($_POST['allow_download'] ?? 1);
    $download_url = trim($_POST['download_url'] ?? '');
    $selected_cats = $_POST['cat_ids'] ?? [];
    $selected_albums = $_POST['album_ids'] ?? [];

    // Film stock field supports explicit "N/A" via checkbox.
    $film_val = $_POST['film_stock'] ?? '';
    if (isset($_POST['film_na'])) {
        $film_val = 'N/A';
    }

    // Compile metadata into JSON for storage.
    $updated_exif = [
        'camera'   => strtoupper($_POST['camera_model'] ?? ''),
        'lens'     => $_POST['lens_info'] ?? '',
        'focal'    => $_POST['focal_length'] ?? '',
        'iso'      => $_POST['iso_speed'] ?? 'N/A',
        'aperture' => $_POST['aperture'] ?? '',
        'shutter'  => $_POST['shutter_speed'] ?? '',
        'flash'    => $_POST['flash_fire'] ?? 'No'
    ];

    // --- FRAME & DISPLAY OPTIONS ---
    // Merge user-submitted frame/mat/bevel settings into img_display_options JSON.
    $disp_stmt = $pdo->prepare("SELECT img_display_options FROM snap_images WHERE id = ?");
    $disp_stmt->execute([$id]);
    $existing_display_raw = $disp_stmt->fetchColumn();
    $existing_display = json_decode($existing_display_raw ?: '{}', true) ?: [];

    if (isset($_POST['reset_frame_options'])) {
        // Reset: keep palette but clear frame overrides.
        $display_opts = [];
        if (!empty($existing_display['palette'])) {
            $display_opts['palette'] = $existing_display['palette'];
        }
    } else {
        $display_opts = $existing_display;
        if (isset($_POST['frame_color']) && $_POST['frame_color'] !== '') {
            $display_opts['frame_color'] = $_POST['frame_color'];
        }
        if (isset($_POST['frame_width']) && $_POST['frame_width'] !== '') {
            $display_opts['frame_width'] = (int)$_POST['frame_width'];
        }
        if (isset($_POST['mat_color']) && $_POST['mat_color'] !== '') {
            $display_opts['mat_color'] = $_POST['mat_color'];
        }
        if (isset($_POST['mat_width']) && $_POST['mat_width'] !== '') {
            $display_opts['mat_width'] = (int)$_POST['mat_width'];
        }
        if (isset($_POST['bevel_style'])) {
            $display_opts['bevel_style'] = $_POST['bevel_style'];
        }
    }
    $display_json = !empty($display_opts) ? json_encode($display_opts) : null;

    // Update the primary image record with all modified fields.
    $stmt = $pdo->prepare("UPDATE snap_images SET img_title = ?, img_description = ?, img_film = ?, img_exif = ?, img_status = ?, img_date = ?, img_orientation = ?, allow_comments = ?, allow_download = ?, download_url = ?, img_display_options = ? WHERE id = ?");
    $stmt->execute([$title, $desc, $film_val, json_encode($updated_exif), $status, $custom_date, $orientation, $allow_comments, $allow_download, $download_url, $display_json, $id]);

    // Sync hashtags extracted from description
    snap_sync_tags($pdo, $id, $desc);

    // Delete and re-populate category mappings.
    $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id = ?")->execute([$id]);
    foreach ($selected_cats as $cid) {
        $pdo->prepare("INSERT INTO snap_image_cat_map (image_id, cat_id) VALUES (?, ?)")->execute([$id, (int)$cid]);
    }

    // Delete and re-populate album mappings.
    $pdo->prepare("DELETE FROM snap_image_album_map WHERE image_id = ?")->execute([$id]);
    foreach ($selected_albums as $aid) {
        $pdo->prepare("INSERT INTO snap_image_album_map (image_id, album_id) VALUES (?, ?)")->execute([$id, (int)$aid]);
    }

    $msg = "Success: Mission parameters updated.";
}

// --- DATA RETRIEVAL ---
// Load the image record and its associated metadata for editing.
$stmt = $pdo->prepare("SELECT * FROM snap_images WHERE id = ?");
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) {
    die("Post not found.");
}

$exif = json_decode($post['img_exif'], true) ?? [];
$display_opts = json_decode($post['img_display_options'] ?? '{}', true) ?: [];
$palette = $display_opts['palette'] ?? [];

// Load the image's current category associations.
$mapped_cats = $pdo->prepare("SELECT cat_id FROM snap_image_cat_map WHERE image_id = ?");
$mapped_cats->execute([$id]);
$mapped_cats = $mapped_cats->fetchAll(PDO::FETCH_COLUMN);

// Load the image's current album associations.
$mapped_albums = $pdo->prepare("SELECT album_id FROM snap_image_album_map WHERE image_id = ?");
$mapped_albums->execute([$id]);
$mapped_albums = $mapped_albums->fetchAll(PDO::FETCH_COLUMN);

// Load all available categories and albums for the form selectors.
$all_cats = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
$all_albums = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();

// Count likes and comments for display
$like_count = $pdo->prepare("SELECT COUNT(*) FROM snap_likes WHERE post_id = ?");
$like_count->execute([$id]);
$like_count = (int)$like_count->fetchColumn();

$comment_count = $pdo->prepare("SELECT COUNT(*) FROM snap_community_comments WHERE post_id = ? AND status = 'visible'");
$comment_count->execute([$id]);
$comment_count = (int)$comment_count->fetchColumn();

// Download count from the image record
$download_count = (int)($post['img_download_count'] ?? 0);

$page_title = "Edit Meta";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>EDIT METADATA: <?php echo htmlspecialchars($post['img_title']); ?></h2>
    </div>
    
    <?php if($msg) echo "<div class='alert box alert-success'>$msg</div>"; ?>

    <form id="smack-form-edit" method="POST">
        
        <div class="box">
            <div class="post-layout-grid">
                
                <div class="post-col-left">
                    <div class="lens-input-wrapper">
                        <label>IMAGE TITLE</label>
                        <input type="text" name="title" value="<?php echo htmlspecialchars($post['img_title']); ?>" required>
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
                                                    <input type="checkbox" name="cat_ids[]" value="<?php echo $c['id']; ?>" <?php echo in_array($c['id'], $mapped_cats) ? 'checked' : ''; ?> onchange="updateLabel('cat')">
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
                                                    <input type="checkbox" name="album_ids[]" value="<?php echo $a['id']; ?>" <?php echo in_array($a['id'], $mapped_albums) ? 'checked' : ''; ?> onchange="updateLabel('album')">
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
                        <textarea id="desc" name="desc" placeholder="Plain text. Blank lines become paragraph breaks."><?php echo htmlspecialchars($post['img_description']); ?></textarea>
                    </div>
                </div>

                <div class="post-col-right">
                    <div class="lens-input-wrapper">
                        <label>ENGAGEMENT METRICS</label>
                        <div class="engagement-stats">
                            <span class="stat-item">❤️ Likes: <strong><?php echo $like_count; ?></strong></span>
                            <span class="stat-item">💬 Transmissions: <strong><?php echo $comment_count; ?></strong></span>
                            <span class="stat-item">⬇️ Downloads: <strong><?php echo $download_count; ?></strong></span>
                        </div>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>PUBLICATION STATUS</label>
                        <select name="img_status" class="full-width-select">
                            <option value="published" <?php echo ($post['img_status'] === 'published') ? 'selected' : ''; ?>>Published</option>
                            <option value="draft" <?php echo ($post['img_status'] === 'draft') ? 'selected' : ''; ?>>Draft</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>ORIENTATION OVERRIDE</label>
                        <select name="img_orientation" class="full-width-select">
                            <option value="0" <?php echo ($post['img_orientation'] == 0) ? 'selected' : ''; ?>>Landscape</option>
                            <option value="1" <?php echo ($post['img_orientation'] == 1) ? 'selected' : ''; ?>>Portrait</option>
                            <option value="2" <?php echo ($post['img_orientation'] == 2) ? 'selected' : ''; ?>>Square</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>INTERNAL TIMESTAMP</label>
                        <input type="datetime-local" name="img_date" class="full-width-select edit-timestamp" 
                               onclick="this.showPicker()"
                               value="<?php echo date('Y-m-d\TH:i', strtotime($post['img_date'])); ?>">
                    </div>

                    <div class="lens-input-wrapper">
                        <label>ALLOW PUBLIC SIGNALS?</label>
                        <select name="allow_comments" class="full-width-select">
                            <option value="1" <?php echo ($post['allow_comments'] == 1) ? 'selected' : ''; ?>>Oh hell yes!</option>
                            <option value="0" <?php echo ($post['allow_comments'] == 0) ? 'selected' : ''; ?>>Nope nope nope!</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>ALLOW DOWNLOAD?</label>
                        <select name="allow_download" class="full-width-select">
                            <option value="1" <?php echo ($post['allow_download'] == 1) ? 'selected' : ''; ?>>ENABLED</option>
                            <option value="0" <?php echo ($post['allow_download'] == 0) ? 'selected' : ''; ?>>DISABLED</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>DOWNLOAD URL (EXTERNAL)</label>
                        <input type="text" name="download_url" value="<?php echo htmlspecialchars($post['download_url'] ?? ''); ?>" placeholder="Google Drive, Dropbox, etc. Leave blank for local file.">
                    </div>

                    <div class="lens-input-wrapper">
                        <label>REFERENCE SIGNAL</label>
                        <div class="preview-frame">
                            <img src="<?php echo $post['img_file']; ?>" class="swap-preview">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="box">
            <h3>FRAME & DISPLAY OPTIONS</h3>
            <p class="dim exif-hint">Per-image picture frame customisation for gallery skins. Leave at defaults to use skin-wide settings.</p>

            <div class="meta-grid">
                <div class="lens-input-wrapper">
                    <label>FRAME COLOUR</label>
                    <div class="frame-color-row">
                        <input type="color" name="frame_color" value="<?php echo htmlspecialchars($display_opts['frame_color'] ?? '#2c2017'); ?>" class="color-picker-input">
                        <?php if (!empty($palette)): ?>
                            <div class="palette-swatches" data-target="frame_color">
                                <?php foreach ($palette as $hex): ?>
                                    <span class="swatch" style="background:<?php echo htmlspecialchars($hex); ?>" data-color="<?php echo htmlspecialchars($hex); ?>" title="<?php echo htmlspecialchars($hex); ?>"></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="lens-input-wrapper">
                    <label>FRAME WIDTH <span class="range-val" id="frame-width-val"><?php echo (int)($display_opts['frame_width'] ?? 8); ?>px</span></label>
                    <input type="range" name="frame_width" min="3" max="20" value="<?php echo (int)($display_opts['frame_width'] ?? 8); ?>" oninput="document.getElementById('frame-width-val').textContent=this.value+'px'">
                </div>

                <div class="lens-input-wrapper">
                    <label>MAT COLOUR</label>
                    <div class="frame-color-row">
                        <input type="color" name="mat_color" value="<?php echo htmlspecialchars($display_opts['mat_color'] ?? '#f5f0eb'); ?>" class="color-picker-input">
                        <?php if (!empty($palette)): ?>
                            <div class="palette-swatches" data-target="mat_color">
                                <?php foreach ($palette as $hex): ?>
                                    <span class="swatch" style="background:<?php echo htmlspecialchars($hex); ?>" data-color="<?php echo htmlspecialchars($hex); ?>" title="<?php echo htmlspecialchars($hex); ?>"></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="lens-input-wrapper">
                    <label>MAT WIDTH <span class="range-val" id="mat-width-val"><?php echo (int)($display_opts['mat_width'] ?? 24); ?>px</span></label>
                    <input type="range" name="mat_width" min="8" max="60" value="<?php echo (int)($display_opts['mat_width'] ?? 24); ?>" oninput="document.getElementById('mat-width-val').textContent=this.value+'px'">
                </div>

                <div class="lens-input-wrapper">
                    <label>BEVEL STYLE</label>
                    <select name="bevel_style" class="full-width-select">
                        <option value="none" <?php echo ($display_opts['bevel_style'] ?? 'single') === 'none' ? 'selected' : ''; ?>>None</option>
                        <option value="single" <?php echo ($display_opts['bevel_style'] ?? 'single') === 'single' ? 'selected' : ''; ?>>Single</option>
                        <option value="double" <?php echo ($display_opts['bevel_style'] ?? 'single') === 'double' ? 'selected' : ''; ?>>Double</option>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>EXTRACTED PALETTE</label>
                    <?php if (!empty($palette)): ?>
                        <div class="palette-preview-row">
                            <?php foreach ($palette as $hex): ?>
                                <span class="palette-chip" style="background:<?php echo htmlspecialchars($hex); ?>" title="<?php echo htmlspecialchars($hex); ?> (click to copy)" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($hex); ?>')"></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="dim">No palette extracted. Run backfill or re-upload.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-action-row" style="margin-top:10px;">
                <button type="submit" name="reset_frame_options" value="1" class="btn-secondary" onclick="return confirm('Reset frame options to skin defaults?')">RESET TO DEFAULTS</button>
            </div>
        </div>

        <div class="box">
            <h3>TECHNICAL SPECIFICATIONS (EXIF OVERRIDES)</h3>
            
            <div class="meta-grid">
                <div class="lens-input-wrapper">
                    <label>CAMERA MODEL</label>
                    <input type="text" name="camera_model" value="<?php echo htmlspecialchars($exif['camera'] ?? ''); ?>">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>LENS INFO</label>
                    <div class="input-control-row">
                        <input type="text" name="lens_info" id="meta-lens" value="<?php echo htmlspecialchars($exif['lens'] ?? ''); ?>" >
                        <label class="built-in-label" ><input type="checkbox" id="fixed-lens-check" <?php echo (($exif['lens'] ?? '') === 'Built-in') ? 'checked' : ''; ?>> Built-in</label>
                    </div>
                </div>
                
                <div class="lens-input-wrapper">
                    <label>FOCAL LENGTH</label>
                    <input type="text" name="focal_length" value="<?php echo htmlspecialchars($exif['focal'] ?? ''); ?>">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>FILM STOCK</label>
                    <div class="input-control-row">
                        <input type="text" name="film_stock" id="meta-film" value="<?php echo htmlspecialchars($post['img_film'] ?? ''); ?>" >
                        <label class="built-in-label" ><input type="checkbox" name="film_na" id="film-na-check" <?php echo (($post['img_film'] ?? '') === 'N/A') ? 'checked' : ''; ?>> N/A</label>
                    </div>
                </div>
                
                <div class="lens-input-wrapper">
                    <label>ISO</label>
                    <input type="text" name="iso_speed" value="<?php echo htmlspecialchars($exif['iso'] ?? ''); ?>">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>APERTURE</label>
                    <input type="text" name="aperture" value="<?php echo htmlspecialchars($exif['aperture'] ?? ''); ?>">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>SHUTTER SPEED</label>
                    <input type="text" name="shutter_speed" value="<?php echo htmlspecialchars($exif['shutter'] ?? ''); ?>">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>FLASH FIRED</label>
                    <select name="flash_fire" class="full-width-select">
                        <option value="No" <?php echo ($exif['flash'] ?? '') === 'No' ? 'selected' : ''; ?>>No</option>
                        <option value="Yes" <?php echo ($exif['flash'] ?? '') === 'Yes' ? 'selected' : ''; ?>>Yes</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="master-update-btn">SAVE CHANGES</button>
        </div>
    </form>
</div>

<style>
.frame-color-row { display: flex; align-items: center; gap: 8px; }
.color-picker-input { width: 50px; height: 34px; border: 1px solid #333; padding: 2px; cursor: pointer; background: #111; }
.palette-swatches { display: flex; gap: 4px; }
.palette-swatches .swatch { width: 24px; height: 24px; border-radius: 3px; cursor: pointer; border: 1px solid #444; transition: transform 0.15s; }
.palette-swatches .swatch:hover { transform: scale(1.2); border-color: #aaa; }
.palette-preview-row { display: flex; gap: 6px; }
.palette-chip { width: 36px; height: 36px; border-radius: 4px; cursor: pointer; border: 1px solid #444; transition: transform 0.15s; }
.palette-chip:hover { transform: scale(1.15); border-color: #aaa; }
.range-val { color: #888; font-size: 0.85em; margin-left: 6px; }
.btn-secondary { background: #333; color: #aaa; border: 1px solid #555; padding: 6px 14px; cursor: pointer; font-size: 0.8em; text-transform: uppercase; }
.btn-secondary:hover { background: #444; color: #fff; }
</style>
<script src="assets/js/ss-engine-admin-ui.js?v=<?php echo time(); ?>"></script>
<script>
    window.addEventListener('DOMContentLoaded', () => {
        if(typeof updateLabel === "function") { 
            updateLabel('cat'); 
            updateLabel('album'); 
        }
    });
</script>
<script src="assets/js/shortcode-toolbar.js"></script>
<script>
// Palette swatch click → set corresponding colour picker
document.querySelectorAll('.palette-swatches .swatch').forEach(s => {
    s.addEventListener('click', () => {
        const target = s.closest('.palette-swatches').dataset.target;
        const input = document.querySelector('input[name="' + target + '"]');
        if (input) input.value = s.dataset.color;
    });
});
</script>
<?php include 'core/admin-footer.php'; ?>