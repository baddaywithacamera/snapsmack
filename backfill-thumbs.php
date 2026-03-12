<?php
/**
 * SNAPSMACK - Thumbnail regeneration utility
 * Alpha v0.7.3
 *
 * Generates two thumbnail variants per image:
 *   t_ — 400x400 center-cropped square (for square grid layout)
 *   a_ — 400px on the long side, aspect preserved (for cropped & masonry layouts)
 *
 * Requires authentication. Generates thumbnails in subdirectories per image folder.
 */

require_once 'core/auth.php';

// --- ACCESS CONTROL ---
// Ensure user is authenticated before running intensive file operations
if (!isset($_SESSION['user_id'])) {
    die("Access Denied.");
}

// --- CONFIGURATION ---
// Thumbnail dimension parameters
$square_size   = 400;  // Square thumbnail dimension (t_ prefix)
$aspect_long   = 400;  // Long-side max for aspect-preserved thumbnail (a_ prefix)

// --- IMAGE LOOKUP ---
// Retrieve all image file paths from the database
$stmt = $pdo->query("SELECT img_file FROM snap_images");
$images = $stmt->fetchAll();

// --- PROGRESS UI ---
// Basic HTML output for progress logging
echo "<html><head><title>SnapSmack Backfill</title>";
echo "<style>body{background:#1a1a1a;color:#ccc;font-family:monospace;padding:20px;} .success{color:#39FF14;} .info{color:#00bfff;} .warn{color:#ffaa00;}</style></head><body>";
echo "<h2>MULTI-LAYOUT THUMBNAIL BACKFILL</h2>";
echo "<p class='info'>GENERATING: t_ (400x400 square) + a_ (400px long side, aspect preserved)</p><hr>";

$processed = 0;
$skipped = 0;

// --- PROCESSING LOOP ---
// Iterates through each image and generates both thumbnail variants
foreach ($images as $img) {
    $file = $img['img_file'];

    // Verify source file exists before processing
    if (!file_exists($file)) {
        echo "<span class='warn'>SKIPPING:</span> $file (Original missing)<br>";
        $skipped++;
        continue;
    }

    // --- PATH & DIRECTORY SETUP ---
    // Ensures thumbs subdirectory exists for each image folder
    $path_info = pathinfo($file);
    $thumb_dir = $path_info['dirname'] . '/thumbs';

    if (!is_dir($thumb_dir)) {
        mkdir($thumb_dir, 0755, true);
    }

    // Define output paths for both variants
    $square_path = $thumb_dir . '/t_' . $path_info['basename'];
    $aspect_path = $thumb_dir . '/a_' . $path_info['basename'];

    // --- SOURCE IMAGE SETUP ---
    // Load image metadata and create resource based on file type
    list($w, $h) = getimagesize($file);
    $mime = mime_content_type($file);

    switch ($mime) {
        case 'image/jpeg': $src = imagecreatefromjpeg($file); break;
        case 'image/png':  $src = imagecreatefrompng($file);  break;
        case 'image/webp': $src = imagecreatefromwebp($file); break;
        default:
            echo "<span class='warn'>ERROR:</span> Unsupported type for $file<br>";
            continue 2;
    }

    // --- SQUARE THUMBNAIL ---
    // 400x400 center-cropped variant for square grid layout
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

    // --- ASPECT-PRESERVED THUMBNAIL ---
    // 400px on the long side, preserves aspect ratio for cropped and masonry layouts
    if ($w >= $h) {
        // Landscape or square: width is the long side
        $a_w = $aspect_long;
        $a_h = round($h * ($aspect_long / $w));
    } else {
        // Portrait: height is the long side
        $a_h = $aspect_long;
        $a_w = round($w * ($aspect_long / $h));
    }

    // Don't upscale images smaller than the long-side target
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

    // Free source image resource
    imagedestroy($src);

    $processed++;
    echo "<span class='success'>PROCESSED:</span> t_ + a_ → " . $path_info['basename'] . "<br>";

    // Flush buffer for real-time progress monitoring
    flush();
}

// --- COMPLETION REPORT ---
echo "<hr>";
echo "<h3>BACKFILL COMPLETE.</h3>";
echo "<p class='info'>Processed: {$processed} | Skipped: {$skipped}</p>";
echo "<p class='warn'>NOTE: Old wall_ prefix thumbnails are no longer generated.<br>";
echo "The floating gallery loads full-resolution images directly.<br>";
echo "You can safely delete any existing wall_* files from your thumbs directories.</p>";
echo "<p>You can now delete this file from your server.</p>";
echo "</body></html>";
?>
