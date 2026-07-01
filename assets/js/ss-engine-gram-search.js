/**
 * SNAPSMACK - Floating Search Dock engine
 *
 * Drives the bottom-left magnifier dock (core/gram-search-dock.php):
 *   - Desktop: CSS :hover expands the box; clicking the magnifier with text
 *     submits, clicking empty focuses the field.
 *   - Touch (no hover): tap the magnifier to expand (.is-open), tap-away or
 *     Escape to collapse.
 * No inline JS in skins — this loads via the smack-gram-search manifest handle.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
(function () {
    'use strict';

    function initDock(dock) {
        var form   = dock.querySelector('.gsd-form');
        var toggle = dock.querySelector('.gsd-toggle');
        var input  = dock.querySelector('.gsd-input');
        if (!form || !toggle || !input) return;

        function open() {
            dock.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
            input.setAttribute('tabindex', '0');
            try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); }
        }

        function close() {
            dock.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
            input.setAttribute('tabindex', '-1');
            input.blur();
        }

        toggle.addEventListener('click', function () {
            // Text already entered (e.g. via hover-expand on desktop) → search.
            if (input.value.trim() !== '') {
                form.submit();
                return;
            }
            if (dock.classList.contains('is-open')) {
                close();
            } else {
                open();
            }
        });

        // Keep the field usable when revealed by hover on desktop.
        input.addEventListener('focus', function () {
            input.setAttribute('tabindex', '0');
            toggle.setAttribute('aria-expanded', 'true');
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { close(); }
        });

        // Tap-away / click-outside collapses the dock.
        document.addEventListener('pointerdown', function (e) {
            if (dock.classList.contains('is-open') && !dock.contains(e.target)) {
                close();
            }
        });

        // Never submit an empty query.
        form.addEventListener('submit', function (e) {
            if (input.value.trim() === '') {
                e.preventDefault();
                open();
            }
        });
    }

    function boot() {
        var docks = document.querySelectorAll('[data-gram-search-dock]');
        for (var i = 0; i < docks.length; i++) { initDock(docks[i]); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
// ===== SNAPSMACK EOF =====
