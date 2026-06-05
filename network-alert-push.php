<?php
/**
 * SNAPSMACK — Network Alert Push Receiver
 *
 * Smack Central calls this endpoint to deliver an immediate alert push when
 * a network-wide breach is detected. This is only reached if the operator
 * opted in to push notifications in Admin → SMACKBACK → Network Alert.
 *
 * SC authenticates every push with the per-install push_token (64-char hex
 * generated locally at registration time and never transmitted to SC except
 * during the initial registration handshake). Constant-time comparison —
 * timing-safe against brute force.
 *
 * This file lives at the web root. It accepts POST only, no auth session
 * required (it IS the inbound channel from SC). Rate-limited by IP.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// No session, no admin include — must be lean and standalone.
// Only load what we need: DB access.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ─── Bootstrap minimal DB access ─────────────────────────────────────────────

define('SNAPSMACK_PUSH_RECEIVER', true);

$cfg_path = __DIR__ . '/core/db.php';
if (!file_exists($cfg_path)) {
    http_response_code(503);
    echo json_encode(['ok' => false]);
    exit;
}
require_once $cfg_path;

// ─── Rate limit by IP (10 pushes/min — SC should push at most once per event) ─

try {
    $ip = substr(($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);

    // We use snap_smackback_log as a lightweight rate-limit store if it exists,
    // otherwise skip rate limiting (table created on SMACKBACK init).
    $rl_ok = true;
    try {
        $rl = $pdo->prepare(
            "SELECT COUNT(*) FROM snap_smackback_log
             WHERE event_type = 'nalert_push' AND detail = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        );
        $rl->execute([$ip]);
        if ((int)$rl->fetchColumn() >= 10) {
            $rl_ok = false;
        }
    } catch (PDOException $e) {
        // Table may not exist on installs that haven't enabled SMACKBACK yet — skip.
    }

    if (!$rl_ok) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded.']);
        exit;
    }
} catch (Throwable $e) {
    // DB not available — fail closed.
    http_response_code(503);
    echo json_encode(['ok' => false]);
    exit;
}

// ─── Validate push token ──────────────────────────────────────────────────────

try {
    $rows = $pdo->query(
        "SELECT setting_key, setting_val FROM snap_settings
         WHERE setting_key IN (
             'network_alert_push_enabled',
             'network_alert_push_token',
             'network_alert_push_registered'
         )"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    http_response_code(503);
    echo json_encode(['ok' => false]);
    exit;
}

// Must be opted in and registered
if (($rows['network_alert_push_enabled'] ?? '0') !== '1'
    || ($rows['network_alert_push_registered'] ?? '0') !== '1') {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$stored_token = $rows['network_alert_push_token'] ?? '';

// Token comes in the Authorization header: "Bearer {token}"
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$sent_token  = '';
if (str_starts_with($auth_header, 'Bearer ')) {
    $sent_token = trim(substr($auth_header, 7));
}

// Constant-time comparison — must not short-circuit
if (!$stored_token
    || strlen($sent_token) !== 64
    || strlen($stored_token) !== 64
    || !hash_equals($stored_token, $sent_token)) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

// ─── Parse push payload ───────────────────────────────────────────────────────

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON.']);
    exit;
}

$valid_levels = ['green', 'yellow_slow', 'yellow_fast'];
$level        = in_array($body['level'] ?? '', $valid_levels, true) ? $body['level'] : null;

if ($level === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Missing or invalid level.']);
    exit;
}

$message = substr(trim((string)($body['message'] ?? '')), 0, 500);
$since   = substr(trim((string)($body['since']   ?? '')), 0, 50);
$now     = date('Y-m-d H:i:s');

// ─── Apply alert level immediately ───────────────────────────────────────────

try {
    $upsert = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    );
    $upsert->execute(['network_alert_status',       $level]);
    $upsert->execute(['network_alert_message',      $message]);
    $upsert->execute(['network_alert_since',        $since]);
    $upsert->execute(['network_alert_last_checked', $now]);

    // Log the push event for rate limiting and audit
    try {
        $pdo->prepare(
            "INSERT INTO snap_smackback_log (event_type, detail, created_at)
             VALUES ('nalert_push', ?, NOW())"
        )->execute([$ip]);
    } catch (PDOException $e) { /* non-fatal */ }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

http_response_code(200);
echo json_encode(['ok' => true, 'level' => $level]);
// ===== SNAPSMACK EOF =====
