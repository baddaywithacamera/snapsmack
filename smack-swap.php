<?php
/**
 * SnapSmack - Swap Image
 * Version: 3.9 - Precision Restore & Hint Alignment
 * MASTER DIRECTIVE: Full file return. All logic preserved.
 */
require_once 'core/auth.php';
require_once 'core/fix-exif.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM snap_images WHERE id = ?");
$stmt->execute([$id]);
$img = $stmt->fetch();

if (!$img) { die("Signal lost."); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['new_image'])) {
    if ($_FILES['new_image']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['new_image']['tmp_name'];
        $mime = mime_content_type($tmp);
        
        $camera_manual = $_POST['camera_model'] ?? '';
        $lens_manual   = $_POST['lens_info'] ?? '';
        $focal_manual  = $_POST['focal_length'] ?? '';
        $film_manual   = $_POST['film_stock'] ?? '';
        if (isset($_POST['film_na'])) { $film_manual = 'N/A'; }

        $iso_manual      = $_POST['iso_speed'] ?? '';
        $aperture_manual = $_POST['aperture'] ?? '';
        $shutter_manual  = $_POST['shutter_speed'] ?? '';
        $flash_manual    = $_POST['flash_fire'] ?? 'No';
        $orientation     = (int)($_POST['img_orientation'] ?? 0);

        // Delete old assets
        if (file_exists($img['img_file'])) { unlink($img['img_file']); }
        $old_thumb = str_replace('img_uploads/', 'img_uploads/thumbs/t_', $img['img_file']);
        if (file_exists($old_thumb)) { unlink($old_thumb); }

        $raw_harvest = [];
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $raw_exif = @exif_read_data($tmp);
            $raw_harvest['Model'] = !empty($camera_manual) ? $camera_manual : ($raw_exif['Model'] ?? 'N/A');
            $raw_harvest['ExposureTime'] = !empty($shutter_manual) ? $shutter_manual : ($raw_exif['ExposureTime'] ?? 'N/A');
            $raw_harvest['FNumber'] = !empty($aperture_manual) ? $aperture_manual : ($raw_exif['FNumber'] ?? 'N/A');
            $raw_harvest['ISOSpeedRatings'] = !empty($iso_manual) ? $iso_manual : ($raw_exif['ISOSpeedRatings'] ?? 'N/A');
            $raw_harvest['FocalLength'] = !empty($focal_manual) ? $focal_manual : ($raw_exif['FocalLength'] ?? 'N/A');
        }

        $final_exif = get_smack_exif($raw_harvest);
        $final_exif['lens'] = $lens_manual;
        $final_exif['film'] = $film_manual;
        $final_exif['flash'] = $flash_manual;

        list($orig_w, $orig_h) = getimagesize($tmp);
        $thumb_size = 400;
        $crop_size = min($orig_w, $orig_h);
        $cx = ($orig_w - $crop_size) / 2;
        $cy = ($orig_h - $crop_size) / 2;

        if ($mime == 'image/jpeg') { $src = @imagecreatefromjpeg($tmp); } 
        elseif ($mime == 'image/png') { $src = @imagecreatefrompng($tmp); } 
        elseif ($mime == 'image/webp') { $src = @imagecreatefromwebp($tmp); }

        $t_dst = imagecreatetruecolor($thumb_size, $thumb_size);
        if ($mime != 'image/jpeg') {
            imagealphablending($t_dst, false);
            imagesavealpha($t_dst, true);
        }
        imagecopyresampled($t_dst, $src, 0, 0, $cx, $cy, $thumb_size, $thumb_size, $crop_size, $crop_size);

        $dir = dirname($img['img_file']);
        $thumb_path = $dir . '/thumbs';
        if (!is_dir($thumb_path)) { mkdir($thumb_path, 0755, true); }

        $base_fn = time() . '_' . preg_replace("/[^a-zA-Z0-9]/", "", $img['img_title']);
        $ext = ($mime == 'image/png') ? '.png' : (($mime == 'image/webp') ? '.webp' : '.jpg');
        $new_path = $dir . '/' . $base_fn . $ext;
        $save_thumb = $thumb_path . '/t_' . $base_fn . $ext;

        if (move_uploaded_file($tmp, $new_path)) {
            if ($mime == 'image/png') { imagepng($t_dst, $save_thumb, 8); } 
            elseif ($mime == 'image/webp') { imagewebp($t_dst, $save_thumb, 60); } 
            else { imagejpeg($t_dst, $save_thumb, 70); }

            imagedestroy($src); imagedestroy($t_dst);

            $sql = "UPDATE snap_images SET img_file = ?, img_film = ?, img_exif = ?, img_width = ?, img_height = ?, img_orientation = ? WHERE id = ?";
            $pdo->prepare($sql)->execute([$new_path, $film_manual, json_encode($final_exif), $orig_w, $orig_h, $orientation, $id]);

            header("Location: smack-manage.php?msg=swapped");
            exit;
        }
    }
}

