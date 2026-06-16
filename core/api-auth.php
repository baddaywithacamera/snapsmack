<?php
/**
 * SNAPSMACK - API / Session Dual Authentication
 *
 * Drop-in replacement for require_once 'core/auth-smack.php' on endpoints that
 * must serve both browser sessions (admin UI) and tool API key access (SYBU).
 *
 * Priority order:
 *   1. X-Snap-Key header present → validate against tool_api_key setting.
 *      Valid: define SNAP_API_AUTH and return (caller proceeds immediately).
 *      Invalid: 401 JSON error, exit.
 *   2. No key header → fall through to normal session auth (core/auth-smack.php),
 *      which redirects browsers to the login page if not authenticated.
 *
 * The tool_api_key setting is managed in Admin → Settings → API Access.
 * An empty stored key means API key auth is disabled — key header is ignored
 * and falls through to session auth.
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
 * file; on a successful API auth (typed Bearer or legacy X-Snap-Key) the
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
 * versa. Absent/empty = no Bearer auth offered (legacy X-Snap-Key + session
 * only). This branch is ADDITIVE: it runs before the legacy shared-key check,
 * so existing tools keep working until they migrate to a typed key.
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
            $_kst = $pdo->prepare(
                "SELECT id FROM snap_ohsnap_keys
                 WHERE key_hash = ? AND is_active = 1 AND key_type IN ($_place)
                 LIMIT 1"
            );
            $_kst->execute(array_merge([$_bhash], array_values($_allowed_types)));
            $_krow = $_kst->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $_krow = false; // fail closed
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

$_x_snap_key = trim($_SERVER['HTTP_X_SNAP_KEY'] ?? '');

if ($_x_snap_key !== '') {
    // Key was provided — look up the stored key
    $_stored_key = '';
    try {
        $_stmt = $pdo->prepare(
            "SELECT setting_val FROM snap_settings WHERE setting_key = 'tool_api_key' LIMIT 1"
        );
        $_stmt->execute();
        $_stored_key = (string)($_stmt->fetchColumn() ?: '');
        unset($_stmt);
    } catch (PDOException $e) {
        // DB error — fail closed
    }

    if ($_stored_key === '' || !hash_equals($_stored_key, $_x_snap_key)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid or missing API key.']);
        exit;
    }

    // Valid key — mark as API-authenticated and return immediately.
    // The caller proceeds without a session.
    define('SNAP_API_AUTH', true);
    snap_api_enforce_mode($pdo);
    unset($_x_snap_key, $_stored_key);
    return;
}

unset($_x_snap_key);

// No key header — fall through to standard session auth.
require_once __DIR__ . '/auth-smack.php';
// ===== SNAPSMACK EOF =====
