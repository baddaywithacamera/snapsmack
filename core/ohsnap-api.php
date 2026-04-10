<?php
/**
 * SNAPSMACK - Oh Snap! API Handler
 * Alpha v0.7.9d
 *
 * Authenticated REST endpoints for the Oh Snap! desktop skin designer.
 * All endpoints require a valid Bearer token from snap_ohsnap_keys.
 *
 * Route structure: ohsnap/{resource}/{sub}
 *   GET  ohsnap/ping          — connection test, returns site vitals
 *   GET  ohsnap/config        — site name, tagline, active skin
 *   GET  ohsnap/posts         — recent 20 published posts with cover image
 *   GET  ohsnap/media         — recent 60 published images with URLs
 *   GET  ohsnap/skin          — active skin files (manifest, CSS, variable map)
 *   POST ohsnap/skin/push     — upload a skin zip and optionally activate it
 */

// --- ENVIRONMENT BOOTSTRAP ---
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    define('BASE_URL', $protocol . '://' . $_SERVER['HTTP_HOST'] . '/');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/constants.php';

// --- CORS: allow Oh Snap! desktop app (file:// and tauri://) ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^(file://|tauri://)#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- RESPONSE HELPERS ---
function os_respond(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function os_ok(array $data = []): void  { os_respond(array_merge(['ok' => true], $data)); }
function os_err(string $msg, int $code = 400): void { os_respond(['ok' => false, 'error' => $msg], $code); }

// --- ROUTE PARSING ---
$parts    = explode('/', trim($GLOBALS['route'] ?? ($_GET['route'] ?? ''), '/'));
$resource = $parts[1] ?? '';   // ping, config, posts, media, skin
$sub      = $parts[2] ?? '';   // push (for skin/push)
$method   = $_SERVER['REQUEST_METHOD'];

// --- SETTINGS ---
try {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    os_err('Database unavailable', 503);
}

// --- BEARER TOKEN AUTH ---
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$raw_key     = '';
if (preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $m)) {
    $raw_key = $m[1];
}
if (!$raw_key) {
    os_err('Authorization header required', 401);
}

