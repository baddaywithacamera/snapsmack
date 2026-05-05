<?php
/**
 * SNAPSMACK - Audit Endpoint
 *
 * JSON API consumed by Smack Your Batch Up's Audit and Repair tabs.
 * Requires an active admin session (auth.php).
 *
 * GET  ?action=summary     — post counts, duplicate title stats, missing Drive links
 * GET  ?action=list        — all published posts with id, title, download_url, date
 * POST ?action=update_title — set img_title for one record (snap_id, new_title)
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/api-auth.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // ── Summary ───────────────────────────────────────────────────────────────
    case 'summary':
        // Total published posts + Drive link status
        $row = $pdo->query("
            SELECT
                COUNT(*)                                                      AS total,
                SUM(download_url IS NOT NULL AND download_url != '')          AS has_drive,
                SUM(download_url IS NULL     OR  download_url = '')           AS missing_drive
            FROM snap_images
            WHERE img_status = 'published'
        ")->fetch(PDO::FETCH_ASSOC);

        // Duplicate title groups and how many posts need new titles
        $dup = $pdo->query("
            SELECT
                COUNT(*)       AS dup_groups,
                SUM(cnt - 1)   AS posts_needing_titles
            FROM (
                SELECT img_title, COUNT(*) AS cnt
                FROM   snap_images
                WHERE  img_status = 'published'
                GROUP  BY img_title
                HAVING cnt > 1
            ) t
        ")->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'                  => true,
            'total'               => (int) $row['total'],
            'has_drive'           => (int) $row['has_drive'],
            'missing_drive'       => (int) $row['missing_drive'],
            'duplicate_groups'    => (int) $dup['dup_groups'],
            'posts_needing_titles'=> (int) $dup['posts_needing_titles'],
        ]);
        break;

    // ── List ─────────────────────────────────────────────────────────────────
    case 'list':
        // Full post list — client extracts Drive file IDs from download_url
        $stmt = $pdo->query("
            SELECT
                id           AS snap_id,
                img_title,
                img_file,
                img_date,
                download_url,
                allow_download
            FROM   snap_images
            WHERE  img_status = 'published'
            ORDER  BY id ASC
        ");
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast types so JSON is clean
        foreach ($posts as &$p) {
            $p['snap_id']       = (int) $p['snap_id'];
            $p['allow_download'] = (int) $p['allow_download'];
        }
        unset($p);

        echo json_encode(['ok' => true, 'posts' => $posts],
                         JSON_UNESCAPED_UNICODE);
        break;

    // ── Update Title ──────────────────────────────────────────────────────────
    case 'update_title':
        $snap_id   = (int)   ($_POST['snap_id']   ?? 0);
        $new_title = trim($_POST['new_title'] ?? '');

        if (!$snap_id || $new_title === '') {
            http_response_code(400);
            echo json_encode(['ok' => false,
                              'error' => 'snap_id and new_title are required']);
            break;
        }

        $stmt = $pdo->prepare("
            UPDATE snap_images
            SET    img_title = ?
            WHERE  id        = ?
              AND  img_status = 'published'
        ");
        $stmt->execute([$new_title, $snap_id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['ok' => false,
                              'error' => "No published post found with id=$snap_id"]);
            break;
        }

        echo json_encode(['ok' => true]);
        break;

    // ── Unknown ───────────────────────────────────────────────────────────────
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
// ===== SNAPSMACK EOF =====
