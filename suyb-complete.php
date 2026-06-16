<?php
/**
 * SNAPSMACK - SUYB Backup-Complete Endpoint
 *
 * Called by Smack Up Your Backup (SUYB) immediately after it finishes backing
 * up THIS site. Records the backup timestamp + status into snap_settings so the
 * Multisite dashboard can show backup freshness. Writes this site's OWN values
 * only — it never touches other nodes. Spoke values reach the hub via the
 * normal heartbeat the dashboard already performs on load.
 *
 * Authentication: X-Snap-Key header (tool API key) or admin session cookie.
 * Method: POST
 * Params:
 *   status        clean|partial|failed   (default: clean)
 *   size_bytes    integer                (optional)
 *   destination   string                 (optional, e.g. "google_drive", "local")
 *   backed_up_at  Y-m-d H:i:s            (optional; default = server now)
 * Response: application/json
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// CSRF: tool API authentication flow — legitimately accepts POST without a
// session-tied CSRF token. Mark exempt before auth.php's auto-validator fires.
require_once __DIR__ . '/core/csrf.php';
csrf_exempt();

require_once 'core/api-auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// ── Inputs ───────────────────────────────────────────────────────────────────
$allowed_status = ['clean', 'partial', 'failed'];
$status = in_array($_POST['status'] ?? '', $allowed_status, true)
    ? $_POST['status']
    : 'clean';

$size = isset($_POST['size_bytes']) && $_POST['size_bytes'] !== ''
    ? (string)(int)$_POST['size_bytes']
    : '';

$dest = trim((string)($_POST['destination'] ?? ''));
if (strlen($dest) > 255) {
    $dest = substr($dest, 0, 255);
}

// Prefer a server-computed timestamp to avoid client clock skew; accept a
// supplied value only if it parses exactly as Y-m-d H:i:s.
$backed_up_at = date('Y-m-d H:i:s');
$supplied = trim((string)($_POST['backed_up_at'] ?? ''));
if ($supplied !== '') {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $supplied);
    if ($dt && $dt->format('Y-m-d H:i:s') === $supplied) {
        $backed_up_at = $supplied;
    }
}

// ── Persist to snap_settings (this site's own values) ────────────────────────
$upsert = $pdo->prepare(
    "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
);
$upsert->execute(['last_backup_at',     $backed_up_at]);
$upsert->execute(['last_backup_status', $status]);
if ($size !== '') {
    $upsert->execute(['last_backup_size', $size]);
}
if ($dest !== '') {
    $upsert->execute(['last_backup_dest', $dest]);
}

// ── Optional history row (table is optional — fail soft if absent) ───────────
try {
    $log = $pdo->prepare(
        "INSERT INTO snap_backup_log (created_at, status, size_bytes, destination, notes)
         VALUES (?, ?, ?, ?, ?)"
    );
    $log->execute([
        $backed_up_at,
        $status,
        $size !== '' ? (int)$size : null,
        $dest !== '' ? $dest : null,
        'SUYB',
    ]);
} catch (\Exception $e) {
    // snap_backup_log may not exist on this install — non-fatal.
}

echo json_encode([
    'ok'                 => true,
    'last_backup_at'     => $backed_up_at,
    'last_backup_status' => $status,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
// ===== SNAPSMACK EOF =====
