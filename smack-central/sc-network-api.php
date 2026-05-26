<?php
/**
 * SMACK CENTRAL — Network Alert Public API
 *
 * Public endpoint — no admin session required.
 *
 * Routes:
 *   GET  ?route=status  — Returns current alert level for polling installs.
 *   POST ?route=report  — Receives breach report from a SnapSmack install.
 *
 * Rate limit on reports: max 3 POSTs per IP per hour (stored in sc_network_alert_reports).
 * Caller does not need to be registered — any SnapSmack install can report.
 * Data is non-sensitive (site name, IP, file paths, timestamps, hashes).
 *
 * Auto-escalation: if 5+ distinct IPs report within 2 hours and level is green/yellow_slow,
 * automatically escalates to yellow_fast. Sean can override at any time via sc-network-alert.php.
 */

// SNAPSMACK_EOF_HEADER
//     // ===== SNAPSMACK EOF =====
// Last non-empty line of this file MUST match the line above.
// Missing or different = truncated/corrupted. Restore before saving.

require_once __DIR__ . '/sc-config.php';
require_once __DIR__ . '/sc-db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');

$route  = trim($_GET['route'] ?? '', '/');
$method = $_SERVER['REQUEST_METHOD'];

// ─── Helpers ──────────────────────────────────────────────────────────────────

function na_ok(array $data = []): never {
    echo json_encode(array_merge(['status' => 'ok'], $data));
    exit;
}

function na_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

/** Get current alert state row (always returns a row; seeds if missing). */
function na_get_state(PDO $db): array {
    $row = $db->query("SELECT * FROM sc_network_alert_state WHERE id = 1")->fetch();
    if (!$row) {
        // Seed — sc-schema.php should have done this but be defensive
        $db->exec("INSERT IGNORE INTO sc_network_alert_state (id,level,message,set_by) VALUES (1,'green','','init')");
        $row = ['id' => 1, 'level' => 'green', 'message' => '', 'set_at' => date('Y-m-d H:i:s'), 'set_by' => 'init'];
    }
    return $row;
}

$db = sc_db();

// ─── GET ?route=status ─────────────────────────────────────────────────────────
// Returns current alert level. Polled by opted-in installs every 30 min.

if ($route === 'status' && $method === 'GET') {
    $state = na_get_state($db);
    na_ok([
        'level'   => $state['level'],
        'message' => $state['message'],
        'since'   => $state['set_at'],
    ]);
}

// ─── POST ?route=report ────────────────────────────────────────────────────────
// Receives breach report from a SnapSmack install.

if ($route === 'report' && $method === 'POST') {

    // Use REMOTE_ADDR only — X-Forwarded-For is client-controlled and spoofable.
    // Using it for rate limiting would let an attacker cycle fake IPs to bypass
    // the limit and fabricate distinct-IP counts for false auto-escalation.
    $request_ip = substr(($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);

    // Rate limit: max 3 reports per IP per hour
    $rate_stmt = $db->prepare(
        "SELECT COUNT(*) FROM sc_network_alert_reports
         WHERE request_ip = ? AND received_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $rate_stmt->execute([$request_ip]);
    $rate_count = (int)$rate_stmt->fetchColumn();

    if ($rate_count >= 3) {
        na_err('Rate limit exceeded. Maximum 3 reports per hour per IP.', 429);
    }

    // Parse body
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        na_err('Invalid JSON body.', 400);
    }

    $site_name = substr(trim((string)($body['site_name'] ?? '')), 0, 255);
    $site_url  = substr(trim((string)($body['site_url']  ?? '')), 0, 500);

    // Cap arrays and their string entries to bound storage size.
    // 200 files per report is well above any realistic breach payload.
    $affected_files_raw = $body['affected_files'] ?? null;
    $file_hashes_raw    = $body['file_hashes']    ?? null;

    $affected_files = null;
    if (is_array($affected_files_raw)) {
        $affected_files = array_slice($affected_files_raw, 0, 200);
        $affected_files = array_map(fn($v) => substr((string)$v, 0, 500), $affected_files);
    }

    $file_hashes = null;
    if (is_array($file_hashes_raw)) {
        $file_hashes = array_slice($file_hashes_raw, 0, 200);
        // Hashes are key→value pairs; preserve structure but cap values
        $capped = [];
        foreach ($file_hashes as $k => $v) {
            $capped[substr((string)$k, 0, 500)] = substr((string)$v, 0, 128);
        }
        $file_hashes = $capped;
    }

    $file_count = is_array($affected_files) ? count($affected_files) : 0;

    // Validate — at minimum we need something useful
    if (empty($site_name) && empty($site_url) && $file_count === 0) {
        na_err('Report contains no useful data.', 422);
    }

    // Store
    $db->prepare(
        "INSERT INTO sc_network_alert_reports
             (site_name, site_url, request_ip, affected_files, file_hashes, file_count)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $site_name,
        $site_url,
        $request_ip,
        $affected_files !== null ? json_encode($affected_files) : null,
        $file_hashes    !== null ? json_encode($file_hashes)    : null,
        $file_count,
    ]);

    // ── Auto-escalation check ─────────────────────────────────────────────
    // 5+ distinct IPs reporting within 2 hours → escalate to yellow_fast
    $state     = na_get_state($db);
    $current   = $state['level'];

    if ($current !== 'yellow_fast') {
        $distinct_stmt = $db->prepare(
            "SELECT COUNT(DISTINCT request_ip) FROM sc_network_alert_reports
             WHERE received_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)"
        );
        $distinct_stmt->execute();
        $distinct_ips = (int)$distinct_stmt->fetchColumn();

        if ($distinct_ips >= 5) {
            $db->prepare(
                "UPDATE sc_network_alert_state
                 SET level = 'yellow_fast',
                     message = CASE WHEN message = '' THEN ? ELSE message END,
                     set_by = 'auto'
                 WHERE id = 1"
            )->execute(['Coordinated breach activity detected across the SnapSmack network.']);
        } elseif ($distinct_ips >= 2 && $current === 'green') {
            // 2–4 distinct IPs: escalate to yellow_slow automatically
            $db->prepare(
                "UPDATE sc_network_alert_state
                 SET level = 'yellow_slow',
                     message = CASE WHEN message = '' THEN ? ELSE message END,
                     set_by = 'auto'
                 WHERE id = 1"
            )->execute(['Elevated breach activity reported on the SnapSmack network. Monitor your site.']);
        }
    }

    na_ok(['received' => true]);
}

// Unknown route
na_err('Unknown route.', 404);
// ===== SNAPSMACK EOF =====
