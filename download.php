<?php
/**
 * SnapSmack - Secure Download Handler
 * Version: 1.0
 * -------------------------------------------------------------------------
 * THREE-LOCK SECURITY MODEL:
 *   Lock 1 — Global kill-switch: downloads_enabled = 1 in snap_settings
 *   Lock 2 — Skin manifest:      supports_downloads = true
 *   Lock 3 — Post level:         img_download_url is populated
 *
 * All three locks must pass. Any failure = hard 403, no information leaked.
 *
 * GOOGLE DRIVE SUPPORT:
 *   Share links (drive.google.com/file/d/{ID}/view) are normalised
 *   to direct export URLs automatically.
 *
 * SECURITY:
 *   - Extension allowlist enforced
 *   - MIME type verified server-side via finfo
 *   - Raw download URL never exposed to the browser
 *   - Download counter incremented on success only
 * -------------------------------------------------------------------------
 */

require_once __DIR__ . '/core/db.php';

// -------------------------------------------------------------------------
// HELPERS
// -------------------------------------------------------------------------

function hard_deny(string $reason = ''): void {
    header('HTTP/1.1 403 Forbidden');
    die('403 — Signal Rejected.' . ($reason ? ' ' . $reason : ''));
}

function normalise_gdrive_url(string $url): string {
    // https://drive.google.com/file/d/{ID}/view?... → direct export
    if (preg_match('#drive\.google\.com/file/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return 'https://drive.google.com/uc?export=download&id=' . $m[1];
    }
    return $url;
}

// -------------------------------------------------------------------------
// 1. INPUT VALIDATION
// -------------------------------------------------------------------------

$post_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($post_id <= 0) hard_deny();

// -------------------------------------------------------------------------
// 2. FETCH SETTINGS + POST IN ONE PASS
// -------------------------------------------------------------------------

try {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $pdo->prepare("SELECT id, img_slug, img_title, img_download_url, img_download_count
                           FROM snap_images
                           WHERE id = ? AND img_status = 'published'
                           LIMIT 1");
    $stmt->execute([$post_id]);
    $img = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    hard_deny();
}

if (!$img) hard_deny();

// -------------------------------------------------------------------------
// 3. LOCK 1 — Global kill-switch
// -------------------------------------------------------------------------

if (($settings['downloads_enabled'] ?? '0') !== '1') hard_deny();

// -------------------------------------------------------------------------
// 4. LOCK 2 — Skin manifest must declare supports_downloads = true
// -------------------------------------------------------------------------

$active_skin   = $settings['active_skin'] ?? '';
$manifest_path = __DIR__ . '/skins/' . $active_skin . '/manifest.php';
$manifest      = file_exists($manifest_path) ? (include $manifest_path) : [];

if (empty($manifest['supports_downloads'])) hard_deny();

// -------------------------------------------------------------------------
// 5. LOCK 3 — Post must have a download URL
// -------------------------------------------------------------------------

$raw_url = trim($img['img_download_url'] ?? '');
if (empty($raw_url)) hard_deny();

// -------------------------------------------------------------------------
// 6. NORMALISE URL (Google Drive share links → direct export)
// -------------------------------------------------------------------------

$fetch_url = normalise_gdrive_url($raw_url);

// Validate URL scheme — only https/http allowed
if (!preg_match('#^https?://#i', $fetch_url)) hard_deny();

// -------------------------------------------------------------------------
// 7. EXTENSION ALLOWLIST
// -------------------------------------------------------------------------

$allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'heic', 'pdf'];
$url_path           = parse_url($fetch_url, PHP_URL_PATH) ?? '';
$ext                = strtolower(pathinfo($url_path, PATHINFO_EXTENSION));

// Google Drive export URLs don't have an extension in the path — allow them through
$is_gdrive = strpos($fetch_url, 'drive.google.com') !== false;
if (!$is_gdrive && !in_array($ext, $allowed_extensions, true)) hard_deny();

// -------------------------------------------------------------------------
// 8. FETCH FILE SERVER-SIDE (URL never reaches the browser)
// -------------------------------------------------------------------------

$ctx = stream_context_create([
    'http' => [
        'timeout'        => 30,
        'user_agent'     => 'SnapSmack/1.0',
        'follow_location' => true,
    ],
    'ssl'  => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
    ],
]);

$file_data = @file_get_contents($fetch_url, false, $ctx);
if ($file_data === false || strlen($file_data) === 0) hard_deny();

// -------------------------------------------------------------------------
// 9. SERVER-SIDE MIME VERIFICATION
// -------------------------------------------------------------------------

$finfo     = new finfo(FILEINFO_MIME_TYPE);
$mime_type = $finfo->buffer($file_data);

$allowed_mimes = [
    'image/jpeg', 'image/png', 'image/webp', 'image/tiff',
    'image/heic', 'image/heif', 'application/pdf',
];

if (!in_array($mime_type, $allowed_mimes, true)) hard_deny();

// -------------------------------------------------------------------------
// 10. BUILD FILENAME
// -------------------------------------------------------------------------

$mime_to_ext = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/tiff'      => 'tif',
    'image/heic'      => 'heic',
    'image/heif'      => 'heic',
    'application/pdf' => 'pdf',
];

$file_ext   = $mime_to_ext[$mime_type] ?? 'bin';
$title_slug = $img['img_slug'] ?? 'download';
$filename   = preg_replace('/[^a-zA-Z0-9_-]/', '-', $title_slug) . '.' . $file_ext;

// -------------------------------------------------------------------------
// 11. INCREMENT DOWNLOAD COUNTER
// -------------------------------------------------------------------------

try {
    $pdo->prepare("UPDATE snap_images SET img_download_count = img_download_count + 1 WHERE id = ?")
        ->execute([$post_id]);
} catch (PDOException $e) {
    // Non-fatal — don't block the download over a counter failure
}

// -------------------------------------------------------------------------
// 12. SERVE FILE
// -------------------------------------------------------------------------

header('Content-Type: ' . $mime_type);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($file_data));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
echo $file_data;
exit;
