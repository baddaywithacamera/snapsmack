/**
 * SNAPSMACK - SS-COLUMNS: fixed-column photo wall.
 *
 * A COLUMN wall, not a packer. There is no search, no genome, no annealing and
 * no solve time: the geometry is arithmetic and runs in O(n), about a
 * millisecond for a 400-photo feed.
 *
 * THE DESIGN, in one paragraph. The container is divided into a FIXED number of
 * equal columns (--ss-cols, default 4). Every photograph is drawn at its OWN
 * native aspect ratio - nothing is ever cropped - so a tile's height is decided
 * entirely by its shape. Each photo goes into whichever column is currently
 * shortest, in feed order. That is the whole algorithm.
 *
 * WHY THIS EMPHASISES PORTRAITS, for free. Every column is the same width, so a
 * 2:3 portrait is 1.5 x colW tall while a 3:2 landscape is 0.67 x colW tall:
 * the portrait occupies 2.25x the area WITHOUT any special-casing, any weight,
 * or any crop. Portraits are the rarer shape in this feed, and this is what
 * makes them read as the large pictures. Do not "improve" this with a portrait
 * multiplier - the ratio IS the emphasis, and anything added on top starts
 * cropping.
 *
 * WHY COLUMNS ARE NOT A DEFECT HERE. The predecessor engine
 * (ss-engine-masonry.js) is a skyline packer whose hard requirement was that no
 * vertical edge may run unbroken down the wall. That requirement belongs to a
 * free tessellation. This wall is columnar BY DESIGN: the vertical rhythm is
 * the point, the same way it is on a newspaper page. The two engines are not
 * interchangeable and neither is a fix for the other.
 *
 * BORDERS ARE INSIDE THE BOX. Tile height is computed from the INNER width
 * (colW - 2*border), so a non-zero --scroll-border-width neither crops the
 * photograph nor distorts it. The item is written box-sizing:border-box here,
 * inline, rather than inherited from a skin reset that a fork could drop.
 *
 * HOOKS the skin sets on the container (read once per layout):
 *   --ss-cols   integer, columns across            (default 4)
 *   --ss-gap    px, the ONLY separation            (default 6)
 * plus padding-left / padding-right, which are honoured.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    var DEFAULT_COLS = 4;
    var DEFAULT_GAP = 6;
    var FALLBACK_ASPECT = 3 / 2;   // matches the server-side fallback in landing.php
    var MAX_TILE_EDGE = 900;       // a_ thumbnail derivative longest edge

    function cssNum(cs, name, fb) {
        var v = parseFloat(cs.getPropertyValue(name));
        return (typeof v === 'number' && isFinite(v)) ? v : fb;
    }

    /** Photo shapes, read from markup only. NEVER naturalWidth: the lazy loader
     *  swaps src for a 1x1 GIF, so naturalWidth is a lie for anything offscreen. */
    function readItems(grid) {
        var els = grid.querySelectorAll('.ss-masonry-item');
        var out = [], i;
        for (i = 0; i < els.length; i++) {
            var el = els[i], img = el.querySelector('img');
            var w = parseInt(el.getAttribute('data-w'), 10)
                || (img ? parseInt(img.getAttribute('data-w'), 10) : 0);
            var h = parseInt(el.getAttribute('data-h'), 10)
                || (img ? parseInt(img.getAttribute('data-h'), 10) : 0);
            var provisional = !(w > 0 && h > 0);
            if (provisional) { w = 3; h = 2; }
            out.push({
                el: el, img: img,
                aspect: w / h,
                provisional: provisional
            });
        }
        return out;
    }

    function boundedTileSize(slotWidth, aspect, borderWidth) {
        aspect = aspect > 0 ? aspect : FALLBACK_ASPECT;
        borderWidth = Math.max(0, borderWidth || 0);
        var maxInnerEdge = Math.max(1, MAX_TILE_EDGE - 2 * borderWidth);
        var slotInnerWidth = Math.max(1, slotWidth - 2 * borderWidth);
        var innerWidth = Math.min(slotInnerWidth, maxInnerEdge, maxInnerEdge * aspect);
        return {
            width: innerWidth + 2 * borderWidth,
            height: innerWidth / aspect + 2 * borderWidth
        };
    }

    function layout(grid) {
        var cs = window.getComputedStyle(grid);
        var cols = Math.max(1, Math.round(cssNum(cs, '--ss-cols', DEFAULT_COLS)));
        var gap = Math.max(0, cssNum(cs, '--ss-gap', DEFAULT_GAP));
        var bw = Math.max(0, cssNum(cs, '--scroll-border-width', 0));
        var padL = cssNum(cs, 'padding-left', 0);
        var padR = cssNum(cs, 'padding-right', 0);

        // getBoundingClientRect, never clientWidth: clientWidth is integer-rounded
        // and a half-pixel short reads as a 1px overflow on the last column.
        var W = grid.getBoundingClientRect().width - padL - padR;
        if (!(W > 0)) return false;

        var items = readItems(grid);
        if (!items.length) { grid.style.height = '0px'; return true; }

        var colW = (W - gap * (cols - 1)) / cols;
        if (!(colW > 0)) return false;

        var colH = new Array(cols), c;
        for (c = 0; c < cols; c++) colH[c] = 0;

        var i, anyProvisional = false;
        for (i = 0; i < items.length; i++) {
            var it = items[i];
            if (it.provisional) anyProvisional = true;

            // shortest column wins; ties go to the LEFTMOST so the first row fills
            // left-to-right in feed order rather than by floating-point accident.
            var best = 0;
            for (c = 1; c < cols; c++) if (colH[c] < colH[best] - 1e-6) best = c;

            var top = colH[best] === 0 ? 0 : colH[best] + gap;
            // Preserve native aspect without enlarging the 900px derivative.
            // Extreme portraits become narrower than their column slot and are
            // centred inside it instead of growing beyond 900px in height.
            var bounded = boundedTileSize(colW, it.aspect, bw);
            var tileW = bounded.width;
            var h = bounded.height;

            var st = it.el.style;
            st.position = 'absolute';
            st.boxSizing = 'border-box';
            st.margin = '0';
            st.left = (padL + best * (colW + gap) + (colW - tileW) / 2) + 'px';
            st.top = top + 'px';
            st.width = tileW + 'px';
            st.height = h + 'px';
            st.overflow = 'hidden';

            if (it.img) {
                // The tile's inner box IS the photo's aspect, so the image fills it
                // exactly: no cover box, no crop, no object-fit dependency.
                var ist = it.img.style;
                ist.display = 'block';
                ist.width = '100%';
                ist.height = '100%';
                ist.maxWidth = 'none';    // a global img{max-width:100%} would shrink it
                ist.maxHeight = 'none';
            }

            colH[best] = top + h;
        }

        var tallest = 0;
        for (c = 0; c < cols; c++) if (colH[c] > tallest) tallest = colH[c];

        grid.style.position = 'relative';
        grid.style.display = 'block';
        grid.style.height = tallest + 'px';

        grid.dataset.ssCols = String(cols);
        if (anyProvisional) grid.dataset.ssProvisional = '1';
        else if (grid.dataset.ssProvisional) delete grid.dataset.ssProvisional;

        try {
            grid.dispatchEvent(new CustomEvent('ss:columns-layout', {
                bubbles: true,
                detail: { cols: cols, colW: colW, height: tallest, n: items.length }
            }));
        } catch (e) { /* older browsers */ }

        return true;
    }

    function init(grid) {
        if (!grid || grid.__ssColumns) return;
        grid.__ssColumns = true;

        var pending = null;
        function run() {
            if (pending) { clearTimeout(pending); pending = null; }
            try { layout(grid); }
            catch (e) { if (window.console && console.warn) console.warn('[ss-columns]', e); }
        }
        function debounced() {
            if (pending) clearTimeout(pending);
            pending = setTimeout(run, 80);
        }
        grid.__ssColumnsRun = run;

        run();
        // Fonts and scrollbars can settle after first paint and change the width.
        setTimeout(run, 50);
        setTimeout(run, 300);

        if (typeof ResizeObserver !== 'undefined') {
            var ro = new ResizeObserver(debounced);
            ro.observe(grid);
        } else {
            window.addEventListener('resize', debounced, { passive: true });
        }

        // A photo whose dimensions were missing from the markup was laid out at the
        // 3:2 fallback. Once its file actually loads we know the truth, so promote
        // the real shape onto the element and relayout ONCE.
        if (grid.dataset.ssProvisional === '1') {
            var imgs = grid.querySelectorAll('.ss-masonry-item img'), z;
            for (z = 0; z < imgs.length; z++) {
                imgs[z].addEventListener('load', function () {
                    var im = this;
                    if (im.naturalWidth > 1 && im.naturalHeight > 1
                        && !im.getAttribute('data-w')) {
                        im.setAttribute('data-w', String(im.naturalWidth));
                        im.setAttribute('data-h', String(im.naturalHeight));
                        debounced();
                    }
                }, { once: true });
            }
        }
    }

    function initAll(root) {
        var scope = root || document;
        var grids = scope.querySelectorAll('.ss-masonry'), i;
        for (i = 0; i < grids.length; i++) init(grids[i]);
    }

    /** Relayout after items are added or removed from an already-initialised
     *  container (an infinite-scroll append, a filter). O(n) - just call it. */
    function relayout(grid) {
        if (!grid) {
            var all = document.querySelectorAll('.ss-masonry'), i;
            for (i = 0; i < all.length; i++) relayout(all[i]);
            return;
        }
        if (grid.__ssColumnsRun) grid.__ssColumnsRun();
        else init(grid);
    }

    /* ---------- INFINITE SCROLL ----------------------------------------------
     * The wall is paged: the skin renders page 0 into .ss-masonry plus a
     * .scroll-wall-sentinel carrying data-next / data-cutoff / data-has-more.
     * As the sentinel nears the viewport we fetch the next page as JSON from the
     * same URL (?pg=wall&format=json&c=N&t=cutoff), append its tiles into the
     * EXISTING grid, hand them to the lazy loader, and relayout. Because the
     * column placement is deterministic (item i -> shortest column at step i),
     * re-laying out with the new tiles appended leaves every earlier tile in the
     * exact same spot — no reflow, no jump — it just extends the columns. Columns
     * have no ragged join, so appended pages flow in seamlessly. */
    function initInfiniteScroll() {
        var sentinel = document.querySelector('.scroll-wall-sentinel');
        var grid = document.querySelector('.ss-masonry');
        if (!sentinel || !grid) return;

        var loading = false, fails = 0, MAX_FAILS = 4;

        function exhausted() { return sentinel.getAttribute('data-has-more') !== '1'; }
        function stop() { sentinel.setAttribute('data-has-more', '0'); if (io) io.disconnect(); }

        function loadNext() {
            if (loading || exhausted()) return;
            loading = true;
            var next = parseInt(sentinel.getAttribute('data-next'), 10) || 1;
            var url = '?pg=wall&format=json&c=' + next
                    + '&t=' + encodeURIComponent(sentinel.getAttribute('data-cutoff') || '');
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data || !data.html) { stop(); return; }
                    fails = 0;
                    var tmp = document.createElement('div');
                    tmp.innerHTML = data.html;
                    var added = [], el;
                    while ((el = tmp.firstElementChild)) { grid.appendChild(el); added.push(el); }
                    sentinel.setAttribute('data-next', String(data.next));
                    sentinel.setAttribute('data-has-more', data.has_more ? '1' : '0');
                    // Hand the new tiles to the shared lazy loader, then relayout.
                    if (window.ssLazyScan) window.ssLazyScan(grid);
                    relayout(grid);
                    if (exhausted() && io) io.disconnect();
                })
                .catch(function () {
                    // A non-JSON response (another skin answering ?pg=wall) would
                    // fail identically and retry forever — give up after a few.
                    if (++fails >= MAX_FAILS) stop();
                })
                .then(function () { loading = false; });
        }

        var io = null;
        if (typeof IntersectionObserver !== 'undefined') {
            io = new IntersectionObserver(function (entries) {
                var i;
                for (i = 0; i < entries.length; i++) if (entries[i].isIntersecting) { loadNext(); break; }
            }, { rootMargin: '1200px 0px' });   // ~a screen of lead before the seam
            io.observe(sentinel);
        } else {
            window.addEventListener('scroll', function () {
                if (window.innerHeight + window.scrollY >= document.body.scrollHeight - 1200) loadNext();
            }, { passive: true });
        }
    }

    window.SSColumns = {
        init: init,
        initAll: initAll,
        relayout: relayout,
        layout: layout,
        boundedTileSize: boundedTileSize
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAll(); initInfiniteScroll(); });
    } else {
        initAll();
        initInfiniteScroll();
    }
    // Late webfont swaps change nothing about tile geometry, but a late scrollbar
    // does. One more pass on full load costs a millisecond.
    window.addEventListener('load', function () {
        var all = document.querySelectorAll('.ss-masonry'), i;
        for (i = 0; i < all.length; i++) relayout(all[i]);
    });
}());
// ===== SNAPSMACK EOF =====
