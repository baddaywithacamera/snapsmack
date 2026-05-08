<?php
/**
 * SNAPSMACK - SUYB Data Endpoint
 *
 * JSON endpoint consumed by Smack Up Your Backup at connect time.
 * Returns cloud backup config, multisite node list, and site metadata
 * so SUYB can auto-populate profile fields.
 *
 * Authentication: standard session cookie (same as all admin pages).
 * Method: GET
 * Response: application/json
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

require_once 'core/auth.php';

header('Content-Type: application/json; charset=utf-8');

// ── Load settings ────────────────────────────────────────────────────────────
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                ->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Cloud configuration ──────────────────────────────────────────────────────
// Only expose whether cloud is configured and which provider — never send
// secrets or refresh tokens over this endpoint. SUYB uses its own OAuth
// credentials file; it just needs to know the folder target and provider.

$cloud_provider = 'none';
if (!empty($settings['google_client_id']) && !empty($settings['google_refresh_token'])) {
    $cloud_provider = 'google_drive';
} elseif (!empty($settings['onedrive_client_id']) && !empty($settings['onedrive_refresh_token'])) {
    $cloud_provider = 'onedrive';
}

$cloud_config = [
    'provider'  => $cloud_provider,
    // Google Drive folder ID if configured (not a secret — it's a target)
    'folder_id' => $settings['google_drive_folder_id'] ?? $settings['onedrive_folder_id'] ?? '',
];

// ── FTP heuristics ───────────────────────────────────────────────────────────
// SnapSmack itself doesn't store FTP credentials in snap_settings (that's
// hosting-level config), but we can derive the remote directory from the
// install path and provide the site URL for convenience.

$site_url  = $settings['site_url'] ?? (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/'
);
$site_name = $settings['site_name'] ?? 'SnapSmack';

// ── Backup status ────────────────────────────────────────────────────────────
$backup_status = [
    'last_backup_at'     => $settings['last_backup_at']     ?? null,
    'last_backup_size'   => $settings['last_backup_size']   ?? null,
    'last_backup_dest'   => $settings['last_backup_dest']   ?? null,
    'last_backup_status' => $settings['last_backup_status'] ?? 'unknown',
];

// ── Multisite nodes (only if this is a hub) ──────────────────────────────────
$nodes = [];
try {
    $node_rows = $pdo->query("
        SELECT id, role, site_url, site_name, api_key_local, api_key_remote,
               software_version, last_seen_at, post_count, image_count,
               last_backup_at, last_backup_status, status
        FROM snap_multisite_nodes
        WHERE status = 'active'
        ORDER BY role ASC, site_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($node_rows as $n) {
        $nodes[] = [
            'id'                 => (int) $n['id'],
            'role'               => $n['role'],
            'site_url'           => $n['site_url'],
            'site_name'          => $n['site_name'],
            'api_key_remote'     => $n['api_key_remote'],  // key for calling that node
            'software_version'   => $n['software_version'],
            'last_seen_at'       => $n['last_seen_at'],
            'post_count'         => (int) $n['post_count'],
            'image_count'        => (int) $n['image_count'],
            'last_backup_at'     => $n['last_backup_at'],
            'last_backup_status' => $n['last_backup_status'],
            'status'             => $n['status'],
        ];
    }
} catch (Exception $e) {
    // snap_multisite_nodes may not exist — single-site install
}

// ── Response ─────────────────────────────────────────────────────────────────
echo json_encode([
    'ok'            => true,
    'site_url'      => $site_url,
    'site_name'     => $site_name,
    'cloud_config'  => $cloud_config,
    'backup_status' => $backup_status,
    'multisite'     => [
        'is_hub'   => count($nodes) > 0,
        'nodes'    => $nodes,
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
// ===== SNAPSMACK EOF =====
