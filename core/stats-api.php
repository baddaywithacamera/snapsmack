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
 *     Records engaged Scroll Time against a prior hit. Bot-excluded,
 *     recent-window-only and capped inside snapsmack_record_dwell(). Always
 *     answers 204 so navigator.sendBeacon() never blocks page unload.
 *
 *     NOT SET-ONCE. This said "set-once" and the code has never behaved that
 *     way: snapsmack_record_dwell writes
 *     GREATEST(COALESCE(dwell_ms, 0), ?), which CONVERGES ON THE MAXIMUM, and
 *     that is deliberate -- repeated beacons over one visit must not freeze at
 *     the first short tab-away sample. The function's own comment explains the
 *     maximum; this header contradicted it. Corrected rather than left, because
 *     the next reader checking whether a second beacon can overwrite the first
 *     would have got the wrong answer from the wrong place.
 *
 *     WHAT IS NOT GUARDED, named here because the word "capped" reads like
 *     more protection than it is (Muse, security audit, 30 October). The route
 *     is unauthenticated by design and takes the hit id from the request body.
 *     snap_stats.id is an auto-increment record id AND it is published to every
 *     visitor -- core/stats-logger.php emits data-hit="<id>" so the engine can
 *     use it -- so a caller does not have to guess: it can read its own id off
 *     the page and walk neighbouring ids. Within the 6-hour window, on a
 *     non-bot hit, that sets dwell_ms to the 30-minute cap.
 *
 *     The blast radius is the owner's own Scroll Time figures. No data is
 *     exposed, no other column or row is writable, and no other table is
 *     reachable from here. So this is analytics integrity, not a breach -- but
 *     it means a Scroll Time number is not evidence of anything if someone
 *     cared to inflate it. NOT FIXED: binding the beacon to its hit needs a
 *     decision and this is a comment, not a repair.
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
