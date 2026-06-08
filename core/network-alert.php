<?php
/**
 * SNAPSMACK — Network Alert (Layer 2)
 *
 * Opt-in participation in the Smack Central global network alert system.
 * Layer 2 only — entirely separate from Layer 1 (hub/spoke RED in smackback.php).
 *
 * Layer 1: local RED alerts between hub and spokes on your own servers.
 * Layer 2: global YELLOW alerts from Smack Central to all opted-in installs.
 *
 * These two layers NEVER interact. Red is always local. Yellow is always SC.
 */

// SNAPSMACK_EOF_HEADER
//     // ===== SNAPSMACK EOF =====
// Last non-empty line of this file MUST match the line above.
// Missing or different = truncated/corrupted. Restore before saving.


// Smack Central base URL — hardcoded, not user-configurable.
define('NALERT_SC_URL', 'https://snapsmack.ca');

// ─── LOCAL STATE ─────────────────────────────────────────────────────────────

/**
 * Return current network alert state from snap_settings cache.
 * Never makes an outbound call — reads local DB only.
 *
 * @return array{
 *   send:         bool,
 *   receive:      bool,
 *   status:       string,
 *   message:      string,
 *   since:        string,
 *   last_checked: string,
 *   sc_url:       string
 * }
 */
function nalert_get_local(): array {
    global $pdo;
    try {
        $rows = $pdo->query(
            "SELECT setting_key, setting_val FROM snap_settings
             WHERE setting_key IN (
                 'network_alert_send',
                 'network_alert_receive',
                 'network_alert_status',
                 'network_alert_message',
                 'network_alert_since',
                 'network_alert_last_checked'
             )"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return _nalert_defaults();
    }
    return [
        'send'         => ($rows['network_alert_send']         ?? '0') === '1',
        'receive'      => ($rows['network_alert_receive']       ?? '0') === '1',
        'status'       => $rows['network_alert_status']         ?? 'green',
        'message'      => $rows['network_alert_message']        ?? '',
        'since'        => $rows['network_alert_since']          ?? '',
        'last_checked' => $rows['network_alert_last_checked']   ?? '',
        'sc_url'       => NALERT_SC_URL,
    ];
}

/** @internal */
function _nalert_defaults(): array {
    return [
        'send'         => false,
        'receive'      => false,
        'status'       => 'green',
        'message'      => '',
        'since'        => '',
        'last_checked' => '',
        'sc_url'       => NALERT_SC_URL,
    ];
}


// ─── SC POLLING ───────────────────────────────────────────────────────────────

/**
 * Poll Smack Central for the current network alert level.
 * Writes result to snap_settings. Call only when a poll is actually due.
 *
 * @param  string $sc_url  Base URL of Smack Central (no trailing slash).
 * @return string|null  Level string on success, null on failure.
 */
function nalert_poll_sc(string $sc_url): ?string {
    global $pdo;

    if (!function_exists('curl_init')) {
        return null;
    }

    $url = rtrim($sc_url, '/') . '/sc-network-api.php?route=status';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'SnapSmack-NetworkAlert/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0'),
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$resp || $code !== 200) {
        return null;
    }

    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['level'])) {
        return null;
    }

    $valid   = ['green', 'yellow_slow', 'yellow_fast'];
    $level   = in_array($data['level'], $valid, true) ? $data['level'] : 'green';
    $message = substr((string)($data['message'] ?? ''), 0, 500);
    $since   = (string)($data['since'] ?? '');
    $now     = date('Y-m-d H:i:s');

    try {
        $upsert = $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
        );
        $upsert->execute(['network_alert_status',       $level]);
        $upsert->execute(['network_alert_message',      $message]);
        $upsert->execute(['network_alert_since',        $since]);
        $upsert->execute(['network_alert_last_checked', $now]);
    } catch (PDOException $e) {
        error_log('NETWORK ALERT: Failed to save polled status — ' . $e->getMessage());
    }

    return $level;
}

/**
 * Poll SC if enough time has elapsed since last check (30-minute throttle).
 * Called once per authenticated page load from auth-smack.php.
 * Silently no-ops on any failure.
 */
