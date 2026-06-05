<?php
/**
 * SMACK CENTRAL — Network Alert Fan-out Helper
 *
 * Shared include for pushing alert level changes to all registered
 * sc_push_subscribers. Used by both sc-network-api.php (auto-escalation)
 * and sc-network-alert.php (manual level change).
 *
 * Not a routable page — include only.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!defined('SC_DB_HOST')) {
    // Guard: must not be accessed directly
    http_response_code(403);
    exit;
}

if (function_exists('na_fanout')) return; // already loaded

/**
 * Push current alert level to all registered subscribers in parallel.
 * curl_multi — all requests fire concurrently. Short timeouts, fire-and-forget.
 * Auto-prunes subscribers with 5+ consecutive delivery failures.
 */
function na_fanout(PDO $db, string $level, string $message, string $since): void {
    if (!function_exists('curl_multi_init')) return;

    try {
        $subs = $db->query(
            "SELECT id, push_url, push_token FROM sc_push_subscribers WHERE push_failures < 5"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return; }

    if (empty($subs)) return;

    $payload = json_encode(['level' => $level, 'message' => $message, 'since' => $since]);
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($subs as $sub) {
        $ch = curl_init($sub['push_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $sub['push_token'],
                'User-Agent: SnapSmack-SC-Push/1.0',
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$sub['id']] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.5);
    } while ($running > 0);

    $stamp_ok  = $db->prepare("UPDATE sc_push_subscribers SET push_failures = 0, last_push_at = NOW() WHERE id = ?");
    $stamp_err = $db->prepare("UPDATE sc_push_subscribers SET push_failures = push_failures + 1 WHERE id = ?");
    $prune     = $db->prepare("DELETE FROM sc_push_subscribers WHERE id = ? AND push_failures >= 5");

    foreach ($handles as $id => $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($code === 200) {
            $stamp_ok->execute([$id]);
        } else {
            $stamp_err->execute([$id]);
            $prune->execute([$id]);
        }
    }

    curl_multi_close($mh);
}
// ===== SNAPSMACK EOF =====
