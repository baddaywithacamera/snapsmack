(function () {
    'use strict';

    function isEmptyParagraph(node) {
        return node
            && node.nodeType === 1
            && node.matches('p')
            && node.textContent.trim() === ''
            && node.children.length === 0;
    }

    function mosaicData(container) {
        try {
            var value = JSON.parse(container.getAttribute('data-mosaic') || '[]');
            return Array.isArray(value) ? value : [];
        } catch (error) {
            return [];
        }
    }

    document.querySelectorAll('.entry-content > .snap-mosaic[data-mosaic]').forEach(function (first) {
        if (!first.isConnected) return;

        var images = mosaicData(first);
        var cursor = first.nextElementSibling;
        var bridges = [];

        while (cursor) {
            if (isEmptyParagraph(cursor)) {
                bridges.push(cursor);
                cursor = cursor.nextElementSibling;
                continue;
            }
            if (!cursor.matches('.snap-mosaic[data-mosaic]')) break;

            images = images.concat(mosaicData(cursor));
            bridges.forEach(function (bridge) { bridge.remove(); });
            bridges = [];

            var merged = cursor;
            cursor = cursor.nextElementSibling;
            merged.remove();
        }

        first.setAttribute('data-mosaic', JSON.stringify(images));
    });
}());
// ===== SNAPSMACK EOF =====
