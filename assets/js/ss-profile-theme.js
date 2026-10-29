/**
 * SNAPSMACK - Fediverse profile page light/dark theme
 *
 * Was two inline scripts in core/public-profile.php, one in <head> and one at
 * the end of <body>. JavaScript does not live in a PHP file. Extracted
 * 29 October 2026, SS-1041.
 *
 * Both halves are here because they are one feature. The head half has to run
 * before first paint or the page flashes the wrong palette, so its tag is
 * loaded in <head> with no defer; it does nothing but read the stored choice
 * and set the attribute. The toggle half waits for the button to exist.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    var KEY = 'pp-theme';
    var root = document.documentElement;

    // Before paint: honour the visitor's stored choice.
    try {
        var stored = localStorage.getItem(KEY);
        if (stored) root.setAttribute('data-theme', stored);
    } catch (e) {}

    function wire() {
        if (!root.getAttribute('data-theme')) root.setAttribute('data-theme', 'dark');
        var btn = document.getElementById('pp-theme');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem(KEY, next); } catch (e) {}
        });
    }

    // The script is loaded in <head>, so the button does not exist yet.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
}());

// ===== SNAPSMACK EOF =====
