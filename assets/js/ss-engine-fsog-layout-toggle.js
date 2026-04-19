/**
 * SNAPSMACK - 50 Shades of Noah Grey: Archive Layout Toggle Engine
 *
 * Handles three-way archive toggle: square / cropped / justified.
 * Reads configuration from data attributes on the toggle widget so no
 * PHP values are injected into JS (CLAUDE.md §1 compliance).
 *
 * Required HTML contract (provided by archive-layout.php):
 *   .fsog-layout-toggle[data-key][data-default]  — the toggle widget
 *   .fsog-toggle-btn[data-layout]                — each toggle button
 *   #browse-grid                                 — square grid pane
 *   #browse-grid-cropped                         — cropped grid pane
 *   #justified-grid                              — justified grid pane
 *
 * NOTE: The settings DB stores the justified mode as "masonry"; this engine
 * normalises that to "justified" internally so the data-layout="justified"
 * attributes on the buttons always match.
 */

(function () {
    'use strict';

    var widget = document.querySelector('.fsog-layout-toggle[data-key]');
    if (!widget) return;

    var KEY           = widget.getAttribute('data-key')     || 'fsog_gallery_layout';
    var defaultLayout = widget.getAttribute('data-default') || 'square';

    // Normalise: settings store 'masonry', JS uses 'justified' throughout
    if (defaultLayout === 'masonry') defaultLayout = 'justified';

    var toggleBtns    = document.querySelectorAll('.fsog-toggle-btn');
    var squareGrid    = document.getElementById('browse-grid');
    var croppedGrid   = document.getElementById('browse-grid-cropped');
    var justifiedGrid = document.getElementById('justified-grid');

    function setLayout(layout) {
        // Normalise masonry → justified
        if (layout === 'masonry') layout = 'justified';

        for (var i = 0; i < toggleBtns.length; i++) {
            toggleBtns[i].classList.toggle('active',
                toggleBtns[i].getAttribute('data-layout') === layout);
        }

        // Clear inline display to let CSS class rules take over for the
        // active pane; force display:none for the inactive panes.
        if (squareGrid)    squareGrid.style.display    = layout === 'square'    ? '' : 'none';
        if (croppedGrid)   croppedGrid.style.display   = layout === 'cropped'   ? '' : 'none';
        if (justifiedGrid) justifiedGrid.style.display = layout === 'justified' ? '' : 'none';

        try {
            if (window.snapConsent && window.snapConsent.ok()) {
                localStorage.setItem(KEY, layout);
            }
        } catch (e) {}
    }

    function init() {
        var saved = null;
        try {
            if (window.snapConsent && window.snapConsent.ok()) {
                saved = localStorage.getItem(KEY);
            }
        } catch (e) {}
        setLayout(saved || defaultLayout);
    }

    for (var i = 0; i < toggleBtns.length; i++) {
        toggleBtns[i].addEventListener('click', function () {
            setLayout(this.getAttribute('data-layout'));
        });
    }

    init();
}());
