/**
 * SNAPSMACK - AURORA avatar lightbox
 *
 * Click (or keyboard-activate) any element carrying [data-au-lightbox] to open
 * the larger image in a full-screen overlay. The overlay container (#au-lightbox)
 * is rendered once in skin-footer.php so every Grid page can use it.
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
        var triggers = document.querySelectorAll('[data-au-lightbox]');
        if (!triggers.length) { return; }

        var box = document.getElementById('au-lightbox');
        if (!box) { return; }

        var img      = box.querySelector('.au-lightbox-img');
        var closeBtn = box.querySelector('.au-lightbox-close');

        function open(src) {
            if (!src) { return; }
            img.setAttribute('src', src);
            box.hidden = false;
            void box.offsetWidth;            // force reflow so the transition runs
            box.classList.add('is-open');
            document.body.classList.add('au-modal-open');
        }

        function close() {
            box.classList.remove('is-open');
            document.body.classList.remove('au-modal-open');
            window.setTimeout(function () {
                box.hidden = true;
                img.setAttribute('src', '');
            }, 200);
        }

        triggers.forEach(function (t) {
            t.addEventListener('click', function () {
                open(t.getAttribute('data-au-lightbox'));
            });
            t.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    open(t.getAttribute('data-au-lightbox'));
                }
            });
        });

        if (closeBtn) { closeBtn.addEventListener('click', close); }
        box.addEventListener('click', function (e) {
            if (e.target === box || e.target === img) { close(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !box.hidden) { close(); }
        });
    });
}());
// ===== SNAPSMACK EOF =====
