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
 * FEED PAGING (2026-09-11): the same engine now pages LANDING feeds. A skin that
 * used to ship every post's tile emits one batch plus
 *   <div id="<P>-sentinel" data-feed data-next="2" data-base="...">
 * and this fetches ?p=N from the same landing and appends the tiles. For the
 * justified skins the sentinel is #justified-sentinel and whole .justified-row
 * elements are appended into #justified-grid. Six skins sent their entire
 * archive on every visit before this; Sean asked for months whether they did.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    function init() {
        var sentinel = document.querySelector('[id$="-sentinel"][data-base][data-tag], [id$="-sentinel"][data-base][data-feed]');
        if (!sentinel || !window.IntersectionObserver) return;

        var P = sentinel.id.replace(/-sentinel$/, '');
        if (!P) return;

        var isFeed    = sentinel.hasAttribute('data-feed');
        var justified = (P === 'justified');
        var grid = justified ? document.getElementById('justified-grid')
                             : document.querySelector('.' + P + '-grid');
        if (!grid) return;
        var itemSel = justified ? '#justified-grid > .justified-row'
                                : '.' + P + '-grid .' + P + '-tile';

        var loading = false;
        var nextPg  = parseInt(sentinel.dataset.next, 10);
        var tag     = sentinel.dataset.tag;
        var base    = sentinel.dataset.base;

        var obs = new IntersectionObserver(function (entries) {
            if (!entries[0].isIntersecting || loading) return;
            loading = true;

            var url = isFeed
                ? base + (base.indexOf('?') === -1 ? '?' : '&') + 'p=' + nextPg
                : base + '?tag=' + encodeURIComponent(tag) + '&p=' + nextPg;
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = html;

                    // Append the tiles (or justified rows) from the fetched page.
                    var newTiles = tmp.querySelectorAll(itemSel);
                    newTiles.forEach(function (t) { grid.appendChild(t); });
                    // Other engines (border travel, fade-in, lightbox) re-scan on this.
                    document.dispatchEvent(new CustomEvent(P + ':grid-updated'));

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
        }, { rootMargin: isFeed ? '1200px' : '400px' });

        obs.observe(sentinel);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
