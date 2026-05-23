/**
 * SNAPSMACK - 2FA setup page JS
 *
 * Handles the "Copy All Codes" button on the recovery codes section
 * of smack-2fa.php. Reads codes from the DOM so no PHP data is
 * embedded in JavaScript.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('copy-recovery-codes-btn');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var items = document.querySelectorAll('.recovery-code-item');
            var codes = Array.prototype.map.call(items, function (el) {
                return el.textContent.trim();
            });

            if (!codes.length) return;

            navigator.clipboard.writeText(codes.join('\n')).then(function () {
                var msg = document.getElementById('copy-confirm');
                if (msg) msg.style.display = 'inline';
            }).catch(function () {
                // Clipboard API unavailable — silently do nothing
            });
        });
    });
}());
