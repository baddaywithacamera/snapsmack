<?php
/**
 * SNAPSMACK - Stats Beacon API Handler
 *
 * Public, unauthenticated first-party analytics beacon — same trust model as
 * the server-side page-hit logger (no cookies, no third-party call). Routed
 * here by api.php for any /api/stats/* request.
 *
 * Endpoint:
 *   POST stats/dwell   { "hit": <snap_stats.id>, "ms": <engaged milliseconds> }
 *     Records engaged Scroll Time against a prior hit. Bot-excluded, set-once,
 *     recent-window-only and capped inside snapsmack_record_dwell(). Always
 *     answers 204 so navigator.sendBeacon() never blocks page unload.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stats-logger.php';

// Route arrives as 'stats/{sub}'.
$parts = explode('/', trim($GLOBALS['route'] ?? ($_GET['route'] ?? ''), '/'));
$sub   = $parts[1] ?? '';

if ($sub === 'dwell' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Body may arrive as a sendBeacon JSON blob, or as plain form fields.
    $raw  = file_get_contents('php://input');
    $data = [];
    if ($raw !== '' && ($raw[0] ?? '') === '{') {
        $data = json_decode($raw, true) ?: [];
    }
    $hit = (int)($data['hit'] ?? $_POST['hit'] ?? 0);
    $ms  = (int)($data['ms']  ?? $_POST['ms']  ?? 0);

    if (isset($pdo) && $pdo instanceof PDO) {
        snapsmack_record_dwell($pdo, $hit, $ms);
    }

    http_response_code(204); // No Content — the beacon ignores any body.
    exit;
}

// Unknown stats sub-route.
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'error' => 'Unknown stats endpoint']);
exit;
// ===== SNAPSMACK EOF =====
