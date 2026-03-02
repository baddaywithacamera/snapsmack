<?php
/**
 * SnapSmack - Obfuscated Download Endpoint
 * Version: 1.0
 * -------------------------------------------------------------------------
 * Streams original full-res images with Content-Disposition: attachment.
 * Path is never exposed — URL is download.php?t={token} where token is
 * HMAC-SHA256 of image ID + secret salt stored in snap_settings.
 *
 * Security:
 *   - Token validated against DB-stored salt
 *   - Only published images with allow_download = 1 are served
 *   - Global kill-switch via global_downloads_enabled setting
 *   - No directory traversal possible — file path comes from DB only
 * -------------------------------------------------------------------------
 */

require_once __DIR__ . '/core/db.php';

// --- 1. VALIDATE TOKEN ---
$token = $_GET['t'] ?? '';
$img_id = (int)($_GET['id'] ?? 0);

if (empty($token) || $img_id < 1) {
    http_response_code(403);
    die('Invalid request.');
}

// --- 2. LOAD SETTINGS ---
$settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Global kill-switch
if (($settings['global_downloads_enabled'] ?? '0') !== '1') {
    http_response_code(403);
    die('Downloads are not available.');
}

// --- 3. FETCH IMAGE RECORD ---
$stmt = $pdo->prepare("SELECT * FROM snap_images WHERE id = ? AND img_status = 'published' LIMIT 1");
$stmt->execute([$img_id]);
$img = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$img) {
    http_response_code(404);
    die('Image not found.');
}

// Per-post check
if (($img['allow_download'] ?? 0) != 1) {
    http_response_code(403);
    die('Download not available for this image.');
}

// --- 4. VERIFY TOKEN ---
$salt = $settings['download_salt'] ?? 'snapsmack-default-salt-change-me';
$expected_token = hash_hmac('sha256', (string)$img_id, $salt);

if (!hash_equals($expected_token, $token)) {
    http_response_code(403);
    die('Invalid token.');
}

// --- 5. STREAM FILE ---
$file_path = __DIR__ . '/' . $img['img_file'];

if (!file_exists($file_path)) {
    http_response_code(404);
    die('File not found.');
}

$mime = mime_content_type($file_path);
$ext = pathinfo($file_path, PATHINFO_EXTENSION);

// Build a clean filename from the title
$clean_name = preg_replace('/[^a-zA-Z0-9_-]/', '-', $img['img_title']);
$clean_name = preg_replace('/-+/', '-', trim($clean_name, '-'));
$download_name = $clean_name . '.' . $ext;

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $download_name . '"');
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

readfile($file_path);
exit;
