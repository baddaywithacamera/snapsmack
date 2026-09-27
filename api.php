<?php
/**
 * SNAPSMACK - API Router
 *
 * Public API entry point. Routes /api/* requests to appropriate handlers.
 * Supports query parameter routing for shared hosting compatibility:
 * api.php?route=multisite/heartbeat
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// --- ROUTE EXTRACTION ---
// Parse route from query parameter or rewritten PATH_INFO
$route = '';

if (isset($_GET['route'])) {
    $route = trim($_GET['route'], '/');
} elseif (!empty($_SERVER['PATH_INFO'])) {
    $route = trim($_SERVER['PATH_INFO'], '/');
    // Remove leading 'api/' if present from rewrite
    if (strpos($route, 'api/') === 0) {
        $route = substr($route, 4);
    }
}

// --- SITE SCOPE (mutual-auth A1, SECAUDIT 054) ---
// Before any route runs: a Bearer WRITE that names a different site is refused.
// Dark by default (snap_settings.api_site_scope = off); see core/api-site-scope.php.
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/api-site-scope.php';
snap_api_site_scope_check($pdo);

// --- PAGE CACHE: desktop-tool writes clear it, like admin publishing does ---
// The admin posting pages call page_cache_purge_all() after publishing; the
// APIs the desktop tools post through never did. A SYBU batch of 13 grams left
// visitors on a front page saved at 17:25:22, mid-batch, with 7 of the 13
// missing (theschoolofhardnocks.ca, 2026-09-27). Any successful write on a
// content route now clears the saved pages when the request finishes. Heartbeats
// and other background multisite traffic are excluded so the cache still works.
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)
    && snap_api_route_changes_content($route)) {
    register_shutdown_function(static function () {
        $code = http_response_code();
        if ($code >= 200 && $code < 300) {
            require_once __DIR__ . '/core/page-cache.php';
            page_cache_purge_all();
        }
    });
}

/** Routes whose writes can add, change or remove what visitors see. */
function snap_api_route_changes_content(string $route): bool {
    foreach (['ohsnap', 'smackpress', 'bloggerflogger', 'flkrfckr', 'gyss', 'smackthemup',
              'threeacross', 'unzucker', 'tyswy', 'multisite/posts'] as $prefix) {
        if (strpos($route, $prefix) === 0) return true;
    }
    return false;
}

// --- MULTISITE ROUTES ---
// Route all /api/multisite/* requests to the multisite API handler
if (strpos($route, 'multisite') === 0) {
    require_once 'core/multisite-api.php';
    exit;
}

// --- SNAP HQ DEVICE AUTHORIZATION ---
// One-use activation followed by device-signed status/renewal requests.
if (strpos($route, 'desktop-auth') === 0) {
    require_once 'core/desktop-auth.php';
    exit;
}

// --- OH SNAP! ROUTES ---
// Route all /api/ohsnap/* requests to the Oh Snap! skin designer API handler
if (strpos($route, 'ohsnap') === 0) {
    require_once 'core/ohsnap-api.php';
    exit;
}

// --- SMACKPRESS ROUTES ---
// Route all /api/smackpress/* requests to the SmackPress migration API handler
if (strpos($route, 'smackpress') === 0) {
    require_once 'core/smackpress-api.php';
    exit;
}

// --- BLOGGER FLOGGER ROUTES ---
// Blogger Takeout -> SMACKTALK. Shares the proven longform ingest implementation
// with SMACKPRESS, but authenticates as its own narrowly-scoped key type.
if (strpos($route, 'bloggerflogger') === 0) {
    require_once 'core/smackpress-api.php';
    exit;
}

// --- FLKR FCKR ROUTES ---
// Route all /api/flkrfckr/* requests to the FLKR FCKR migration API handler
if (strpos($route, 'flkrfckr') === 0) {
    require_once 'core/flkrfckr-api.php';
    exit;
}

// --- GET YOUR SHIT SORTED ROUTES ---
// Route all /api/gyss/* requests to the GYSS desktop sorter API handler
if (strpos($route, 'gyss') === 0) {
    require_once 'core/gyss-api.php';
    exit;
}

// --- SMACKTHEMUP PUBLISHING ROUTES ---
// Dedicated, mode-bound surface for SNAP SLAPPER. It is intentionally separate
// from the carousel and longform writers: no other desktop key opens this door.
if (strpos($route, 'smackthemup') === 0) {
    require_once 'core/smackthemup-api.php';
    exit;
}

// --- THREE-ACROSS ROUTES (GRAMOFSMACK carousel write API) ---
// Shared carousel/trigram write API used by BOTH the Unzucker IG importer and
// the SMACK YOUR BATCH UP offline poster. The legacy 'unzucker/*' prefix is kept as a
// backward-compat alias so already-deployed Unzucker builds keep working until
// they're rebuilt onto 'threeacross/*'; both dispatch to the same handler.
if (strpos($route, 'threeacross') === 0 || strpos($route, 'unzucker') === 0) {
    // Load the renamed handler, falling back to the old filename if the git mv
    // hasn't been run yet — so a forgotten rename can't fatal the whole API.
    require_once is_file(__DIR__ . '/core/threeacross-api.php')
        ? __DIR__ . '/core/threeacross-api.php'
        : __DIR__ . '/core/unzucker-api.php';
    exit;
}

// --- STATS BEACON ROUTES ---
// Route all /api/stats/* requests (Scroll Time dwell beacon) to the stats API.
if (strpos($route, 'stats') === 0) {
    require_once 'core/stats-api.php';
    exit;
}

// --- TAKE YOUR SHIT WITH YOU (portable export) ---
// Read-only, streaming export surface for the TYSWY desktop tool. Scoped to
// key_type 'tyswy'; no write operation exists here by design.
if (strpos($route, 'tyswy') === 0) {
    require_once 'core/tyswy-api.php';
    exit;
}

// --- FALLBACK: Unknown API endpoint ---
header('HTTP/1.1 404 Not Found');
header('Content-Type: application/json');
echo json_encode([
    'status' => 'error',
    'message' => 'Unknown API endpoint'
]);
// ===== SNAPSMACK EOF =====
