/**
 * SNAPSMACK - Public config bootstrap
 *
 * Was two one-line inline scripts in core/meta.php:
 *     window.SNAP_BASE_URL  = <?php echo json_encode(BASE_URL); ?>;
 *     window.SNAP_SITE_MODE = <?php echo json_encode($settings['site_mode']); ?>;
 * JavaScript does not live in a PHP file. Extracted 29 October 2026, SS-1041.
 *
 * assets/js/smack-pwa.js reads SNAP_BASE_URL and assets/js/ss-engine-comms.js
 * reads SNAP_SITE_MODE, so both globals must exist before those run. This tag
 * is emitted WITHOUT defer, immediately before the deferred pwa script, so it
 * executes first in document order.
 *
 * Both values arrive as data-* attributes on this script's own tag.
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
    if (!tag) return;
    var base = tag.getAttribute('data-base-url');
    var mode = tag.getAttribute('data-site-mode');
    if (base !== null) window.SNAP_BASE_URL = base;
    if (mode !== null) window.SNAP_SITE_MODE = mode;
}());

// ===== SNAPSMACK EOF =====
