<?php
/**
 * SnapSmack - Preflight Check
 * Analyzes image for Title and EXIF data before final upload.
 */
require_once 'core/auth.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image_file'])) {
    $file = $_FILES['image_file'];
    $response = ['success' => false, 'meta' => [], 'title' => ''];

    // 1. Generate Title from Filename
    $name = pathinfo($file['name'], PATHINFO_FILENAME);
    // Convert underscores/dashes to spaces, keep dates intact
    $title = str_replace(['_', '-'], ' ', $name); 
    // Clean up double spaces
    $response['title'] = trim(preg_replace('/\s+/', ' ', $title));

    // 2. Read EXIF
    if (in_array(mime_content_type($file['tmp_name']), ['image/jpeg', 'image/jpg'])) {
        $exif = @exif_read_data($file['tmp_name']);
        if ($exif) {
            $response['meta'] = [
                'camera' => trim(($exif['Make'] ?? '') . ' ' . ($exif['Model'] ?? '')),
                'iso' => $exif['ISOSpeedRatings'] ?? '',
                'aperture' => isset($exif['FNumber']) ? "f/" . eval("return " . $exif['FNumber'] . ";") : '',
                'shutter' => $exif['ExposureTime'] ?? '',
                'focal' => isset($exif['FocalLength']) ? eval("return " . $exif['FocalLength'] . ";") . "mm" : '',
                'flash' => (isset($exif['Flash']) && ($exif['Flash'] & 1)) ? 'Yes' : 'No',
                'date' => $exif['DateTimeOriginal'] ?? ''
            ];
        }
    }
    
    $response['success'] = true;
    echo json_encode($response);
    exit;
}