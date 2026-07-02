/**
 * SNAPSMACK - SMACKVERSE admin page engine
 *
 * One job: live-preview the fediverse address as the handle is typed.
 * Reads the domain from data-sv-domain on the preview element; sanitises
 * the input exactly like core/smackverse.php sv_handle() does (lowercase,
 * [a-z0-9_] only) so what you see is what WebFinger will answer for.
 * No inline JS anywhere — server data arrives via data-* attributes.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    function init() {
        var input   = document.getElementById('sv-handle-input');
        var preview = document.getElementById('sv-handle-preview');
        if (!input || !preview) return;
        var domain = preview.getAttribute('data-sv-domain') || '';

        function sanitise(v) {
            v = String(v).toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
            return v !== '' ? v : 'photoblog';
        }
        function update() {
            preview.textContent = '@' + sanitise(input.value) + '@' + domain;
        }
        input.addEventListener('input', update);
        update();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
// ===== SNAPSMACK EOF =====
