/**
 * SNAPSMACK - The Grid Post Modal
 *
 * Intercepts .tg-tile link clicks and renders the post as an IG-style overlay
 * instead of navigating to a new page. Fetches the post URL with &modal=1
 * appended, which instructs layout.php to return only the inner .tg-post-ig
 * fragment (no html/head/body shell).
 *
 * Deep links: a direct visit to a post URL renders the grid (layout.php
 * delegates to landing.php) with the overlay container flagged data-autoopen.
 * We detect that flag and open the modal over the grid — there is no longer a
 * standalone "flat" post page.
 *
 * Carousel: the carousel engine (ss-engine-carousel-view.js) only runs on
 * DOMContentLoaded, so it never sees the fragment we inject at runtime. We
 * mirror its init here. NB: SnapSlider is a CLASS instantiated with
 * `new SnapSlider({container})` — there is NO SnapSlider.initAll(); calling
 * that phantom method is what left the modal carousel with no dots/arrows.
 *
 * Handles: open, close (backdrop, ESC, back arrow, browser back), carousel
 * (re)init, and history (push / replace / popstate).
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

    // Where to land when a deep-linked modal is closed (no flat page exists to
    // history.back() to). Set by skin-footer.php from BASE_URL.
    var gridUrl = overlay.getAttribute('data-grid-url') || '/';

    // ── Carousel (re)init for the injected fragment ──────────────────────────
    // Mirrors ss-engine-carousel-view.js, which only auto-runs on page load.
    function initCarouselIn(root) {
        if (typeof SnapSlider === 'undefined') return;
        var sliders = root.querySelectorAll('.ss-slider');
        for (var i = 0; i < sliders.length; i++) {
            var container = sliders[i];
            var speed = parseInt(container.getAttribute('data-speed'), 10) || 400;
            var loop  = container.getAttribute('data-loop') === 'true';
            try {
                new SnapSlider({ container: container, speed: speed, loop: loop });
            } catch (err) {
                console.warn('[tg-modal] SnapSlider init failed:', err);
                continue;
            }
            wireExifPanel(container, root);
        }
    }

    // Update the EXIF panel on slide change (parity with the standalone engine).
    function wireExifPanel(container, root) {
        container.addEventListener('snapslider:slidechange', function (e) {
            var exif  = (e.detail && e.detail.exif) || {};
            var panel = root.querySelector('#tg-exif-panel');
            if (!panel) return;
            panel.innerHTML = '';
            var fields = {
                camera: 'Camera', lens: 'Lens', focal: 'Focal', film: 'Film',
                iso: 'ISO', aperture: 'Aperture', shutter: 'Shutter', flash: 'Flash'
            };
            Object.keys(fields).forEach(function (key) {
                var val = (exif[key] || '').trim();
                if (!val) return;
                var item = document.createElement('div');
                item.className = 'tg-exif-item';
                item.setAttribute('data-exif-key', key);
                var lbl = document.createElement('span');
                lbl.className = 'tg-exif-label';
                lbl.textContent = fields[key];
                var valEl = document.createElement('span');
                valEl.className = 'tg-exif-value';
                valEl.textContent = val;
                item.appendChild(lbl);
                item.appendChild(valEl);
                panel.appendChild(item);
            });
        });
    }

    // ── Open ──────────────────────────────────────────────────────────────--
    function openModal(url) {
        var sep      = url.indexOf('?') !== -1 ? '&' : '?';
        var fetchUrl = url + sep + 'modal=1';
        // Opening the URL we're already on = a deep link the server rendered the
        // grid for; replaceState (don't stack a duplicate history entry).
        var isDeepLink = (url === location.href);

        fetch(fetchUrl, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('fetch failed');
                return r.text();
            })
            .then(function (html) {
                frame.innerHTML = html;
                overlay.removeAttribute('hidden');
                document.body.classList.add('tg-modal-open');

                var state = { tgModal: true, deepLink: isDeepLink };
                if (isDeepLink) {
                    history.replaceState(state, '', url);
                } else {
                    history.pushState(state, '', url);
                }

                initCarouselIn(frame);
                frame.dispatchEvent(new CustomEvent('snapsmack:modal:opened', { bubbles: true }));
            })
            .catch(function () {
                // Network error or bad response — fall back to a full page load.
                location.href = url;
            });
    }

    // ── Close ─────────────────────────────────────────────────────────────--
    function closeModal() {
        var wasDeepLink = !!(history.state && history.state.deepLink);
        overlay.setAttribute('hidden', '');
        document.body.classList.remove('tg-modal-open');
        frame.innerHTML = '';
        if (history.state && history.state.tgModal) {
            if (wasDeepLink) {
                // No grid entry behind us — reveal the already-rendered grid and
                // reflect its URL without a reload.
                history.replaceState(null, '', gridUrl);
            } else {
                history.back();
            }
        }
    }

    // ── Intercept tile clicks ────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var tile = e.target.closest('.tg-tile a');
        if (!tile) return;
        e.preventDefault();
        openModal(tile.href);
    });

    // ── In-modal back arrow (.tg-back-btn) → close cleanly ───────────────────
    document.addEventListener('click', function (e) {
        if (e.target.closest('.tg-back-btn')) {
            e.preventDefault();
            closeModal();
        }
    });

    // ── Backdrop click ───────────────────────────────────────────────────────
    if (backdrop) {
        backdrop.addEventListener('click', closeModal);
    }

    // ── ESC key ─────────────────────────────────────────────────────────────-
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.hasAttribute('hidden')) {
            closeModal();
        }
    });

    // ── Browser back (popstate) closes the modal ─────────────────────────────
    window.addEventListener('popstate', function () {
        if (!overlay.hasAttribute('hidden')) {
            overlay.setAttribute('hidden', '');
            document.body.classList.remove('tg-modal-open');
            frame.innerHTML = '';
        }
    });

    // ── Deep link: server flagged that we should open this post over the grid ─
    if (overlay.getAttribute('data-autoopen')) {
        openModal(location.href);
    }

})();
// ===== SNAPSMACK EOF =====
