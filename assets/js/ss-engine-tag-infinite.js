/**
 * SNAPSMACK - Hashtag Infinite Scroll (shared engine)
 *
 * Lazily appends the next page of tag results as the reader nears the bottom of a
 * hashtag page. Replaces the per-skin inline <script> that used to live at the
 * foot of each skin's hashtag.php (kept all JS out of skins per the no-inline-JS
 * architecture rule; loaded via the skin manifest instead).
 *
 * PREFIX-DERIVED (shared lib): works for any Grid-family skin (au-, pa-, tg-, …)
 * with no fork. The sentinel <div id="<P>-sentinel" data-tag data-next data-base>
 * is emitted by hashtag.php only when more pages exist; the prefix P is read from
 * that id, and the grid/tile selectors follow the convention .<P>-grid / .<P>-tile.
 * The engine no-ops on any page without such a sentinel, so it is safe to load
 * site-wide from the manifest.
 *
 * Pure client-side: the server endpoint is the same hashtag page (?tag=&p=N).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    function init() {
        var sentinel = document.querySelector('[id$="-sentinel"][data-base][data-tag]');
        if (!sentinel || !window.IntersectionObserver) return;

        var P = sentinel.id.replace(/-sentinel$/, '');
        if (!P) return;

        var grid = document.querySelector('.' + P + '-grid');
        if (!grid) return;

        var loading = false;
        var nextPg  = parseInt(sentinel.dataset.next, 10);
        var tag     = sentinel.dataset.tag;
        var base    = sentinel.dataset.base;

        var obs = new IntersectionObserver(function (entries) {
            if (!entries[0].isIntersecting || loading) return;
            loading = true;

            var url = base + '?tag=' + encodeURIComponent(tag) + '&p=' + nextPg;
            fetch(url)
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = html;

                    // Append the tiles from the fetched page's grid.
                    var newTiles = tmp.querySelectorAll('.' + P + '-grid .' + P + '-tile');
                    newTiles.forEach(function (t) { grid.appendChild(t); });

                    // Stop once the fetched page no longer carries a sentinel.
                    var newSentinel = tmp.querySelector('#' + P + '-sentinel');
                    if (newSentinel) {
                        nextPg++;
                        loading = false;
                    } else {
                        obs.disconnect();
                        sentinel.remove();
                    }
                })
                .catch(function () { loading = false; });
        }, { rootMargin: '400px' });

        obs.observe(sentinel);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