$page_title = "Swap Image";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="section-header">
        <h2>SWAP IMAGE</h2>
        <p class="field-hint">TARGET: <?php echo strtoupper(htmlspecialchars($img['img_title'])); ?></p>
    </div>

    <div class="box">
        <form id="smack-form" method="POST" enctype="multipart/form-data">
            <div class="post-layout-grid">
                
                <div class="post-col-left">
                    <label>CURRENT SIGNAL</label>
                    <div class="preview-frame">
                        <img src="<?php echo $img['img_file']; ?>" class="swap-preview">
                    </div>
                    <p class="field-hint mt-20">
                        <strong>NOTE:</strong> Replacing this file will permanently delete the current asset and regenerate its thumbnail.
                    </p>
                </div>

                <div class="post-col-right">
                    <label>SELECT REPLACEMENT FILE</label>
                    <div class="file-upload-wrapper mb-25">
                        <input type="file" name="new_image" id="file-input" required class="full-width-select">
                    </div>

                    <label>ORIENTATION OVERRIDE</label>
                    <select name="img_orientation" class="full-width-select mb-25">
                        <option value="0" <?php echo ($img['img_orientation'] == 0) ? 'selected' : ''; ?>>Landscape</option>
                        <option value="1" <?php echo ($img['img_orientation'] == 1) ? 'selected' : ''; ?>>Portrait</option>
                        <option value="2" <?php echo ($img['img_orientation'] == 2) ? 'selected' : ''; ?>>Square</option>
                    </select>

                    <p class="field-hint">
                        Metadata is re-harvested from the new file. Use overrides below if file lacks EXIF data.
                    </p>
                </div>
            </div>

            <hr class="section-divider">

            <label>TECHNICAL OVERRIDES (OPTIONAL)</label>
            <div class="meta-grid">
                <div class="lens-input-wrapper">
                    <label>CAMERA MODEL</label>
                    <input type="text" name="camera_model" placeholder="Auto-detect if blank">
                </div>
                <div class="lens-input-wrapper">
                    <label>LENS INFO</label>
                    <div class="input-control-row">
                        <input type="text" name="lens_info" id="meta-lens">
                        <label class="built-in-label"><input type="checkbox" name="fixed_lens" id="fixed-lens-check"> Built-in</label>
                    </div>
                </div>
                <div class="lens-input-wrapper"><label>FOCAL LENGTH</label><input type="text" name="focal_length"></div>
                <div class="lens-input-wrapper">
                    <label>FILM STOCK</label>
                    <div class="input-control-row">
                        <input type="text" name="film_stock" id="meta-film">
                        <label class="built-in-label"><input type="checkbox" name="film_na" id="film-na-check"> N/A</label>
                    </div>
                </div>
                <div><label>ISO</label><input type="text" name="iso_speed"></div>
                <div><label>APERTURE</label><input type="text" name="aperture"></div>
                <div><label>SHUTTER SPEED</label><input type="text" name="shutter_speed"></div>
                <div>
                    <label>FLASH FIRED</label>
                    <select name="flash_fire" class="full-width-select">
                        <option value="No">No</option>
                        <option value="Yes">Yes</option>
                    </select>
                </div>
            </div>

            <div class="form-actions mt-40">
                <button type="submit" class="master-update-btn">PERFORM SWAP</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/smack-ui-private.js?v=<?php echo time(); ?>"></script>
<?php include 'core/admin-footer.php'; ?>