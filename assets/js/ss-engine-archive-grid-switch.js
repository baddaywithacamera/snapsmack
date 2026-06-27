/**
 * SNAPSMACK - Archive Grid Switch (responder)
 *
 * Shows/hides the two archive grids (#browse-grid = thumbnails, #justified-grid =
 * masonry) in response to the [T]/[M] toggle. The toggle UI itself lives in
 * ss-engine-archive-toggle.js, which sets <html data-archive-layout> and fires
 * `smackarchive:layoutchange`; this engine just reflects that state. Replaces the
 * inline <script> that used to sit at the foot of 50-shades' archive-layout.php
 * (no inline JS in skins — loaded via the skin manifest instead).
 *
 * Self-gating: no-ops on any page without both grids, so it is safe to load
 * site-wide from the manifest.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    function init() {
        var browseGrid    = document.getElementById('browse-grid');
        var justifiedGrid = document.getElementById('justified-grid');
        if (!browseGrid || !justifiedGrid) return;   // not an archive page with both grids

        function applyLayout(layout) {
            if (layout === 'masonry') {
                browseGrid.style.display    = 'none';
                justifiedGrid.style.display = 'block';
            } else { // 'thumbs' or anything else falls through to thumbs
                browseGrid.style.display    = 'grid';
                justifiedGrid.style.display = 'none';
            }
        }

        // Initial state from the html data-attr (set by archive.php from cookie).
        var initial = document.documentElement.getAttribute('data-archive-layout') || 'thumbs';
        applyLayout(initial);

        // React to the in-place toggle from ss-engine-archive-toggle.js.
        document.addEventListener('smackarchive:layoutchange', function (e) {
            applyLayout((e.detail && e.detail.layout) || 'thumbs');
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
// ===== SNAPSMACK EOF =====
