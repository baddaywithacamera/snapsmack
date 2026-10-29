/**
 * SNAPSMACK - Sign-in page tab switching
 *
 * Was an inline script at the end of snap-in.php. JavaScript does not live in
 * a PHP file. Extracted 29 October 2026, SS-1041.
 *
 * One listener per tab button, delegated nowhere: the sign-in page is small
 * and its markup does not change after load.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    function wire() {
        var tabs = document.querySelectorAll('.login-tabs .login-tab');
        if (!tabs.length) return;
        tabs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tab = this.dataset.tab;
                document.querySelectorAll('.login-tabs .login-tab').forEach(function (b) {
                    b.classList.toggle('active', b.dataset.tab === tab);
                });
                document.querySelectorAll('.login-panel').forEach(function (p) {
                    p.classList.toggle('active', p.id === tab);
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
}());

// ===== SNAPSMACK EOF =====
