/**
 * SNAPSMACK — SS-SCROLL-WALL: one photo-wall engine for the SCROLL skin, three
 * layouts. (Not to be confused with ss-engine-wall.js, the physics-driven
 * horizontal pan/zoom wall — a different feature entirely.)
 *
 * A single engine the skin loads once. The chosen layout is read from the
 * container's data-wall-layout attribute (set by the skin from a manifest
 * select) and ALL THREE layouts consume the exact same markup: server-rendered
 * .ss-masonry-item tiles carrying data-w / data-h. Nothing here ever reads a
 * live <img>'s naturalWidth — see the LAZY-LOAD CONTRACT below.
 *
 *   columns  — fixed equal columns, each tile at native aspect, dropped into the
 *              shortest column. Portraits read largest (a 2:3 covers 2.25x a 3:2
 *              at equal width). This is the proven ss-engine-columns geometry,
 *              carried over unchanged.
 *   rows     — justified rows: fill a row to the container width and scale it to
 *              a common height. Landscapes read largest (the mirror of columns).
 *   mosaic   — asymmetric editorial quilt. The packing math is NOT reimplemented
 *              here; we call window.SnapMosaic.computeLayout (ss-engine-mosaic.js)
 *              — Codex's compositor stays the single source of truth — and apply
 *              the rectangles it returns to our existing tiles. Its emphasis knob
 *              (natural / balanced / landscape / portrait) is honoured via
 *              data-emphasis.
 *
 * ── LAZY-LOAD CONTRACT (read before touching this file) ──────────────────────
 * ss-engine-lazyload.js auto-upgrades every .ss-masonry-item <img>: it swaps src
 * for a 1x1 GIF and only restores the real photo on scroll. So a not-yet-scrolled
 * tile's naturalWidth is 1. Therefore:
 *   1. Tile SHAPE comes ONLY from data-w / data-h in the markup — NEVER
 *      naturalWidth, never the live <img>. (The one exception: promoting a tile
 *      that shipped with NO dims, after its real file has loaded — guarded below.)
 *   2. We POSITION existing tiles; we never rebuild them (that would drop their
 *      .ss-lazy / data-src state).
 *   3. After an infinite-scroll append we call window.ssLazyScan(newChunk) SCOPED
 *      to the appended subtree — never bare (bare leaks a duplicate observer).
 *   4. We never set image opacity — the loader owns the 0->1 fade.
 * Breaking any of these regresses lazy loading. It has a harness gate; keep it.
 *
 * HOOKS the skin sets on the container (read once per layout):
 *   data-wall-layout   columns | rows | mosaic   (default columns)
 *   data-emphasis      natural | balanced | landscape | portrait  (mosaic only)
 *   --ss-cols          integer, columns across / target tiles per row (default 4)
 *   --ss-gap           px, the only separation                      (default 6)
 *   --scroll-border-width  px, honoured so a border never crops/distorts a tile
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

    /** Photo shapes, read from MARKUP ONLY (data-w/data-h). NEVER naturalWidth:
     *  the lazy loader swaps src for a 1x1 GIF, so naturalWidth is a lie for any
     *  tile still offscreen. This single rule is why the wall survives lazy load. */
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
            out.push({ el: el, img: img, aspect: w / h, provisional: provisional });
        }
        return out;
    }

    function geometry(grid) {
        var cs = window.getComputedStyle(grid);
        var padL = cssNum(cs, 'padding-left', 0);
        var padR = cssNum(cs, 'padding-right', 0);
        return {
            cs: cs,
            cols: Math.max(1, Math.round(cssNum(cs, '--ss-cols', DEFAULT_COLS))),
            gap: Math.max(0, cssNum(cs, '--ss-gap', DEFAULT_GAP)),
            bw: Math.max(0, cssNum(cs, '--scroll-border-width', 0)),
            padL: padL,
            padR: padR,
            // getBoundingClientRect, never clientWidth: clientWidth is integer-
            // rounded and a half-pixel short reads as a 1px overflow on the last
            // column/tile of a row.
            W: grid.getBoundingClientRect().width - padL - padR
        };
    }

    /** Common tile placement. Position the EXISTING element; make its <img> fill
     *  the box exactly (the box already carries the photo's aspect, so no crop,
     *  no object-fit dependency). Opacity is never touched — the lazy loader owns
     *  the fade. */
    function place(it, x, y, w, h) {
        var st = it.el.style;
        st.position = 'absolute';
        st.boxSizing = 'border-box';
        st.margin = '0';
        st.left = x.toFixed(2) + 'px';
        st.top = y.toFixed(2) + 'px';
        st.width = w.toFixed(2) + 'px';
        st.height = h.toFixed(2) + 'px';
        st.overflow = 'hidden';
        if (it.img) {
            var ist = it.img.style;
            ist.display = 'block';
            ist.width = '100%';
            ist.height = '100%';
            ist.maxWidth = 'none';    // a global img{max-width:100%} would shrink it
            ist.maxHeight = 'none';
        }
    }

    // ── COLUMNS ──────────────────────────────────────────────────────────────
    // Proven ss-engine-columns geometry, unchanged: shortest column wins, ties to
    // the leftmost so the first row fills in feed order. Height from the INNER
    // width so a border never crops or distorts. Portraits read largest for free.
    function layoutColumns(grid, items, g) {
        var cols = g.cols, gap = g.gap, bw = g.bw;
        var colW = (g.W - gap * (cols - 1)) / cols;
        if (!(colW > 0)) return false;

        var colH = new Array(cols), c;
        for (c = 0; c < cols; c++) colH[c] = 0;

        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            var best = 0;
            for (c = 1; c < cols; c++) if (colH[c] < colH[best] - 1e-6) best = c;
            var top = colH[best] === 0 ? 0 : colH[best] + gap;
            var innerW = colW - 2 * bw;
            var h = innerW / it.aspect + 2 * bw;
            place(it, g.padL + best * (colW + gap), top, colW, h);
            colH[best] = top + h;
        }
        var tallest = 0;
        for (c = 0; c < cols; c++) if (colH[c] > tallest) tallest = colH[c];
        return tallest;
    }

    // ── ROWS ─────────────────────────────────────────────────────────────────
    // Justified rows, the mirror of columns. Tiles fill left-to-right; when a row
    // reaches the container width it is scaled to a common OUTER height h so it
    // fits exactly. Borders are folded in without distorting: for equal outer
    // height h, a tile's outer width is (h - 2bw)*aspect + 2bw, so a full row of n
    // tiles solves to  h = 2bw + (W - n*2bw - gap*(n-1)) / Σaspect. Landscapes,
    // being wide, dominate row area — so they read largest here.
    function layoutRows(grid, items, g) {
        var gap = g.gap, bw = g.bw, W = g.W;
        if (!(W > 0)) return false;
        // Target row height derived from the columns control so the existing
        // "Columns Across" slider still means something sensible: ~cols landscape
        // tiles per row.
        var targetH = Math.max(80, (W / g.cols) / FALLBACK_ASPECT);

        var y = 0, row = [], sumAsp = 0, i;

        function rowOuterWidthAt(h) {
            // sum of outer widths + gaps for the current row at outer height h
            return (h - 2 * bw) * sumAsp + row.length * 2 * bw + gap * (row.length - 1);
        }
        function solveHeight() {
            // outer height that makes the current row exactly W wide
            return 2 * bw + (W - row.length * 2 * bw - gap * (row.length - 1)) / sumAsp;
        }
        function flush(h) {
            var x = g.padL;
            for (var k = 0; k < row.length; k++) {
                var it = row[k];
                var wid = (h - 2 * bw) * it.aspect + 2 * bw;
                place(it, x, y, wid, h);
                x += wid + gap;
            }
            y += h + gap;
            row = []; sumAsp = 0;
        }

        for (i = 0; i < items.length; i++) {
            row.push(items[i]);
            sumAsp += items[i].aspect;
            if (rowOuterWidthAt(targetH) >= W) flush(solveHeight());
        }
        // Last, partial row: left-justified at target height, never stretched wide.
        if (row.length) flush(Math.min(targetH, solveHeight()));

        return Math.max(0, y - gap);
    }

    // ── MOSAIC (asymmetric) ──────────────────────────────────────────────────
    // Delegate the packing to Codex's compositor — driven EXACTLY as the live
    // mosaic page drives it: one block of MOSAIC_BLOCK photos at a time. That
    // matters because computeLayout numbers its returned tiles WITHIN the block it
    // was given (cell.order is 0..blockLen-1), so we must map each rectangle back
    // to block[cell.order], not to a global index — feeding it all tiles at once
    // and treating cell.order as global scrambles the wall. Blocks stack down the
    // page. Shapes come from data-w/h (NEVER naturalWidth). If the compositor is
    // absent, fall back to columns rather than leaving a blank wall.
    var MOSAIC_BLOCK = 6;   // same block size the live page uses

    function shapeOf(it) {
        var w = parseInt(it.el.getAttribute('data-w'), 10)
            || (it.img ? parseInt(it.img.getAttribute('data-w'), 10) : 0);
        var h = parseInt(it.el.getAttribute('data-h'), 10)
            || (it.img ? parseInt(it.img.getAttribute('data-h'), 10) : 0);
        if (!(w > 0 && h > 0)) { w = 3; h = 2; }
        return { width: w, height: h };
    }

    function layoutMosaic(grid, items, g) {
        if (!(window.SnapMosaic && typeof window.SnapMosaic.computeLayout === 'function')) {
            return layoutColumns(grid, items, g);
        }
        var emphasis = grid.getAttribute('data-emphasis') || 'natural';
        var mgap = Math.max(0, Math.min(20, g.gap));   // mosaic caps its own gap at 20
        var y = 0, start, k;

        for (start = 0; start < items.length; start += MOSAIC_BLOCK) {
            var block = items.slice(start, start + MOSAIC_BLOCK);
            var shapes = block.map(function (it, i) {
                var s = shapeOf(it); s.order = i; return s;   // order WITHIN the block
            });
            var lay = window.SnapMosaic.computeLayout(shapes, g.W, mgap, emphasis);

            if (!lay || !lay.items || !lay.items.length) {
                // Degenerate block — stack it full-width so no photograph is lost.
                for (k = 0; k < block.length; k++) {
                    var s0 = shapeOf(block[k]);
                    var h0 = g.W / (s0.width / s0.height);
                    place(block[k], g.padL, y, g.W, h0);
                    y += h0 + mgap;
                }
                continue;
            }
            for (k = 0; k < lay.items.length; k++) {
                var cell = lay.items[k];
                var it = block[cell.order];               // within-block order -> correct tile
                if (it) place(it, g.padL + cell.x, y + cell.y, cell.width, cell.height);
            }
            y += lay.height + mgap;
        }
        return Math.max(0, y - mgap);
    }

    var LAYOUTS = { columns: layoutColumns, rows: layoutRows, mosaic: layoutMosaic, asymmetric: layoutMosaic };

    function layout(grid) {
        var g = geometry(grid);
        if (!(g.W > 0)) return false;

        var items = readItems(grid);
        if (!items.length) { grid.style.height = '0px'; return true; }

        var mode = (grid.getAttribute('data-wall-layout') || 'columns').toLowerCase();
        var fn = LAYOUTS[mode] || layoutColumns;

        var anyProvisional = false, i;
        for (i = 0; i < items.length; i++) if (items[i].provisional) anyProvisional = true;

        var height = fn(grid, items, g);
        if (height === false) return false;

        grid.style.position = 'relative';
        grid.style.display = 'block';
        grid.style.height = height + 'px';

        grid.dataset.ssWallLayout = mode;
        if (anyProvisional) grid.dataset.ssProvisional = '1';
        else if (grid.dataset.ssProvisional) delete grid.dataset.ssProvisional;

        try {
            grid.dispatchEvent(new CustomEvent('ss:scroll-wall-layout', {
                bubbles: true,
                detail: { layout: mode, width: g.W, height: height, n: items.length }
            }));
        } catch (e) { /* older browsers */ }
        return true;
    }

    function init(grid) {
        if (!grid || grid.__ssScrollWall) return;
        grid.__ssScrollWall = true;

        var pending = null;
        function run() {
            if (pending) { clearTimeout(pending); pending = null; }
            try { layout(grid); }
            catch (e) { if (window.console && console.warn) console.warn('[ss-scroll-wall]', e); }
        }
        function debounced() {
            if (pending) clearTimeout(pending);
            pending = setTimeout(run, 80);
        }
        grid.__ssScrollWallRun = run;

        run();
        // Fonts and scrollbars can settle after first paint and change the width.
        setTimeout(run, 50);
        setTimeout(run, 300);

        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(debounced).observe(grid);
        } else {
            window.addEventListener('resize', debounced, { passive: true });
        }

        // A tile whose dimensions were missing from the markup was laid out at the
        // 3:2 fallback. Once its real file loads we know the truth — promote the
        // real shape onto the element and relayout once. This is the ONE place a
        // live image is read, and only for tiles that shipped with no data-w/h.
        if (grid.dataset.ssProvisional === '1') {
            var imgs = grid.querySelectorAll('.ss-masonry-item img'), z;
            for (z = 0; z < imgs.length; z++) {
                imgs[z].addEventListener('load', function () {
                    var im = this;
                    if (im.naturalWidth > 1 && im.naturalHeight > 1 && !im.getAttribute('data-w')) {
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

    /** Relayout after tiles are added/removed from an initialised container. */
    function relayout(grid) {
        if (!grid) {
            var all = document.querySelectorAll('.ss-scroll-wall'), i;
            for (i = 0; i < all.length; i++) relayout(all[i]);
            return;
        }
        if (grid.__ssScrollWallRun) grid.__ssScrollWallRun();
        else init(grid);
    }

    /* ── INFINITE SCROLL ───────────────────────────────────────────────────────
     * Shared by all three layouts because they share one markup + one feed. The
     * skin renders page 0 into .ss-masonry plus a .scroll-wall-sentinel carrying
     * data-next / data-cutoff / data-has-more. As the sentinel nears the viewport
     * we fetch the next page as HTML tiles (?pg=wall&format=json&c=N&t=cutoff),
     * append them into the EXISTING grid, hand the NEW subtree to the lazy loader
     * (scoped), then relayout. */
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
                    // Collect the NEW tiles so we can hand exactly them to the lazy
                    // loader (scoped) — never a bare, whole-document rescan.
                    var added = [], el;
                    while ((el = tmp.firstElementChild)) { grid.appendChild(el); added.push(el); }
                    sentinel.setAttribute('data-next', String(data.next));
                    sentinel.setAttribute('data-has-more', data.has_more ? '1' : '0');
                    if (window.ssLazyScan) {
                        for (var k = 0; k < added.length; k++) window.ssLazyScan(added[k]);
                    }
                    relayout(grid);
                    if (exhausted() && io) io.disconnect();
                })
                .catch(function () { if (++fails >= MAX_FAILS) stop(); })
                .then(function () { loading = false; });
        }

        var io = null;
        if (typeof IntersectionObserver !== 'undefined') {
            io = new IntersectionObserver(function (entries) {
                var i;
                for (i = 0; i < entries.length; i++) if (entries[i].isIntersecting) { loadNext(); break; }
            }, { rootMargin: '1200px 0px' });
            io.observe(sentinel);
        } else {
            window.addEventListener('scroll', function () {
                if (window.innerHeight + window.scrollY >= document.body.scrollHeight - 1200) loadNext();
            }, { passive: true });
        }
    }

    window.SSScrollWall = { init: init, initAll: initAll, relayout: relayout, layout: layout };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAll(); initInfiniteScroll(); });
    } else {
        initAll();
        initInfiniteScroll();
    }
    // A late scrollbar can change the width after first paint. One more pass on
    // full load costs a millisecond.
    window.addEventListener('load', function () {
        var all = document.querySelectorAll('.ss-scroll-wall'), i;
        for (i = 0; i < all.length; i++) relayout(all[i]);
    });
}());
// ===== SNAPSMACK EOF =====
