<?php
/**
 * SNAPSMACK - System Constants
 * Alpha v0.7.2
 *
 * Defines version strings and system-wide constants. Include this early in
 * the bootstrap chain (e.g., from db.php) to ensure availability throughout
 * the application.
 */

define('SNAPSMACK_VERSION', 'Alpha 0.7.2');
define('SNAPSMACK_VERSION_SHORT', '0.7.2');
define('SNAPSMACK_VERSION_CODENAME', 'Sitzfleisch');

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
