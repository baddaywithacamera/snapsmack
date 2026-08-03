/**
 * SNAPSMACK — Mosaic Layout Engine
 *
 * Renders inline image mosaics from [mosaic:ID] shortcodes. Unlike a
 * justified-row gallery, this compositor can nest horizontal and vertical
 * groups, allowing a tall photograph to span a stack of smaller photographs.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    var MOBILE_BREAKPOINT = 520;
    var MAX_TILE_DIMENSION = 900;
    var mosaicContainers = [];
    var resizeBound = false;

    function imageAR(image) {
        var width  = Number(image.width) || 0;
        var height = Number(image.height) || 0;
        return width > 0 && height > 0 ? width / height : 1.5;
    }

    function focalValue(value) {
        value = Number(value);
        return Number.isFinite(value) ? Math.max(0, Math.min(100, value)) : 50;
    }

    function leaf(image, order) {
        return { type: 'leaf', image: image, ar: imageAR(image), order: order };
    }

    function group(direction, children) {
        return { type: direction, children: children };
    }

    /*
     * Build one editorial block. Portrait-led groups become a spanning image
     * beside a vertical stack. Landscape-led four-image groups become a true
     * mixed-span quilt: image one crosses two columns, image two crosses two
     * rows, and the remaining pair occupy unequal cells beneath image one.
     */
    function buildSection(images) {
        var cells = images.map(function (image, index) { return leaf(image, index); });

        if (cells.length === 1) return cells[0];
        if (cells.length === 2) return group('horizontal', cells);

        if (cells.length === 3) {
            if (cells[0].ar < 1.15) {
                return group('horizontal', [cells[0], group('vertical', cells.slice(1))]);
            }
            return group('vertical', [cells[0], group('horizontal', cells.slice(1))]);
        }

        if (cells[0].ar < 1.15) {
            return group('horizontal', [cells[0], group('vertical', cells.slice(1))]);
        }

        return group('horizontal', [
            group('vertical', [cells[0], group('horizontal', cells.slice(2, 4))]),
            cells[1]
        ]);
    }

    function sectionSize(remaining) {
        if (remaining <= 4) return remaining;
        if (remaining === 5) return 4;
        if (remaining === 6) return 3;
        if (remaining === 9) return 3;
        return 4;
    }

    /*
     * Every node's height can be expressed as height = a × width + b. Keeping
     * the gap term in b lets nested groups land on exact container boundaries.
     */
    function coefficients(node, gap) {
        if (node.type === 'leaf') return { a: 1 / node.ar, b: 0 };

        var child = node.children.map(function (item) {
            return coefficients(item, gap);
        });
        node._coefficients = child;

        if (node.type === 'vertical') {
            return {
                a: child.reduce(function (sum, value) { return sum + value.a; }, 0),
                b: child.reduce(function (sum, value) { return sum + value.b; }, 0) + gap * (child.length - 1)
            };
        }

        var inverseA = child.reduce(function (sum, value) { return sum + 1 / value.a; }, 0);
        var bOverA   = child.reduce(function (sum, value) { return sum + value.b / value.a; }, 0);
        return {
            a: 1 / inverseA,
            b: (bOverA - gap * (child.length - 1)) / inverseA
        };
    }

    function placeNode(node, x, y, width, gap, output) {
        var own = coefficients(node, gap);
        var height = own.a * width + own.b;

        if (node.type === 'leaf') {
            output.push({
                image: node.image,
                order: node.order,
                x: x,
                y: y,
                width: width,
                height: height
            });
            return height;
        }

        if (node.type === 'vertical') {
            var childY = y;
            node.children.forEach(function (child) {
                var childHeight = placeNode(child, x, childY, width, gap, output);
                childY += childHeight + gap;
            });
            return height;
        }

        var childX = x;
        node.children.forEach(function (child, index) {
            var coeff = node._coefficients[index];
            var childWidth = (height - coeff.b) / coeff.a;
            placeNode(child, childX, y, childWidth, gap, output);
            childX += childWidth + gap;
        });
        return height;
    }

    function tileLimit(image, dimension) {
        var source = Number(image[dimension]) || MAX_TILE_DIMENSION;
        return Math.max(1, Math.min(MAX_TILE_DIMENSION, source));
    }

    function sectionFits(tree, width, gap) {
        var trial = [];
        placeNode(tree, 0, 0, width, gap, trial);
        return trial.every(function (tile) {
            return tile.width <= tileLimit(tile.image, 'width') + 0.01 &&
                   tile.height <= tileLimit(tile.image, 'height') + 0.01;
        });
    }

    function fittedSectionWidth(tree, containerWidth, gap) {
        if (sectionFits(tree, containerWidth, gap)) return containerWidth;

        var low = 1;
        var high = containerWidth;
        for (var pass = 0; pass < 24; pass++) {
            var middle = (low + high) / 2;
            if (sectionFits(tree, middle, gap)) low = middle;
            else high = middle;
        }
        return low;
    }

    function computeLayout(images, containerWidth, gap) {
        gap = Math.max(0, Math.min(20, Number(gap) || 0));
        containerWidth = Math.max(0, Number(containerWidth) || 0);
        if (!images || images.length === 0 || containerWidth === 0) {
            return { items: [], sections: [], height: 0 };
        }

        var sections = [];
        var items = [];
        var index = 0;
        var y = 0;

        if (containerWidth <= MOBILE_BREAKPOINT) {
            images.forEach(function (image) {
                var itemWidth = Math.min(containerWidth, tileLimit(image, 'width'), tileLimit(image, 'height') * imageAR(image));
                var height = itemWidth / imageAR(image);
                var itemX = (containerWidth - itemWidth) / 2;
                items.push({ image: image, x: itemX, y: y, width: itemWidth, height: height });
                sections.push({ x: itemX, y: y, width: itemWidth, height: height });
                y += height + gap;
            });
        } else {
            while (index < images.length) {
                var count = sectionSize(images.length - index);
                var tree = buildSection(images.slice(index, index + count));
                var sectionItems = [];
                var sectionWidth = fittedSectionWidth(tree, containerWidth, gap);
                var sectionX = (containerWidth - sectionWidth) / 2;
                var sectionHeight = placeNode(tree, sectionX, y, sectionWidth, gap, sectionItems);
                sectionItems.sort(function (a, b) { return a.order - b.order; });
                Array.prototype.push.apply(items, sectionItems);
                sections.push({ x: sectionX, y: y, width: sectionWidth, height: sectionHeight });
                y += sectionHeight + gap;
                index += count;
            }
        }

        return {
            items: items,
            sections: sections,
            height: Math.max(0, y - gap)
        };
    }

    function renderMosaic(container) {
        if (mosaicContainers.indexOf(container) === -1) mosaicContainers.push(container);
        var dataAttr = container.getAttribute('data-mosaic');
        if (!dataAttr) return;

        var images;
        try {
            images = JSON.parse(dataAttr);
        } catch (error) {
            console.error('SnapMosaic: invalid JSON in data-mosaic', error);
            return;
        }

        var gap = parseInt(container.getAttribute('data-gap') || '4', 10);
        var width = container.clientWidth || container.offsetWidth;
        if (width <= 0) return;

        var layout = computeLayout(images, width, gap);
        var fragment = document.createDocumentFragment();

        layout.items.forEach(function (tile) {
            var image = tile.image;
            var item = document.createElement('div');
            var img = document.createElement('img');
            var media = item;

            item.className = 'mosaic-item';
            item.style.left = tile.x.toFixed(2) + 'px';
            item.style.top = tile.y.toFixed(2) + 'px';
            item.style.width = tile.width.toFixed(2) + 'px';
            item.style.height = tile.height.toFixed(2) + 'px';

            if (image.lazy) {
                img.setAttribute('data-src', image.src);
                img.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
                img.className = 'ss-lazy';
            } else {
                img.src = image.src;
            }
            img.alt = image.alt || '';
            img.loading = 'lazy';
            img.setAttribute('data-asset-id', image.id || '');
            if (image.href) {
                media = document.createElement('a');
                media.className = 'mosaic-link';
                media.href = image.href;
                media.setAttribute('aria-label', image.alt || 'View photograph');
                item.appendChild(media);
            } else {
                img.setAttribute('data-lightbox-src', image.full || image.src);
            }
            img.style.objectPosition = focalValue(image.focusX) + '% ' + focalValue(image.focusY) + '%';
            media.appendChild(img);
            fragment.appendChild(item);
        });

        container.replaceChildren(fragment);
        container.style.height = layout.height.toFixed(2) + 'px';
        if (images.some(function (image) { return !!image.lazy; }) && window.ssLazyScan) {
            window.ssLazyScan(container);
        }
    }

    function initMosaics() {
        var containers = Array.prototype.slice.call(document.querySelectorAll('.snap-mosaic[data-mosaic]'));
        if (containers.length === 0) return;

        containers.forEach(renderMosaic);

        if (resizeBound) return;
        resizeBound = true;
        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                mosaicContainers = mosaicContainers.filter(function (el) { return document.documentElement.contains(el); });
                mosaicContainers.forEach(renderMosaic);
            }, 150);
        });
    }

    window.SnapMosaic = {
        computeLayout: computeLayout,
        renderMosaic: renderMosaic,
        init: initMosaics
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMosaics);
    } else {
        initMosaics();
    }

}());
// ===== SNAPSMACK EOF =====
