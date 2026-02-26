<?php
/**
 * SNAPSMACK - Metadata editor.
 * Orchestrates updates for image metadata, EXIF overrides, and taxonomy mappings.
 * Synchronizes publication status and scheduling with standard SQL timestamps.
 * Git Version Official Alpha 0.5
 */

require_once 'core/auth.php';

// --- 1. VALIDATION ---
// Ensure a valid record ID is provided before proceeding.
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { 
    header("Location: smack-manage.php"); 
    exit; 
}

$msg = "";

// --- 2. UPDATE HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $desc = $_POST['desc'];
    $status = $_POST['img_status'] ?? 'published';
    $orientation = (int)($_POST['img_orientation'] ?? 0);
    
    // --- CALENDAR FIX ---
    // Converts HTML5 datetime-local format ('T' separator) to standard SQL ' ' separator.
    $raw_date = $_POST['img_date'] ?? '';
    $custom_date = !empty($raw_date) ? str_replace('T', ' ', $raw_date) : date('Y-m-d H:i:s');

    $allow_comments = (int)($_POST['allow_comments'] ?? 1);
    $selected_cats = $_POST['cat_ids'] ?? [];
    $selected_albums = $_POST['album_ids'] ?? [];
    
    // Handle conditional N/A for film stock.
    $film_val = $_POST['film_stock'] ?? '';
    if (isset($_POST['film_na'])) { 
        $film_val = 'N/A'; 
    }

    // Compile Technical Specifications for JSON storage.
    $updated_exif = [
        'camera'   => strtoupper($_POST['camera_model'] ?? ''),
        'lens'     => $_POST['lens_info'] ?? '',
        'focal'    => $_POST['focal_length'] ?? '',
        'iso'      => $_POST['iso_speed'] ?? 'N/A',
        'aperture' => $_POST['aperture'] ?? '',
        'shutter'  => $_POST['shutter_speed'] ?? '',
        'flash'    => $_POST['flash_fire'] ?? 'No'
    ];

    // Primary record update.
    $stmt = $pdo->prepare("UPDATE snap_images SET img_title = ?, img_description = ?, img_film = ?, img_exif = ?, img_status = ?, img_date = ?, img_orientation = ?, allow_comments = ? WHERE id = ?");
    $stmt->execute([$title, $desc, $film_val, json_encode($updated_exif), $status, $custom_date, $orientation, $allow_comments, $id]);

    // Re-map categories (Purge and Re-populate pattern).
    $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id = ?")->execute([$id]);
    foreach ($selected_cats as $cid) { 
        $pdo->prepare("INSERT INTO snap_image_cat_map (image_id, cat_id) VALUES (?, ?)")->execute([$id, (int)$cid]); 
    }

    // Re-map missions/albums.
    $pdo->prepare("DELETE FROM snap_image_album_map WHERE image_id = ?")->execute([$id]);
    foreach ($selected_albums as $aid) { 
        $pdo->prepare("INSERT INTO snap_image_album_map (image_id, album_id) VALUES (?, ?)")->execute([$id, (int)$aid]); 
    }
    
    $msg = "Success: Mission parameters updated.";
}

// --- 3. DATA ACQUISITION ---
// Fetch the current record for form population.
$stmt = $pdo->prepare("SELECT * FROM snap_images WHERE id = ?");
$stmt->execute([$id]);
$post = $stmt->fetch();
if (!$post) { 
    die("Post not found."); 
}

$exif = json_decode($post['img_exif'], true) ?? [];

// Fetch existing taxonomy mappings.
$mapped_cats = $pdo->prepare("SELECT cat_id FROM snap_image_cat_map WHERE image_id = ?");
$mapped_cats->execute([$id]);
$mapped_cats = $mapped_cats->fetchAll(PDO::FETCH_COLUMN);

$mapped_albums = $pdo->prepare("SELECT album_id FROM snap_image_album_map WHERE image_id = ?");
$mapped_albums->execute([$id]);
$mapped_albums = $mapped_albums->fetchAll(PDO::FETCH_COLUMN);

// Load registry lists for select interfaces.
$all_cats = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
$all_albums = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();

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
            <div class="post-layout-grid" style="display: flex; align-items: stretch; gap: 30px;">
                
                <div class="post-col-left" style="flex: 1; display: flex; flex-direction: column;">
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

                    <div class="lens-input-wrapper" style="margin-top: 30px; flex-grow: 1; display: flex; flex-direction: column;">
                        <label>DESCRIPTION / STORY</label>
                        <textarea name="desc" style="flex-grow: 1; height: 100%;"><?php echo htmlspecialchars($post['img_description']); ?></textarea>
                    </div>
                </div>

                <div class="post-col-right" style="flex: 1;">
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
                        <label>ALLOW PUBLIC TRANSMISSIONS?</label>
                        <select name="allow_comments" class="full-width-select">
                            <option value="1" <?php echo ($post['allow_comments'] == 1) ? 'selected' : ''; ?>>Oh hell yes!</option>
                            <option value="0" <?php echo ($post['allow_comments'] == 0) ? 'selected' : ''; ?>>Nope nope nope!</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>REFERENCE SIGNAL</label>
                        <div class="preview-frame">
                            <img src="<?php echo $post['img_file']; ?>" class="swap-preview" style="max-width: 100%; border-radius: 4px; border: 1px solid rgba(255,255,255,0.1);">
                        </div>
                    </div>
                </div>
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
                    <div class="input-control-row" style="display:flex; gap:10px; align-items:center;">
                        <input type="text" name="lens_info" id="meta-lens" value="<?php echo htmlspecialchars($exif['lens'] ?? ''); ?>" style="flex-grow:1;">
                        <label class="built-in-label" style="margin:0; white-space:nowrap;"><input type="checkbox" id="fixed-lens-check" <?php echo (($exif['lens'] ?? '') === 'Built-in') ? 'checked' : ''; ?>> Built-in</label>
                    </div>
                </div>
                
                <div class="lens-input-wrapper">
                    <label>FOCAL LENGTH</label>
                    <input type="text" name="focal_length" value="<?php echo htmlspecialchars($exif['focal'] ?? ''); ?>">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>FILM STOCK</label>
                    <div class="input-control-row" style="display:flex; gap:10px; align-items:center;">
                        <input type="text" name="film_stock" id="meta-film" value="<?php echo htmlspecialchars($post['img_film'] ?? ''); ?>" style="flex-grow:1;">
                        <label class="built-in-label" style="margin:0; white-space:nowrap;"><input type="checkbox" name="film_na" id="film-na-check" <?php echo (($post['img_film'] ?? '') === 'N/A') ? 'checked' : ''; ?>> N/A</label>
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

<script src="assets/js/smack-ui-private.js?v=<?php echo time(); ?>"></script>
<script>
    window.addEventListener('DOMContentLoaded', () => {
        if(typeof updateLabel === "function") { 
            updateLabel('cat'); 
            updateLabel('album'); 
        }
    });
</script>
<?php include 'core/admin-footer.php'; ?>