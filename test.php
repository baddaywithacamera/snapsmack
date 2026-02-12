<?php
require_once 'core/db.php';

// 1. Get the URL exactly as it sits in the DB
$db_url = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'site_url' LIMIT 1")->fetchColumn();

// 2. Get the last image path (e.g., 'img_uploads/2026/02/12345_file.jpg')
$img_path = $pdo->query("SELECT img_file FROM snap_images ORDER BY img_date DESC LIMIT 1")->fetchColumn();

// 3. The Smack-Manual Thumb logic: folder + 'thumbs/t_' + filename
$filename = basename($img_path);
$thumb_path = str_replace($filename, 'thumbs/t_' . $filename, $img_path);

// 4. The Final Smash
$final_address = $db_url . $thumb_path;

echo "<pre>";
echo "DATABASE URL:  [" . $db_url . "]\n";
echo "IMAGE FILE:    [" . $img_path . "]\n";
echo "THUMB PATH:    [" . $thumb_path . "]\n";
echo "--------------------------------------------------\n";
echo "FINAL ADDRESS: " . $final_address . "\n";
echo "</pre>";