<?php
/**
 * SNAPSMACK - Thumbnail regeneration utility.
 * Version: 2.0 - Multi-Layout Thumb Engine
 * 
 * Generates two thumbnail variants per image:
 *   t_  — 400x400 center-cropped square (for square grid layout)
 *   a_  — 400px on the long side, aspect preserved (for cropped & masonry layouts)
 *
 * The old wall_ prefix thumbnails are no longer generated.
 * The gallery wall loads full-resolution images directly.
 *
 * Git Version Official Alpha 0.5
 */

require_once 'core/auth.php';

// Access control: Ensure the user is authenticated before running intensive file operations.
if (!isset($_SESSION['user_id'])) {
    die("Access Denied.");
}

// --- CONFIGURATION ---
$square_size   = 400;  // Square thumb dimension (t_ prefix)
$aspect_long   = 400;  // Long-side max for aspect-preserved thumb (a_ prefix)

// Retrieve all image file paths from the database registry.
$stmt = $pdo->query("SELECT img_file FROM snap_images");
$images = $stmt->fetchAll();

// Basic UI output for the progress log.
echo "<html><head><title>SnapSmack Backfill</title>";
echo "<style>body{background:#1a1a1a;color:#ccc;font-family:monospace;padding:20px;} .success{color:#39FF14;} .info{color:#00bfff;} .warn{color:#ffaa00;}</style></head><body>";
echo "<h2>MULTI-LAYOUT THUMBNAIL BACKFILL</h2>";
echo "<p class='info'>GENERATING: t_ (400x400 square) + a_ (400px long side, aspect preserved)</p><hr>";

$processed = 0;
$skipped = 0;

foreach ($images as $img) {
    $file = $img['img_file'];
    
    // Verify the source file exists on the filesystem before attempting to process.
    if (!file_exists($file)) {
        echo "<span class='warn'>SKIPPING:</span> $file (Original missing)<br>";
        $skipped++;
        continue;
    }

    // --- DIRECTORY & PATH PREPARATION ---
    $path_info = pathinfo($file);
    $thumb_dir = $path_info['dirname'] . '/thumbs';
    
    // Ensure the 'thumbs' subdirectory exists for each image folder.
    if (!is_dir($thumb_dir)) {
        mkdir($thumb_dir, 0755, true);
    }
    
    // Define output paths
    $square_path = $thumb_dir . '/t_' . $path_info['basename'];
    $aspect_path = $thumb_dir . '/a_' . $path_info['basename'];

    // Retrieve source image metadata.
    list($w, $h) = getimagesize($file);
    $mime = mime_content_type($file);

    // Initialize the source image resource based on file type.
    switch ($mime) {
        case 'image/jpeg': $src = imagecreatefromjpeg($file); break;
        case 'image/png':  $src = imagecreatefrompng($file);  break;
        case 'image/webp': $src = imagecreatefromwebp($file); break;
        default: 
            echo "<span class='warn'>ERROR:</span> Unsupported type for $file<br>";
            continue 2;
    }

    // =====================================================================
    // 1. SQUARE THUMBNAIL (t_ prefix) — 400x400 center-cropped
    // =====================================================================
    if ($w > $h) {
        $src_x = ($w - $h) / 2;
        $src_y = 0;
        $src_w = $src_h = $h;
    } else {
        $src_x = 0;
        $src_y = ($h - $w) / 2;
        $src_w = $src_h = $w;
    }

    $t_dst = imagecreatetruecolor($square_size, $square_size);
    
    if ($mime == 'image/png' || $mime == 'image/webp') {
        imagealphablending($t_dst, false);
        imagesavealpha($t_dst, true);
    }

    imagecopyresampled(
        $t_dst, $src, 
        0, 0, $src_x, $src_y, 
        $square_size, $square_size, 
        $src_w, $src_h
    );

    if ($mime === 'image/jpeg') {
        imagejpeg($t_dst, $square_path, 82);
    } elseif ($mime === 'image/png') {
        imagepng($t_dst, $square_path, 8);
    } else {
        imagewebp($t_dst, $square_path, 78);
    }
    imagedestroy($t_dst);

    // =====================================================================
    // 2. ASPECT-PRESERVED THUMBNAIL (a_ prefix) — 400px on the long side
    // =====================================================================
    if ($w >= $h) {
        // Landscape or square: width is the long side
        $a_w = $aspect_long;
        $a_h = round($h * ($aspect_long / $w));
    } else {
        // Portrait: height is the long side
        $a_h = $aspect_long;
        $a_w = round($w * ($aspect_long / $h));
    }

    // Don't upscale tiny images
    if ($w < $aspect_long && $h < $aspect_long) {
        $a_w = $w;
        $a_h = $h;
    }

    $a_dst = imagecreatetruecolor($a_w, $a_h);
    
    if ($mime == 'image/png' || $mime == 'image/webp') {
        imagealphablending($a_dst, false);
        imagesavealpha($a_dst, true);
    }

    imagecopyresampled(
        $a_dst, $src, 
        0, 0, 0, 0, 
        $a_w, $a_h, 
        $w, $h
    );

    if ($mime === 'image/jpeg') {
        imagejpeg($a_dst, $aspect_path, 82);
    } elseif ($mime === 'image/png') {
        imagepng($a_dst, $aspect_path, 8);
    } else {
        imagewebp($a_dst, $aspect_path, 78);
    }
    imagedestroy($a_dst);

    // Free source
    imagedestroy($src);
    
    $processed++;
    echo "<span class='success'>PROCESSED:</span> t_ + a_ → " . $path_info['basename'] . "<br>";
    
    // Flush the output buffer for real-time progress monitoring.
    flush(); 
}

echo "<hr>";
echo "<h3>BACKFILL COMPLETE.</h3>";
echo "<p class='info'>Processed: {$processed} | Skipped: {$skipped}</p>";
echo "<p class='warn'>NOTE: Old wall_ prefix thumbnails are no longer generated.<br>";
echo "The gallery wall loads full-resolution images directly.<br>";
echo "You can safely delete any existing wall_* files from your thumbs directories.</p>";
echo "<p>You can now delete this file from your server.</p>";
echo "</body></html>";
?>
