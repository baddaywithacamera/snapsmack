/**
 * SNAPSMACK - Justified Grid Engine
 *
 * Wrapper for fjGallery (Flickr's justified gallery library). Only initializes
 * if .justified-grid element exists. Reads row height from window.JUSTIFIED_CONFIG.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function() {
    'use strict';

    var grid = document.querySelector('.justified-grid');
    if (!grid) return;

    if (typeof window.fjGallery === 'undefined') {
        console.error('[JUSTIFIED] fjGallery library not loaded. Check manifest-inventory.php load order.');
        return;
    }

    // --- CONFIGURATION ---
    var config = window.JUSTIFIED_CONFIG || {};
    var targetH = config.targetHeight || 280;
    var gap = config.gap || 4;

    // --- GALLERY INITIALIZATION ---
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

    // --- LAYOUT STABILIZATION ---
    // Trigger recalculation after CSS layout completes
    setTimeout(function() {
        fjGallery(grid, 'resize');
    }, 50);
    setTimeout(function() {
        fjGallery(grid, 'resize');
    }, 300);
})();
// ===== SNAPSMACK EOF =====
