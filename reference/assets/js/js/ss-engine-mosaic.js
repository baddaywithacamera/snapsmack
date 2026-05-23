/**
 * SNAPSMACK — Mosaic Layout Engine
 *
 * Renders inline image mosaics from [mosaic:ID] shortcodes.
 * Takes a set of images and packs them into rows that form a clean
 * rectangular block, respecting aspect ratios so nothing gets cropped.
 *
 * The algorithm is row-based: for each row, images are scaled to a
 * common height so their combined width (plus gaps) equals the
 * container width. This produces a Jetpack-style tiled gallery.
 *
 * Also used by smack-mosaics.php for the admin live preview.
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
     * @param {Array}  images         - [{src, width, height, alt, id}, ...]
     * @param {number} containerWidth - Available width in pixels
     * @param {number} gap            - Gap between images in pixels
     * @returns {Array} rows - Each row is an object {images, height, isLastRow}
     */
    function computeLayout(images, containerWidth, gap) {
        if (!images || images.length === 0) return [];

        var rows       = [];
        var currentRow = [];
        var currentAR  = 0; // sum of aspect ratios in current row

        for (var i = 0; i < images.length; i++) {
            var img = images[i];
            var ar  = (img.width && img.height) ? img.width / img.height : 1.5;

            currentRow.push({ src: img.src, alt: img.alt || '', id: img.id, ar: ar });
            currentAR += ar;

            // What would row height be if we closed the row here?
            var totalGap  = (currentRow.length - 1) * gap;
            var rowHeight = (containerWidth - totalGap) / currentAR;

            if (rowHeight <= TARGET_ROW_HEIGHT) {
                rowHeight = Math.max(MIN_ROW_HEIGHT, rowHeight);
                rows.push(finalizeRow(currentRow, rowHeight, containerWidth, gap, false));
                currentRow = [];
                currentAR  = 0;
            }
        }

        // Leftover images that didn't fill a complete row
        if (currentRow.length > 0) {
            var totalGap  = (currentRow.length - 1) * gap;
            var rowHeight = (containerWidth - totalGap) / currentAR;
            rowHeight = Math.min(MAX_ROW_HEIGHT, Math.max(MIN_ROW_HEIGHT, rowHeight));

            // Single image in last row: cap width at 60% of container
            if (currentRow.length === 1) {
                var singleAR     = currentRow[0].ar;
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
     * Assign pixel dimensions to each image in a row.
     */
    function finalizeRow(rowImages, rowHeight, containerWidth, gap, isLastRow) {
        var totalGap = (rowImages.length - 1) * gap;
        var result   = [];
        var usedWidth = 0;

        for (var i = 0; i < rowImages.length; i++) {
            var img         = rowImages[i];
            var renderWidth = Math.round(img.ar * rowHeight);

            // Last image in a non-last row absorbs any rounding remainder
            if (i === rowImages.length - 1 && !isLastRow) {
                renderWidth = containerWidth - usedWidth - totalGap + ((rowImages.length - 1) * gap);
                // Simpler equivalent: remaining space
                renderWidth = containerWidth - usedWidth - (rowImages.length - 1 - i) * gap;
            }

            result.push({
                src:          img.src,
                alt:          img.alt,
                id:           img.id,
                renderWidth:  renderWidth,
                renderHeight: Math.round(rowHeight)
            });

            usedWidth += renderWidth;
        }

        return { images: result, height: Math.round(rowHeight), isLastRow: !!isLastRow };
    }

    /**
     * Render a mosaic into a container element.
     * The element must have data-mosaic (JSON image array) and optionally data-gap.
     */
    function renderMosaic(container) {
        var dataAttr = container.getAttribute('data-mosaic');
        if (!dataAttr) return;

        var data;
        try {
            data = JSON.parse(dataAttr);
        } catch (e) {
            console.error('SnapMosaic: invalid JSON in data-mosaic', e);
            return;
        }

        var gap            = parseInt(container.getAttribute('data-gap') || '4', 10);
        var containerWidth = container.offsetWidth;
        if (containerWidth <= 0) return;

        var rows = computeLayout(data, containerWidth, gap);

        var html = '';
        for (var r = 0; r < rows.length; r++) {
            var row       = rows[r];
            var mbStyle   = r < rows.length - 1 ? 'margin-bottom:' + gap + 'px;' : '';
            var justStyle = row.isLastRow ? 'justify-content:flex-start;' : '';

            html += '<div class="mosaic-row" style="display:flex;gap:' + gap + 'px;' + mbStyle + justStyle + '">';

            for (var c = 0; c < row.images.length; c++) {
                var img = row.images[c];
                html += '<div class="mosaic-item" style="width:' + img.renderWidth + 'px;height:' + row.height + 'px;">';
                html += '<img src="' + img.src + '"'
                      + ' alt="' + img.alt + '"'
                      + ' loading="lazy"'
                      + ' data-asset-id="' + (img.id || '') + '"'
                      + ' data-lightbox-src="' + img.src + '"'
                      + ' style="width:100%;height:100%;object-fit:cover;cursor:zoom-in;display:block;">';
                html += '</div>';
            }

            html += '</div>';
        }

        container.innerHTML = html;
    }

    /**
     * Find and render all .snap-mosaic[data-mosaic] containers on the page.
     */
    function initMosaics() {
        var containers = document.querySelectorAll('.snap-mosaic[data-mosaic]');
        if (containers.length === 0) return;

        containers.forEach(function (el) {
            renderMosaic(el);
        });

        // Re-render on resize (debounced 200 ms)
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

    // --- PUBLIC API (used by smack-mosaics.php admin preview) ---
    window.SnapMosaic = {
        computeLayout: computeLayout,
        renderMosaic:  renderMosaic,
        init:          initMosaics
    };

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMosaics);
    } else {
        initMosaics();
    }

}());
