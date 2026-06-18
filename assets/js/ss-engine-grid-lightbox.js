/**
 * SNAPSMACK - Grid-family avatar lightbox (shared engine)
 *
 * Prefix-derived from the skin's #<prefix>-modal-overlay (always rendered in
 * skin-footer alongside the lightbox). Click / keyboard-activate any element
 * carrying [data-<prefix>-lightbox] to open its image full-screen.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    ready(function () {
        // Derive the skin prefix from the modal overlay (unique, skin-owned).
        var anchor = document.querySelector('[data-grid-url][id$="-modal-overlay"]');
        if (!anchor) { return; }
        var P = anchor.id.replace(/-modal-overlay$/, '');

        var triggers = document.querySelectorAll('[data-' + P + '-lightbox]');
        if (!triggers.length) { return; }

        var box = document.getElementById(P + '-lightbox');
        if (!box) { return; }

        var img      = box.querySelector('.' + P + '-lightbox-img');
        var closeBtn = box.querySelector('.' + P + '-lightbox-close');
        var openClass = P + '-modal-open';

        function open(src) {
            if (!src) { return; }
            img.setAttribute('src', src);
            box.hidden = false;
            void box.offsetWidth;            // force reflow so the transition runs
            box.classList.add('is-open');
            document.body.classList.add(openClass);
        }
        function close() {
            box.classList.remove('is-open');
            document.body.classList.remove(openClass);
            window.setTimeout(function () { box.hidden = true; img.setAttribute('src', ''); }, 200);
        }

        triggers.forEach(function (t) {
            t.addEventListener('click', function () { open(t.getAttribute('data-' + P + '-lightbox')); });
            t.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    open(t.getAttribute('data-' + P + '-lightbox'));
                }
            });
        });

        if (closeBtn) { closeBtn.addEventListener('click', close); }
        box.addEventListener('click', function (e) { if (e.target === box || e.target === img) { close(); } });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !box.hidden) { close(); }
        });
    });
}());
// ===== SNAPSMACK EOF =====
