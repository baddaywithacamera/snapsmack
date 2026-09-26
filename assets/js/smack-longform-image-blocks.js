/**
 * Visual controls for inline image shortcodes in the longform editor.
 * Prose remains plain text. Only [img:gID|size|align|width] entries become
 * WordPress-style visual blocks, and every edit is written back to the source.
 */
(function () {
    'use strict';

    var ta = document.getElementById('long-content');
    var panel = document.getElementById('long-image-blocks');
    var list = document.getElementById('long-image-block-list');
    if (!ta || !panel || !list) return;

    var base = panel.getAttribute('data-base') || '';
    var timer = null;
    var imageCache = {};
    var rx = /\[img:\s*(g)?\s*(\d+)(?:\s*\|\s*(small|wall|full))?(?:\s*\|\s*(left|center|right))?(?:\s*\|\s*(\d{1,3})(?:%)?)?\s*\]/gi;

    function esc(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function parse() {
        var blocks = [];
        var match;
        rx.lastIndex = 0;
        while ((match = rx.exec(ta.value)) !== null) {
            blocks.push({
                start: match.index,
                end: rx.lastIndex,
                gallery: !!match[1],
                id: parseInt(match[2], 10),
                size: match[3] || 'full',
                align: match[4] || 'center',
                width: Math.max(20, Math.min(100, parseInt(match[5], 10) || 100))
            });
        }
        return blocks;
    }

    function shortcode(block) {
        return '[img:' + (block.gallery ? 'g' : '') + block.id + '|' + block.size + '|' + block.align + '|' + block.width + ']';
    }

    function replace(block, next) {
        ta.value = ta.value.substring(0, block.start) + shortcode(next) + ta.value.substring(block.end);
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function remove(block) {
        ta.value = (ta.value.substring(0, block.start) + ta.value.substring(block.end))
            .replace(/\n{3,}/g, '\n\n');
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function imageUrl(image) {
        var path = image && (image.img_thumb_aspect || image.img_thumb_square || image.img_file);
        return path ? base + String(path).replace(/^\//, '') : '';
    }

    function render() {
        var blocks = parse();
        panel.style.display = blocks.length ? 'block' : 'none';
        list.innerHTML = '';
        if (!blocks.length) return;

        blocks.forEach(function (block, index) {
            var image = block.gallery ? imageCache[block.id] : null;
            var card = document.createElement('article');
            card.style.cssText = 'position:relative;border:1px solid var(--border);background:var(--card-bg,rgba(255,255,255,.02));padding:12px;border-radius:4px;';
            card.innerHTML =
                '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:9px;">' +
                    '<strong style="font-size:11px;">IMAGE ' + (index + 1) + (image && image.img_title ? ' · ' + esc(image.img_title) : ' · #' + block.id) + '</strong>' +
                    '<button type="button" data-remove style="border:0;background:transparent;color:var(--danger,#d66);cursor:pointer;font-size:11px;">REMOVE</button>' +
                '</div>' +
                '<div data-stage style="position:relative;width:100%;min-height:90px;background:var(--input-bg,#111);overflow:hidden;">' +
                    '<div data-visual style="position:relative;width:' + block.width + '%;max-width:100%;margin:' + (block.align === 'left' ? '0 auto 0 0' : block.align === 'right' ? '0 0 0 auto' : '0 auto') + ';">' +
                        (imageUrl(image) ? '<img src="' + esc(imageUrl(image)) + '" alt="" style="display:block;width:100%;height:auto;max-height:260px;object-fit:contain;background:#111;">' : '<div style="height:110px;display:grid;place-items:center;color:var(--dim,#888);font-size:11px;">IMAGE #' + block.id + '</div>') +
                        '<button type="button" data-handle title="Drag to resize" style="position:absolute;right:-8px;top:0;width:16px;height:100%;border:0;border-radius:3px;background:var(--accent,#64d94f);opacity:.88;cursor:ew-resize;touch-action:none;"></button>' +
                    '</div>' +
                '</div>' +
                '<div style="display:grid;grid-template-columns:minmax(150px,1fr) 72px 120px;gap:10px;align-items:center;margin-top:10px;">' +
                    '<input data-width type="range" min="20" max="100" step="5" value="' + block.width + '">' +
                    '<output data-output style="font-size:12px;text-align:right;">' + block.width + '%</output>' +
                    '<select data-align style="width:100%;"><option value="left"' + (block.align === 'left' ? ' selected' : '') + '>Left</option><option value="center"' + (block.align === 'center' ? ' selected' : '') + '>Centre</option><option value="right"' + (block.align === 'right' ? ' selected' : '') + '>Right</option></select>' +
                '</div>';

            var range = card.querySelector('[data-width]');
            var output = card.querySelector('[data-output]');
            var visual = card.querySelector('[data-visual]');
            var stage = card.querySelector('[data-stage]');
            function previewWidth(value) {
                value = Math.max(20, Math.min(100, Math.round(value / 5) * 5));
                range.value = value;
                output.textContent = value + '%';
                visual.style.width = value + '%';
                return value;
            }
            range.addEventListener('input', function () { previewWidth(parseInt(this.value, 10)); });
            range.addEventListener('change', function () {
                replace(block, Object.assign({}, block, { width: parseInt(this.value, 10) }));
            });
            card.querySelector('[data-align]').addEventListener('change', function () {
                replace(block, Object.assign({}, block, { align: this.value }));
            });
            card.querySelector('[data-remove]').addEventListener('click', function () { remove(block); });

            card.querySelector('[data-handle]').addEventListener('pointerdown', function (event) {
                event.preventDefault();
                this.setPointerCapture(event.pointerId);
                var handle = this;
                function move(e) {
                    var rect = stage.getBoundingClientRect();
                    var left = block.align === 'right' ? rect.right - e.clientX : e.clientX - rect.left;
                    previewWidth((left / rect.width) * 100);
                }
                function up() {
                    handle.removeEventListener('pointermove', move);
                    handle.removeEventListener('pointerup', up);
                    handle.removeEventListener('pointercancel', up);
                    replace(block, Object.assign({}, block, { width: parseInt(range.value, 10) }));
                }
                handle.addEventListener('pointermove', move);
                handle.addEventListener('pointerup', up);
                handle.addEventListener('pointercancel', up);
            });
            list.appendChild(card);
        });
    }

    function resolveAndRender() {
        var ids = parse().filter(function (b) { return b.gallery; }).map(function (b) { return b.id; });
        ids = ids.filter(function (id, i) { return ids.indexOf(id) === i && !imageCache[id]; });
        if (!ids.length) { render(); return; }
        fetch('smack-gallery.php?ajax=1&per_page=100&ids=' + encodeURIComponent(ids.join(',')))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                (data.images || []).forEach(function (image) { imageCache[parseInt(image.id, 10)] = image; });
                render();
            })
            .catch(render);
    }

    ta.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(resolveAndRender, 120);
    });
    resolveAndRender();
}());
// ===== SNAPSMACK EOF =====
