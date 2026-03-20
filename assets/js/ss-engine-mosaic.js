/**
 * SNAPSMACK — Mosaic Layout Engine
 * Alpha v0.7.5
 *
 * Renders inline image mosaics from [mosaic:ID] shortcodes.
 * Takes a set of images and packs them into rows that form a clean
 * rectangular block, respecting aspect ratios so nothing gets cropped.
 *
 * The algorithm is row-based: for each row, images are scaled to a
 * common height so their combined width (plus gaps) equals the
 * container width. This produces a Jetpack-style tiled gallery.
 */

(function () {
    'use strict';

    // --- CONFIGURATION ---
    var TARGET_ROW_HEIGHT = 260;  // Ideal row height in pixels
    var MIN_ROW_HEIGHT    = 160;  // Don't squish rows below this
    var MAX_ROW_HEIGHT    = 400;  // Don't stretch rows above this

    /**
     * Compute the mosaic layout for a set of images.
     *
     * @param {Array} images  - [{src, width, height, alt, id}, ...]
     * @param {number} containerWidth - Available width in pixels
     * @param {number} gap - Gap between images in pixels
     * @returns {Array} rows - Each row is an array of {src, alt, id, renderWidth, renderHeight}
     */
    function computeLayout(images, containerWidth, gap) {
        if (!images || images.length === 0) return [];

        var rows = [];
        var currentRow = [];
        var currentAR = 0; // Sum of aspect ratios in current row

        for (var i = 0; i < images.length; i++) {
            var img = images[i];
            var ar = (img.width && img.height) ? img.width / img.height : 1.5;

            currentRow.push({ src: img.src, alt: img.alt || '', id: img.id, ar: ar });
            currentAR += ar;

            // Calculate what the row height would be if we finalize this row
            var totalGap = (currentRow.length - 1) * gap;
            var rowHeight = (containerWidth - totalGap) / currentAR;

            // If the row height is at or below target, finalize this row
            if (rowHeight <= TARGET_ROW_HEIGHT) {
                // Clamp row height
                rowHeight = Math.max(MIN_ROW_HEIGHT, rowHeight);
                rows.push(finalizeRow(currentRow, rowHeight, containerWidth, gap));
                currentRow = [];
                currentAR = 0;
            }
        }

        // Handle remaining images that didn't fill a complete row
        if (currentRow.length > 0) {
            var totalGap = (currentRow.length - 1) * gap;
            var rowHeight = (containerWidth - totalGap) / currentAR;

            // For the last row, don't stretch beyond max
            rowHeight = Math.min(MAX_ROW_HEIGHT, rowHeight);
            rowHeight = Math.max(MIN_ROW_HEIGHT, rowHeight);

            // If only 1 image in last row, cap its width at 60% of container
            if (currentRow.length === 1) {
                var singleAR = currentRow[0].ar;
                var naturalWidth = singleAR * rowHeight;
                if (naturalWidth > containerWidth * 0.6) {
                    rowHeight = (containerWidth * 0.6) / singleAR;
                }
            }

            rows.push(finalizeRow(currentRow, rowHeight, containerWidth, gap, true));
        }

        return rows;
    }

    /**
     * Assign render dimensions to each image in a row.
     */
    function finalizeRow(rowImages, rowHeight, containerWidth, gap, isLastRow) {
        var totalGap = (rowImages.length - 1) * gap;
        var result = [];
        var usedWidth = 0;

        for (var i = 0; i < rowImages.length; i++) {
            var img = rowImages[i];
            var renderWidth = Math.round(img.ar * rowHeight);

            // Last image in a non-last row gets the remaining width to avoid rounding gaps
            if (i === rowImages.length - 1 && !isLastRow) {
                renderWidth = containerWidth - usedWidth - totalGap + (rowImages.length - 1) * gap - (usedWidth > 0 ? 0 : 0);
                // Simpler: just use remaining space
                renderWidth = containerWidth - usedWidth - (rowImages.length - 1) * gap;
            }

            result.push({
                src: img.src,
                alt: img.alt,
                id: img.id,
                renderWidth: renderWidth,
                renderHeight: Math.round(rowHeight)
            });

            usedWidth += renderWidth;
        }

        return { images: result, height: Math.round(rowHeight), isLastRow: !!isLastRow };
    }

    /**
     * Render a mosaic into a container element.
     */
    function renderMosaic(container) {
        var dataAttr = container.getAttribute('data-mosaic');
        if (!dataAttr) return;

        var data;
        try {
            data = JSON.parse(dataAttr);
        } catch (e) {
            console.error('Mosaic: invalid JSON data', e);
            return;
        }

        var gap = parseInt(container.getAttribute('data-gap') || '4', 10);
        var containerWidth = container.offsetWidth;
        if (containerWidth <= 0) return;

        var rows = computeLayout(data, containerWidth, gap);

        // Build HTML
        var html = '';
        for (var r = 0; r < rows.length; r++) {
            var row = rows[r];
            html += '<div class="mosaic-row" style="display:flex;gap:' + gap + 'px;' +
                    (r < rows.length - 1 ? 'margin-bottom:' + gap + 'px;' : '') +
                    (row.isLastRow ? 'justify-content:flex-start;' : '') +
                    '">';

            for (var c = 0; c < row.images.length; c++) {
                var img = row.images[c];
                html += '<div class="mosaic-item" style="width:' + img.renderWidth + 'px;height:' + row.height + 'px;overflow:hidden;flex-shrink:0;">';
                html += '<img src="' + img.src + '" alt="' + img.alt + '"' +
                        ' loading="lazy"' +
                        ' data-asset-id="' + (img.id || '') + '"' +
                        ' data-lightbox-src="' + img.src + '"' +
                        ' style="width:100%;height:100%;object-fit:cover;cursor:zoom-in;display:block;">';
                html += '</div>';
            }

            html += '</div>';
        }

        container.innerHTML = html;
    }

    /**
     * Initialize all mosaics on the page.
     */
    function initMosaics() {
        var containers = document.querySelectorAll('.snap-mosaic[data-mosaic]');
        if (containers.length === 0) return;

        containers.forEach(function (el) {
            renderMosaic(el);
        });

        // Re-render on resize (debounced)
        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                containers.forEach(function (el) {
                    renderMosaic(el);
                });
            }, 200);
        });
    }

    // Expose for admin preview use
    window.SnapMosaic = {
        computeLayout: computeLayout,
        renderMosaic: renderMosaic,
        init: initMosaics
    };

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMosaics);
    } else {
        initMosaics();
    }
})();
