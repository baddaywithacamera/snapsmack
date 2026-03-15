<?php
/**
 * SNAPSMACK - Obfuscated download endpoint for full-resolution images
 * Alpha v0.7.4
 *
 * Streams original images with attachment disposition. Token is HMAC-SHA256
 * of image ID plus a server secret, preventing direct URL guessing.
 * Global and per-image kill switches available for download control.
 */

require_once __DIR__ . '/core/db.php';

// --- REQUEST VALIDATION ---
// Token and image ID are required parameters
$token = $_GET['t'] ?? '';
$img_id = (int)($_GET['id'] ?? 0);

if (empty($token) || $img_id < 1) {
    http_response_code(403);
    die('Invalid request.');
}

// --- SETTINGS & GLOBAL GATE ---
// Load all settings. Check global download enable flag first.
$settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

if (($settings['global_downloads_enabled'] ?? '0') !== '1') {
    http_response_code(403);
    die('Downloads are not available.');
}

// --- IMAGE LOOKUP ---
// Fetch only published images to prevent leaking unpublished content
$stmt = $pdo->prepare("SELECT * FROM snap_images WHERE id = ? AND img_status = 'published' LIMIT 1");
$stmt->execute([$img_id]);
$img = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$img) {
    http_response_code(404);
    die('Image not found.');
}

// Per-image download flag check
if (($img['allow_download'] ?? 0) != 1) {
    http_response_code(403);
    die('Download not available for this image.');
}

// --- TOKEN VERIFICATION ---
// HMAC-SHA256 of image ID against stored salt prevents direct URL guessing
$salt = $settings['download_salt'] ?? 'snapsmack-default-salt-change-me';
$expected_token = hash_hmac('sha256', (string)$img_id, $salt);

if (!hash_equals($expected_token, $token)) {
    http_response_code(403);
    die('Invalid token.');
}

// --- FILE STREAMING ---
// Retrieve file from filesystem and stream with attachment headers
$file_path = __DIR__ . '/' . $img['img_file'];

if (!file_exists($file_path)) {
    http_response_code(404);
    die('File not found.');
}

$mime = mime_content_type($file_path);
$ext = pathinfo($file_path, PATHINFO_EXTENSION);

// Build a clean filename from the image title
$clean_name = preg_replace('/[^a-zA-Z0-9_-]/', '-', $img['img_title']);
$clean_name = preg_replace('/-+/', '-', trim($clean_name, '-'));
$download_name = $clean_name . '.' . $ext;

// --- INCREMENT DOWNLOAD COUNTER ---
// Bump the download count before streaming the file
$update_stmt = $pdo->prepare("UPDATE snap_images SET img_download_count = img_download_count + 1 WHERE id = ?");
$update_stmt->execute([$img_id]);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $download_name . '"');
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

readfile($file_path);
exit;
