/**
 * SNAPSMACK - SS-ROWS: justified-row photo wall. Pure SnapSmack, no third-party
 * library — a direct adaptation of ss-engine-columns.js, its mirror image.
 *
 * THE DESIGN, in one paragraph. Where the columns engine divides the width into
 * fixed equal COLUMNS and lets each photo's shape decide its height, this engine
 * fills equal-height ROWS across the full width: photographs are placed left to
 * right at a common target height; when the row is full it is scaled to a single
 * height so its right edge lands exactly on the container edge. Every photograph
 * is drawn at its OWN native aspect ratio — nothing is ever cropped.
 *
 * WHY THIS EMPHASISES LANDSCAPES, for free. In a fixed row height a 3:2 landscape
 * is 1.5 x rowH wide while a 2:3 portrait is 0.67 x rowH wide: the landscape
 * occupies 2.25x the width (and area) WITHOUT any special-casing. Landscapes are
 * the rarer shape in a portrait-led feed, so this makes them read as the large
 * pictures — the exact mirror of columns favouring portraits. Do not add a
 * landscape multiplier on top: the ratio IS the emphasis.
 *
 * BORDERS ARE INSIDE THE BOX. For a common OUTER row height h, a tile's outer
 * width is (h - 2*border)*aspect + 2*border, so a full row of n tiles solves to a
 * single height h = 2*border + (W - n*2*border - gap*(n-1)) / Σaspect. A non-zero
 * --scroll-border-width therefore neither crops nor distorts the photograph.
 *
 * HOOKS the skin sets on the container (read once per layout):
 *   --ss-cols   integer, reused as ~landscape tiles per row (default 4)
 *   --ss-gap    px, the ONLY separation                     (default 6)
 * plus padding-left / padding-right, which are honoured. Container class is
 * .ss-scroll-wall (styled by assets/css/ss-engine-scroll-wall.css); tiles are the
 * same .ss-masonry-item markup the columns engine reads.
 *
 * LAZY LOAD: shape is read ONLY from data-w / data-h, NEVER naturalWidth — the
 * lazy loader swaps src for a 1x1 GIF, so naturalWidth is a lie for any offscreen
 * tile. This is carried over verbatim from the columns engine and must not change.
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

    function layout(grid) {
        var cs = window.getComputedStyle(grid);
        var cols = Math.max(1, Math.round(cssNum(cs, '--ss-cols', DEFAULT_COLS)));
        var gap = Math.max(0, cssNum(cs, '--ss-gap', DEFAULT_GAP));
        var bw = Math.max(0, cssNum(cs, '--scroll-border-width', 0));
        var padL = cssNum(cs, 'padding-left', 0);
        var padR = cssNum(cs, 'padding-right', 0);

        // getBoundingClientRect, never clientWidth: clientWidth is integer-rounded
        // and a half-pixel short reads as a 1px overflow on the last tile of a row.
        var W = grid.getBoundingClientRect().width - padL - padR;
        if (!(W > 0)) return false;

        var items = readItems(grid);
        if (!items.length) { grid.style.height = '0px'; return true; }

        // Target row height derived from the columns control so the existing
        // "Columns Across" slider still means something sensible for rows:
        // roughly `cols` landscape (3:2) tiles per row.
        var targetH = Math.max(80, (W / cols) / FALLBACK_ASPECT);

        var y = 0, row = [], sumAsp = 0, i, anyProvisional = false;

        // Place the current row at a single OUTER height h and advance y.
        function flushRow(h) {
            var x = padL, k;
            for (k = 0; k < row.length; k++) {
                var it = row[k];
                // width from the INNER height so a border never crops or distorts.
                var wid = (h - 2 * bw) * it.aspect + 2 * bw;
                var st = it.el.style;
                st.position = 'absolute';
                st.boxSizing = 'border-box';
                st.margin = '0';
                st.left = x.toFixed(2) + 'px';
                st.top = y.toFixed(2) + 'px';
                st.width = wid.toFixed(2) + 'px';
                st.height = h.toFixed(2) + 'px';
                st.overflow = 'hidden';
                if (it.img) {
                    var ist = it.img.style;
                    ist.display = 'block';
                    ist.width = '100%';
                    ist.height = '100%';
                    ist.maxWidth = 'none';   // a global img{max-width:100%} would shrink it
                    ist.maxHeight = 'none';
                }
                x += wid + gap;
            }
            y += h + gap;
            row = []; sumAsp = 0;
        }

        for (i = 0; i < items.length; i++) {
            var it = items[i];
            if (it.provisional) anyProvisional = true;
            row.push(it);
            sumAsp += it.aspect;
            // Row width at the target height; when it reaches W, scale to fit.
            var rowW = (targetH - 2 * bw) * sumAsp + row.length * 2 * bw + gap * (row.length - 1);
            if (rowW >= W) {
                var h = 2 * bw + (W - row.length * 2 * bw - gap * (row.length - 1)) / sumAsp;
                flushRow(h);
            }
        }
        // Last, partial row: left-justified at the target height, never stretched
        // wide across the whole width (that would blow a single photo up huge).
        if (row.length) {
            var hLast = 2 * bw + (W - row.length * 2 * bw - gap * (row.length - 1)) / sumAsp;
            flushRow(Math.min(targetH, hLast));
        }

        var total = y > 0 ? y - gap : 0;

        grid.style.position = 'relative';
        grid.style.display = 'block';
        grid.style.height = total + 'px';

        if (anyProvisional) grid.dataset.ssProvisional = '1';
        else if (grid.dataset.ssProvisional) delete grid.dataset.ssProvisional;

        try {
            grid.dispatchEvent(new CustomEvent('ss:rows-layout', {
                bubbles: true,
                detail: { height: total, n: items.length }
            }));
        } catch (e) { /* older browsers */ }

        return true;
    }

    function init(grid) {
        if (!grid || grid.__ssRows) return;
        grid.__ssRows = true;

        var pending = null;
        function run() {
            if (pending) { clearTimeout(pending); pending = null; }
            try { layout(grid); }
            catch (e) { if (window.console && console.warn) console.warn('[ss-rows]', e); }
        }
        function debounced() {
            if (pending) clearTimeout(pending);
            pending = setTimeout(run, 80);
        }
        grid.__ssRowsRun = run;

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
        var grids = scope.querySelectorAll('.ss-scroll-wall'), i;
        for (i = 0; i < grids.length; i++) init(grids[i]);
    }

    /** Relayout after items are added or removed from an already-initialised
     *  container (an infinite-scroll append). O(n) - just call it. */
    function relayout(grid) {
        if (!grid) {
            var all = document.querySelectorAll('.ss-scroll-wall'), i;
            for (i = 0; i < all.length; i++) relayout(all[i]);
            return;
        }
        if (grid.__ssRowsRun) grid.__ssRowsRun();
        else init(grid);
    }

    /* ---------- INFINITE SCROLL ----------------------------------------------
     * Identical to the columns engine: the skin renders page 0 into .ss-scroll-wall
     * plus a .scroll-wall-sentinel carrying data-next / data-cutoff / data-has-more.
     * As the sentinel nears the viewport we fetch the next page as JSON tiles
     * (?pg=wall&format=json&c=N&t=cutoff — the SAME feed the landing serves), append
     * them into the grid, hand the NEW subtree to the lazy loader (scoped), and
     * relayout. Rows re-flow from the top on each relayout, which is fine at O(n). */
    function initInfiniteScroll() {
        var sentinel = document.querySelector('.scroll-wall-sentinel');
        var grid = document.querySelector('.ss-scroll-wall');
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
                    // Hand ONLY the new tiles to the shared lazy loader (scoped),
                    // never a bare rescan, then relayout.
                    if (window.ssLazyScan) {
                        for (var k = 0; k < added.length; k++) window.ssLazyScan(added[k]);
                    }
                    relayout(grid);
                    if (exhausted() && io) io.disconnect();
                })
                .catch(function () {
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

    window.SSRows = { init: init, initAll: initAll, relayout: relayout, layout: layout };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAll(); initInfiniteScroll(); });
    } else {
        initAll();
        initInfiniteScroll();
    }
    // Late webfont swaps change nothing about tile geometry, but a late scrollbar
    // does. One more pass on full load costs a millisecond.
    window.addEventListener('load', function () {
        var all = document.querySelectorAll('.ss-scroll-wall'), i;
        for (i = 0; i < all.length; i++) relayout(all[i]);
    });
}());
// ===== SNAPSMACK EOF =====
