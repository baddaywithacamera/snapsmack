<?php
/**
 * SNAPSMACK - Colour Palette Extraction
 * Alpha v0.7.2
 *
 * Extracts a dominant colour palette from an image using quantized sampling.
 * Supports JPEG, PNG, and WebP formats via GD library.
 *
 * Usage:
 *   $palette = snapsmack_extract_palette('/path/to/image.jpg', 5);
 *   // Returns: ['#2c2017', '#f5f0eb', '#8b4513', '#d4a574', '#1a1a2e']
 */

/**
 * Extracts a colour palette from an image file.
 *
 * @param string $image_path Path to the image file (JPEG, PNG, or WebP)
 * @param int $count Number of colours to extract (default: 5)
 * @return array Array of hex colour strings, or empty array on failure
 */
function snapsmack_extract_palette(string $image_path, int $count = 5): array {
    // Validate file exists
    if (!file_exists($image_path) || !is_readable($image_path)) {
        return [];
    }

    // Check if GD is available
    if (!extension_loaded('gd')) {
        return [];
    }

    // Load image based on file type
    $mime_type = mime_content_type($image_path);
    $image = null;

    try {
        switch ($mime_type) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($image_path);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($image_path);
                break;
            case 'image/webp':
                $image = @imagecreatefromwebp($image_path);
                break;
            default:
                return [];
        }
    } catch (Exception $e) {
        return [];
    }

    // Verify image loaded successfully
    if (!$image) {
        return [];
    }

    // Create working copy at 100x100 for performance
    $working_width = 100;
    $working_height = 100;
    $working_image = imagecreatetruecolor($working_width, $working_height);

    if (!$working_image) {
        imagedestroy($image);
        return [];
    }

    // Resize image to working dimensions
    $original_width = imagesx($image);
    $original_height = imagesy($image);

    if (!imagecopyresampled(
        $working_image, $image,
        0, 0, 0, 0,
        $working_width, $working_height,
        $original_width, $original_height
    )) {
        imagedestroy($image);
        imagedestroy($working_image);
        return [];
    }

    imagedestroy($image);

    // Sample pixels and quantize to 4-bit buckets (16 levels per channel)
    $buckets = [];

    for ($y = 0; $y < $working_height; $y++) {
        for ($x = 0; $x < $working_width; $x++) {
            $pixel = imagecolorat($working_image, $x, $y);

            // Extract RGB components
            $r = ($pixel >> 16) & 0xFF;
            $g = ($pixel >> 8) & 0xFF;
            $b = $pixel & 0xFF;

            // Skip near-white (all components > 240)
            if ($r > 240 && $g > 240 && $b > 240) {
                continue;
            }

            // Skip near-black (all components < 15)
            if ($r < 15 && $g < 15 && $b < 15) {
                continue;
            }

            // Quantize to 4-bit (16 levels per channel)
            // Divide by 16 to get 0-15 range, then multiply by 17 to get 0-255 equivalent
            $qr = ($r >> 4) & 0x0F;
            $qg = ($g >> 4) & 0x0F;
            $qb = ($b >> 4) & 0x0F;

            // Create bucket key
            $bucket_key = sprintf('%X%X%X', $qr, $qg, $qb);

            // Increment bucket count
            if (!isset($buckets[$bucket_key])) {
                $buckets[$bucket_key] = [
                    'count' => 0,
                    'r' => $qr,
                    'g' => $qg,
                    'b' => $qb
                ];
            }
            $buckets[$bucket_key]['count']++;
        }
    }

    imagedestroy($working_image);

    // Return empty if no valid colours found
    if (empty($buckets)) {
        return [];
    }

    // Sort buckets by count (most common first)
    uasort($buckets, function($a, $b) {
        return $b['count'] - $a['count'];
    });

    // Convert bucket centres to hex colours
    $palette = [];
    $bucket_count = 0;

    foreach ($buckets as $bucket) {
        if ($bucket_count >= $count) {
            break;
        }

        // Convert 4-bit quantized values back to 8-bit by multiplying by 17
        // This centres the quantized value within its range
        $r = ($bucket['r'] * 17);
        $g = ($bucket['g'] * 17);
        $b = ($bucket['b'] * 17);

        // Ensure values are in valid range
        $r = min(255, max(0, $r));
        $g = min(255, max(0, $g));
        $b = min(255, max(0, $b));

        // Convert to hex colour string
        $hex = sprintf('#%02x%02x%02x', $r, $g, $b);
        $palette[] = $hex;
        $bucket_count++;
    }

    return $palette;
}
?>
