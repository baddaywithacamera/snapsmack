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
    var nextUrl = sentinel.getAttribute('data-next-url') || '';
    var hasMore = sentinel.getAttribute('data-has-more') === '1';
    var cutoff = sentinel.getAttribute('data-cutoff') || '';
    var gap = parseInt(feed.getAttribute('data-gap') || '6', 10);
    var emphasis = feed.getAttribute('data-emphasis') || 'natural';
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

    function sentinelStillNear() {
        return sentinel.getBoundingClientRect().top <= window.innerHeight + 1000;
    }

    function fetchWithTimeout(url) {
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timer = controller ? setTimeout(function () { controller.abort(); }, 15000) : null;
        return fetch(url, {
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            if (timer) clearTimeout(timer);
            return response;
        }, function (error) {
            if (timer) clearTimeout(timer);
            throw error;
        });
    }

    function loadNext() {
        if (!hasMore || loading) return;
        loading = true;
        if (status) status.textContent = 'Loading another mosaic…';

        if (nextUrl) {
            fetchWithTimeout(nextUrl)
                .then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var blocks = doc.querySelectorAll('#eatmeclaude-feed .snap-mosaic[data-mosaic]');
                    if (!blocks.length) {
                        stop('Every photograph is here.');
                        return;
                    }
                    Array.prototype.forEach.call(blocks, function (source) {
                        var block = source.cloneNode(true);
                        feed.appendChild(block);
                        prepare(block);
                    });
                    var nextSentinel = doc.getElementById('eatmeclaude-sentinel');
                    nextUrl = nextSentinel ? (nextSentinel.getAttribute('data-next-url') || '') : '';
                    hasMore = nextUrl !== '';
                    if (!hasMore) stop('Every photograph is here.');
                    else if (status) status.textContent = '';
                })
                .catch(function () {
                    if (status) status.textContent = 'Could not load the next mosaic. Scroll to retry.';
                })
                .then(function () {
                    loading = false;
                    if (hasMore && sentinelStillNear()) loadNext();
                });
            return;
        }

        var url = 'eatmeclaude.php?format=json&block=' + encodeURIComponent(next)
            + '&cutoff=' + encodeURIComponent(cutoff);
        fetchWithTimeout(url)
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
                block.setAttribute('data-emphasis', emphasis);
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
            .then(function () {
                loading = false;
                // IntersectionObserver fires on boundary changes, not after each
                // append. A short block can leave the sentinel continuously inside
                // the 1000px prefetch margin, which previously stalled after exactly
                // one request. Keep filling until it has genuinely moved beyond it.
                if (hasMore && sentinelStillNear()) loadNext();
            });
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
