/**
 * SNAPSMACK - Photogram Feed Engine
 *
 * Infinite-scroll for the Photogram landing feed grid. Externalised from an
 * inline <script> in skins/photogram/landing.php so the skin ships ZERO inline
 * JS (manifest-only JS policy; secaudit 025 Finding S1).
 *
 * Loaded via the skin manifest (require_scripts: 'smack-photogram-feed') from
 * skin-footer.php, after the grid markup, so the DOM nodes already exist.
 * Watches #pg-grid-sentinel and appends ?format=json&p=N pages into #pg-grid.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';
    var sentinel = document.getElementById('pg-grid-sentinel');
    var grid     = document.getElementById('pg-grid');
    if (!sentinel || !grid) return;

    var loading = false;

    function loadNext() {
        if (loading || sentinel.dataset.hasMore === '0') return;
        loading = true;
        sentinel.className = 'pg-feed-sentinel pg-feed-loading';

        var page = parseInt(sentinel.dataset.page, 10) || 2;
        fetch('?format=json&p=' + page)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = data.html;
                    while (tmp.firstChild) grid.appendChild(tmp.firstChild);
                }
                sentinel.dataset.page     = page + 1;
                sentinel.dataset.hasMore  = data.has_more ? '1' : '0';
                sentinel.className        = data.has_more
                    ? 'pg-feed-sentinel pg-feed-loading'
                    : 'pg-feed-sentinel pg-feed-end';
                loading = false;
            })
            .catch(function () {
                loading = false;
                sentinel.className = 'pg-feed-sentinel';
            });
    }

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting) loadNext();
        }, { rootMargin: '300px' });
        observer.observe(sentinel);
    } else {
        // Fallback: load all at once for older browsers
        loadNext();
    }
}());
// ===== SNAPSMACK EOF =====
