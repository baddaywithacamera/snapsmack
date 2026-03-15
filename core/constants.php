<?php
/**
 * SNAPSMACK - System Constants
 * Alpha v0.7.3a
 *
 * Defines version strings and system-wide constants. Include this early in
 * the bootstrap chain (e.g., from db.php) to ensure availability throughout
 * the application.
 */

define('SNAPSMACK_VERSION', 'Alpha 0.7.3a');
define('SNAPSMACK_VERSION_SHORT', '0.7.3a');
define('SNAPSMACK_VERSION_CODENAME', 'Bedpan');

// --- VERSION COMPARISON ---
// PHP's version_compare() treats trailing letters as "alpha" (lower than
// no suffix), so 0.7.3a < 0.7.3. SnapSmack uses the letter suffix as a
// patch increment: 0.7.3a > 0.7.3, 0.7.3b > 0.7.3a, etc.
//
// This function normalises the trailing letter to a fourth numeric segment
// (a=1, b=2, ...) before delegating to version_compare(). No letter = .0.
//
// Usage: snap_version_compare('0.7.3a', '0.7.3', '>') => true
function snap_version_compare(string $v1, string $v2, string $op = '>'): bool {
    $normalise = function (string $v): string {
        if (preg_match('/^(\d+(?:\.\d+)*)([a-z])$/i', $v, $m)) {
            return $m[1] . '.' . (ord(strtolower($m[2])) - ord('a') + 1);
        }
        return $v . '.0';
    };
    return version_compare($normalise($v1), $normalise($v2), $op);
}

// --- MOBILE SKIN OVERRIDE ---
// The slug of the skin forced onto mobile devices. This skin is not selectable
// in the admin skin picker — it is served automatically when a phone is detected.
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
