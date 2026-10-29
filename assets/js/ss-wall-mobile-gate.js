/**
 * SNAPSMACK - Gallery wall mobile gate
 *
 * Was an inline script in gallery-wall.php's <head>. JavaScript does not live
 * in a PHP file. Extracted 29 October 2026, SS-1041.
 *
 * The wall is a desktop and large-tablet page. The server already refuses
 * phones by user agent; some devices get past that, so this is the client-side
 * fallback. It runs in <head> with no defer so the redirect happens before the
 * wall renders rather than after it has been seen.
 *
 * The address to fall back to arrives as a data attribute, so this file holds
 * no knowledge of the site's routes.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';
    var tag = document.currentScript;
    var fallback = (tag && tag.getAttribute('data-fallback')) || 'archive.php';
    var narrow = window.innerWidth < 768;
    var touchTablet = ('ontouchstart' in window) && window.innerWidth < 1024;
    if (narrow || touchTablet) window.location.replace(fallback);
}());

// ===== SNAPSMACK EOF =====
