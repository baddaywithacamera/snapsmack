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

// --- MULTISITE ROUTES ---
// Route all /api/multisite/* requests to the multisite API handler
if (strpos($route, 'multisite') === 0) {
    require_once 'core/multisite-api.php';
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

// --- THREE-ACROSS ROUTES (GRAMOFSMACK carousel write API) ---
// Shared carousel/trigram write API used by BOTH the Unzucker IG importer and
// the SUMNABATCH offline poster. The legacy 'unzucker/*' prefix is kept as a
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

// --- FALLBACK: Unknown API endpoint ---
header('HTTP/1.1 404 Not Found');
header('Content-Type: application/json');
echo json_encode([
    'status' => 'error',
    'message' => 'Unknown API endpoint'
]);
// ===== SNAPSMACK EOF =====
