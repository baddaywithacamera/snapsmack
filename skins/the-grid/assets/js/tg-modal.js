/**
 * SNAPSMACK - The Grid Post Modal
 *
 * Intercepts .tg-tile link clicks and renders the post as an IG-style
 * overlay instead of navigating to a new page. Fetches the post URL with
 * &modal=1 appended, which instructs layout.php to return only the inner
 * .tg-post-ig fragment (no html/head/body shell).
 *
 * Handles: open, close (backdrop, ESC, back button), pushState/popstate.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    var overlay  = document.getElementById('tg-modal-overlay');
    var frame    = document.getElementById('tg-modal-frame');
    var backdrop = overlay ? overlay.querySelector('.tg-modal-backdrop') : null;

    if (!overlay || !frame) {
        // Fail loudly, not silently. A missing container is the #1 cause of
        // "the modal won't open" — without this warning it looks identical to
        // a JS-not-loaded problem and burns hours. The container is rendered
        // by skin-footer.php (shared across all Grid pages).
        console.warn('[tg-modal] #tg-modal-overlay / #tg-modal-frame not in DOM — ' +
                     'modal disabled, tiles will navigate instead. ' +
                     'Check that skin-footer.php rendered the overlay container.');
        return;
    }

    function openModal(url) {
        var sep      = url.indexOf('?') !== -1 ? '&' : '?';
        var fetchUrl = url + sep + 'modal=1';

        fetch(fetchUrl, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('fetch failed');
                return r.text();
            })
            .then(function (html) {
                frame.innerHTML = html;
                overlay.removeAttribute('hidden');
                document.body.classList.add('tg-modal-open');
                history.pushState({ tgModal: true, returnUrl: location.href }, '', url);

                // Best-effort carousel reinit
                if (window.SnapSlider && typeof window.SnapSlider.initAll === 'function') {
                    window.SnapSlider.initAll(frame);
                }
                frame.dispatchEvent(new CustomEvent('snapsmack:modal:opened', { bubbles: true }));
            })
            .catch(function () {
                // Network error or bad response — fall back to full page load
                location.href = url;
            });
    }

    function closeModal() {
        overlay.setAttribute('hidden', '');
        document.body.classList.remove('tg-modal-open');
        frame.innerHTML = '';
        if (history.state && history.state.tgModal) {
            history.back();
        }
    }

    // ── Intercept tile clicks ────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var tile = e.target.closest('.tg-tile a');
        if (!tile) return;
        e.preventDefault();
        openModal(tile.href);
    });

    // ── Backdrop click ───────────────────────────────────────────────────
    if (backdrop) {
        backdrop.addEventListener('click', closeModal);
    }

    // ── ESC key ──────────────────────────────────────────────────────────
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.hasAttribute('hidden')) {
            closeModal();
        }
    });

    // ── Browser back (popstate) closes the modal ─────────────────────────
    window.addEventListener('popstate', function () {
        if (!overlay.hasAttribute('hidden')) {
            overlay.setAttribute('hidden', '');
            document.body.classList.remove('tg-modal-open');
            frame.innerHTML = '';
        }
    });

})();
// ===== SNAPSMACK EOF =====
