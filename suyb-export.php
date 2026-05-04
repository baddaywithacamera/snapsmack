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
 *   type=keys     — snap_users only (emergency credential recovery)
 *   type=kit      — Recovery kit (.tar.gz with manifest + SQL dump)
 *
 * Response:
 *   type=schema|full|keys → Content-Type: application/sql, streamed as download
 *   type=kit              → Content-Type: application/x-gzip, streamed as download
 */

require_once 'core/auth.php';
require_once 'core/export-engine.php';

$type = $_GET['type'] ?? 'full';

if (!in_array($type, ['schema', 'full', 'keys', 'kit'], true)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid type. Use: schema, full, keys, or kit']);
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

// SQL dump — schema, full, or keys
$sql = $exporter->generateSqlDump($type);

$filename = "{$siteSlug}_{$type}_{$timestamp}.sql";
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($sql));
echo $sql;
exit;
// EOF
