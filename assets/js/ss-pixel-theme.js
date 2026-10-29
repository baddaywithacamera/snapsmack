/**
 * SNAPSMACK - Fediverse reader light/dark theme bootstrap
 *
 * Was a one-line inline script in pixel.php's <head>. JavaScript does not
 * live in a PHP file. Extracted 29 October 2026, SS-1041.
 *
 * It runs in <head> with no defer because it has to set the attribute before
 * first paint; deferring it shows the wrong palette and then swaps it.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';
    var theme = 'dark';
    try { theme = localStorage.getItem('pixel-theme') || 'dark'; } catch (e) {}
    document.documentElement.setAttribute('data-theme', theme);
}());

// ===== SNAPSMACK EOF =====
