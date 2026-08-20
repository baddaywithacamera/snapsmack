/** SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. */
/**
 * SMACK THAT APP UP service-worker registration. Registers smack-sw.js so the
 * CMS can run as an installable, offline-capable PWA. Loaded by the CMS on every
 * page; not a skin asset and never declared in a skin manifest.
 */
(function () {
    'use strict';
    var secureContext = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    if ('serviceWorker' in navigator && secureContext) {
        addEventListener('load', function () {
            navigator.serviceWorker.register((window.SNAP_BASE_URL || '/') + 'smack-sw.js', {scope:(window.SNAP_BASE_URL || '/')});
        });
    }
}());
// ===== SNAPSMACK EOF =====
