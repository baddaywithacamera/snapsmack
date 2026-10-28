/**
 * SNAPSMACK - Public engine configuration loader.
 * Loaded by core/meta.php before body engines and deferred head engines.
 * Reads inert, HTML-escaped JSON from its own script element. No inline
 * executable code or CSP exception is needed to deliver site settings.
 *
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 */
(function () {
    'use strict';
    var script = document.currentScript;
    var data = script && script.getAttribute('data-smack-config');
    if (data) {
        window.SMACK_CONFIG = JSON.parse(data);
    }
})();
// ===== SNAPSMACK EOF =====
