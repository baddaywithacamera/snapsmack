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
 *     HOW THE BEACON IS BOUND TO ITS HIT (SS-1083, reported by Muse in the
 *     security audit of 30 October and repaired here). The route stays
 *     unauthenticated -- a beacon has to survive page unload, so it cannot
 *     carry a session -- so the binding is a signature instead:
 *
 *       - the tracker tag carries data-token, an HMAC of the hit id under the
 *         per-install salt (snapsmack_dwell_token);
 *       - the engine sends it back with the reading;
 *       - this route refuses anything whose token does not match the hit
 *         (snapsmack_dwell_token_ok), and still answers 204, because a beacon
 *         cannot read a response and a caller learns nothing from the refusal.
 *
 *     That is what stops one caller writing to ANOTHER hit. It deliberately
 *     does NOT stop a visitor inflating their own, because the token is on the
 *     page beside the id and anyone served the page holds both. The second
 *     guard is in snapsmack_record_dwell(): the reading is clamped to the time
 *     that has actually elapsed since the hit was logged, so a page cannot
 *     report having been read for longer than it has existed.
 *
 *     STILL NOT GUARDED, so nobody reads more into this than it says: there is
 *     no rate limiting, and a visitor who stays on their own page can still
 *     report their own real time, which is the point of the feature. The blast
 *     radius was always the owner's own Scroll Time figures -- no data exposed,
 *     no other column, row or table reachable from here.
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
    $hit   = (int)($data['hit'] ?? $_POST['hit'] ?? 0);
    $ms    = (int)($data['ms']  ?? $_POST['ms']  ?? 0);
    $token = (string)($data['token'] ?? $_POST['token'] ?? '');

    // SS-1083. The hit id is published to every visitor in data-hit, so it is
    // not a secret and never was. The token proves the caller was served THIS
    // hit's page, which is what stops one visitor writing to another's hit or
    // walking neighbouring ids. Still answers 204 either way: a beacon cannot
    // read a response and telling a caller its token was wrong helps nobody.
    if (isset($pdo) && $pdo instanceof PDO && snapsmack_dwell_token_ok($hit, $token, $pdo)) {
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
