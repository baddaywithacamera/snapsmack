<?php
/**
 * SNAPSMACK.CA — Network Alert Public API
 *
 * Public endpoint — no admin session required.
 * This file lives at the snapsmack.ca web root so spokes can reach it at
 * https://snapsmack.ca/sc-network-api.php (the default network_alert_sc_url).
 *
 * Routes:
 *   GET  ?route=status  — Returns current alert level for polling installs.
 *   POST ?route=report  — Receives breach report from a SnapSmack install.
 *
 * Rate limit on reports: max 3 POSTs per IP per hour.
 * Auto-escalation: 5+ distinct IPs within 2 hours → yellow_fast.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// NOTE: this file sits at the snapsmack.ca WEB ROOT, so smack-central/ is a direct
// subdirectory (__DIR__ . '/smack-central/'). Do NOT copy ping.php's '../smack-central'
// path — ping.php lives in releases/, one level deeper, where '../' is correct. Here it
// would resolve ABOVE the web root and fatal (500 → 0 subscribers, no yellow polling).
require_once __DIR__ . '/smack-central/sc-config.php';
require_once __DIR__ . '/smack-central/sc-db.php';
require_once __DIR__ . '/smack-central/sc-network-fanout.php';

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
        $db->exec("INSERT IGNORE INTO sc_network_alert_state (id,level,message,set_by) VALUES (1,'green','','init')");
        $row = ['id' => 1, 'level' => 'green', 'message' => '', 'set_at' => date('Y-m-d H:i:s'), 'set_by' => 'init'];
    }
    return $row;
}

$db = sc_db();

// ─── GET ?route=status ─────────────────────────────────────────────────────────

if ($route === 'status' && $method === 'GET') {
    $state = na_get_state($db);
    na_ok([
        'level'   => $state['level'],
        'message' => $state['message'],
        'since'   => $state['set_at'],
    ]);
}

// ─── POST ?route=report ────────────────────────────────────────────────────────

if ($route === 'report' && $method === 'POST') {

    $request_ip = substr(($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);

    // Rate limit: max 3 reports per IP per hour
    $rate_stmt = $db->prepare(
        "SELECT COUNT(*) FROM sc_network_alert_reports
         WHERE request_ip = ? AND received_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $rate_stmt->execute([$request_ip]);
    if ((int)$rate_stmt->fetchColumn() >= 3) {
        na_err('Rate limit exceeded.', 429);
    }

    // Parse body
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        na_err('Invalid JSON body.', 400);
    }

    $site_name = substr(trim((string)($body['site_name'] ?? '')), 0, 255);
    $site_url  = substr(trim((string)($body['site_url']  ?? '')), 0, 500);

    $affected_files_raw = $body['affected_files'] ?? null;
    $file_hashes_raw    = $body['file_hashes']    ?? null;

    $affected_files = null;
    if (is_array($affected_files_raw)) {
        $affected_files = array_slice($affected_files_raw, 0, 200);
        $affected_files = array_map(fn($v) => substr((string)$v, 0, 500), $affected_files);
    }

    $file_hashes = null;
    if (is_array($file_hashes_raw)) {
        $capped = [];
        foreach (array_slice($file_hashes_raw, 0, 200, true) as $k => $v) {
            $capped[substr((string)$k, 0, 500)] = substr((string)$v, 0, 128);
        }
        $file_hashes = $capped;
    }

    $file_count = is_array($affected_files) ? count($affected_files) : 0;

    if (empty($site_name) && empty($site_url) && $file_count === 0) {
        na_err('Report contains no useful data.', 422);
    }

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

    // ── Auto-escalation ───────────────────────────────────────────────────────
    $state   = na_get_state($db);
    $current = $state['level'];

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

            ob_start();
            na_ok(['received' => true]);
            $out = ob_get_clean();
            echo $out;
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            else { header('Content-Length: ' . strlen($out)); flush(); }

            $new_state = na_get_state($db);
            na_fanout($db, $new_state['level'], $new_state['message'], $new_state['set_at']);
            exit;

        } elseif ($distinct_ips >= 2 && $current === 'green') {
            $db->prepare(
                "UPDATE sc_network_alert_state
                 SET level = 'yellow_slow',
                     message = CASE WHEN message = '' THEN ? ELSE message END,
                     set_by = 'auto'
                 WHERE id = 1"
            )->execute(['Elevated breach activity reported on the SnapSmack network. Monitor your site.']);

            ob_start();
            na_ok(['received' => true]);
            $out = ob_get_clean();
            echo $out;
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            else { header('Content-Length: ' . strlen($out)); flush(); }

            $new_state = na_get_state($db);
            na_fanout($db, $new_state['level'], $new_state['message'], $new_state['set_at']);
            exit;
        }
    }

    na_ok(['received' => true]);
}

// ─── POST ?route=register ──────────────────────────────────────────────────────

if ($route === 'register' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) na_err('Invalid JSON.', 400);

    $uid        = preg_replace('/[^a-f0-9]/', '', strtolower((string)($body['uid']        ?? '')));
    $site_url   = substr(trim((string)($body['site_url']   ?? '')), 0, 500);
    $site_name  = substr(trim((string)($body['site_name']  ?? '')), 0, 255);
    $push_token = preg_replace('/[^a-f0-9]/', '', strtolower((string)($body['push_token'] ?? '')));
    $push_url   = substr(trim((string)($body['push_url']   ?? '')), 0, 600);

    if (!filter_var($site_url, FILTER_VALIDATE_URL))  na_err('Invalid site_url.', 422);
    if (strlen($push_token) !== 64)                   na_err('Invalid push_token.', 422);
    if (!filter_var($push_url, FILTER_VALIDATE_URL))  na_err('Invalid push_url.', 422);
    if (parse_url($push_url, PHP_URL_HOST) !== parse_url($site_url, PHP_URL_HOST)) {
        na_err('push_url host must match site_url host.', 422);
    }

    try {
        $db->prepare(
            "INSERT INTO sc_push_subscribers (uid, site_url, site_name, push_token, push_url)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 uid        = VALUES(uid),
                 site_name  = VALUES(site_name),
                 push_token = VALUES(push_token),
                 push_url   = VALUES(push_url),
                 push_failures = 0"
        )->execute([substr($uid, 0, 32), $site_url, $site_name, $push_token, $push_url]);
    } catch (PDOException $e) {
        na_err('Registration failed.', 500);
    }

    na_ok(['registered' => true]);
}

// ─── POST ?route=unregister ────────────────────────────────────────────────────

if ($route === 'unregister' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) na_err('Invalid JSON.', 400);

    $site_url   = substr(trim((string)($body['site_url']   ?? '')), 0, 500);
    $push_token = preg_replace('/[^a-f0-9]/', '', strtolower((string)($body['push_token'] ?? '')));

    if (!$site_url || strlen($push_token) !== 64) na_err('Missing site_url or push_token.', 422);

    try {
        $stmt = $db->prepare("SELECT push_token FROM sc_push_subscribers WHERE site_url = ?");
        $stmt->execute([$site_url]);
        $row = $stmt->fetch();

        if (!$row || !hash_equals($row['push_token'], $push_token)) {
            na_ok(['unregistered' => true]); // Don't reveal whether record exists
        }

        $db->prepare("DELETE FROM sc_push_subscribers WHERE site_url = ? AND push_token = ?")
           ->execute([$site_url, $push_token]);

    } catch (PDOException $e) {
        na_err('Unregister failed.', 500);
    }

    na_ok(['unregistered' => true]);
}

// Unknown route
na_err('Unknown route.', 404);
// ===== SNAPSMACK EOF =====
