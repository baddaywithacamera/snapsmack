<?php
/**
 * SNAPSMACK - Photo Layout Support Logic
 * Alpha v0.7.3
 *
 * Centralizes EXIF processing and comment fetching for skin layouts. Ensures
 * that all skins have access to consistently formatted EXIF data and approved
 * comments without duplicating this logic in each template.
 */

// --- EXIF PROCESSING ---
// Include the EXIF helper which provides the get_smack_exif() function
include_once __DIR__ . '/fix-exif.php';

$exif_data = [];
if (!empty($img['img_exif'])) {
    $raw = json_decode($img['img_exif'], true);
    if (is_array($raw)) {
        // Parse raw EXIF JSON into human-readable format
        $processed = get_smack_exif($raw);
        $exif_data = [
            'Model'           => $processed['model'] ?? '',
            'FNumber'         => $processed['aperture'] ?? '',
            'ExposureTime'    => $processed['shutter'] ?? '',
            'ISOSpeedRatings' => $processed['iso'] ?? '',
            'FocalLength'     => $processed['focal_length'] ?? '',
            'lens'            => $raw['lens'] ?? '',
            'film'            => $raw['film'] ?? '',
            'flash'           => $raw['flash'] ?? ''
        ];
    }
}

// --- COMMENT FETCHING ---
// Retrieve all approved comments for this photo, ordered by date
$comments = [];
if (isset($img['id'])) {
    $stm_c = $pdo->prepare("SELECT * FROM snap_comments WHERE img_id = ? AND is_approved = 1 ORDER BY comment_date ASC");
    $stm_c->execute([$img['id']]);
    $comments = $stm_c->fetchAll();
}
