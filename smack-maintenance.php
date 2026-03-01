<?php
/**
 * SNAPSMACK - System maintenance.
 * Performs database optimizations, taxonomy cleanup, and asset synchronization.
 * Clears orphaned mappings and defragments core tables to maintain performance.
 * 
 * Asset sync now generates:
 *   t_  — 400x400 center-cropped square
 *   a_  — 400px on the long side, aspect preserved
 *
 * Git Version Official Alpha 0.5
 */

require_once 'core/auth.php';

$log = [];

// --- ACTION HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    // 1. REGISTRY SYNC
    // Removes ghost entries in the mapping table for images that have been deleted.
    if ($action === 'sync_cats') {
        $stmt = $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id NOT IN (SELECT id FROM snap_images)");
        $stmt->execute();
        $deleted = $stmt->rowCount();
        $log[] = "SUCCESS: Purged $deleted orphaned category mappings.";
    }

    // 2. DB OPTIMIZATION
    // Forces MySQL to defragment and optimize core operational tables.
    if ($action === 'optimize') {
        $pdo->query("OPTIMIZE TABLE snap_images, snap_categories, snap_image_cat_map");
        $log[] = "SUCCESS: Database tables optimized and defragmented.";
    }

    // 3. ASSET SYNC
    // Regenerates missing thumbnails and deletes physical files not found in the DB.
    if ($action === 'sync_assets') {
        set_time_limit(600);
        ini_set('memory_limit', '512M');
        
        $images = $pdo->query("SELECT id, img_title, img_file FROM snap_images")->fetchAll(PDO::FETCH_ASSOC);
        $registered_paths = [];
        $fixed_square = 0;
        $fixed_aspect = 0;
        $purged_orphans = 0;

        foreach ($images as $img) {
            $file = $img['img_file'];
            if (!file_exists($file)) continue;

            $registered_paths[] = realpath($file);
            $path_info = pathinfo($file);
            $thumb_dir = $path_info['dirname'] . '/thumbs';
            
            // Ensure thumbs directory exists
            if (!is_dir($thumb_dir)) {
                mkdir($thumb_dir, 0755, true);
            }
            
            // Expected thumbnail locations
            $sq_thumb = $thumb_dir . '/t_' . $path_info['basename'];
            $aspect_thumb = $thumb_dir . '/a_' . $path_info['basename'];
            
            // Register existing thumbs as valid
            if (file_exists($sq_thumb)) $registered_paths[] = realpath($sq_thumb);
            if (file_exists($aspect_thumb)) $registered_paths[] = realpath($aspect_thumb);

            // Rebuild missing thumbnails
            $need_square = !file_exists($sq_thumb);
            $need_aspect = !file_exists($aspect_thumb);

            if ($need_square || $need_aspect) {
                list($orig_w, $orig_h) = getimagesize($file);
                $mime = mime_content_type($file);
                $src = null;

                if ($mime == 'image/jpeg') { $src = @imagecreatefromjpeg($file); } 
                elseif ($mime == 'image/png') { $src = @imagecreatefrompng($file); } 
                elseif ($mime == 'image/webp') { $src = @imagecreatefromwebp($file); }

                if ($src) {
                    // --- SQUARE THUMB (t_) — 400x400 center-cropped ---
                    if ($need_square) {
                        $sq_size = 400;
                        $min_dim = min($orig_w, $orig_h);
                        $off_x = ($orig_w - $min_dim) / 2;
                        $off_y = ($orig_h - $min_dim) / 2;

                        $sq_dst = imagecreatetruecolor($sq_size, $sq_size);
                        if ($mime != 'image/jpeg') { imagealphablending($sq_dst, false); imagesavealpha($sq_dst, true); }
                        imagecopyresampled($sq_dst, $src, 0, 0, $off_x, $off_y, $sq_size, $sq_size, $min_dim, $min_dim);

                        if ($mime == 'image/png') imagepng($sq_dst, $sq_thumb, 8);
                        elseif ($mime == 'image/webp') imagewebp($sq_dst, $sq_thumb, 78);
                        else imagejpeg($sq_dst, $sq_thumb, 82);

                        imagedestroy($sq_dst);
                        $registered_paths[] = realpath($sq_thumb);
                        $fixed_square++;
                    }

                    // --- ASPECT THUMB (a_) — 400px on the long side ---
                    if ($need_aspect) {
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

                        $a_dst = imagecreatetruecolor($a_w, $a_h);
                        if ($mime != 'image/jpeg') { imagealphablending($a_dst, false); imagesavealpha($a_dst, true); }
                        imagecopyresampled($a_dst, $src, 0, 0, 0, 0, $a_w, $a_h, $orig_w, $orig_h);

                        if ($mime == 'image/png') imagepng($a_dst, $aspect_thumb, 8);
                        elseif ($mime == 'image/webp') imagewebp($a_dst, $aspect_thumb, 78);
                        else imagejpeg($a_dst, $aspect_thumb, 82);

                        imagedestroy($a_dst);
                        $registered_paths[] = realpath($aspect_thumb);
                        $fixed_aspect++;
                    }

                    imagedestroy($src);
                }
            }
        }

        // Scan upload directory and delete unregistered files.
        if (is_dir('img_uploads')) {
            $upload_dir = new RecursiveDirectoryIterator('img_uploads');
            $iterator = new RecursiveIteratorIterator($upload_dir);
            foreach ($iterator as $file_info) {
                if ($file_info->isFile()) {
                    $f_path = $file_info->getRealPath();
                    $ext = strtolower($file_info->getExtension());
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) && !in_array($f_path, $registered_paths)) {
                        unlink($f_path);
                        $purged_orphans++;
                    }
                }
            }
        }
        $log[] = "SUCCESS: Generated $fixed_square square thumbs + $fixed_aspect aspect thumbs. Purged $purged_orphans orphan files.";
    }
}

$page_title = "System Maintenance";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>SYSTEM MAINTENANCE</h2>

    <?php foreach($log as $entry): ?>
        <div class="alert alert-success">> <?php echo $entry; ?></div>
    <?php endforeach; ?>

    <div class="dash-grid">
        <div class="box">
            <h3>REGISTRY SYNC</h3>
            <p class="skin-desc-text">Fixes category count mismatches by purging "ghost" data from deleted images.</p>
            <br>
            <form method="POST">
                <input type="hidden" name="action" value="sync_cats">
                <button type="submit" class="btn-smack btn-block">SYNC REGISTRY</button>
            </form>
        </div>

        <div class="box">
            <h3>DB OPTIMIZATION</h3>
            <p class="skin-desc-text">Defragments tables and recreates indexes. Boosts dashboard and site transmission speeds.</p>
            <br>
            <form method="POST">
                <input type="hidden" name="action" value="optimize">
                <button type="submit" class="btn-smack btn-block">OPTIMIZE TABLES</button>
            </form>
        </div>

        <div class="box">
            <h3>ASSET SYNC</h3>
            <p class="skin-desc-text">Rebuilds missing square (t_) and aspect (a_) thumbnails. <strong>Terminates</strong> all files not found in the database registry.</p>
            <br>
            <form method="POST">
                <input type="hidden" name="action" value="sync_assets">
                <button type="submit" class="btn-smack btn-block">SYNC & PRUNE ASSETS</button>
            </form>
        </div>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
