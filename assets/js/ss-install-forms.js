/**
 * SNAPSMACK - Installer form helpers
 *
 * Was three inline scripts in install.php: the SHOW/HIDE password toggle, the
 * password-match check, and the gate that keeps the Log In link dead until the
 * Break-Glass Card has been downloaded and acknowledged. JavaScript does not
 * live in a PHP file. Extracted 29 October 2026, SS-1041.
 *
 * All three are wired by behaviour rather than by a global function the markup
 * calls, so install.php carries no onclick= either. Each wiring is a no-op on
 * a step whose elements are not on the page, which is why one file serves the
 * whole installer.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    // SHOW / HIDE on a password field. The button names its field with
    // data-toggle-pass, so the markup needs no inline handler.
    function wirePasswordToggles() {
        document.querySelectorAll('[data-toggle-pass]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var field = document.getElementById(this.getAttribute('data-toggle-pass'));
                if (!field) return;
                var hidden = field.type === 'password';
                field.type = hidden ? 'text' : 'password';
                this.textContent = hidden ? 'HIDE' : 'SHOW';
            });
        });
    }

    // Do the two admin passwords match? Blocks the submit until they do.
    function wirePasswordMatch() {
        var p1 = document.getElementById('admin_pass');
        var p2 = document.getElementById('admin_pass2');
        var msg = document.getElementById('pass-match-msg');
        var btn = document.getElementById('btn-finish');
        if (!p1 || !p2 || !msg || !btn) return;

        function check() {
            if (!p2.value) { msg.hidden = true; msg.className = 'hint'; return; }
            var same = p1.value === p2.value;
            msg.hidden = false;
            // The colours are in the stylesheet, not set from here: no inline
            // CSS, same rule as no inline JS.
            msg.className = same ? 'hint hint--ok' : 'hint hint--bad';
            msg.textContent = same ? '✓ Passwords match.' : '✗ Passwords do not match.';
            btn.disabled = !same;
        }
        p1.addEventListener('input', check);
        p2.addEventListener('input', check);
    }

    // The Break-Glass Card is the only way back into a locked-out install, so
    // the Log In link stays dead until the operator has downloaded it and said
    // they put it somewhere offline.
    function wireBreakGlassGate() {
        var download = document.getElementById('break-glass-download');
        var saved = document.getElementById('break-glass-saved');
        var login = document.getElementById('install-login-link');
        if (!download || !saved || !login) return;
        download.addEventListener('click', function () { saved.disabled = false; });
        saved.addEventListener('change', function () {
            login.classList.toggle('is-armed', this.checked);
        });
    }

    function wire() {
        wirePasswordToggles();
        wirePasswordMatch();
        wireBreakGlassGate();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
}());

// ===== SNAPSMACK EOF =====
