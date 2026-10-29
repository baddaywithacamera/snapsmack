/**
 * SNAPSMACK - Archive layout / calendar state persistence
 *
 * Was 24 lines of JavaScript written inline in archive.php since 0.7.159.
 * JavaScript does not live in a PHP file; it lives here and the page loads it.
 * Extracted 29 October 2026, SS-1041.
 *
 * The three server values arrive as data-* attributes on this script's own
 * tag, which is the convention ss-engine-scrolltime.js already uses. Nothing
 * is interpolated into JavaScript: a PHP value echoed into a script body has
 * to be escaped for a language the PHP escapers do not know.
 *
 * It mirrors the server's resolved layout into the cookie and localStorage so
 * the next fresh request resolves to the same thing, and puts the state on
 * <html> for the CSS and for ss-engine-archive-toggle.js to read. The server
 * reads the cookie before rendering, so there is no redirect and no flash.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    // document.currentScript is this tag, and it is only valid while the
    // script is executing synchronously -- so read it first, not inside a
    // later callback.
    var tag = document.currentScript;
    if (!tag) return;

    var layout = tag.getAttribute('data-layout') || 'thumbs';
    if (layout !== 'thumbs' && layout !== 'masonry') layout = 'thumbs';
    var calEnabled = tag.getAttribute('data-calendar-enabled') === '1';
    var calOpen = tag.getAttribute('data-calendar-open') === '1';

    try {
        localStorage.setItem('smack_archive_layout', layout);
        localStorage.setItem('smack_archive_calendar', calOpen ? 'open' : 'closed');
    } catch (e) {}

    // Cookies: one year, root path, lax same-site.
    document.cookie = 'smack_archive_layout=' + encodeURIComponent(layout) +
                      '; path=/; max-age=31536000; SameSite=Lax';
    if (calEnabled) {
        document.cookie = 'smack_archive_calendar=' + (calOpen ? 'open' : 'closed') +
                          '; path=/; max-age=31536000; SameSite=Lax';
    }

    // These attributes drive the CSS that shows or hides each grid and the
    // calendar panel. ss-engine-archive-toggle.js reads and writes them to
    // switch in place without a reload.
    document.documentElement.setAttribute('data-archive-layout', layout);
    document.documentElement.setAttribute('data-archive-calendar', calOpen ? 'open' : 'closed');
}());

// ===== SNAPSMACK EOF =====
