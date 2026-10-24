/**
 * SNAPSMACK - Asymmetric Masonry Wall.
 *
 * Dense-packs photographs into a CSS grid without reducing the wall to fixed
 * columns or justified rows. Landscapes span two tracks, panoramas span three,
 * portraits remain narrow, and CSS Grid fills the resulting openings. SCROLL's
 * stylesheet owns the tracks and chrome; this engine only assigns bounded spans
 * and transports later pages into the same composition.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
(function () {
    'use strict';

    var cfg = window.SS_MASONRY_CONFIG || {};
    var portraitRatio = number(cfg.portraitRatio, 0.85);
    var landscapeCols = Math.max(1, Math.round(number(cfg.landscapeCols, 2)));
    var panoCols = Math.max(landscapeCols, Math.round(number(cfg.panoCols, 3)));
    var landscapeMin = number(cfg.landscapeMin, 1.15);
    var portraitMax = number(cfg.portraitMax, 0.87);
    var panoMin = number(cfg.panoMin, 2.2);

    function number(value, fallback) {
        var parsed = parseFloat(value);
        return typeof parsed === 'number' && isFinite(parsed) ? parsed : fallback;
    }

    function tileAspect(item, img) {
        var width = parseInt(item.getAttribute('data-w'), 10)
            || (img ? parseInt(img.getAttribute('data-w'), 10) : 0);
        var height = parseInt(item.getAttribute('data-h'), 10)
            || (img ? parseInt(img.getAttribute('data-h'), 10) : 0);
        if ((!width || !height) && img && img.naturalWidth > 1 && img.naturalHeight > 1) {
            width = img.naturalWidth;
            height = img.naturalHeight;
        }
        return width > 0 && height > 0 ? width / height : 0;
    }

    function firstTrackPx(template) {
        var tracks = String(template || '').split(/\s+/), i;
        for (i = 0; i < tracks.length; i++) {
            var parsed = parseFloat(tracks[i]);
            if (isFinite(parsed) && parsed > 0) return parsed;
        }
        return 0;
    }

    function tileWidth(columns, columnWidth, gap) {
        return columns * columnWidth + (columns - 1) * gap;
    }

    function layout(grid) {
        var style = window.getComputedStyle(grid);
        var columnWidth = firstTrackPx(style.gridTemplateColumns);
        if (!(columnWidth > 0)) return;
        var columnGap = number(style.columnGap, 6);
        var rowGap = number(style.rowGap, 6);
        var rowUnit = Math.max(1, number(style.gridAutoRows, 8));
        var landscapeLong = landscapeCols * columnWidth + (landscapeCols - 1) * columnGap;
        var items = grid.querySelectorAll('.ss-masonry-item'), i;

        for (i = 0; i < items.length; i++) {
            var item = items[i];
            var aspect = tileAspect(item, item.querySelector('img'));
            var columns = 1;
            var height = columnWidth;
            if (aspect >= panoMin) {
                columns = panoCols;
                height = tileWidth(columns, columnWidth, columnGap) / aspect;
            } else if (aspect >= landscapeMin) {
                columns = landscapeCols;
                height = tileWidth(columns, columnWidth, columnGap) / aspect;
            } else if (aspect > 0 && aspect <= portraitMax) {
                height = portraitRatio * landscapeLong;
            }
            item.style.setProperty('--ss-cols', String(columns));
            item.style.setProperty('--ss-rows', String(Math.max(1, Math.round((height + rowGap) / (rowUnit + rowGap)))));
        }
        grid.setAttribute('data-ss-masonry-ready', '1');
    }

    function watchImages(grid, run) {
        var images = grid.querySelectorAll('.ss-masonry-item img'), i;
        for (i = 0; i < images.length; i++) {
            if (images[i].complete && images[i].naturalWidth > 1) continue;
            images[i].addEventListener('load', run, { once: true });
            images[i].addEventListener('error', run, { once: true });
        }
    }

    function init(grid) {
        if (!grid || grid.__ssMasonry) return;
        grid.__ssMasonry = true;
        var timer;
        var run = function () { layout(grid); };
        grid.__ssMasonryRun = run;
        watchImages(grid, run);
        run();
        setTimeout(run, 50);
        setTimeout(run, 300);
        window.addEventListener('resize', function () {
            clearTimeout(timer);
            timer = setTimeout(run, 100);
        });
    }

    function relayout(grid) {
        if (!grid) {
            var all = document.querySelectorAll('.ss-asymmetric-wall'), i;
            for (i = 0; i < all.length; i++) relayout(all[i]);
            return;
        }
        if (!grid.__ssMasonry) init(grid);
        watchImages(grid, grid.__ssMasonryRun);
        grid.__ssMasonryRun();
    }

    function initAll(root) {
        var grids = (root || document).querySelectorAll('.ss-asymmetric-wall'), i;
        for (i = 0; i < grids.length; i++) init(grids[i]);
    }

    function initInfiniteScroll() {
        var sentinel = document.querySelector('.scroll-wall-sentinel');
        var grid = document.querySelector('.ss-asymmetric-wall');
        if (!sentinel || !grid) return;
        var loading = false, failures = 0, maxFailures = 4, observer = null;

        function exhausted() { return sentinel.getAttribute('data-has-more') !== '1'; }
        function stop() {
            sentinel.setAttribute('data-has-more', '0');
            if (observer) observer.disconnect();
        }
        function sentinelStillNear() {
            return sentinel.getBoundingClientRect().top <= window.innerHeight + 1200;
        }
        function loadNext() {
            if (loading || exhausted()) return;
            loading = true;
            var next = parseInt(sentinel.getAttribute('data-next'), 10) || 1;
            var url = new URL(window.location.href);
            ['format', 'pg', 'c', 't', 'verify'].forEach(function (name) { url.searchParams.delete(name); });
            url.searchParams.set('page', String(next));
            fetch(url, { credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.text() : ''; })
                .then(function (html) {
                    if (!html) { stop(); return; }
                    failures = 0;
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var incoming = doc.querySelectorAll('.scroll-wall .ss-masonry-item');
                    if (!incoming.length) { stop(); return; }
                    for (var i = 0; i < incoming.length; i++) grid.appendChild(document.importNode(incoming[i], true));
                    var nextSentinel = doc.querySelector('.scroll-wall-sentinel');
                    if (nextSentinel) {
                        sentinel.setAttribute('data-next', nextSentinel.getAttribute('data-next') || String(next + 1));
                        sentinel.setAttribute('data-has-more', '1');
                    } else stop();
                    if (window.ssLazyScan) window.ssLazyScan(grid);
                    relayout(grid);
                })
                .catch(function () { if (++failures >= maxFailures) stop(); })
                .then(function () {
                    loading = false;
                    if (!exhausted() && sentinelStillNear()) loadNext();
                });
        }

        if (typeof IntersectionObserver !== 'undefined') {
            observer = new IntersectionObserver(function (entries) {
                for (var i = 0; i < entries.length; i++) if (entries[i].isIntersecting) { loadNext(); break; }
            }, { rootMargin: '1200px 0px' });
            observer.observe(sentinel);
        } else {
            window.addEventListener('scroll', function () {
                if (window.innerHeight + window.scrollY >= document.body.scrollHeight - 1200) loadNext();
            }, { passive: true });
        }
    }

    window.SSMasonry = { init: init, initAll: initAll, relayout: relayout, layout: layout };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAll(); initInfiniteScroll(); });
    } else {
        initAll();
        initInfiniteScroll();
    }
    window.addEventListener('load', function () { relayout(); });
}());
// ===== SNAPSMACK EOF =====