function nalert_maybe_poll(): void {
    global $pdo;

    try {
        $rows = $pdo->query(
            "SELECT setting_key, setting_val FROM snap_settings
             WHERE setting_key IN (
                 'network_alert_receive',
                 'network_alert_last_checked',
                 'network_alert_push_unregister_pending',
                 'network_alert_push_enabled',
                 'network_alert_push_registered'
             )"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return;
    }

    $sc_url = NALERT_SC_URL;

    // Always retry a pending unregister — independent of poll throttle
    nalert_maybe_retry_unregister($sc_url);

    // Auto-register push if enabled but not yet registered (covers stuck installs
    // that tried when the endpoint was broken, and hub-reset registrations).
    if (($rows['network_alert_push_enabled']    ?? '0') === '1'
     && ($rows['network_alert_push_registered'] ?? '0') !== '1') {
        nalert_register_push($sc_url);
    }

    // Only poll if receive is opted in
    if (($rows['network_alert_receive'] ?? '0') !== '1') {
        return;
    }

    $last = $rows['network_alert_last_checked'] ?? '';

    // 30-minute throttle
    if ($last && (time() - strtotime($last) < 1800)) {
        return;
    }

    nalert_poll_sc($sc_url);
}


// ─── BREACH REPORTING ─────────────────────────────────────────────────────────

/**
 * Send a breach report to Smack Central.
 * Called from smackback_handle_breach() when network_alert_send is opted in.
 * Fire-and-forget (short timeout). Failures are logged, never thrown.
 *
 * @param  string[] $tampered
 * @param  string[] $missing
 * @param  string[] $truncated
 * @param  string[] $corrupted
 */
function nalert_send_report(
    array $tampered,
    array $missing,
    array $truncated = [],
    array $corrupted = []
): void {
    global $pdo;

    if (!function_exists('curl_init')) {
        return;
    }

    try {
        $rows = $pdo->query(
            "SELECT setting_key, setting_val FROM snap_settings
             WHERE setting_key IN (
                 'network_alert_send',
                 'site_name',
                 'site_url'
             )"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return;
    }

    if (($rows['network_alert_send'] ?? '0') !== '1') {
        return;
    }

    $sc_url = NALERT_SC_URL;

    $affected = array_merge(
        array_map(fn($p) => ['path' => $p, 'status' => 'tampered'],  $tampered),
        array_map(fn($p) => ['path' => $p, 'status' => 'missing'],   $missing),
        array_map(fn($p) => ['path' => $p, 'status' => 'truncated'], $truncated),
        array_map(fn($p) => ['path' => $p, 'status' => 'corrupted'], $corrupted)
    );

    // Collect known hashes for affected files from the local manifest
    $file_hashes = [];
    if (!empty($affected)) {
        try {
            $paths = array_column($affected, 'path');
            $in    = implode(',', array_fill(0, count($paths), '?'));
            $stmt  = $pdo->prepare(
                "SELECT file_path, expected_hash FROM snap_file_manifest WHERE file_path IN ({$in})"
            );
            $stmt->execute($paths);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $file_hashes[$r['file_path']] = $r['expected_hash'];
            }
        } catch (PDOException $e) { /* non-fatal */ }
    }

    $payload = json_encode([
        'site_name'      => $rows['site_name'] ?? '',
        'site_url'       => $rows['site_url']  ?? '',
        'affected_files' => $affected,
        'file_hashes'    => $file_hashes,
        'detected_at'    => date('c'),
    ]);

    $ch = curl_init($sc_url . '/sc-network-api.php?route=report');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: SnapSmack-NetworkAlert/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0'),
        ],
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$resp || ($code !== 200 && $code !== 202)) {
        error_log("NETWORK ALERT: SC report failed — HTTP {$code}");
    }
}


// ─── PUSH SUBSCRIPTION ───────────────────────────────────────────────────────

/**
 * Generate a cryptographically random 64-char hex push token.
 * Stored locally; never changes after first generation.
 */
function nalert_generate_push_token(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Register this install for SC push notifications.
 * POSTs site_url, site_name, uid, and push_token to SC.
 * On success, marks network_alert_push_registered = 1.
 * On failure, silently no-ops — retry happens on next admin load.
 *
 * @param string $sc_url  Base SC URL (no trailing slash).
 */
function nalert_register_push(string $sc_url): void {
    global $pdo;

    if (!function_exists('curl_init')) return;

    try {
        $rows = $pdo->query(
            "SELECT setting_key, setting_val FROM snap_settings
             WHERE setting_key IN (
                 'network_alert_push_token',
                 'network_alert_push_registered',
                 'site_url', 'site_name', 'thomas_uid'
             )"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) { return; }

    // Generate token if missing
    $token = $rows['network_alert_push_token'] ?? '';
    if (!$token || strlen($token) !== 64) {
        $token = nalert_generate_push_token();
        try {
            $pdo->prepare(
                "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('network_alert_push_token', ?)
                 ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
            )->execute([$token]);
        } catch (PDOException $e) { return; }
    }

    $site_url  = rtrim($rows['site_url']  ?? '', '/');
    $site_name = $rows['site_name'] ?? '';
    $uid       = preg_replace('/[^a-f0-9]/', '', strtolower($rows['thomas_uid'] ?? ''));
    $push_url  = $site_url . '/network-alert-push.php';

    $payload = json_encode([
        'uid'        => $uid,
        'site_url'   => $site_url,
        'site_name'  => $site_name,
        'push_token' => $token,
        'push_url'   => $push_url,
    ]);

    $ch = curl_init(rtrim($sc_url, '/') . '/sc-network-api.php?route=register');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: SnapSmack-NetworkAlert/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0'),
        ],
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp && $code === 200) {
        $data = json_decode($resp, true);
        if (!empty($data['registered'])) {
            try {
                $pdo->prepare(
                    "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('network_alert_push_registered', '1')
                     ON DUPLICATE KEY UPDATE setting_val = '1'"
                )->execute();
            } catch (PDOException $e) { /* non-fatal */ }
        }
    }
}

/**
 * Unregister this install from SC push notifications.
 * On success, clears token and pending flag.
 * On failure, leaves network_alert_push_unregister_pending = 1 for retry.
 *
 * @param string $sc_url  Base SC URL (no trailing slash).
 */
function nalert_unregister_push(string $sc_url): void {
    global $pdo;

    if (!function_exists('curl_init')) return;

    try {
        $rows = $pdo->query(
            "SELECT setting_key, setting_val FROM snap_settings
             WHERE setting_key IN ('network_alert_push_token', 'site_url')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) { return; }

    $token    = $rows['network_alert_push_token'] ?? '';
    $site_url = rtrim($rows['site_url'] ?? '', '/');

    if (!$token || !$site_url) {
        // Nothing registered — clear pending flag and bail
        _nalert_clear_push_state($pdo);
        return;
    }

    $payload = json_encode(['site_url' => $site_url, 'push_token' => $token]);

    $ch = curl_init(rtrim($sc_url, '/') . '/sc-network-api.php?route=unregister');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: SnapSmack-NetworkAlert/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0'),
        ],
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp && $code === 200) {
        $data = json_decode($resp, true);
        if (!empty($data['unregistered'])) {
            _nalert_clear_push_state($pdo);
        }
    }
    // On failure: pending flag stays set; retry fires on next admin load
}

