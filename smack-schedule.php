<?php
/**
 * SNAPSMACK - Schedule Endpoint
 *
 * JSON API consumed by SHOTS FIRED (the fleet-wide scheduled-post board).
 * Scheduling already works via the display date-gate: a post with a FUTURE
 * img_date is stored published-but-hidden and appears the moment its date
 * arrives. This endpoint only SURFACES that queue and lets the tool restamp a
 * post's img_date. It creates nothing and deletes nothing.
 *
 * GET  ?action=list      — upcoming scheduled posts (published AND img_date > now)
 * POST ?action=set_date  — reschedule one post: set img_date (snap_id, img_date)
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// SYBU-scoped key — scheduling is post management, the same tool family (and the
// same key SHOTS FIRED inherits from THE HUB's Discover) as the audit endpoint.
//
// SYBU WRITE SCOPE — LOCKED: the ONLY write this endpoint performs is
// action=set_date -> UPDATE snap_images.img_date for a single PUBLISHED row
// (snap_id, img_date). No other field, row-set, or table is reachable, and it
// can neither publish, unpublish, nor delete. Do not add write actions without
// re-confirming the tool's scope (mirrors smack-audit.php's locked scope).
$GLOBALS['SNAP_API_KEY_TYPES']    = ['sybu'];
$GLOBALS['SNAP_API_REQUIRE_MODE'] = ['photoblog', 'carousel'];
require_once 'core/api-auth.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // ── List upcoming scheduled posts ──────────────────────────────────────────
    case 'list':
        // A "scheduled" post is a published row whose img_date is still in the
        // future (the display gate hides it until then). Optional from/to window.
        $from  = trim($_GET['from'] ?? '');   // default: now
        $to    = trim($_GET['to']   ?? '');   // default: unbounded
        $limit = (int) ($_GET['limit'] ?? 500);
        if ($limit < 1)    $limit = 1;
        if ($limit > 2000) $limit = 2000;

        // Build the window. NOW() when no explicit lower bound; validated params otherwise.
        $params = [];
        $lower  = 'NOW()';
        if ($from !== '' && strtotime($from) !== false) {
            $lower    = '?';
            $params[] = date('Y-m-d H:i:s', strtotime($from));
        }
        $upper = '';
        if ($to !== '' && strtotime($to) !== false) {
            $upper    = ' AND img_date <= ?';
            $params[] = date('Y-m-d H:i:s', strtotime($to));
        }

        $stmt = $pdo->prepare("
            SELECT
                id AS snap_id,
                img_title,
                img_date,
                img_status,
                img_file,
                img_thumb_square,
                post_id
            FROM   snap_images
            WHERE  img_status = 'published'
              AND  img_date > $lower
              $upper
            ORDER  BY img_date ASC
            LIMIT  " . $limit . "
        ");
        $stmt->execute($params);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($posts as &$p) {
            $p['snap_id'] = (int) $p['snap_id'];
            $p['post_id'] = $p['post_id'] !== null ? (int) $p['post_id'] : null;
        }
        unset($p);

        echo json_encode(['ok' => true, 'posts' => $posts], JSON_UNESCAPED_UNICODE);
        break;

    // ── Reschedule one post ─────────────────────────────────────────────────────
    case 'set_date':
        $snap_id  = (int) ($_POST['snap_id'] ?? 0);
        $img_date = trim($_POST['img_date'] ?? '');

        // Never trust the client: require a real 'YYYY-MM-DD HH:MM:SS'.
        $ts = $img_date !== '' ? strtotime($img_date) : false;
        if (!$snap_id || $ts === false) {
            http_response_code(400);
            echo json_encode(['ok' => false,
                              'error' => 'snap_id and a valid img_date (YYYY-MM-DD HH:MM:SS) are required']);
            break;
        }
        $img_date = date('Y-m-d H:i:s', $ts);   // normalise

        $stmt = $pdo->prepare("
            UPDATE snap_images
            SET    img_date  = ?
            WHERE  id        = ?
              AND  img_status = 'published'
        ");
        $stmt->execute([$img_date, $snap_id]);

        // Note: MySQL reports 0 affected rows when the new date equals the old one;
        // treat "row exists but unchanged" as success rather than a false 404.
        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare("SELECT 1 FROM snap_images WHERE id = ? AND img_status = 'published'");
            $exists->execute([$snap_id]);
            if (!$exists->fetchColumn()) {
                http_response_code(404);
                echo json_encode(['ok' => false,
                                  'error' => "No published post found with id=$snap_id"]);
                break;
            }
        }

        echo json_encode(['ok' => true, 'snap_id' => $snap_id, 'img_date' => $img_date]);
        break;

    // ── Unknown ─────────────────────────────────────────────────────────────────
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
// ===== SNAPSMACK EOF =====
