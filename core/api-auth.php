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
        // Accept a single mode (string) OR a list of allowed modes (array).
        $allowed = array_values(array_filter((array)($GLOBALS['SNAP_API_REQUIRE_MODE'] ?? []), 'strlen'));
        if (empty($allowed)) return;
        try {
            $mode = (string)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_mode' LIMIT 1")->fetchColumn() ?: 'photoblog');
        } catch (PDOException $e) {
            $mode = 'photoblog';
        }
        if (in_array($mode, $allowed, true)) return;   // already the right mode

        // WRONG MODE. This used to auto-flip site_mode to whatever the calling
        // tool wanted, on the reasoning that "the KEY decides the MODE" — it saved
        // the owner hunting for a setting on a fresh install.
        //
        // It also converted established sites. SYBU's _on_connect() fetches
        // categories and albums through smack-post-solo.php, which declares
        // photoblog-only, so merely pressing Connect turned a 1,076-post GramOfSmack
        // blog into a photo blog. Silently. And the conversion was one-way in
        // practice: with the mode wrong, the skin gallery hides every gram skin, and
        // activating a gram skin is the only supported way to set the mode back — so
        // the site locked itself out of its own repair path and needed a signed VAX
        // package to recover. (2026-08-06, fauxlaroid.fyi.)
        //
        // A posting tool must not be able to change what KIND of site this is. The
        // convenience is kept only where it cannot destroy anything: a site with no
        // content yet, where "flip to match the tool" is a reasonable reading of an
        // otherwise unconfigured install. Anything with content is refused, loudly.
        $has_content = false;
        try {
            $has_content = ((int)$pdo->query("SELECT COUNT(*) FROM snap_posts")->fetchColumn() > 0)
                        || ((int)$pdo->query("SELECT COUNT(*) FROM snap_images")->fetchColumn() > 0);
        } catch (PDOException $e) {
            $has_content = true;   // cannot prove it is empty — treat it as established
        }

        if (count($allowed) === 1 && !$has_content) {
            try {
                $pdo->prepare(
                    "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('site_mode', ?)
                     ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
                )->execute([$allowed[0]]);
                error_log("SNAP_API: site_mode set '{$mode}' -> '{$allowed[0]}' on an EMPTY site to match the authenticated tool key.");
                $GLOBALS['SNAP_API_MODE_FLIPPED'] = ['from' => $mode, 'to' => $allowed[0]];
                return;
            } catch (PDOException $e) {
                // fall through to the refusal below
            }
        }

        // Refuse, with a plain-language, actionable message rather than a bare code.
        http_response_code(409);
        header('Content-Type: application/json');
        // Say how to actually fix it. There is NO site-mode control in Settings —
        // the mode follows the active skin (smack-skin.php) — and telling the owner
        // to go find a setting that does not exist is how a five-minute problem
        // becomes an hour.
        $how = [
            'photoblog' => 'activate a photoblog skin (50 Shades of Noah Grey, New Horizon)',
            'carousel'  => 'activate a gram skin (The Grid, Instant Camera)',
            'smacktalk' => 'activate a SmackTalk skin (Alfred)',
        ];
        $fix = [];
        foreach ($allowed as $a) { if (isset($how[$a])) $fix[] = $how[$a]; }

        echo json_encode([
            'error' => "WRONG MODE. This tool posts to " . strtoupper(implode(' / ', $allowed))
                     . " sites, but this site is in '" . $mode . "' mode, and it has content — so the mode "
                     . "was NOT changed automatically. To change it, " . implode(' or ', $fix)
                     . " in Pimp Your Ride -> Smooth Your Skin. The site mode follows the active skin; "
                     . "there is no separate mode setting.",
            'site_mode'     => $mode,
            'required_mode' => $allowed,
            'how_to_change' => $fix,
        ]);
        exit;
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
            // Fetch the key WITHOUT filtering on expiry so we can tell an
            // EXPIRED key apart from an invalid/revoked one and return a useful,
            // actionable error. Security is unchanged: an expired key is still
            // rejected below, never authenticated. (Legacy NULL expires_at = no
            // expiry; mandatory ≤4-week keys since 0.7.263.)
            $_kst = $pdo->prepare(
                "SELECT id, key_type, expires_at FROM snap_ohsnap_keys
                 WHERE key_hash = ? AND is_active = 1 AND key_type IN ($_place)
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
            // Key exists, is active, and is the right type. If it carries a set,
            // past expiry, reject it with a distinct, actionable message (the
            // common, fixable case) instead of the generic "invalid" one. Absent
            // expires_at (a legacy key, or the pre-schema fallback query above)
            // means no expiry — treat as valid.
            $_exp = $_krow['expires_at'] ?? null;
            if ($_exp !== null && $_exp !== '' && ($_ets = strtotime((string)$_exp)) !== false && $_ets <= time()) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => 'API key expired — generate a new key in Admin → API Keys.',
                    'code'  => 'key_expired',
                ]);
                exit;
            }
            $pdo->prepare("UPDATE snap_ohsnap_keys SET last_used_at = NOW() WHERE id = ?")
                ->execute([(int)$_krow['id']]);
            define('SNAP_API_AUTH', true);
            define('SNAP_API_KEY_ID', (int)$_krow['id']);
            define('SNAP_API_KEY_TYPE', (string)($_krow['key_type'] ?? ''));
            snap_api_enforce_mode($pdo);
            unset($_allowed_types, $_auth_hdr, $_bm, $_bhash, $_place, $_kst, $_krow);
            return;
        }
        // Bearer header present but no active key matched → invalid or revoked.
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid or revoked API key.', 'code' => 'key_invalid']);
        exit;
    }
}
unset($_allowed_types);

// No typed Bearer key accepted by this endpoint → fall through to standard
// session auth. The legacy shared tool_api_key (X-Snap-Key) path was retired
// in 0.7.261; desktop tools now present a scoped key_type Bearer instead.
require_once __DIR__ . '/auth-smack.php';
// ===== SNAPSMACK EOF =====
