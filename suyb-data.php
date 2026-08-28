<?php
/**
 * SNAPSMACK - SUYB Data Endpoint
 *
 * JSON endpoint consumed by Smack Up Your Backup at connect time.
 * Returns cloud backup config, multisite node list, and site metadata
 * so SUYB can auto-populate profile fields.
 *
 * Authentication: a 'suyb' or 'hub' scoped key (Authorization: Bearer) or admin session cookie.
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

// SUYB holds a least-privilege 'suyb' scoped key (snap_ohsnap_keys, key_type).
// THE HUB holds an even narrower 'hub' key that unlocks ONLY this discovery
// endpoint — never suyb-export.php / suyb-complete.php — so one-time fleet setup
// can read the node list without being able to touch backup data. Declared before
// api-auth so its typed-Bearer branch accepts both; neither can act on
// 'sybu'/importer endpoints. Legacy X-Snap-Key + admin session still work
// (additive) until tool_api_key is retired.
$GLOBALS['SNAP_API_KEY_TYPES'] = ['suyb', 'hub'];
require_once 'core/api-auth.php';

header('Content-Type: application/json; charset=utf-8');

// The hub is not one of its own multisite nodes, so it cannot use the
// spoke-only multisite/provision-key route. Allow the already-authenticated hub
// discovery credential to install the fleet's shared, read-only SUYB key on
// this site itself. This is deliberately limited to one supplied 64-hex key;
// it cannot mint or install keys for any other tool scope.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    $action = is_array($body) ? strtolower(trim((string)($body['action'] ?? ''))) : '';
    $key_value = is_array($body) ? strtolower(trim((string)($body['key_value'] ?? ''))) : '';
    if ($action !== 'provision-backup-key') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
        exit;
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $key_value)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'key_value must be 64 hexadecimal characters.']);
        exit;
    }
    try {
        foreach ([
            "ALTER TABLE snap_ohsnap_keys ADD COLUMN key_type VARCHAR(20) NOT NULL DEFAULT 'ohsnap' AFTER label",
            "ALTER TABLE snap_ohsnap_keys ADD COLUMN key_prefix VARCHAR(8) NOT NULL DEFAULT '' AFTER key_hash",
            "ALTER TABLE snap_ohsnap_keys ADD COLUMN expires_at DATETIME DEFAULT NULL AFTER last_used_at",
        ] as $alter) {
            try { $pdo->exec($alter); } catch (PDOException $e) { /* already present */ }
        }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE snap_ohsnap_keys SET is_active = 0 WHERE key_type = 'suyb' AND label LIKE 'HUB %'")
            ->execute();
        $prefix = substr($key_value, 0, 8);
        $expires = date('Y-m-d H:i:s', strtotime('+1 year'));
        $pdo->prepare("INSERT INTO snap_ohsnap_keys
            (label, key_type, key_hash, key_prefix, expires_at, user_id)
            VALUES ('HUB shared key (suyb)', 'suyb', ?, ?, ?, NULL)")
            ->execute([hash('sha256', $key_value), $prefix, $expires]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'key_type' => 'suyb',
                          'key_prefix' => $prefix, 'expires_at' => $expires,
                          'shared' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Provisioning failed.']);
    }
    exit;
}

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
               COALESCE(api_key_backup, '') AS api_key_backup,
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
            'api_key_local'      => $n['api_key_local'],   // hub->spoke FULL key — legacy fallback for un-rebuilt SUYB
            'api_key_backup'     => $n['api_key_backup'],  // 0.7.261 least-privilege key — SUYB should PREFER this for backup pulls; empty = use api_key_local
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
