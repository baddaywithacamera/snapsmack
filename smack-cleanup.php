<?php
/**
 * SnapSmack - Asset & Database Sync Utility
 * Version: 5.11 - Theme Integrated
 * Purpose: Generates Wall-Thumbs and prunes orphan files with correct admin styling.
 */

require_once 'core/auth.php';

set_time_limit(600);
ini_set('memory_limit', '512M');

$page_title = "System Sync";
include 'core/admin-header.php'; 
include 'core/sidebar.php';
?>

<div class="main">
    <h2>SYSTEM SYNC & REPAIR...</h2>

    <div class="box">
        <?php
        // 1. GET ALL REGISTERED FILES FROM DB
        $stmt = $pdo->query("SELECT id, img_title, img_file FROM snap_images");
        $db_images = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $registered_files = [];

        $fixed = 0;
        $skipped = 0;

        echo "<h3>STEP 1: REPAIRING REGISTERED ASSETS</h3>";
        echo "<div class='signal-body' style='max-height: 300px; overflow-y: auto; margin-bottom: 20px;'>";

        foreach ($db_images as $img) {
            $file = $img['img_file'];
            $registered_files[] = realpath($file); 
            
            $path_info = pathinfo($file);
            $wall_thumb = $path_info['dirname'] . '/thumbs/wall_' . $path_info['basename'];
            $sq_thumb = $path_info['dirname'] . '/thumbs/t_' . $path_info['basename'];
            
            if(file_exists($wall_thumb)) $registered_files[] = realpath($wall_thumb);
            if(file_exists($sq_thumb)) $registered_files[] = realpath($sq_thumb);

            if (!file_exists($file)) {
                echo "<div style='color:orange;'>[MISSING] {$img['img_title']}</div>";
                continue;
            }

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
                    
                    if ($mime == 'image/png') { imagepng($w_dst, $wall_thumb, 8); } 
                    elseif ($mime == 'image/webp') { imagewebp($w_dst, $wall_thumb, 60); } 
                    else { imagejpeg($w_dst, $wall_thumb, 80); }
                    
                    imagedestroy($src); imagedestroy($w_dst);
                    $registered_files[] = realpath($wall_thumb); 
                    echo "<div style='color:#39FF14;'>[FIXED] {$img['img_title']}</div>";
                    $fixed++;
                }
            } else {
                $skipped++;
            }
        }
        echo "</div>"; // End Step 1 Log

        echo "<h3>STEP 2: PRUNING ORPHAN FILES</h3>";
        echo "<div class='signal-body' style='max-height: 300px; overflow-y: auto;'>";
        
        $upload_dir = new RecursiveDirectoryIterator('img_uploads');
        $iterator = new RecursiveIteratorIterator($upload_dir);
        $deleted_count = 0;

        foreach ($iterator as $file_info) {
            if ($file_info->isFile()) {
                $file_path = $file_info->getRealPath();
                
                if (!in_array($file_path, $registered_files)) {
                    $ext = strtolower($file_info->getExtension());
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        if (unlink($file_path)) {
                            echo "<div style='color:#ff3e3e;'>[PURGED] " . $file_info->getFilename() . "</div>";
                            $deleted_count++;
                        }
                    }
                }
            }
        }
        if($deleted_count === 0) echo "<div style='color:#666;'>No orphans found. System is clean.</div>";
        echo "</div>"; // End Step 2 Log
        ?>
    </div>

    <div class="box">
        <h3>SYNC STATUS</h3>
        <div class="stat-group">
            <div>
                <span class="stat-val"><?php echo $fixed; ?></span>
                <span class="stat-label">REPAIRED</span>
            </div>
            <div>
                <span class="stat-val"><?php echo $skipped; ?></span>
                <span class="stat-label">VERIFIED</span>
            </div>
            <div>
                <span class="stat-val" style="color: #ff3e3e;"><?php echo $deleted_count; ?></span>
                <span class="stat-label">PURGED</span>
            </div>
        </div>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>