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

    // ── THE ARROW KEYS, AND WHY THE GRID IS THE LIST (SS-1095) ──────────────
    //
    // The owner reported that the arrows and the spacebar do nothing in this
    // overlay. ss-engine-comms.js looks for #snap-nav-data or a rel="prev"
    // link; the overlay is a FRAGMENT and emits neither, and every one of
    // those sources is read from the document AS LOADED -- which belongs to
    // the grid, not to the photograph now showing. Nothing re-reads them when
    // an overlay opens, so on all nine Grid-family skins the keys were dead.
    //
    // This engine already knows every tile, because it intercepts their
    // clicks. So the neighbour of the open photograph is simply the tile
    // beside it in DOM ORDER -- which is also the order the reader is looking
    // at, and on a filtered or searched grid that is not the archive order a
    // server-side prev/next would have given.
    //
    // Exposed BEFORE the overlay lookup below, deliberately, so the choice can
    // be tested without a page -- the same shape as ss-engine-glide.js.

    /**
     * The href beside currentHref among hrefs, or '' when there is not one.
     *
     * Pure. '' means DO NOTHING, and it is returned for every case where the
     * honest answer is unknown rather than guessed:
     *   - the last loaded tile going forward, or the first going back. The
     *     grid is a window onto the archive under endless scroll, so wrapping
     *     round would lie about where the reader is.
     *   - a photograph that is not in the loaded grid at all, which is what a
     *     deep link into a page that has not loaded looks like.
     */
    function neighbourHref(hrefs, currentHref, step) {
        if (!hrefs || !hrefs.length || !currentHref || !step) return '';
        var at = hrefs.indexOf(currentHref);
        if (at < 0) return '';
        var to = at + step;
        if (to < 0 || to >= hrefs.length) return '';
        return hrefs[to] || '';
    }

    /**
     * Is this keystroke going somewhere the reader is typing?
     *
     * The overlay carries a comment box. A space typed into it must reach the
     * box and not move to another photograph.
     */
    function isTypingTarget(el) {
        if (!el) return false;
        if (el.isContentEditable) return true;
        var tag = (el.tagName || '').toUpperCase();
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
    }

    window.SSGridModal = { neighbourHref: neighbourHref, isTypingTarget: isTypingTarget };

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
        // Nothing here acts unless the overlay is open. Without this the
        // arrows and the spacebar would fire on the grid page too, where
        // space is how a reader scrolls.
        if (overlay.hasAttribute('hidden')) return;

        if (e.key === 'Escape') { closeModal(); return; }

        // A modified arrow belongs to the browser: ctrl/cmd-left is history.
        if (e.ctrlKey || e.metaKey || e.altKey || e.shiftKey) return;

        // e.target is where the event began; activeElement catches the case
        // where focus is in a field but the event was retargeted.
        if (isTypingTarget(e.target) || isTypingTarget(document.activeElement)) return;

        // SPACE GOES BACKWARD, the same direction as ArrowLeft, because that
        // is what ss-engine-comms.js:219 already does on a solo page and one
        // product should not disagree with itself. It is also the one choice
        // here that is a preference rather than a fact, so it is written down
        // as a question for the owner rather than buried.
        var step = e.key === 'ArrowRight' ? 1
                 : (e.key === 'ArrowLeft' || e.key === ' ') ? -1 : 0;
        if (!step) return;

        var hrefs = Array.prototype.map.call(
            document.querySelectorAll('.' + P + '-tile a[href]'),
            function (a) { return a.href; });

        var to = neighbourHref(hrefs, location.href, step);
        if (!to) return;

        // Only now, so an unhandled key and a refused move both leave the
        // page exactly as the browser would.
        e.preventDefault();
        openModal(to);
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
