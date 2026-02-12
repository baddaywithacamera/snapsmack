<?php
/**
 * SnapSmack - Core Layout Logic
 * Centralizes EXIF processing and Comment fetching for skins.
 */
// We use __DIR__ because fix-exif.php is in the same folder as this file.
include_once __DIR__ . '/fix-exif.php';

// 1. EXIF MAPPING
$exif_data = [];
if (!empty($img['img_exif'])) {
    $raw = json_decode($img['img_exif'], true);
    if (is_array($raw)) {
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

// 2. COMMENT FETCHING
$comments = [];
if (isset($img['id'])) {
    $stm_c = $pdo->prepare("SELECT * FROM snap_comments WHERE img_id = ? AND is_approved = 1 ORDER BY comment_date ASC");
    $stm_c->execute([$img['id']]);
    $comments = $stm_c->fetchAll();
}