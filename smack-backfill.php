<?php
/**
 * SNAPSMACK - Drive Link Backfill Endpoint
 *
 * JSON API for SYBU's drive-link backfill / repair function (the standalone
 * "Fix Your Batch Up" desktop tool was folded into SYBU and is retired).
 * Auth: a 'sybu' scoped key (Authorization: Bearer) or an admin session.
 *
 * GET  ?action=list       — images missing a download_url, newest first
 * GET  ?action=list_drive — published images whose download_url is a Google Drive link
 * POST ?action=update     — set download_url + allow_download=1 for one record
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// SYBU endpoint: the drive-link backfill/repair function was folded into SYBU
// (tools/sybu/poster.py + main.py call this with a sybu key). Photoblog-only for
// tool access, matching the other SYBU endpoints — the gate affects key/tool
// access only; admin sessions are unaffected. Additive (legacy X-Snap-Key works).
$GLOBALS['SNAP_API_KEY_TYPES']    = ['sybu'];
// Writes a Drive share URL onto an existing post — mode-agnostic; allow gram too.
$GLOBALS['SNAP_API_REQUIRE_MODE'] = ['photoblog', 'carousel'];
require_once 'core/api-auth.php';

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

    // ── List Drive ───────────────────────────────────────────────────────────
    // Returns published images whose download_url is a Google Drive link.
    // Used by the Fix Your Batch Up Drive → B2 migration tab.
    case 'list_drive':
        $stmt = $pdo->query("
            SELECT id AS snap_id,
                   img_title,
                   img_file,
                   img_date,
                   allow_download,
                   download_url
            FROM   snap_images
            WHERE  img_status = 'published'
              AND  (
                     download_url LIKE 'https://drive.google.com/%'
                  OR download_url LIKE 'https://docs.google.com/%'
              )
            ORDER  BY img_date DESC
        ");
        $images   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $site_url = rtrim($settings['site_url'] ?? '', '/');
        echo json_encode(['ok' => true, 'site_url' => $site_url, 'images' => $images]);
        break;

    // ── List B2 ──────────────────────────────────────────────────────────────
    // Returns published images whose download_url is a Backblaze B2 link.
    // Used by the Fix Your Batch Up Cloud Migration tab (B2 → Drive direction).
    case 'list_b2':
        $stmt = $pdo->query("
            SELECT id AS snap_id,
                   img_title,
                   img_file,
                   img_date,
                   allow_download,
                   download_url
            FROM   snap_images
            WHERE  img_status = 'published'
              AND  (
                     download_url LIKE 'https://f%.backblazeb2.com/%'
                  OR download_url LIKE 'https://%.backblazeb2.com/file/%'
              )
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
// ===== SNAPSMACK EOF =====