$key_hash = hash('sha256', $raw_key);
try {
    $key_stmt = $pdo->prepare("
        SELECT id FROM snap_ohsnap_keys
        WHERE key_hash = ? AND is_active = 1
        LIMIT 1
    ");
    $key_stmt->execute([$key_hash]);
    $api_key_row = $key_stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    os_err('Database error during auth', 503);
}

if (!$api_key_row) {
    os_err('Invalid or revoked API key', 401);
}

// Touch last_used_at
$pdo->prepare("UPDATE snap_ohsnap_keys SET last_used_at = NOW() WHERE id = ?")
    ->execute([$api_key_row['id']]);

// --- HELPERS ---

/** Build an absolute URL for an upload-directory file. */
function os_upload_url(string $path): string {
    if (!$path) return '';
    return BASE_URL . 'uploads/' . ltrim($path, '/');
}

/** Read a skin file safely, return null if missing. */
function os_skin_file(string $skin_slug, string $filename): ?string {
    $path = dirname(__DIR__) . '/skins/' . preg_replace('/[^a-z0-9\-]/', '', $skin_slug) . '/' . $filename;
    return file_exists($path) ? file_get_contents($path) : null;
}

/** Extract css_variables from a skin manifest, if declared. */
function os_skin_variables(string $skin_slug): array {
    $path = dirname(__DIR__) . '/skins/' . preg_replace('/[^a-z0-9\-]/', '', $skin_slug) . '/manifest.php';
    if (!file_exists($path)) return [];
    try {
        $manifest = include $path;
        return $manifest['css_variables'] ?? [];
    } catch (Throwable $e) {
        return [];
    }
}

// =============================================================================
// ENDPOINT: GET ohsnap/ping
// Quick connection test. Returns site name, version, active skin.
// =============================================================================
if ($resource === 'ping' && $method === 'GET') {
    os_ok([
        'site_name'   => $settings['site_name']   ?? 'SnapSmack',
        'tagline'     => $settings['site_tagline'] ?? '',
        'active_skin' => $settings['active_skin']  ?? '',
        'version'     => SNAPSMACK_VERSION,
        'base_url'    => BASE_URL,
    ]);
}

// =============================================================================
// ENDPOINT: GET ohsnap/config
// Full site configuration for Oh Snap! project initialisation.
// =============================================================================
if ($resource === 'config' && $method === 'GET') {
    $active_skin = $settings['active_skin'] ?? '';
    $skin_version = '';

    if ($active_skin) {
        $manifest_path = dirname(__DIR__) . '/skins/' . preg_replace('/[^a-z0-9\-]/', '', $active_skin) . '/manifest.php';
        if (file_exists($manifest_path)) {
            try {
                $m = include $manifest_path;
                $skin_version = $m['version'] ?? '';
            } catch (Throwable $e) {}
        }
    }

    os_ok([
        'site_name'    => $settings['site_name']   ?? 'SnapSmack',
        'tagline'      => $settings['site_tagline'] ?? '',
        'base_url'     => BASE_URL,
        'active_skin'  => $active_skin,
        'skin_version' => $skin_version,
        'version'      => SNAPSMACK_VERSION,
    ]);
}

// =============================================================================
// ENDPOINT: GET ohsnap/posts
// Recent 20 published posts with cover image for live preview population.
// =============================================================================
if ($resource === 'posts' && $method === 'GET') {
    $rows = $pdo->query("
        SELECT
            p.id,
            p.title,
            p.slug,
            p.description,
            p.post_type,
            p.created_at,
            i.img_file,
            i.img_thumb_square,
            i.img_thumb_aspect,
            i.img_width,
            i.img_height
        FROM snap_posts p
        LEFT JOIN snap_post_images pi ON pi.post_id = p.id AND pi.sort_order = (
            SELECT MIN(sort_order) FROM snap_post_images WHERE post_id = p.id
        )
        LEFT JOIN snap_images i ON i.id = pi.image_id
        WHERE p.status = 'published'
        ORDER BY p.created_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    $posts = array_map(function ($row) {
        return [
            'id'          => (int)$row['id'],
            'title'       => $row['title'],
            'slug'        => $row['slug'],
            'description' => $row['description'] ?? '',
            'post_type'   => $row['post_type'],
            'created_at'  => $row['created_at'],
            'cover_url'   => os_upload_url($row['img_file'] ?? ''),
            'thumb_url'   => os_upload_url($row['img_thumb_square'] ?? $row['img_thumb_aspect'] ?? ''),
            'img_width'   => (int)($row['img_width'] ?? 0),
            'img_height'  => (int)($row['img_height'] ?? 0),
        ];
    }, $rows);

    os_ok(['posts' => $posts, 'count' => count($posts)]);
}

// =============================================================================
// ENDPOINT: GET ohsnap/media
// Recent 60 published images for the media browser and preview population.
// =============================================================================
if ($resource === 'media' && $method === 'GET') {
    $rows = $pdo->query("
        SELECT
            id, img_title, img_file,
            img_thumb_square, img_thumb_aspect,
            img_width, img_height, img_date
        FROM snap_images
        WHERE img_status = 'published'
        ORDER BY id DESC
        LIMIT 60
    ")->fetchAll(PDO::FETCH_ASSOC);

    $images = array_map(function ($row) {
        return [
            'id'         => (int)$row['id'],
            'title'      => $row['img_title'],
            'date'       => $row['img_date'],
            'full_url'   => os_upload_url($row['img_file']),
            'thumb_url'  => os_upload_url($row['img_thumb_square'] ?? $row['img_thumb_aspect'] ?? $row['img_file']),
            'img_width'  => (int)($row['img_width'] ?? 0),
            'img_height' => (int)($row['img_height'] ?? 0),
        ];
    }, $rows);

    os_ok(['images' => $images, 'count' => count($images)]);
}

// =============================================================================
// ENDPOINT: GET ohsnap/skin
// Active skin files: manifest contents, style.css, and CSS variable map.
// Oh Snap! uses the variable map to populate its controls panel.
// =============================================================================
if ($resource === 'skin' && $method === 'GET') {
    $active_skin = $settings['active_skin'] ?? '';

    if (!$active_skin) {
        os_err('No active skin configured', 404);
    }

    $manifest_raw = os_skin_file($active_skin, 'manifest.php');
    $style_css    = os_skin_file($active_skin, 'style.css');
    $variables    = os_skin_variables($active_skin);

    if ($manifest_raw === null) {
        os_err('Active skin files not found', 404);
    }

    // Parse the manifest to a clean array for Oh Snap!
    $manifest_data = [];
    $skin_slug     = preg_replace('/[^a-z0-9\-]/', '', $active_skin);
    $manifest_path = dirname(__DIR__) . '/skins/' . $skin_slug . '/manifest.php';
    try {
        $manifest_data = include $manifest_path;
        // Strip closures and callables — not JSON-serialisable
        array_walk_recursive($manifest_data, function (&$v) {
            if (is_callable($v)) $v = null;
        });
    } catch (Throwable $e) {}

    os_ok([
        'skin_slug'      => $active_skin,
        'manifest'       => $manifest_data,
        'style_css'      => $style_css ?? '',
        'css_variables'  => $variables,
        'oh_snap_ready'  => !empty($variables),
    ]);
}

// =============================================================================
// ENDPOINT: POST ohsnap/skin/push
// Upload a skin zip. Oh Snap! sends multipart/form-data with:
//   skin_zip  — the .zip file
//   activate  — '1' to make it the active skin after install
// =============================================================================
if ($resource === 'skin' && $sub === 'push' && $method === 'POST') {
    if (empty($_FILES['skin_zip'])) {
        os_err('skin_zip file is required');
    }

    $upload  = $_FILES['skin_zip'];
    $activate = ($_POST['activate'] ?? '0') === '1';

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        os_err('Upload error code: ' . $upload['error']);
    }

    // Validate it's actually a zip
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($upload['tmp_name']);
    if (!in_array($mime, ['application/zip', 'application/x-zip-compressed'], true)) {
        os_err('Uploaded file is not a zip archive');
    }

    $skins_dir = dirname(__DIR__) . '/skins/';
    $tmp_dir   = sys_get_temp_dir() . '/ohsnap_push_' . bin2hex(random_bytes(8)) . '/';
    mkdir($tmp_dir, 0755, true);

    try {
        $zip = new ZipArchive();
        if ($zip->open($upload['tmp_name']) !== true) {
            os_err('Could not open zip archive');
        }
        $zip->extractTo($tmp_dir);
        $zip->close();

        // Expect either a direct skin directory or a single wrapper folder
        $entries = array_diff(scandir($tmp_dir), ['.', '..']);
        $skin_dir_candidate = $tmp_dir;

        if (count($entries) === 1 && is_dir($tmp_dir . reset($entries))) {
            // Single wrapper folder — descend into it
            $skin_dir_candidate = $tmp_dir . reset($entries) . '/';
            $entries = array_diff(scandir($skin_dir_candidate), ['.', '..']);
        }

        // Validate: must contain manifest.php and style.css
        if (!file_exists($skin_dir_candidate . 'manifest.php') || !file_exists($skin_dir_candidate . 'style.css')) {
            os_err('Invalid skin package: missing manifest.php or style.css');
        }

        // Read the skin slug from the manifest
        try {
            $skin_manifest = include $skin_dir_candidate . 'manifest.php';
        } catch (Throwable $e) {
            os_err('Could not parse skin manifest');
        }

        $skin_name = $skin_manifest['name'] ?? '';
        if (!$skin_name) {
            os_err('Skin manifest is missing a name');
        }

        $skin_slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $skin_name));
        $dest_dir  = $skins_dir . $skin_slug . '/';

        // Install — overwrite if already exists
        if (is_dir($dest_dir)) {
            // Wipe existing skin dir
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dest_dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($dest_dir);
        }

        // Copy extracted files into skins dir
        $copy_iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($skin_dir_candidate, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        mkdir($dest_dir, 0755, true);
        foreach ($copy_iter as $item) {
            $dest_path = $dest_dir . $copy_iter->getSubPathname();
            if ($item->isDir()) {
                mkdir($dest_path, 0755, true);
            } else {
                copy($item->getPathname(), $dest_path);
            }
        }

        // Optionally activate
        if ($activate) {
            $pdo->prepare("
                INSERT INTO snap_settings (setting_key, setting_val)
                VALUES ('active_skin', ?)
                ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)
            ")->execute([$skin_slug]);
        }

        os_ok([
            'skin_slug'  => $skin_slug,
            'skin_name'  => $skin_name,
            'version'    => $skin_manifest['version'] ?? '',
            'activated'  => $activate,
        ]);

    } finally {
        // Always clean up temp dir
        if (is_dir($tmp_dir)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmp_dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($tmp_dir);
        }
    }
}

// --- FALLBACK ---
os_err('Unknown Oh Snap! endpoint: ' . $resource . ($sub ? '/' . $sub : ''), 404);
