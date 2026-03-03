<?php
/**
 * SNAPSMACK - Preflight check
 * Alpha v0.6
 *
 * Analyzes image signals for titles and EXIF metadata prior to final upload.
 * Facilitates the pre-population of post metadata for technical consistency.
 */

require_once 'core/auth.php';

header('Content-Type: application/json');

/**
 * Safely resolve an EXIF rational number string (e.g. "28/10") to a float.
 * EXIF stores aperture, focal length, etc. as rational fractions.
 *
 * This safely parses rational values using whitelist validation, rejecting
 * any non-standard formatting that could indicate code injection attempts.
 *
 * @param  mixed       $value  Raw EXIF value — could be string "28/10", int, or float.
 * @return float|null  Resolved decimal value, or null if unparseable.
 */
function safe_rational($value) {
    if (is_numeric($value)) {
        return (float)$value;
    }

    if (!is_string($value)) {
        return null;
    }

    // Whitelist: only digits, optional whitespace, one forward slash, more digits.
    // Anything else (semicolons, letters, parentheses) gets rejected.
    if (preg_match('#^\s*(\d+)\s*/\s*(\d+)\s*$#', $value, $parts)) {
        $numerator   = (int)$parts[1];
        $denominator = (int)$parts[2];
        if ($denominator === 0) {
            return null;
        }
        return $numerator / $denominator;
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file'])) {
    $file = $_FILES['image_file'];
    $response = ['success' => false, 'meta' => [], 'title' => ''];

    // --- BASIC VALIDATION ---
    // Reject if upload errored or file is missing.
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $response['error'] = 'Upload failed or file is invalid.';
        echo json_encode($response);
        exit;
    }

    // --- TITLE GENERATION ---
    // Extract filename and convert underscores/dashes to natural spaces.
    $name  = pathinfo($file['name'], PATHINFO_FILENAME);
    $title = str_replace(['_', '-'], ' ', $name);
    $response['title'] = trim(preg_replace('/\s+/', ' ', $title));

    // --- EXIF DATA EXTRACTION ---
    // EXIF metadata is native to JPEG; check mime type before attempting read.
    $mime = mime_content_type($file['tmp_name']);
    if (in_array($mime, ['image/jpeg', 'image/jpg'])) {
        $exif = @exif_read_data($file['tmp_name']);
        if ($exif) {
            // Aperture: rational string like "28/10" → float 2.8 → "f/2.8"
            $aperture_raw = safe_rational($exif['FNumber'] ?? null);
            $aperture = ($aperture_raw !== null) ? 'f/' . round($aperture_raw, 1) : '';

            // Focal length: rational string like "500/10" → float 50 → "50mm"
            $focal_raw = safe_rational($exif['FocalLength'] ?? null);
            $focal = ($focal_raw !== null) ? round($focal_raw, 1) . 'mm' : '';

            // Camera: combine Make + Model, trim duplicates where Model includes Make.
            $make  = trim($exif['Make'] ?? '');
            $model = trim($exif['Model'] ?? '');
            if ($make && stripos($model, $make) === 0) {
                $camera = $model;
            } else {
                $camera = trim($make . ' ' . $model);
            }

            $response['meta'] = [
                'camera'   => $camera,
                'iso'      => $exif['ISOSpeedRatings'] ?? '',
                'aperture' => $aperture,
                'shutter'  => $exif['ExposureTime'] ?? '',
                'focal'    => $focal,
                'flash'    => (isset($exif['Flash']) && ($exif['Flash'] & 1)) ? 'Yes' : 'No',
                'date'     => $exif['DateTimeOriginal'] ?? ''
            ];
        }
    }

    $response['success'] = true;
    echo json_encode($response);
    exit;
}
