<?php
/**
 * SNAPSMACK - SUYB Export Endpoint
 *
 * Serves database dumps, schema exports, and recovery kits to Smack Up
 * Your Backup over an authenticated admin session. SUYB calls this
 * endpoint instead of needing direct database access.
 *
 * Authentication: standard session cookie (same as all admin pages).
 * Method: GET
 *
 * Query parameters:
 *   type=schema   — DDL only (CREATE TABLE statements for all snap_* tables)
 *   type=full     — Full SQL dump (schema + all data)  [default]
 *   type=kit      — Recovery kit (.tar.gz with manifest + SQL dump)
 *   type=inventory — File inventory JSON (paths + sizes, NO hashing) for SUYB
 *                    client-side manifest/kit generation (Windows/Linux path)
 *
 * Response:
 *   type=schema|full      → Content-Type: application/sql, streamed as download
 *   type=kit              → Content-Type: application/x-gzip, streamed as download
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// CSRF: this endpoint legitimately accepts POST without a session-tied
// CSRF token (pre-auth flow / tool API authentication). Mark exempt
// before auth.php's auto-validator fires.
require_once __DIR__ . '/core/csrf.php';
csrf_exempt();

// SUYB scoped key (see suyb-data.php). Additive — legacy auth still works.
$GLOBALS['SNAP_API_KEY_TYPES'] = ['suyb'];
require_once 'core/api-auth.php';
require_once 'core/export-engine.php';

$type = $_GET['type'] ?? 'full';

if (!in_array($type, ['schema', 'full', 'kit', 'inventory'], true)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid type. Use: schema, full, kit, or inventory']);
    exit;
}

$exporter = new SnapSmackExport($pdo, __DIR__);

// Load settings for filename
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
$siteName = $settings['site_name'] ?? '';
$siteSlug = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($siteName));
$siteSlug = trim($siteSlug, '_') ?: 'snapsmack';
$timestamp = date('Y-m-d_H-i');

if ($type === 'inventory') {
    // Lightweight manifest for SUYB client-side kit generation: file list with
    // restore paths + sizes (NO server-side hashing), plus metadata, stats, and
    // the database image map. The client computes checksums as it downloads each
    // file over FTP and builds the manifest/kit itself. This keeps the hashing
    // load off the server — the whole point of the tool.
    try {
        $manifest = $exporter->exportInventory();
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Inventory failed: ' . $e->getMessage()]);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'manifest' => $manifest], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($type === 'kit') {
    // Full recovery kit — .tar.gz with manifest + SQL
    try {
        $kitPath = $exporter->exportRecoveryKit();
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Kit generation failed: ' . $e->getMessage()]);
        exit;
    }

    $filename = "{$siteSlug}_{$timestamp}.tar.gz";
    header('Content-Type: application/x-gzip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($kitPath));
    readfile($kitPath);
    @unlink($kitPath);
    exit;
}

// SQL dump — schema or full. STREAMED so a large DB never has to fit in
// memory at once (building the whole dump into a string 500'd/OOM'd on big sites).
$filename = "{$siteSlug}_{$type}_{$timestamp}.sql";
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
@set_time_limit(0);
while (ob_get_level() > 0) { ob_end_flush(); }
$exporter->streamSqlDump($type);
exit;
// ===== SNAPSMACK EOF =====
