/**
 * SNAPSMACK - ss-page-2fa
 *
 * Moved verbatim out of a <script> block in smack-2fa.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';
    function renderQR() {
        var canvas = document.getElementById('totp-qr-canvas');
        if (!canvas || !window.SmackQR || !window._smackTotpUri) return;
        SmackQR.toCanvas(canvas, _smackTotpUri, {
            width: 220, margin: 2,
            color: { dark: '#000000', light: '#ffffff' }
        }, function (err) {
            if (err) canvas.insertAdjacentHTML('afterend',
                '<p style="color:red;font-size:12px">QR render failed — use the manual key below.</p>');
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderQR);
    } else {
        renderQR();
    }
}());

// ===== SNAPSMACK EOF =====
