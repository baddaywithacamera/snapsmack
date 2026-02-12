<?php
/**
 * SnapSmack - System Maintenance
 * Version: 2.8 - Clean Template Build
 * MASTER DIRECTIVE: Full file return. 
 */
require_once 'core/auth.php';

$log = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];

    if ($action === 'sync_cats') {
        $stmt = $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id NOT IN (SELECT id FROM snap_images)");
        $stmt->execute();
        $deleted = $stmt->rowCount();
        $log[] = "SUCCESS: Purged $deleted orphaned category mappings.";
    }

    if ($action === 'optimize') {
        $pdo->query("OPTIMIZE TABLE snap_images, snap_categories, snap_image_cat_map");
        $log[] = "SUCCESS: Database tables optimized and defragmented.";
    }

    if ($action === 'sync_assets') {
        set_time_limit(600);
        ini_set('memory_limit', '512M');
        
        $images = $pdo->query("SELECT id, img_title, img_file FROM snap_images")->fetchAll(PDO::FETCH_ASSOC);
        $registered_paths = [];
        $fixed_thumbs = 0;
        $purged_orphans = 0;

        foreach ($images as $img) {
            $file = $img['img_file'];
            if (!file_exists($file)) continue;

            $registered_paths[] = realpath($file);
            $path_info = pathinfo($file);
            
            $sq_thumb = $path_info['dirname'] . '/thumbs/t_' . $path_info['basename'];
            $wall_thumb = $path_info['dirname'] . '/thumbs/wall_' . $path_info['basename'];
            
            if (file_exists($sq_thumb)) $registered_paths[] = realpath($sq_thumb);
            if (file_exists($wall_thumb)) $registered_paths[] = realpath($wall_thumb);

            if (!file_exists($wall_thumb)) {
                list($orig_w, $orig_h) = getimagesize($file);
                $wall_h = 500;
                $wall_w = round($orig_w * ($wall_h / $orig_h));
                $mime = mime_content_type($file);

                if ($mime == 'image/jpeg') { $src = @imagecreatefromjpeg($file); } 
                elseif ($mime == 'image/png') { $src = @imagecreatefrompng($file); } 
                elseif ($mime == 'image/webp') { $src = @imagecreatefromwebp($file); } 

                if (isset($src)) {
                    $w_dst = imagecreatetruecolor($wall_w, $wall_h);
                    if ($mime != 'image/jpeg') { imagealphablending($w_dst, false); imagesavealpha($w_dst, true); }
                    imagecopyresampled($w_dst, $src, 0, 0, 0, 0, $wall_w, $wall_h, $orig_w, $orig_h);
                    
                    if ($mime == 'image/png') imagepng($w_dst, $wall_thumb, 8);
                    elseif ($mime == 'image/webp') imagewebp($w_dst, $wall_thumb, 60);
                    else imagejpeg($w_dst, $wall_thumb, 80);
                    
                    imagedestroy($src); imagedestroy($w_dst);
                    $registered_paths[] = realpath($wall_thumb);
                    $fixed_thumbs++;
                }
            }
        }

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
        $log[] = "SUCCESS: Generated $fixed_thumbs wall thumbs and purged $purged_orphans orphan files.";
    }
}

$page_title = "Maintenance";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>System Maintenance</h2>

    <?php foreach($log as $entry): ?>
        <div class="msg">> <?php echo $entry; ?></div>
    <?php endforeach; ?>

    <div class="dash-grid">
        
        <div class="box">
            <h3>Registry Sync</h3>
            <p class="maint-desc">
                Fixes category count mismatches by purging "ghost" data from deleted images.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="sync_cats">
                <button type="submit" class="maint-btn btn-blue">SYNC REGISTRY</button>
            </form>
        </div>

        <div class="box">
            <h3>DB Optimization</h3>
            <p class="maint-desc">
                Defragments tables and recreates indexes. This significantly boosts dashboard load speed.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="optimize">
                <button type="submit" class="maint-btn btn-green">OPTIMIZE TABLES</button>
            </form>
        </div>

        <div class="box">
            <h3>Asset Purge</h3>
            <p class="maint-desc">
                Generates Wall Thumbs and <strong>deletes</strong> all files not found in the database registry.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="sync_assets">
                <button type="submit" class="maint-btn btn-orange">SYNC & PRUNE ASSETS</button>
            </form>
        </div>

    </div>
</div>

<?php include 'core/admin-footer.php'; ?>