/** @internal Clear all push state after successful unregister. */
function _nalert_clear_push_state(PDO $pdo): void {
    try {
        $clear = $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
        );
        $clear->execute(['network_alert_push_enabled',            '0']);
        $clear->execute(['network_alert_push_token',              '']);
        $clear->execute(['network_alert_push_registered',         '0']);
        $clear->execute(['network_alert_push_unregister_pending', '0']);
    } catch (PDOException $e) { /* non-fatal */ }
}

/**
 * Check for a pending unregister request and retry it.
 * Called from nalert_maybe_poll() — runs on every admin page load,
 * independent of the 30-minute poll throttle.
 *
 * @param string $sc_url
 */
function nalert_maybe_retry_unregister(string $sc_url): void {
    global $pdo;
    try {
        $pending = $pdo->query(
            "SELECT setting_val FROM snap_settings WHERE setting_key = 'network_alert_push_unregister_pending'"
        )->fetchColumn();
    } catch (PDOException $e) { return; }

    if ($pending === '1') {
        nalert_unregister_push($sc_url);
    }
}


// ─── BANNER RENDER ────────────────────────────────────────────────────────────

/**
 * Render the pulsing yellow network alert banner.
 * Outputs nothing unless status is yellow_slow or yellow_fast.
 * Inline CSS only — no external file dependencies.
 *
 * @param  string $status   'yellow_slow' | 'yellow_fast'
 * @param  string $message  Optional message from SC.
 */
function nalert_render_banner(string $status, string $message = ''): void {
    if ($status !== 'yellow_slow' && $status !== 'yellow_fast') {
        return;
    }

    $duration = ($status === 'yellow_fast') ? '2s' : '4s';
    $raw_msg  = $message ?: 'SnapSmack network advisory active. Check your site\'s file integrity.';
    $safe_msg = htmlspecialchars($raw_msg, ENT_QUOTES);
    // Dismiss key is a hash of status+message so a new SC broadcast resets the dismissal.
    $dismiss_key = 'nalert_dismissed_' . substr(md5($status . $raw_msg), 0, 12);

    echo <<<HTML
<style>
@keyframes nalert-pulse{0%,100%{background:#3a2e00;border-bottom-color:#c8a800}50%{background:#4d3c00;border-bottom-color:#ffe040}}
#nalert-banner{margin-left:240px;}
@media(max-width:1024px){#nalert-banner{margin-left:0;}}
</style>
<div id="nalert-banner" style="background:#3a2e00;border-bottom:3px solid #c8a800;padding:10px 24px;font-size:0.88rem;color:#ffe680;display:flex;align-items:center;gap:16px;position:sticky;top:0;z-index:998;animation:nalert-pulse {$duration} ease-in-out infinite;">
    <strong style="color:#ffcc00;letter-spacing:1px;font-size:0.82rem;white-space:nowrap;">&#9888;&nbsp;NETWORK ALERT</strong>
    <span>{$safe_msg}</span>
    <a href="smack-smackback.php#network-alert" style="color:#ffcc00;text-decoration:none;border:1px solid #997700;padding:3px 10px;font-size:0.8rem;white-space:nowrap;margin-left:auto;">Details &rarr;</a>
    <button onclick="localStorage.setItem('{$dismiss_key}','1');document.getElementById('nalert-banner').style.display='none';"
            title="Dismiss alert"
            style="background:transparent;border:none;color:#997700;font-size:1.1rem;cursor:pointer;padding:0 0 0 8px;line-height:1;">&times;</button>
</div>
<script>if(localStorage.getItem('{$dismiss_key}')==='1'){var b=document.getElementById('nalert-banner');if(b)b.style.display='none';}</script>

HTML;
}
// ===== SNAPSMACK EOF =====
