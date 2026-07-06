<?php
/**
 * SNAPSMACK - System Constants
 *
 * Defines version strings and system-wide constants. Include this early in
 * the bootstrap chain (e.g., from db.php) to ensure availability throughout
 * the application.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// --- MAINTENANCE LOCK ---
// Checked before security headers and before any DB connection. Must remain
// dependency-free — this runs when files may be mid-extraction.
// SNAPSMACK_IS_UPDATER bypass: smack-update.php defines this constant before
// its first require_once so the updater's own chunk requests are never blocked.
if (PHP_SAPI !== 'cli' && !defined('SNAPSMACK_IS_UPDATER')) {
    $_snap_lock = __DIR__ . '/../data/maintenance.lock';
    if (file_exists($_snap_lock)) {
        $_snap_maint = @json_decode(@file_get_contents($_snap_lock), true) ?? [];
        $_snap_since = (int)($_snap_maint['since'] ?? 0);
        if ($_snap_since > 0 && (time() - $_snap_since) > 300) {
            @unlink($_snap_lock); // safety valve: clear locks stuck > 5 minutes
        } else {
            $_snap_site = htmlspecialchars($_snap_maint['site_name'] ?? 'This site');
            http_response_code(503);
            header('Retry-After: 30');
            header('Content-Type: text/html; charset=utf-8');
            // phpcs:ignore
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
               . '<meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<title>Back Shortly</title>'
               . '<style>'
               . '*{margin:0;padding:0;box-sizing:border-box}'
               . 'html,body{height:100%}'
               . 'body{background:#111;color:#777;font-family:"Courier New",Courier,monospace;'
               . 'display:flex;align-items:center;justify-content:center;text-align:center}'
               . '.wrap{max-width:460px;padding:40px 20px}'
               . '.site{color:#bbb;font-size:1rem;letter-spacing:.12em;text-transform:uppercase;margin-bottom:18px}'
               . '.msg{font-size:.82rem;line-height:1.7}'
               . '</style></head><body>'
               . '<div class="wrap">'
               . '<div class="site">' . $_snap_site . '</div>'
               . '<div class="msg">Back shortly.<br>An update is being applied.</div>'
               . '</div></body></html>';
            exit;
        }
    }
    unset($_snap_lock, $_snap_maint, $_snap_since, $_snap_site);
}

// --- SECURITY HEADERS ---
// Sent on every request before any output. Skipped on CLI (e.g. migrations).
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

define('SNAPSMACK_VERSION', 'Alpha 0.7.384');
define('SNAPSMACK_VERSION_SHORT', '0.7.384');
define('SNAPSMACK_VERSION_CODENAME', 'Dragon Quest');

// --- VERSION COMPARISON ---
// Versions are standard three-part semver: 0.7.17, 0.7.18, etc.
// Milestone map: 0.7.x = Alpha, 0.8.x = Closed Beta, 0.9.x = Open Beta, 1.0 = Stable.
//
// Legacy installs (pre-0.7.17) used a letter-suffix scheme (0.7.9P, etc.).
// This function handles both formats: it normalises a trailing letter to a
// fourth numeric segment (a=1, b=2, ...) before delegating to version_compare().
// Plain semver strings pass through unmodified (appended .0 is harmless).
//
// Usage: snap_version_compare('0.7.123', '0.7.123', '>') => true
//        snap_version_compare('0.7.9p', '0.7.9n', '>') => true (legacy)
function snap_version_compare(string $v1, string $v2, string $op = '>'): bool {
    $normalise = function (string $v): string {
        // Strip non-numeric prefix (e.g. 'Alpha 0.7.182' → '0.7.182').
        $v = preg_replace('/^[^0-9]+/', '', $v);
        if (preg_match('/^(\d+(?:\.\d+)*)([a-z])$/i', $v, $m)) {
            return $m[1] . '.' . (ord(strtolower($m[2])) - ord('a') + 1);
        }
        return $v . '.0';
    };
    return version_compare($normalise($v1), $normalise($v2), $op);
}

// --- HTTPS DETECTION ---
// Behind Cloudflare Tunnel (and other SSL-terminating reverse proxies),
// $_SERVER['HTTPS'] is always empty because CF connects to the origin over
// plain HTTP. Always use snap_is_https() instead of checking $_SERVER['HTTPS']
// directly so cookies get the Secure flag, canonical URLs are https://, etc.
function snap_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') return true;
    return false;
}

// --- MOBILE SKIN OVERRIDE ---
// The slug of the skin forced onto mobile devices. This skin is not selectable
// in the admin skin picker — it is served automatically when a phone is detected.
// Empty string = mobile visitors get the active desktop skin.
// Photogram ships in every base release package and is the default mobile skin.
define('SNAPSMACK_MOBILE_SKIN', 'photogram');

/**
 * Detect mobile devices via User-Agent string.
 * Returns true for phones; tablets are treated as desktop.
 */
function snapsmack_is_mobile(): bool {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (empty($ua)) return false;

    // Match common phone tokens. The 'Mobile' token catches most modern phones
    // (iOS Safari, Chrome Mobile, Samsung, etc.). Additional patterns cover
    // older or niche handsets. Tablets (iPad, Android without 'Mobile') are
    // intentionally excluded so they receive the normal desktop skin.
    return (bool) preg_match('/Mobile|iPhone|iPod|Android.*Mobile|webOS|BlackBerry|Opera Mini|IEMobile|Windows Phone/i', $ua);
}

// ===== SNAPSMACK EOF =====
