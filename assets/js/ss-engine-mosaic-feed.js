/**
 * SNAPSMACK — Incremental MOSAIC block feed.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

(function () {
    'use strict';

    var feed = document.getElementById('eatmeclaude-feed');
    var sentinel = document.getElementById('eatmeclaude-sentinel');
    var status = document.getElementById('eatmeclaude-status');
    if (!feed || !sentinel || !window.SnapMosaic) return;

    var next = parseInt(sentinel.getAttribute('data-next') || '1', 10);
    var hasMore = sentinel.getAttribute('data-has-more') === '1';
    var cutoff = sentinel.getAttribute('data-cutoff') || '';
    var gap = parseInt(feed.getAttribute('data-gap') || '6', 10);
    var loading = false;
    var observer = null;

    function prepare(block) {
        window.SnapMosaic.renderMosaic(block);
    }

    Array.prototype.forEach.call(feed.querySelectorAll('.snap-mosaic[data-mosaic]'), prepare);

    function stop(message) {
        hasMore = false;
        if (observer) observer.disconnect();
        if (status) status.textContent = message || '';
    }

    function loadNext() {
        if (!hasMore || loading) return;
        loading = true;
        if (status) status.textContent = 'Loading another mosaic…';

        var url = 'eatmeclaude.php?format=json&block=' + encodeURIComponent(next)
            + '&cutoff=' + encodeURIComponent(cutoff);
        fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (data) {
                if (!data.images || !data.images.length) {
                    stop('Every photograph is here.');
                    return;
                }

                var block = document.createElement('div');
                block.className = 'snap-mosaic eatmeclaude-block';
                block.setAttribute('data-mosaic', JSON.stringify(data.images));
                block.setAttribute('data-gap', String(gap));
                feed.appendChild(block);
                prepare(block);

                next = parseInt(data.next, 10);
                hasMore = !!data.has_more;
                if (!hasMore) stop('Every photograph is here.');
                else if (status) status.textContent = '';
            })
            .catch(function () {
                if (status) status.textContent = 'Could not load the next mosaic. Scroll to retry.';
            })
            .then(function () { loading = false; });
    }

    if ('IntersectionObserver' in window) {
        observer = new IntersectionObserver(function (entries) {
            if (entries[0] && entries[0].isIntersecting) loadNext();
        }, { rootMargin: '1000px 0px' });
        observer.observe(sentinel);
    } else {
        window.addEventListener('scroll', function () {
            if (window.innerHeight + window.scrollY >= document.body.scrollHeight - 1000) loadNext();
        }, { passive: true });
    }

    if (!hasMore) stop('Every photograph is here.');

}());
// ===== SNAPSMACK EOF =====
