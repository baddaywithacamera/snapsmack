<?php
/**
 * SNAPSMACK - API / Session Dual Authentication
 *
 * Drop-in replacement for require_once 'core/auth-smack.php' on endpoints that
 * must serve both browser sessions (admin UI) and desktop-tool API access.
 *
 * Priority order:
 *   1. Typed scoped key (Authorization: Bearer <key>) — when the endpoint
 *      declares $GLOBALS['SNAP_API_KEY_TYPES']. Validated against
 *      snap_ohsnap_keys by key_type. Valid: define SNAP_API_AUTH and return.
 *      Bearer present but invalid: 401 JSON error, exit (no session fallthrough).
 *   2. No accepted key → fall through to normal session auth (core/auth-smack.php),
 *      which redirects browsers to the login page if not authenticated.
 *
 * Tools mint a scoped key in Admin → API Keys. The legacy shared tool_api_key
 * (X-Snap-Key header) was retired in 0.7.261 — there is no shared key any more.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once __DIR__ . '/db.php';

/**
 * Optional install-mode gate for tool/API access. An endpoint sets
 * $GLOBALS['SNAP_API_REQUIRE_MODE'] (e.g. 'photoblog') BEFORE including this
 * file; on a successful API auth (typed Bearer key) the
 * site's snap_settings.site_mode must equal it or the request is refused 409.
 * Browser sessions are NOT gated here — only tool access.
 */
if (!function_exists('snap_api_enforce_mode')) {
    function snap_api_enforce_mode(PDO $pdo): void {
        $need = (string)($GLOBALS['SNAP_API_REQUIRE_MODE'] ?? '');
        if ($need === '') return;
        try {
            $mode = (string)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_mode' LIMIT 1")->fetchColumn() ?: 'photoblog');
        } catch (PDOException $e) {
            $mode = 'photoblog';
        }
        if ($mode !== $need) {
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode(['error' => "This tool works only on '{$need}' sites; this site is in '{$mode}' mode."]);
            exit;
        }
    }
}

/**
 * Typed scoped key (Bearer) — the least-privilege model (snap_ohsnap_keys,
 * key_type) shared with the importers. An endpoint declares which key_type(s)
 * it accepts by setting $GLOBALS['SNAP_API_KEY_TYPES'] (array) before including
 * this file. A 'suyb' key therefore cannot act on a 'sybu' endpoint and vice
 * versa. Absent/empty = no Bearer auth offered (session only). The legacy
 * shared tool_api_key / X-Snap-Key path was retired in 0.7.261.
 */
$_allowed_types = $GLOBALS['SNAP_API_KEY_TYPES'] ?? [];
if (is_array($_allowed_types) && $_allowed_types) {
    $_auth_hdr = $_SERVER['HTTP_AUTHORIZATION']
              ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
              ?? '';
    if (!$_auth_hdr && function_exists('getallheaders')) {
        $_hh = getallheaders();
        $_auth_hdr = $_hh['Authorization'] ?? $_hh['authorization'] ?? '';
    }
    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $_auth_hdr, $_bm)) {
        $_bhash = hash('sha256', $_bm[1]);
        $_place = implode(',', array_fill(0, count($_allowed_types), '?'));
        $_krow  = false;
        try {
            // Expiry-aware: NULL expires_at = legacy key (no expiry); a set,
            // past expiry is rejected (mandatory ≤4-week keys, 0.7.263).
            $_kst = $pdo->prepare(
                "SELECT id FROM snap_ohsnap_keys
                 WHERE key_hash = ? AND is_active = 1 AND key_type IN ($_place)
                   AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1"
            );
            $_kst->execute(array_merge([$_bhash], array_values($_allowed_types)));
            $_krow = $_kst->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // expires_at column may not exist yet (pre schema-sync) — retry
            // without it so tools keep working until the column lands.
            try {
                $_kst = $pdo->prepare(
                    "SELECT id FROM snap_ohsnap_keys
                     WHERE key_hash = ? AND is_active = 1 AND key_type IN ($_place)
                     LIMIT 1"
                );
                $_kst->execute(array_merge([$_bhash], array_values($_allowed_types)));
                $_krow = $_kst->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e2) {
                $_krow = false; // fail closed
            }
        }
        if ($_krow) {
            $pdo->prepare("UPDATE snap_ohsnap_keys SET last_used_at = NOW() WHERE id = ?")
                ->execute([(int)$_krow['id']]);
            define('SNAP_API_AUTH', true);
            define('SNAP_API_KEY_ID', (int)$_krow['id']);
            snap_api_enforce_mode($pdo);
            unset($_allowed_types, $_auth_hdr, $_bm, $_bhash, $_place, $_kst, $_krow);
            return;
        }
        // Bearer header present but invalid → fail closed (no session fall-through).
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid or revoked API key.']);
        exit;
    }
}
unset($_allowed_types);

// No typed Bearer key accepted by this endpoint → fall through to standard
// session auth. The legacy shared tool_api_key (X-Snap-Key) path was retired
// in 0.7.261; desktop tools now present a scoped key_type Bearer instead.
require_once __DIR__ . '/auth-smack.php';
// ===== SNAPSMACK EOF =====
