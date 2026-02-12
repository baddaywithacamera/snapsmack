<?php
/**
 * SnapSmack - Thumbnail Backfiller
 * Version: 1.0
 * Purpose: Regenerates all thumbnails as center-cropped squares to fix the grid.
 */
require_once 'core/auth.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Access Denied.");
}

$thumb_size = 400; // Standard SnapSmack thumb dimension

// Fetch all images from the database
$stmt = $pdo->query("SELECT img_file FROM snap_images");
$images = $stmt->fetchAll();

echo "<html><head><title>SnapSmack Backfill</title>";
echo "<style>body{background:#1a1a1a;color:#ccc;font-family:monospace;padding:20px;} .success{color:#39FF14;}</style></head><body>";
echo "<h2>STARTING SQUARE THUMBNAIL BACKFILL...</h2><hr>";

foreach ($images as $img) {
    $file = $img['img_file'];
    
    // Check if original exists
    if (!file_exists($file)) {
        echo "SKIPPING: $file (Original missing)<br>";
        continue;
    }

    // Prepare paths
    $path_info = pathinfo($file);
    $thumb_dir = $path_info['dirname'] . '/thumbs';
    
    // Create thumbs folder if it was purged
    if (!is_dir($thumb_dir)) {
        mkdir($thumb_dir, 0755, true);
    }
    
    $thumb_path = $thumb_dir . '/t_' . $path_info['basename'];

    // Get dimensions and type
    list($w, $h) = getimagesize($file);
    $mime = mime_content_type($file);

    // Create source image
    switch ($mime) {
        case 'image/jpeg': $src = imagecreatefromjpeg($file); break;
        case 'image/png':  $src = imagecreatefrompng($file);  break;
        case 'image/webp': $src = imagecreatefromwebp($file); break;
        default: 
            echo "ERROR: Unsupported type for $file<br>";
            continue 2;
    }

    // Square Center-Crop Math
    if ($w > $h) {
        // Landscape: crop sides
        $src_x = ($w - $h) / 2;
        $src_y = 0;
        $src_w = $src_h = $h;
    } else {
        // Portrait: crop top/bottom
        $src_x = 0;
        $src_y = ($h - $w) / 2;
        $src_w = $src_h = $w;
    }

    // Create target square canvas
    $t_dst = imagecreatetruecolor($thumb_size, $thumb_size);
    
    // Transparency handling
    if ($mime == 'image/png' || $mime == 'image/webp') {
        imagealphablending($t_dst, false);
        imagesavealpha($t_dst, true);
    }

    // Perform the crop and resize
    imagecopyresampled(
        $t_dst, $src, 
        0, 0, $src_x, $src_y, 
        $thumb_size, $thumb_size, 
        $src_w, $src_h
    );

    // Save with compression logic
    if ($mime === 'image/jpeg') {
        imagejpeg($t_dst, $thumb_path, 80);
    } elseif ($mime === 'image/png') {
        imagepng($t_dst, $thumb_path, 8);
    } else {
        imagewebp($t_dst, $thumb_path, 75);
    }

    // Cleanup memory
    imagedestroy($src);
    imagedestroy($t_dst);
    
    echo "<span class='success'>PROCESSED:</span> $thumb_path<br>";
    flush(); // Push output to browser during long runs
}

echo "<hr><h3>BACKFILL COMPLETE.</h3>";
echo "<p>You can now delete this file from your server.</p>";
echo "</body></html>";
?>