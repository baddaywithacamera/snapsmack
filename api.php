<?php
/**
 * SNAPSMACK - API Router
 * Alpha v0.7.9
 *
 * Public API entry point. Routes /api/* requests to appropriate handlers.
 * Supports query parameter routing for shared hosting compatibility:
 * api.php?route=multisite/heartbeat
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

// --- FALLBACK: Unknown API endpoint ---
header('HTTP/1.1 404 Not Found');
header('Content-Type: application/json');
echo json_encode([
    'status' => 'error',
    'message' => 'Unknown API endpoint'
], JSON_PRETTY_PRINT);
exit;
?>
