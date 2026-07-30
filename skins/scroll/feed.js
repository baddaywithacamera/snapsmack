(function () {
    'use strict';

    var grid = document.getElementById('scroll-justified-grid');
    var sentinel = document.getElementById('scroll-feed-sentinel');
    if (!grid || !sentinel || !('IntersectionObserver' in window)) return;

    var loading = false;

    function classifyUnknownPortraits(scope) {
        scope.querySelectorAll(
            '.scroll-feature-item[data-scroll-orientation="unknown"] img'
        ).forEach(function (image) {
            function classify() {
                if (!image.naturalWidth || !image.naturalHeight) return;
                var item = image.closest('.scroll-feature-item');
                if (!item) return;
                item.classList.toggle(
                    'scroll-feature-portrait',
                    image.naturalWidth / image.naturalHeight < 0.82
                );
                item.dataset.scrollOrientation = 'measured';
            }

            if (image.complete) classify();
            else image.addEventListener('load', classify, { once: true });
        });
    }

    classifyUnknownPortraits(grid);
    var observer = new IntersectionObserver(function (entries) {
        if (!entries[0].isIntersecting || loading) return;

        var page = parseInt(sentinel.dataset.nextPage || '0', 10);
        if (!page) {
            observer.disconnect();
            return;
        }

        loading = true;
        var url = new URL(window.location.href);
        url.searchParams.set('scroll_page', String(page));

        fetch(url.toString(), { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var nextGrid = doc.getElementById('scroll-justified-grid');
                var nextSentinel = doc.getElementById('scroll-feed-sentinel');
                if (!nextGrid || !nextSentinel) throw new Error('SCROLL feed fragment missing');

                Array.from(nextGrid.children).forEach(function (node) {
                    grid.appendChild(document.importNode(node, true));
                });
                classifyUnknownPortraits(grid);
                sentinel.dataset.nextPage = nextSentinel.dataset.nextPage || '0';

                document.dispatchEvent(new CustomEvent('snapsmack:grid-appended', {
                    detail: { grid: grid, page: page }
                }));

                if (sentinel.dataset.nextPage === '0') observer.disconnect();
            })
            .catch(function (error) {
                console.warn('SCROLL: could not load the next page', error);
            })
            .finally(function () {
                loading = false;
            });
    }, { rootMargin: '900px 0px' });

    observer.observe(sentinel);
}());
