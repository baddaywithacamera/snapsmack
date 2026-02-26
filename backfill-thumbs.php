<?php
/**
 * SNAPSMACK - Thumbnail regeneration utility.
 * Scans the database for all registered images and recreates 
 * center-cropped square thumbnails. This is used to fix layout 
 * inconsistencies or update the grid style after a theme change.
 * Git Version Official Alpha 0.5
 */

require_once 'core/auth.php';

// Access control: Ensure the user is authenticated before running intensive file operations.
if (!isset($_SESSION['user_id'])) {
    die("Access Denied.");
}

// Define the target dimension for the square thumbnails.
$thumb_size = 400; 

// Retrieve all image file paths from the database registry.
$stmt = $pdo->query("SELECT img_file FROM snap_images");
$images = $stmt->fetchAll();

// Basic UI output for the progress log.
echo "<html><head><title>SnapSmack Backfill</title>";
echo "<style>body{background:#1a1a1a;color:#ccc;font-family:monospace;padding:20px;} .success{color:#39FF14;}</style></head><body>";
echo "<h2>STARTING SQUARE THUMBNAIL BACKFILL...</h2><hr>";

foreach ($images as $img) {
    $file = $img['img_file'];
    
    // Verify the source file exists on the filesystem before attempting to process.
    if (!file_exists($file)) {
        echo "SKIPPING: $file (Original missing)<br>";
        continue;
    }

    // --- DIRECTORY & PATH PREPARATION ---
    $path_info = pathinfo($file);
    $thumb_dir = $path_info['dirname'] . '/thumbs';
    
    // Ensure the 'thumbs' subdirectory exists for each image folder.
    if (!is_dir($thumb_dir)) {
        mkdir($thumb_dir, 0755, true);
    }
    
    // Define the output path using the 't_' prefix standard.
    $thumb_path = $thumb_dir . '/t_' . $path_info['basename'];

    // Retrieve source image metadata.
    list($w, $h) = getimagesize($file);
    $mime = mime_content_type($file);

    // Initialize the source image resource based on file type.
    switch ($mime) {
        case 'image/jpeg': $src = imagecreatefromjpeg($file); break;
        case 'image/png':  $src = imagecreatefrompng($file);  break;
        case 'image/webp': $src = imagecreatefromwebp($file); break;
        default: 
            echo "ERROR: Unsupported type for $file<br>";
            continue 2;
    }

    // --- SQUARE CENTER-CROP CALCULATION ---
    // Calculates coordinates to crop the center-most square from the source.
    if ($w > $h) {
        // Landscape orientation: calculate horizontal offset to center the crop.
        $src_x = ($w - $h) / 2;
        $src_y = 0;
        $src_w = $src_h = $h;
    } else {
        // Portrait orientation: calculate vertical offset to center the crop.
        $src_x = 0;
        $src_y = ($h - $w) / 2;
        $src_w = $src_h = $w;
    }

    // Create the destination canvas for the new thumbnail.
    $t_dst = imagecreatetruecolor($thumb_size, $thumb_size);
    
    // Preserve transparency for PNG and WebP formats.
    if ($mime == 'image/png' || $mime == 'image/webp') {
        imagealphablending($t_dst, false);
        imagesavealpha($t_dst, true);
    }

    // Perform the high-quality resampled crop and resize.
    imagecopyresampled(
        $t_dst, $src, 
        0, 0, $src_x, $src_y, 
        $thumb_size, $thumb_size, 
        $src_w, $src_h
    );

    // --- OUTPUT & COMPRESSION ---
    // Saves the processed thumbnail with balanced quality/file-size settings.
    if ($mime === 'image/jpeg') {
        imagejpeg($t_dst, $thumb_path, 80);
    } elseif ($mime === 'image/png') {
        imagepng($t_dst, $thumb_path, 8);
    } else {
        imagewebp($t_dst, $thumb_path, 75);
    }

    // Free up system memory by destroying image resources after each loop.
    imagedestroy($src);
    imagedestroy($t_dst);
    
    echo "<span class='success'>PROCESSED:</span> $thumb_path<br>";
    
    // Flush the output buffer to the browser so progress can be monitored in real-time.
    flush(); 
}

echo "<hr><h3>BACKFILL COMPLETE.</h3>";
echo "<p>You can now delete this file from your server.</p>";
echo "</body></html>";
?>