/**
 * SnapSmack Engine: Justified Grid
 * Version: 2.0 - fjGallery Edition
 * -------------------------------------------------------------------------
 * Wrapper for Flickr's fjGallery (flickr-justified-gallery).
 * Self-gating: does nothing if .justified-grid is not on the page.
 * Reads window.JUSTIFIED_CONFIG for targetHeight (set by archive.php).
 *
 * DEPENDENCY: fjGallery.min.js must load BEFORE this script.
 *             Register both in manifest-inventory.php and ensure
 *             fjGallery is listed first in require_scripts.
 * -------------------------------------------------------------------------
 */

(function() {
    'use strict';

    var grid = document.querySelector('.justified-grid');
    if (!grid) return;

    if (typeof window.fjGallery === 'undefined') {
        console.error('[JUSTIFIED] fjGallery library not loaded. Check manifest-inventory.php load order.');
        return;
    }

    var config = window.JUSTIFIED_CONFIG || {};
    var targetH = config.targetHeight || 280;
    var gap = config.gap || 4;

    var gallery = fjGallery(grid, {
        itemSelector: '.justified-item',
        imageSelector: 'img',
        rowHeight: targetH,
        gutter: gap,
        rowHeightTolerance: 0.25,
        lastRow: 'left',
        transitionDuration: '0.3s',
        resizeDebounce: 100
    });

    // Fix: container may report 320px at script execution time
    // if skin CSS hasn't finished laying out #scroll-stage yet.
    // Force a recalculation after the browser has completed layout.
    setTimeout(function() {
        fjGallery(grid, 'resize');
    }, 50);
    setTimeout(function() {
        fjGallery(grid, 'resize');
    }, 300);
})();
