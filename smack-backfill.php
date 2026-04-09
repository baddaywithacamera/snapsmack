<?php
/**
 * SNAPSMACK - Drive Link Backfill Endpoint
 * Alpha v0.7.9
 *
 * JSON API for the Fix Your Batch Up desktop tool.
 * Requires an active admin session (auth.php).
 *
 * GET  ?action=list   — images missing a download_url, newest first
 * POST ?action=update — set download_url + allow_download=1 for one record
 */

require_once 'core/auth.php';

$settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$settings      = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // ── List ─────────────────────────────────────────────────────────────────
    case 'list':
        $stmt = $pdo->query("
            SELECT id AS snap_id,
                   img_title,
                   img_file,
                   img_date,
                   allow_download,
                   download_url
            FROM   snap_images
            WHERE  (download_url IS NULL OR download_url = '')
              AND  img_status = 'published'
            ORDER  BY img_date DESC
        ");
        $images   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $site_url = rtrim($settings['site_url'] ?? '', '/');
        echo json_encode(['ok' => true, 'site_url' => $site_url, 'images' => $images]);
        break;

    // ── Update ───────────────────────────────────────────────────────────────
    case 'update':
        $snap_id      = (int)($_POST['snap_id']      ?? 0);
        $download_url = trim($_POST['download_url']  ?? '');

        if (!$snap_id || !$download_url) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'snap_id and download_url are required']);
            break;
        }

        $stmt = $pdo->prepare("
            UPDATE snap_images
            SET    allow_download = 1,
                   download_url   = ?
            WHERE  id             = ?
        ");
        $stmt->execute([$download_url, $snap_id]);

        // Ensure the global downloads switch is on so the download button
        // actually renders in the skin (download-overlay.php checks this).
        $pdo->exec("
            INSERT INTO snap_settings (setting_key, setting_val)
            VALUES ('global_downloads_enabled', '1')
            ON DUPLICATE KEY UPDATE setting_val = '1'
        ");

        echo json_encode(['ok' => true]);
        break;

    // ── Unknown ──────────────────────────────────────────────────────────────
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
