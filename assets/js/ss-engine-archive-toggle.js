/**
 * SNAPSMACK - Archive Layout Toggle Engine (0.7.79)
 *
 * Intercepts clicks on the [Thumbs] and [Masonry] segmented buttons in the
 * archive header so they switch layouts in-place (no full-page reload, no
 * visible blip), update the URL via history.pushState, and persist the choice
 * to a cookie + localStorage so the server resolves correctly on the next
 * fresh request.
 *
 * Triggered by: the .archive-layout-toggle controls rendered by archive.php.
 *
 * Hotkeys (when on archive page):
 *   T → Thumbs
 *   M → Masonry
 *   (C is handled by the calendar engine — separate concern.)
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    // Cookie / storage helpers ────────────────────────────────────────────
    function setLayoutCookie(layout) {
        document.cookie = 'smack_archive_layout=' + encodeURIComponent(layout) +
                          '; path=/; max-age=31536000; SameSite=Lax';
        try { localStorage.setItem('smack_archive_layout', layout); } catch (e) {}
    }

    // Apply a layout switch in-place: update body classes, history, persist.
    // Does NOT swap grid markup (the actual photo grid CSS reflows on body
    // class change — square-vs-cropped uses the same DOM, masonry uses a
    // different layout but the grid items are the same elements).
    //
    // For the cleanest switch we'd want to swap the rendered grid template
    // too. As a first cut we just update the URL + cookie + active button
    // state and let the user's *next* navigation pick up the new layout.
    // Visible during this session: button highlights move; clicking another
    // page and coming back uses the new layout via the cookie.
    //
    // For a true in-place visual swap we'd need server-rendered partial HTML
    // for both layouts loaded eagerly OR a fetch+replace. Out of scope for
    // 0.7.79 first pass — current behaviour: instant active-state update,
    // pushState URL change, cookie persisted, full re-render only if user
    // explicitly reloads. That's the bulk of the "no blip" win.
    function applyLayout(layout, pushHistory) {
        if (layout !== 'thumbs' && layout !== 'masonry') return;

        // Update active button visual.
        var btns = document.querySelectorAll('.archive-layout-toggle [data-layout]');
        btns.forEach(function (b) {
            if (b.dataset.layout === layout) {
                b.classList.add('alt-btn--active');
            } else {
                b.classList.remove('alt-btn--active');
            }
        });

        // Update html data-attr (cosmetic — CSS may key off this).
        document.documentElement.setAttribute('data-archive-layout', layout);

        // Persist.
        setLayoutCookie(layout);

        // Update URL via pushState (no reload).
        if (pushHistory) {
            try {
                var url = new URL(window.location.href);
                url.searchParams.set('layout', layout);
                ['q', 'cat', 'album', 'date', 'from', 'to'].forEach(function (k) {
                    url.searchParams.delete(k);
                });
                history.pushState({ smackLayout: layout }, '', url.toString());
            } catch (e) {}
        }

        // Fire a custom event so other engines (calendar, gallery) can
        // react if needed.
        try {
            document.dispatchEvent(new CustomEvent('smackarchive:layoutchange', {
                detail: { layout: layout }
            }));
        } catch (e) {}

        // Reload the grid by fetching fresh HTML server-side.
        // This is the "true visual swap" path: lightweight enough since the
        // archive grid is the only thing changing.
        fetchAndReplaceGrid(layout);
    }

    // Fetch the new layout's HTML, extract the grid container, swap it in.
    // Avoids a full page reload (header, sidebar, calendar panel all stay).
    function fetchAndReplaceGrid(layout) {
        var url = new URL(window.location.href);
        url.searchParams.set('layout', layout);
        url.searchParams.set('_partial', '1');

        var liveGrid = document.querySelector('.archive-grid, .archive-masonry');
        if (!liveGrid) return; // skin doesn't use these classes — give up gracefully

        // Show a subtle in-place loading hint.
        liveGrid.style.opacity = '0.4';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', url.toString(), true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            liveGrid.style.opacity = '';
            if (xhr.status !== 200) return;
            try {
                var doc = new DOMParser().parseFromString(xhr.responseText, 'text/html');
                var newGrid = doc.querySelector('.archive-grid, .archive-masonry');
                if (newGrid && liveGrid.parentNode) {
                    liveGrid.parentNode.replaceChild(newGrid, liveGrid);
                }
            } catch (e) {}
        };
        xhr.send();
    }

    // Wire button clicks ──────────────────────────────────────────────────
    function wireToggle() {
        var btns = document.querySelectorAll('.archive-layout-toggle [data-layout]');
        if (!btns.length) return;

        btns.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                var layout = this.dataset.layout;
                if (layout !== 'thumbs' && layout !== 'masonry') return;
                e.preventDefault();
                applyLayout(layout, true);
            });
        });

        // History back/forward → re-apply layout from URL.
        window.addEventListener('popstate', function () {
            var url = new URL(window.location.href);
            var layout = url.searchParams.get('layout') || 'thumbs';
            applyLayout(layout, false);
        });
    }

    // Hotkeys T / M (only on archive page, only when toggle visible) ──────
    function wireHotkeys() {
        var hasToggle = !!document.querySelector('.archive-layout-toggle');
        if (!hasToggle) return;

        document.addEventListener('keydown', function (e) {
            // Don't fire when typing in inputs / contenteditable.
            var t = e.target;
            if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' ||
                      t.tagName === 'SELECT' || t.isContentEditable)) return;
            if (e.metaKey || e.ctrlKey || e.altKey) return;
            if (e.key === 't' || e.key === 'T') {
                e.preventDefault();
                applyLayout('thumbs', true);
            } else if (e.key === 'm' || e.key === 'M') {
                e.preventDefault();
                applyLayout('masonry', true);
            }
        });
    }

    // Dock the .archive-controls row into the existing filter bar so the
    // [T][M][C] buttons appear inline with [SHOW ALL] / [FILTER] / search
    // (which is where the old skin-specific toggle used to live). Falls
    // back to leaving the controls where archive.php placed them if no
    // known dock target is present.
    function dockControls() {
        var ctrls = document.querySelector('.archive-controls');
        if (!ctrls) return;
        // Try common filter-bar containers in order; first match wins.
        var dock = document.querySelector('#infobox')
                || document.querySelector('.archive-filter-bar')
                || document.querySelector('.archive-toolbar')
                || document.querySelector('.archive-search-row');
        if (dock && dock !== ctrls.parentNode) {
            ctrls.classList.add('archive-controls--docked');
            dock.appendChild(ctrls);
        }
        alignDockedControls(ctrls);
    }

    // Align the docked controls to the right inner edge of the photo grid.
    function alignDockedControls(ctrls) {
        if (!ctrls || !ctrls.classList.contains('archive-controls--docked')) return;
        var grid = document.querySelector('.fsog-archive-grid') || document.querySelector('#justified-grid');
        var infobox = document.getElementById('infobox');
        if (!grid || !infobox) return;
        var infoboxRect = infobox.getBoundingClientRect();
        var gridRect    = grid.getBoundingClientRect();
        var gridStyle   = window.getComputedStyle(grid);
        var padRight    = parseFloat(gridStyle.paddingRight) || 0;
        // Right edge of grid inner content area, measured from right of infobox.
        var offset = infoboxRect.right - (gridRect.right - padRight);
        ctrls.style.right = Math.max(0, offset) + 'px';
    }

    window.addEventListener('resize', function () {
        alignDockedControls(document.querySelector('.archive-controls--docked'));
    });

    document.addEventListener('DOMContentLoaded', function () {
        wireToggle();
        wireHotkeys();
        dockControls();
        // Controls may already be server-rendered inside #infobox (no move needed),
        // so alignDockedControls must also run unconditionally on load.
        alignDockedControls(document.querySelector('.archive-controls--docked'));
    });
}());
// ===== SNAPSMACK EOF =====
