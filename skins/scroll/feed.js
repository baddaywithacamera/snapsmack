/**
 * SCROLL — feed engine (skin-local, zero dependencies).
 *
 * Two hand-rolled jobs:
 *   1. NATIVE-ASPECT MASONRY. Every .scroll-feature-item spans a number of grid
 *      row-units proportional to its image's TRUE aspect ratio, so portraits
 *      stand tall and landscapes stay short — orientation is a consequence of the
 *      aspect, not a class you toggle. (Replaces the old binary "portrait spans 2
 *      rows" option; the engine just does the right thing per image.)
 *   2. INFINITE SCROLL. Fetches the next page, appends it, and re-lays-out only
 *      the freshly-added tiles.
 *
 * MISSING ORIENTATION: aspect is taken from the <img> width/height attributes
 * (the server's img_width/img_height) when they're real, else the image's own
 * natural size once it decodes, else a landscape default. A 1x1 sentinel or a
 * not-yet-loaded image is treated as landscape, NEVER portrait, so a wall of
 * dimension-less images can never collapse into all-portrait.
 */

(function () {
    'use strict';

    var grid = document.getElementById('scroll-feed-grid');
    if (!grid) return;
    var sentinel = document.getElementById('scroll-feed-sentinel');

    function px(v, fb) { var n = parseFloat(v); return isFinite(n) ? n : fb; }

    /** Reliable aspect (w/h) for a tile's image, or 0 when not yet knowable. */
    function aspectOf(img) {
        if (!img) return 0;
        var w = parseInt(img.getAttribute('width'), 10) || 0;
        var h = parseInt(img.getAttribute('height'), 10) || 0;
        // 1x1 is landing.php's "dimensions unknown" sentinel — don't trust it.
        if (w === 1 && h === 1) { w = 0; h = 0; }
        if ((!w || !h) && img.naturalWidth > 0 && img.naturalHeight > 0) {
            w = img.naturalWidth;
            h = img.naturalHeight;
        }
        return (w > 0 && h > 0) ? (w / h) : 0;
    }

    function layout() {
        var cs = window.getComputedStyle(grid);
        var unit = px(cs.gridAutoRows, 10); if (!(unit >= 1)) unit = 10;
        var gap = px(cs.rowGap, 0); if (!(gap >= 0)) gap = 0;
        var items = grid.querySelectorAll('.scroll-feature-item');
        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            var cw = item.getBoundingClientRect().width;
            if (cw <= 0) continue;                        // grid not laid out yet; a later run catches it
            var aspect = aspectOf(item.querySelector('img'));
            // Unknown aspect => a landscape tile (3:2-ish). NEVER the portrait branch.
            var pxHeight = aspect > 0 ? (cw / aspect) : (cw * 0.66);
            var span = Math.max(1, Math.round((pxHeight + gap) / (unit + gap)));
            item.style.setProperty('--ss-rows', span);
        }
    }

    // Re-measure a tile the instant its image decodes — covers lazy-loaded images
    // and any without stored dimensions, so they settle to their true height.
    function watch(scope) {
        var imgs = scope.querySelectorAll('.scroll-feature-item img');
        for (var i = 0; i < imgs.length; i++) {
            if (imgs[i].complete && imgs[i].naturalWidth > 0) continue;
            imgs[i].addEventListener('load', layout, { once: true });
            imgs[i].addEventListener('error', layout, { once: true });
        }
    }

    watch(grid);
    layout();
    setTimeout(layout, 50);
    setTimeout(layout, 300);

    var rt;
    window.addEventListener('resize', function () {
        clearTimeout(rt);
        rt = setTimeout(layout, 100);
    });

    // --- Infinite scroll ---
    if (sentinel && 'IntersectionObserver' in window) {
        var loading = false;
        var observer = new IntersectionObserver(function (entries) {
            if (!entries[0].isIntersecting || loading) return;
            var page = parseInt(sentinel.dataset.nextPage || '0', 10);
            if (!page) { observer.disconnect(); return; }

            loading = true;
            var url = new URL(window.location.href);
            url.searchParams.set('scroll_page', String(page));

            fetch(url.toString(), { credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var nextGrid = doc.getElementById('scroll-feed-grid');
                    var nextSentinel = doc.getElementById('scroll-feed-sentinel');
                    if (!nextGrid || !nextSentinel) throw new Error('SCROLL feed fragment missing');

                    Array.from(nextGrid.children).forEach(function (node) {
                        grid.appendChild(document.importNode(node, true));
                    });
                    watch(grid);
                    layout();
                    sentinel.dataset.nextPage = nextSentinel.dataset.nextPage || '0';

                    document.dispatchEvent(new CustomEvent('snapsmack:grid-appended', {
                        detail: { grid: grid, page: page }
                    }));

                    if (sentinel.dataset.nextPage === '0') observer.disconnect();
                })
                .catch(function (e) { console.warn('SCROLL: could not load the next page', e); })
                .finally(function () { loading = false; });
        }, { rootMargin: '900px 0px' });

        observer.observe(sentinel);
    }
}());
// ===== SNAPSMACK EOF =====
