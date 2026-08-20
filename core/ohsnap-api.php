<?php
/**
 * SNAPSMACK - Oh Snap! API Handler
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
 *   GET  ohsnap/library       — shared resources library (asset inventory), the
 *                               canonical route every tool fetches; serve-time
 *                               validated (200 present / 409 incomplete / 404 missing)
 *   POST ohsnap/skin/push     — upload a skin zip and optionally activate it
 *   POST ohsnap/skin/vars     — push CSS variable overrides (stored + served live)
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// --- ENVIRONMENT BOOTSTRAP ---
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    define('BASE_URL', $protocol . '://' . $_SERVER['HTTP_HOST'] . '/');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/constants.php';

// --- CORS: allow Oh Snap! desktop app. Origins: file:// and tauri://localhost
//     (macOS/Linux) AND http(s)://tauri.localhost — Tauri 2 on Windows/WebView2
//     serves the app from there, so the old file://|tauri:// allowlist silently
//     blocked every Windows connect with a CORS failure. ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^(file://|tauri://|https?://tauri\.localhost)#', $origin)) {
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
// SECAUDIT 039 (sweep): this handler authenticated on key_hash + is_active ALONE.
// Two gaps: (1) no key_type filter, so a key minted for ANY other tool — GYSS,
// SYBU, flkr-fckr — authenticated here, crossing tool scopes the key UI implies
// are separate; (2) no expiry check, so a lapsed key kept working (the platform
// mandates <=4-week keys since 0.7.263, enforced in core/api-auth.php).
// Legacy keys predating the key_type column default to 'ohsnap', so the added
// filter does not lock out existing installs. Falls back if the column is absent.
try {
    $key_stmt = $pdo->prepare("
        SELECT id FROM snap_ohsnap_keys
        WHERE key_hash = ? AND is_active = 1 AND key_type = 'ohsnap'
          AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 1
    ");
    $key_stmt->execute([$key_hash]);
    $api_key_row = $key_stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $key_stmt = $pdo->prepare("
            SELECT id FROM snap_ohsnap_keys
            WHERE key_hash = ? AND is_active = 1
            LIMIT 1
        ");
        $key_stmt->execute([$key_hash]);
        $api_key_row = $key_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        os_err('Database error during auth', 503);
    }
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
    $path = dirname(__DIR__) . '/skins/' . preg_replace('/[^a-z0-9\-]/', '', $skin_slug) . '/manifest.json';
    if (!file_exists($path)) return [];
    try {
        $manifest = snapsmack_load_manifest($path);
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
        $manifest_path = dirname(__DIR__) . '/skins/' . preg_replace('/[^a-z0-9\-]/', '', $active_skin) . '/manifest.json';
        if (file_exists($manifest_path)) {
            try {
                $m = snapsmack_load_manifest($manifest_path);
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
        LEFT JOIN snap_post_images pi ON pi.post_id = p.id AND pi.sort_position = (
            SELECT MIN(sort_position) FROM snap_post_images WHERE post_id = p.id
        )
        LEFT JOIN snap_images i ON i.id = pi.image_id
        WHERE p.status = 'published'
        ORDER BY CASE WHEN p.sort_order > 0 THEN 1 ELSE 0 END ASC, p.sort_order ASC, p.id DESC
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

    $manifest_raw = os_skin_file($active_skin, 'manifest.json');
    $style_css    = os_skin_file($active_skin, 'style.css');
    $variables    = os_skin_variables($active_skin);

    if ($manifest_raw === null) {
        os_err('Active skin files not found', 404);
    }

    // Parse the manifest to a clean array for Oh Snap!
    $manifest_data = [];
    $skin_slug     = preg_replace('/[^a-z0-9\-]/', '', $active_skin);
    $manifest_path = dirname(__DIR__) . '/skins/' . $skin_slug . '/manifest.json';
    try {
        $manifest_data = snapsmack_load_manifest($manifest_path);
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
// ENDPOINT: POST ohsnap/skin/push — REMOVED 2026-08-09 (security)
// A server endpoint that received a ZIP and unpacked it into the live skins/
// directory is remote-code-execution by design: receiving and unzipping an
// untrusted archive is itself attack surface (zip-parser and upload-handling
// zero-days), and a skin contains executable PHP. Skins now enter ONE way —
// through git, examined locally by the owner first. Oh Snap! saves a .zip /
// opens the mail client so a submission is reviewed BEFORE it can reach the
// server. This route is refused unconditionally and reads no request body.
// =============================================================================
if ($resource === 'skin' && $sub === 'push') {
    os_err('Direct skin upload has been permanently removed. Export the skin from Oh Snap! and email it for review; approved skins are installed through the normal git pipeline.', 410);
}

// =============================================================================
// ENDPOINT: POST ohsnap/skin/vars
// Push CSS variable overrides from Oh Snap! directly onto the active skin
// without needing a full skin zip. Stores values in snap_settings under
// the key "ohsnap_vars_{skin_slug}" as a JSON blob.
//
// Request body (JSON): { "vars": { "--bg-page": "#000", ... } }
// The meta system reads this blob and injects it as a :root block after the
// compiled skin CSS, so changes appear immediately on the live site.
// =============================================================================
if ($resource === 'skin' && $sub === 'vars' && $method === 'POST') {
    $active_skin = $settings['active_skin'] ?? '';
    if (!$active_skin) os_err('No active skin configured', 404);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!isset($body['vars']) || !is_array($body['vars'])) {
        os_err('Request body must be JSON with a "vars" object');
    }

    // Sanitise: only allow CSS custom property keys (--something) and safe values
    $safe = [];
    foreach ($body['vars'] as $prop => $val) {
        $prop = (string)$prop;
        $val  = (string)$val;
        if (!preg_match('/^--[a-z][a-z0-9-]*$/i', $prop)) continue;
        // Strip anything that could break the CSS block
        if (preg_match('/[;<>{}]/', $val)) continue;
        $safe[$prop] = $val;
    }

    $key     = 'ohsnap_vars_' . preg_replace('/[^a-z0-9\-]/', '', $active_skin);
    $encoded = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $pdo->prepare("
        INSERT INTO snap_settings (setting_key, setting_val)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)
    ")->execute([$key, $encoded]);

    os_ok([
        'skin_slug'  => $active_skin,
        'vars_count' => count($safe),
        'stored_key' => $key,
    ]);
}

// =============================================================================
// ENDPOINT: GET ohsnap/library
// The shared resources library — the catalogue of JS engines, fonts and CSS
// blocks a skin can reuse (generated by tools/asset-inventory.php into
// assets/ASSET-INVENTORY.json). THE canonical route every tool fetches, behind
// the same Bearer gate as the rest of ohsnap/*; do not let a tool reinvent this.
//
// This is fleet-canonical: the file ships identically to every install via the
// updater, so the library is the same on every site (a skin designed against it
// installs at or above the build it was designed on).
//
// Serve-time validation is the belt-and-suspenders behind the generator's
// generation-time fail-loud gate: even though the manifest "cannot" ship
// incomplete, a drifted or tampered file might. So the four outcomes are kept
// DISTINGUISHABLE for the client, which acts oppositely on each:
//   404  manifest file missing/unreadable       -> client: FAILED (fail closed)
//   500  manifest present but not valid JSON     -> client: FAILED (fail closed)
//   409  manifest parses but is INCOMPLETE       -> client: INCOMPLETE (fail closed)
//   200  manifest complete                       -> client: PRESENT (build)
// (The fourth state, connected-but-genuinely-empty/fresh-install, is decided by
//  the media/posts routes returning zero, not by this route — the library is
//  always present on any install because it is core's own assets.)
// =============================================================================
if ($resource === 'library' && $method === 'GET') {
    $manifest_path = dirname(__DIR__) . '/assets/ASSET-INVENTORY.json';
    if (!is_readable($manifest_path)) {
        os_err('Shared library manifest not found on this install', 404);
    }
    $raw = file_get_contents($manifest_path);
    $manifest = json_decode((string)$raw, true);
    if (!is_array($manifest)) {
        os_err('Shared library manifest is unreadable (not valid JSON)', 500);
    }

    // Serve-time validation — mirrors the generator guard so a drifted file is
    // caught as INCOMPLETE (409), never served as if it were complete.
    $problems = [];
    if (empty($manifest['schema_version'])) {
        $problems[] = 'missing schema_version';
    }
    $known = [];
    foreach (($manifest['javascript'] ?? []) as $e) if (!empty($e['file'])) $known[basename($e['file'])] = true;
    foreach (($manifest['css'] ?? [])        as $e) if (!empty($e['file'])) $known[basename($e['file'])] = true;

    $blank = static function ($v): bool {
        return !is_string($v) || trim($v) === '' || str_starts_with($v, 'NEEDS');
    };
    foreach (($manifest['javascript'] ?? []) as $e) {
        $f = $e['file'] ?? '(unknown)';
        foreach (['enable', 'family', 'purpose'] as $field) {
            if ($blank($e[$field] ?? '')) $problems[] = "{$f}: blank {$field}";
        }
        foreach (($e['requires'] ?? []) as $dep) {
            if (empty($known[$dep])) $problems[] = "{$f}: requires missing '{$dep}'";
        }
    }
    foreach (($manifest['css'] ?? []) as $e) {
        $f = $e['file'] ?? '(unknown)';
        if ($blank($e['purpose'] ?? '')) $problems[] = "{$f}: blank purpose";
        foreach (($e['requires'] ?? []) as $dep) {
            if (empty($known[$dep])) $problems[] = "{$f}: requires missing '{$dep}'";
        }
    }

    if ($problems) {
        os_respond([
            'ok'        => false,
            'error'     => 'Shared library manifest is incomplete — not serving a partial library.',
            'incomplete'=> true,
            'problems'  => array_slice($problems, 0, 50),
        ], 409);
    }

    // The curated, skin-FACING engine registry — core/manifest-inventory.php is
    // the single source of truth for what a skin can actually turn on, and,
    // crucially, THE HANDLE it declares in require_scripts (e.g. "smack-columns",
    // NOT the filename). The raw asset inventory above lists every file including
    // CMS back-office; only these are declarable in a skin. We merge the registry
    // (handle, label, controls) with the inventory (purpose, requires) by path, so
    // Oh Snap! gets everything it needs to declare an engine correctly instead of
    // guessing the token.
    $engines = [];
    $by_path = [];
    foreach (($manifest['javascript'] ?? []) as $e) if (!empty($e['file'])) $by_path[$e['file']] = $e;
    $registry = @include __DIR__ . '/manifest-inventory.php';
    if (is_array($registry) && !empty($registry['scripts'])) {
        // filename -> declaration handle, so a dependency can be expressed as the
        // token a skin actually declares (smack-organized-mayhem), not a filename.
        $file_to_handle = [];
        foreach ($registry['scripts'] as $h => $m) {
            if (!empty($m['path'])) $file_to_handle[basename($m['path'])] = $h;
        }
        foreach ($registry['scripts'] as $handle => $meta) {
            $path = $meta['path'] ?? '';
            // Skip a stale registry entry whose file isn't actually present.
            if ($path === '' || !isset($by_path[$path])) continue;
            $inv = $by_path[$path];
            // Translate each dependency filename to its handle where it is itself a
            // declarable engine; keep the filename for non-engine deps (e.g. a CSS).
            $needs = [];
            foreach (($inv['requires'] ?? []) as $dep) {
                $needs[] = $file_to_handle[$dep] ?? $dep;
            }
            $engines[] = [
                'handle'       => $handle,          // the require_scripts token
                'label'        => $meta['label'] ?? '',
                'path'         => $path,
                'purpose'      => $inv['purpose'] ?? '',
                'requires'     => $needs,
                'has_settings' => !empty($meta['has_settings']),
            ];
        }
    }

    os_ok([
        'schema_version' => $manifest['schema_version'],
        'engines'        => $engines,   // curated skin-facing list, with handles
        'library'        => $manifest,  // full asset inventory (everything, incl. CSS)
        'counts'         => [
            'engines'    => count($engines),
            'javascript' => count($manifest['javascript'] ?? []),
            'fonts'      => count($manifest['fonts'] ?? []),
            'css'        => count($manifest['css'] ?? []),
        ],
    ]);
}

// --- FALLBACK ---
os_err('Unknown Oh Snap! endpoint: ' . $resource . ($sub ? '/' . $sub : ''), 404);
// ===== SNAPSMACK EOF =====
