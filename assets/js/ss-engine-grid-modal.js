/**
 * SNAPSMACK - Grid-family Post Modal (shared engine)
 *
 * Prefix-derived from #<prefix>-modal-overlay. Intercepts <prefix>-tile link
 * clicks and renders the post as an IG-style overlay (fetching the URL with
 * &modal=1 so layout.php returns only the inner fragment). Handles deep-link
 * autoopen, carousel (re)init for the injected fragment, EXIF panel sync, and
 * history (push / replace / popstate). One engine for every Grid-family skin.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
(function () {
    'use strict';

    var overlay = document.querySelector('[data-grid-url][id$="-modal-overlay"]');
    if (!overlay) return;
    var P = overlay.id.replace(/-modal-overlay$/, '');

    var frame    = document.getElementById(P + '-modal-frame');
    var backdrop = overlay.querySelector('.' + P + '-modal-backdrop');

    if (!frame) {
        console.warn('[' + P + '-modal] #' + P + '-modal-frame not in DOM — modal disabled, ' +
                     'tiles will navigate instead. Check skin-footer.php rendered the overlay.');
        return;
    }

    var openClass = P + '-modal-open';
    var gridUrl   = overlay.getAttribute('data-grid-url') || '/';

    // ── Carousel (re)init for the injected fragment (mirrors ss-engine-carousel-view) ─
    function initCarouselIn(root, _attempt) {
        if (typeof SnapSlider === 'undefined') {
            _attempt = _attempt || 0;
            if (_attempt < 10) {
                setTimeout(function () { initCarouselIn(root, _attempt + 1); }, 150);
            } else {
                console.warn('[' + P + '-modal] SnapSlider never loaded — carousel nav disabled.');
            }
            return;
        }
        var sliders = root.querySelectorAll('.ss-slider');
        for (var i = 0; i < sliders.length; i++) {
            var container = sliders[i];
            var speed = parseInt(container.getAttribute('data-speed'), 10) || 400;
            var loop  = container.getAttribute('data-loop') === 'true';
            try {
                new SnapSlider({ container: container, speed: speed, loop: loop });
            } catch (err) {
                console.warn('[' + P + '-modal] SnapSlider init failed:', err);
                continue;
            }
            wireExifPanel(container, root);
        }
    }

    function wireExifPanel(container, root) {
        container.addEventListener('snapslider:slidechange', function (e) {
            var exif  = (e.detail && e.detail.exif) || {};
            var panel = root.querySelector('#' + P + '-exif-panel');
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
                item.className = P + '-exif-item';
                item.setAttribute('data-exif-key', key);
                var lbl = document.createElement('span');
                lbl.className = P + '-exif-label';
                lbl.textContent = fields[key];
                var valEl = document.createElement('span');
                valEl.className = P + '-exif-value';
                valEl.textContent = val;
                item.appendChild(lbl);
                item.appendChild(valEl);
                panel.appendChild(item);
            });
        });
    }

    function openModal(url) {
        var sep      = url.indexOf('?') !== -1 ? '&' : '?';
        var fetchUrl = url + sep + 'modal=1';
        var isDeepLink = (url === location.href);

        fetch(fetchUrl, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('fetch failed'); return r.text(); })
            .then(function (html) {
                frame.innerHTML = html;
                overlay.removeAttribute('hidden');
                document.body.classList.add(openClass);

                var state = { ssModal: true, deepLink: isDeepLink };
                if (isDeepLink) { history.replaceState(state, '', url); }
                else { history.pushState(state, '', url); }

                initCarouselIn(frame);
                frame.dispatchEvent(new CustomEvent('snapsmack:modal:opened', { bubbles: true }));
            })
            .catch(function () { location.href = url; });
    }

    function closeModal() {
        var wasDeepLink = !!(history.state && history.state.deepLink);
        overlay.setAttribute('hidden', '');
        document.body.classList.remove(openClass);
        frame.innerHTML = '';
        if (history.state && history.state.ssModal) {
            if (wasDeepLink) { history.replaceState(null, '', gridUrl); }
            else { history.back(); }
        }
    }

    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) return;
        var tile = e.target.closest('.' + P + '-tile a');
        if (!tile) return;
        e.preventDefault();
        openModal(tile.href);
    });

    document.addEventListener('click', function (e) {
        if (e.target && e.target.closest && e.target.closest('.' + P + '-back-btn')) {
            e.preventDefault();
            closeModal();
        }
    });

    if (backdrop) backdrop.addEventListener('click', closeModal);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.hasAttribute('hidden')) closeModal();
    });

    window.addEventListener('popstate', function () {
        if (!overlay.hasAttribute('hidden')) {
            overlay.setAttribute('hidden', '');
            document.body.classList.remove(openClass);
            frame.innerHTML = '';
        }
    });

    if (overlay.getAttribute('data-autoopen')) openModal(location.href);
})();
// ===== SNAPSMACK EOF =====
