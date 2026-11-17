<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
declare(strict_types=1);

/**
 * THOMAS DISCOVER-PING UID — the anonymous install id behind the Easter-egg
 * counter ping. Sean chose to restore the ping (08:16, 2026-11-08), superseding
 * the "historical totals" caption.
 *
 * A random 32-char hex generated ONCE per install and stored in snap_settings
 * (thomas_uid). It is NOT derived from the site URL or name and cannot be
 * reverse-engineered to identify the install -- it only lets the receiver count
 * unique installs. The documented payload lives in projects/snapsmack-ca/tnb.php
 * ("a counter ping when someone discovers the Thomas the Bear Easter egg ...
 * pseudonymous hashed IDs ... a count -- not a name, not a URL").
 *
 * WHY A DATA ATTRIBUTE, NOT window.ssUid.
 * The id was once emitted as an inline <script>window.ssUid=...</script> from
 * core/footer-scripts.php. That is wrong twice over now: (1) the public CSP is
 * script-src 'self' with no nonce (core/custom-code-policy.php), so an inline
 * script is blocked on every site without owner custom code; and (2)
 * footer-scripts.php is the LEGACY render path that strict skins never reach --
 * the same fleet-wide trap documented for Thomas himself in core/public-runtime.php.
 * So the id rides a hidden data attribute emitted from the registered-assets
 * component (the one point all 30 renderable skins reach, like the consent
 * banner), and ss-engine-thomas.js reads it from the DOM. The ping itself is an
 * <img> GET, which img-src 'self' data: https: already allows.
 */

if (!function_exists('snapsmack_thomas_uid')) {
    function snapsmack_thomas_uid(): string
    {
        static $uid = null;
        if ($uid !== null) return $uid;
        $uid = '';
        $pdo = $GLOBALS['pdo'] ?? null;
        try {
            if ($pdo instanceof PDO) {
                $uid = (string)($pdo->query(
                    "SELECT setting_val FROM snap_settings WHERE setting_key = 'thomas_uid' LIMIT 1"
                )->fetchColumn() ?: '');
                if (strlen($uid) !== 32 || !ctype_xdigit($uid)) {
                    $uid = bin2hex(random_bytes(16));
                    $pdo->prepare(
                        "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('thomas_uid', ?)
                         ON DUPLICATE KEY UPDATE setting_val = setting_val"
                    )->execute([$uid]);
                }
            }
        } catch (Throwable $e) { $uid = ''; }
        return $uid;
    }
}

if (!function_exists('snapsmack_thomas_uid_tag')) {
    /**
     * Emit the anonymous install id as a hidden data attribute for the Easter-egg
     * engine to read. Prints nothing when there is no database (admin preview, a
     * test rendering a component on its own) or when the id could not be made.
     * One-shot so two calls in a request cannot emit it twice.
     */
    function snapsmack_thomas_uid_tag(): void
    {
        static $emitted = false;
        if ($emitted) return;
        $uid = snapsmack_thomas_uid();
        if ($uid === '') return;
        $emitted = true;
        echo '<span id="ss-thomas-uid" data-uid="'
            . htmlspecialchars($uid, ENT_QUOTES) . '" hidden></span>' . "\n";
    }
}

// ===== SNAPSMACK EOF =====
