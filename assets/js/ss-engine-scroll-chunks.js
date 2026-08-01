/**
 * SNAPSMACK - SCROLL Chunked Wall Fetcher
 *
 * This is a CHUNK FETCHER, not a lazy loader. Lazy loading is done by the
 * shared assets/js/ss-engine-lazyload.js, which this file calls into via
 * window.ssLazyScan(). Nothing here loads an image.
 *
 * SCROLL's photo wall is paged: skins/scroll/landing.php renders the first
 * chunk of scroll_page_size photographs as one .ss-masonry container, and
 * serves further chunks as JSON from the same file through the generic hook at
 * index.php (?pg=wall&format=json&c=N&t=<cutoff>). Each chunk is its OWN
 * container, packed by ss-engine-masonry.js as its own complete, independent
 * asymmetric mosaic.
 *
 * Chunk containers are appended as FLAT SIBLINGS, before the sentinel. They are
 * never nested: the masonry engine harvests tiles with a descendant selector, so
 * a nested container would have its items claimed by the outer one too.
 *
 * Appending a chunk cannot re-solve an earlier chunk. Plan state lives on the
 * element (__ssPlan / __ssSig / __ssPlanW), SSMasonry.init() returns immediately
 * for any container already initialised, and a new block-level sibling below an
 * earlier container changes neither its width nor its height. (The skin pins
 * html{overflow-y:scroll} so a document scrollbar can never appear mid-scroll
 * and shift widths.)
 *
 * Externalised per the manifest-only JS policy (secaudit 025 Finding S1) — the
 * skin ships zero inline JS. Registered as 'smack-scroll-chunks'.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    var wall     = document.querySelector('.scroll-wall');
    // The chunk whose waterline remainder is still waiting for somewhere to go.
    // Seeded with the server-rendered first chunk.
    var pendingFrom = document.querySelector('.ss-masonry');
    var sentinel = document.getElementById('scroll-wall-sentinel');
    if (!wall || !sentinel) return;          // no-op on every other page and skin

    var loading = false;
    var io = null;
    var fails = 0;
    var MAX_FAILS = 4;

    function exhausted() { return sentinel.dataset.hasMore === '0'; }

    function stop() {
        sentinel.dataset.hasMore = '0';
        if (io) io.disconnect();
    }

    function loadNext() {
        if (loading || exhausted()) return;
        loading = true;

        var next = parseInt(sentinel.dataset.next, 10) || 1;
        var url  = '?pg=wall&format=json&c=' + next
                 + '&t=' + encodeURIComponent(sentinel.dataset.cutoff || '');

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.html) { stop(); return; }
                fails = 0;

                var tmp = document.createElement('div');
                var added = [];
                var el;
                tmp.innerHTML = data.html;
                while ((el = tmp.firstElementChild)) {
                    wall.insertBefore(el, sentinel);      // flat sibling, before sentinel
                    added.push(el);
                }

                sentinel.dataset.next    = String(data.next);
                sentinel.dataset.hasMore = data.has_more ? '1' : '0';

                added.forEach(function (chunkEl) {
                    // 0. WATERLINE HANDOFF. The previous chunk was trimmed at its
                    //    waterline, so the tiles below that line were never rendered.
                    //    They belong at the FRONT of this chunk's queue, not the back,
                    //    so the running order stays roughly intact. Moving the existing
                    //    elements is enough — no refetch, no server round trip.
                    var prev = pendingFrom;
                    if (prev && prev.__ssDeferredEls && prev.__ssDeferredEls.length) {
                        var first = chunkEl.firstChild, k;
                        for (k = 0; k < prev.__ssDeferredEls.length; k++) {
                            var d = prev.__ssDeferredEls[k];
                            d.style.display = '';
                            d.removeAttribute('data-ss-deferred');
                            chunkEl.insertBefore(d, first);
                        }
                        prev.__ssDeferredEls = [];
                    }
                    // Trim this chunk only if another one will follow it; the final
                    // chunk has nowhere to hand a remainder to and keeps its ragged edge.
                    if (data.has_more) chunkEl.setAttribute('data-ss-trim', '');
                    else chunkEl.removeAttribute('data-ss-trim');
                    pendingFrom = chunkEl;

                    // 1. Hand the new subtree to the SHARED lazy loader. Scoped to the
                    //    chunk so it never re-collects or re-observes earlier chunks.
                    if (window.ssLazyScan) window.ssLazyScan(chunkEl);
                    // 2. First solve + observers for this container only. init() is
                    //    idempotent and grid-scoped; it cannot touch an earlier chunk.
                    if (window.SSMasonry && window.SSMasonry.init) window.SSMasonry.init(chunkEl);
                });

                if (exhausted() && io) io.disconnect();
            })
            .catch(function () {
                // A transient network blip should be retried on the next scroll, so
                // has_more is deliberately left alone. But a response that is not JSON
                // at all (e.g. another skin answering ?pg=wall) would fail identically
                // and retry forever, so give up after a few consecutive failures.
                if (++fails >= MAX_FAILS) stop();
            })
            .then(function () { loading = false; });
    }

    if (typeof IntersectionObserver !== 'undefined') {
        // ~one chunk height of lead, so the solve starts about a screen before
        // the visitor reaches the seam.
        io = new IntersectionObserver(function (entries) {
            var i;
            for (i = 0; i < entries.length; i++) {
                if (entries[i].isIntersecting) { loadNext(); break; }
            }
        }, { rootMargin: '1200px 0px' });
        io.observe(sentinel);
    } else {
        window.addEventListener('scroll', function () {
            if (window.innerHeight + window.scrollY >= document.body.scrollHeight - 1200) loadNext();
        }, { passive: true });
    }
}());
// ===== SNAPSMACK EOF =====
