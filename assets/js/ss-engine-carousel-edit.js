/**
 * SnapSmack Carousel Edit Engine
 *
 * Manages the image strip UI on smack-edit-carousel.php.
 * Responsibilities:
 *   - Drag-to-reorder existing images in the strip
 *   - Cover image selection (click badge to promote)
 *   - Remove image from post (marks image_id in remove_image_ids[])
 *   - EXIF panel toggle per image
 *   - Add new images: drop zone collapse/expand, FileReader previews,
 *     deduplication, appending to the strip
 *   - Sync hidden inputs (sort_order[], cover_image_id, remove_image_ids[])
 *     before form submission
 *
 * DOM contract:
 *   #ce-strip                  — the existing-image strip
 *   .ce-strip-item             — each existing image item
 *     data-image-id            — snap_images.id
 *     data-thumb               — URL to img_thumb_square
 *   .ce-cover-badge            — click to promote this image to cover
 *   .ce-remove-btn             — click to remove image from post
 *   .ce-exif-toggle            — click to show/hide EXIF panel
 *   .ce-exif-panel             — EXIF fields container
 *
 *   #ce-add-toggle             — button to reveal the add-more drop zone
 *   #ce-add-zone               — collapsible add-more section
 *   #ce-drop-zone              — drop zone for new images
 *   #ce-file-input             — hidden <input type="file">
 *
 *   #ce-form                   — the main form (submit listener)
 *   input[name="sort_order[]"] — updated before submit
 *   input[name="cover_image_id"] — updated before submit
 *   input[name="remove_image_ids[]"] — added before submit
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    var MAX_FILES  = 20;
    var ACCEPTED   = ['image/jpeg', 'image/png', 'image/webp'];

    // Tracks image IDs removed in this session
    var removedIds    = [];
    // Tracks image IDs split out into their own new post this session
    var splitIds      = [];
    // New files to add (File objects, from drop zone)
    var newFiles      = [];
    // Parallel style objects for new files (same index as newFiles[])
    var newFileStyles = [];

    var strip;
    var form;
    var coverImageId   = 0;
    var customizeLevel = 'per_grid';

    // -------------------------------------------------------------------------
    // INIT
    // -------------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        strip = document.getElementById('ce-strip');
        form  = document.getElementById('ce-form');
        if (!strip || !form) return;

        customizeLevel = form.getAttribute('data-customize-level') || 'per_grid';

        // Read initial cover from data attribute on strip
        var firstItem = strip.querySelector('.ce-strip-item');
        if (firstItem) {
            coverImageId = parseInt(firstItem.getAttribute('data-image-id'), 10) || 0;
        }

        initExistingStrip();
        initAddZone();
        initFormSubmit();
    });

    // -------------------------------------------------------------------------
    // STYLE HELPERS
    // -------------------------------------------------------------------------

    function defaultStyle() {
        return { size_pct: 100, border_px: 0, border_color: '#000000', bg_color: '#ffffff', shadow: 0 };
    }

    function buildNewStylePanel(newIdx) {
        var s = newFileStyles[newIdx] || defaultStyle();
        function opt(val, label, cur) {
            return '<option value="' + val + '"' + (cur == val ? ' selected' : '') + '>' + label + '</option>';
        }
        return '<div class="ce-new-style-panel cp-exif-panel" style="display:none;">' +
            '<div class="cp-exif-grid">' +
                '<div class="lens-input-wrapper">' +
                    '<label>IMAGE SIZE</label>' +
                    '<select class="full-width-select cp-style-input" data-style-field="size_pct">' +
                        opt(100,'100% — edge to edge',s.size_pct)+opt(95,'95%',s.size_pct)+opt(90,'90%',s.size_pct)+
                        opt(85,'85%',s.size_pct)+opt(80,'80%',s.size_pct)+opt(75,'75%',s.size_pct)+
                    '</select></div>' +
                '<div class="lens-input-wrapper">' +
                    '<label>BORDER</label>' +
                    '<select class="full-width-select cp-style-input" data-style-field="border_px">' +
                        opt(0,'None',s.border_px)+opt(1,'1px',s.border_px)+opt(2,'2px',s.border_px)+
                        opt(3,'3px',s.border_px)+opt(5,'5px',s.border_px)+opt(8,'8px',s.border_px)+
                        opt(10,'10px',s.border_px)+opt(15,'15px',s.border_px)+opt(20,'20px',s.border_px)+
                    '</select></div>' +
                '<div class="lens-input-wrapper"><label>BORDER COLOUR</label>' +
                    '<input type="color" class="cp-style-input" data-style-field="border_color"' +
                           ' value="'+s.border_color+'" style="height:30px;width:100%;padding:2px 4px;"></div>' +
                '<div class="lens-input-wrapper"><label>BG COLOUR</label>' +
                    '<input type="color" class="cp-style-input" data-style-field="bg_color"' +
                           ' value="'+s.bg_color+'" style="height:30px;width:100%;padding:2px 4px;"></div>' +
                '<div class="lens-input-wrapper">' +
                    '<label>SHADOW</label>' +
                    '<select class="full-width-select cp-style-input" data-style-field="shadow">' +
                        opt(0,'None',s.shadow)+opt(1,'Soft',s.shadow)+opt(2,'Medium',s.shadow)+opt(3,'Heavy',s.shadow)+
                    '</select></div>' +
            '</div>' +
        '</div>';
    }

    // -------------------------------------------------------------------------
    // EXISTING STRIP
    // -------------------------------------------------------------------------

    function initExistingStrip() {
        var items = strip.querySelectorAll('.ce-strip-item');
        items.forEach(function (item) {
            initDragEvents(item);
            initItemButtons(item);
            initCrop(item);
        });
        refreshBadges();
    }

    // ── Per-image style widget ────────────────────────────────────────────────
    // Ported from the new-post composer (ss-engine-gram-post.js) so the edit strip
    // has the SAME controls: square crop (IG), zoom + pan, size, border, matte,
    // shadow. Seeded from each image's saved values (data-* attrs) and written to
    // the img_* hidden inputs on submit (syncCrops). applyPreview/applySquareCrop
    // mirror core/thumb-generator.php so preview == published.
    var SHADOW_CSS = ['none',
        '0 2px 10px rgba(0,0,0,.20)',
        '0 4px 20px rgba(0,0,0,.45)',
        '0 8px 40px rgba(0,0,0,.70)'];

    function applySquareCrop(st, wrap, img) {
        var nw = img.naturalWidth, nh = img.naturalHeight;
        var S  = wrap.clientWidth || wrap.offsetWidth;
        if (!nw || !nh || !S) { img.addEventListener('load', function () { applySquareCrop(st, wrap, img); }, { once: true }); return; }
        var zoom = Math.max(100, Math.min(300, st.zoom || 100));
        var winD = Math.min(nw, nh) / (zoom / 100);
        var offX = (nw - winD) * (Math.max(0, Math.min(100, st.fx)) / 100);
        var offY = (nh - winD) * (Math.max(0, Math.min(100, st.fy)) / 100);
        var scale = S / winD;
        img.style.position = 'absolute';
        img.style.width  = (nw * scale) + 'px';
        img.style.height = (nh * scale) + 'px';
        img.style.left   = (-offX * scale) + 'px';
        img.style.top    = (-offY * scale) + 'px';
    }

    function applyPreview(st, wrap, img) {
        var cropped = (st.crop === 'fill') || st.zoom > 100 || st.fx !== 50 || st.fy !== 50;
        wrap.style.position = 'relative';
        wrap.style.overflow = 'hidden';
        if (cropped) {
            wrap.style.background = (st.crop === 'fill') ? '#111' : st.bg;
            img.style.objectFit = '';
            img.style.maxWidth = 'none'; img.style.maxHeight = 'none';
            img.style.border    = (st.crop !== 'fill' && st.bpx > 0) ? (st.bpx + 'px solid ' + st.bcol) : 'none';
            img.style.boxShadow = (st.crop !== 'fill') ? (SHADOW_CSS[st.shadow] || 'none') : 'none';
            applySquareCrop(st, wrap, img);
        } else {
            wrap.style.background = st.bg;
            img.style.position = 'static';
            img.style.left = ''; img.style.top = '';
            img.style.width = 'auto'; img.style.height = 'auto';
            img.style.maxWidth = st.size + '%';
            img.style.maxHeight = st.size + '%';
            img.style.objectFit = 'contain';
            img.style.border = st.bpx > 0 ? (st.bpx + 'px solid ' + st.bcol) : 'none';
            img.style.boxShadow = SHADOW_CSS[st.shadow] || 'none';
        }
    }

    function initCrop(item) {
        var wrap  = item.querySelector('.ce-thumb-wrap');
        var thumb = item.querySelector('.ce-thumb');
        if (!wrap || !thumb) return;
        var aspect = item.getAttribute('data-aspect');

        function ip(attr, def) { var v = parseInt(item.getAttribute(attr), 10); return isNaN(v) ? def : v; }
        var st = {
            crop:   (item.getAttribute('data-crop') === 'fill') ? 'fill' : 'fit',
            fx:     ip('data-focus-x', 50),
            fy:     ip('data-focus-y', 50),
            zoom:   ip('data-zoom', 100),
            size:   ip('data-size', 100),
            bpx:    ip('data-bpx', 0),
            bcol:   item.getAttribute('data-bcol') || '#000000',
            bg:     item.getAttribute('data-bg')   || '#ffffff',
            shadow: ip('data-shadow', 0)
        };
        item._crop = st;

        // Wrap sizing is owned by the .cp-thumb-wrap CSS (square tile inside the
        // 420px card) — the same rules the composer uses. No inline pin needed.
        if (aspect) thumb.src = aspect;   // uncropped, so panning has room

        var shadowOpts = [0,1,2,3].map(function (n) {
            return '<option value="' + n + '"' + (st.shadow === n ? ' selected' : '') + '>' +
                ['None','Soft','Medium','Heavy'][n] + '</option>';
        }).join('');
        var row = document.createElement('div');
        row.className = 'gp-style';
        row.innerHTML =
            '<label class="gp-sqr"><input type="checkbox" class="gp-crop"' +
                (st.crop === 'fill' ? ' checked' : '') + '> Square crop (IG)</label>' +
            '<div class="gp-ctl gp-crop-ctl"><span>Zoom</span>' +
                '<input type="range" class="gp-zoom" min="100" max="300" value="' + st.zoom + '">' +
                '<input type="number" class="gp-zoom-v" min="100" max="300" step="5" value="' + st.zoom + '" style="width:52px;">%</div>' +
            '<div class="gp-pan-hint" style="font:10px/1.3 monospace;opacity:.55;margin:-2px 0 6px;">drag the photo to reposition the crop</div>' +
            '<div class="gp-fit"' + (st.crop === 'fill' ? ' style="display:none;"' : '') + '>' +
                '<div class="gp-ctl"><span>Size</span>' +
                    '<input type="range" class="gp-size" min="10" max="100" value="' + st.size + '">' +
                    '<input type="number" class="gp-size-v" min="10" max="100" step="1" value="' + st.size + '" style="width:48px;">%</div>' +
                '<div class="gp-ctl"><span>Border</span>' +
                    '<input type="range" class="gp-bpx" min="0" max="50" value="' + st.bpx + '">' +
                    '<input type="number" class="gp-bpx-v" min="0" max="50" step="1" value="' + st.bpx + '" style="width:48px;">px</div>' +
                '<div class="gp-ctl gp-ctl-row">' +
                    '<label class="gp-swatch"><input type="color" class="gp-bcol" value="' + st.bcol + '"> Border</label>' +
                    '<label class="gp-swatch"><input type="color" class="gp-bg" value="' + st.bg + '"> Matte</label>' +
                '</div>' +
                '<div class="gp-ctl"><span>Shadow</span>' +
                    '<select class="gp-shadow">' + shadowOpts + '</select></div>' +
            '</div>';
        wrap.parentNode.insertBefore(row, wrap.nextSibling);

        applyPreview(st, wrap, thumb);

        var cropCb = row.querySelector('.gp-crop');
        var fitBox = row.querySelector('.gp-fit');
        // Native controls can't operate inside a draggable card — disable card drag
        // while the pointer is over any control (this also stops a slider grabbing
        // the whole card).
        row.querySelectorAll('input, select, label').forEach(function (n) {
            n.addEventListener('click',      function (e) { e.stopPropagation(); });
            n.addEventListener('mousedown',  function (e) { e.stopPropagation(); });
            n.addEventListener('mouseenter', function () { item.setAttribute('draggable', 'false'); });
            n.addEventListener('mouseleave', function () { item.setAttribute('draggable', 'true'); });
        });
        cropCb.addEventListener('change', function () {
            st.crop = cropCb.checked ? 'fill' : 'fit';
            fitBox.style.display = cropCb.checked ? 'none' : '';
            applyPreview(st, wrap, thumb);
        });
        var sizeR = row.querySelector('.gp-size'), sizeV = row.querySelector('.gp-size-v');
        function syncSize(v) { v = Math.max(10, Math.min(100, parseInt(v, 10) || 10)); st.size = v; sizeR.value = v; sizeV.value = v; applyPreview(st, wrap, thumb); }
        sizeR.addEventListener('input', function () { syncSize(sizeR.value); });
        sizeV.addEventListener('input', function () { syncSize(sizeV.value); });
        var bpxR = row.querySelector('.gp-bpx'), bpxV = row.querySelector('.gp-bpx-v');
        function syncBpx(v) { v = Math.max(0, Math.min(50, parseInt(v, 10) || 0)); st.bpx = v; bpxR.value = v; bpxV.value = v; applyPreview(st, wrap, thumb); }
        bpxR.addEventListener('input', function () { syncBpx(bpxR.value); });
        bpxV.addEventListener('input', function () { syncBpx(bpxV.value); });
        row.querySelector('.gp-bcol').addEventListener('input', function (e) { st.bcol = e.target.value; applyPreview(st, wrap, thumb); });
        row.querySelector('.gp-bg').addEventListener('input',   function (e) { st.bg   = e.target.value; applyPreview(st, wrap, thumb); });
        row.querySelector('.gp-shadow').addEventListener('change', function (e) { st.shadow = parseInt(e.target.value, 10); applyPreview(st, wrap, thumb); });

        var zoomR = row.querySelector('.gp-zoom'), zoomV = row.querySelector('.gp-zoom-v');
        function syncZoom(v) { v = Math.max(100, Math.min(300, parseInt(v, 10) || 100)); st.zoom = v; zoomR.value = v; zoomV.value = v; applyPreview(st, wrap, thumb); }
        zoomR.addEventListener('input', function () { syncZoom(zoomR.value); });
        zoomV.addEventListener('input', function () { syncZoom(zoomV.value); });

        // Drag the photo to pan the square crop (focal point).
        thumb.style.cursor = 'grab';
        thumb.addEventListener('mousedown', function (e) {
            var active = (st.crop === 'fill') || st.zoom > 100 || st.fx !== 50 || st.fy !== 50;
            if (!active) return;
            item.setAttribute('draggable', 'false'); e.preventDefault(); e.stopPropagation();
            thumb.style.cursor = 'grabbing';
            var lastX = e.clientX, lastY = e.clientY;
            function move(ev) {
                var nw = thumb.naturalWidth, nh = thumb.naturalHeight;
                var S  = wrap.clientWidth || wrap.offsetWidth;
                if (!nw || !nh || !S) return;
                var zoom = Math.max(100, Math.min(300, st.zoom || 100));
                var winD = Math.min(nw, nh) / (zoom / 100);
                var scale = S / winD;
                var rangeX = (nw - winD) * scale, rangeY = (nh - winD) * scale;
                var dx = ev.clientX - lastX, dy = ev.clientY - lastY;
                lastX = ev.clientX; lastY = ev.clientY;
                if (rangeX > 0) st.fx = Math.max(0, Math.min(100, st.fx - (dx / rangeX) * 100));
                if (rangeY > 0) st.fy = Math.max(0, Math.min(100, st.fy - (dy / rangeY) * 100));
                applySquareCrop(st, wrap, thumb);
            }
            function up() {
                item.setAttribute('draggable', 'true'); thumb.style.cursor = 'grab';
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', up);
            }
            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
        });
    }

    function initItemButtons(item) {
        var removeBtn  = item.querySelector('.ce-remove-btn');
        var coverBadge = item.querySelector('.ce-cover-badge');
        var exifToggle = item.querySelector('.ce-exif-toggle');
        var exifPanel  = item.querySelector('.ce-exif-panel');

        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                var imageId = parseInt(item.getAttribute('data-image-id'), 10);
                removedIds.push(imageId);
                item.parentNode.removeChild(item);
                // If we removed the cover, promote the first remaining item
                if (imageId === coverImageId) {
                    var first = strip.querySelector('.ce-strip-item');
                    if (first) coverImageId = parseInt(first.getAttribute('data-image-id'), 10) || 0;
                }
                refreshBadges();
            });
        }

        // Split: pull this image out into its own new post. Like remove, but the
        // server creates a fresh single post for it (no delete + reupload).
        var splitBtn = item.querySelector('.ce-split-btn');
        if (splitBtn) {
            splitBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (splitBtn.dataset.confirm && !window.confirm(splitBtn.dataset.confirm)) return;
                var imageId = parseInt(item.getAttribute('data-image-id'), 10);
                splitIds.push(imageId);
                item.parentNode.removeChild(item);
                if (imageId === coverImageId) {
                    var first = strip.querySelector('.ce-strip-item');
                    if (first) coverImageId = parseInt(first.getAttribute('data-image-id'), 10) || 0;
                }
                refreshBadges();
            });
        }

        if (coverBadge) {
            coverBadge.addEventListener('click', function () {
                coverImageId = parseInt(item.getAttribute('data-image-id'), 10) || 0;
                refreshBadges();
            });
        }

        if (exifToggle && exifPanel) {
            exifToggle.addEventListener('click', function () {
                var open = exifPanel.style.display !== 'none';
                exifPanel.style.display = open ? 'none' : 'block';
                exifToggle.textContent  = open ? 'EXIF ▸' : 'EXIF ▾';
            });
        }
    }

    function refreshBadges() {
        var items = strip.querySelectorAll('.ce-strip-item');
        items.forEach(function (item, idx) {
            var imageId    = parseInt(item.getAttribute('data-image-id'), 10);
            var coverBadge = item.querySelector('.ce-cover-badge');
            var posBadge   = item.querySelector('.ce-pos-badge');

            if (coverBadge) {
                var isCover = (imageId === coverImageId || (idx === 0 && coverImageId === 0));
                coverBadge.style.display = isCover ? 'flex' : 'none';
            }
            if (posBadge) {
                posBadge.textContent = idx + 1;
            }
        });
    }

    // -------------------------------------------------------------------------
    // DRAG TO REORDER
    // -------------------------------------------------------------------------

    var dragSource = null;

    function initDragEvents(item) {
        item.setAttribute('draggable', 'true');

        item.addEventListener('dragstart', function (e) {
            dragSource = item;
            setTimeout(function () { item.classList.add('is-dragging'); }, 0);
            e.dataTransfer.effectAllowed = 'move';
        });

        item.addEventListener('dragend', function () {
            item.classList.remove('is-dragging');
            strip.querySelectorAll('.ce-strip-item').forEach(function (i) {
                i.classList.remove('drag-over');
            });
            dragSource = null;
            refreshBadges();
        });

        item.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (dragSource && dragSource !== item) {
                item.classList.add('drag-over');
            }
        });

        item.addEventListener('dragleave', function () {
            item.classList.remove('drag-over');
        });

        item.addEventListener('drop', function (e) {
            e.preventDefault();
            item.classList.remove('drag-over');
            if (!dragSource || dragSource === item) return;

            // Insert dragSource before or after item depending on position
            var allItems  = Array.from(strip.querySelectorAll('.ce-strip-item'));
            var srcIdx    = allItems.indexOf(dragSource);
            var tgtIdx    = allItems.indexOf(item);

            if (srcIdx < tgtIdx) {
                strip.insertBefore(dragSource, item.nextSibling);
            } else {
                strip.insertBefore(dragSource, item);
            }
            refreshBadges();
        });
    }

    // -------------------------------------------------------------------------
    // ADD-MORE DROP ZONE
    // -------------------------------------------------------------------------

    function initAddZone() {
        var addToggle = document.getElementById('ce-add-toggle');
        var addZone   = document.getElementById('ce-add-zone');
        var dropZone  = document.getElementById('ce-drop-zone');
        var fileInput = document.getElementById('ce-file-input');

        if (!addToggle || !addZone || !dropZone || !fileInput) return;

        addToggle.addEventListener('click', function () {
            var open = addZone.style.display !== 'none';
            addZone.style.display   = open ? 'none' : 'block';
            addToggle.textContent   = open ? '+ ADD MORE IMAGES' : '− CLOSE';
        });

        // Drop zone events
        dropZone.addEventListener('dragover', function (e) {
            e.preventDefault();
            dropZone.classList.add('is-over');
        });
        dropZone.addEventListener('dragleave', function () {
            dropZone.classList.remove('is-over');
        });
        dropZone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropZone.classList.remove('is-over');
            if (e.dataTransfer.files) addNewFiles(e.dataTransfer.files);
        });
        dropZone.addEventListener('click', function () {
            fileInput.value = '';
            fileInput.click();
        });

        fileInput.addEventListener('change', function () {
            if (fileInput.files) addNewFiles(fileInput.files);
        });
    }

    function addNewFiles(fileList) {
        var existing = strip.querySelectorAll('.ce-strip-item').length;

        Array.from(fileList).forEach(function (file) {
            if (!ACCEPTED.includes(file.type)) return;
            if (existing >= MAX_FILES) return;

            // Deduplicate by name+size+lastModified
            var isDupe = newFiles.some(function (f) {
                return f.name === file.name && f.size === file.size && f.lastModified === file.lastModified;
            });
            if (isDupe) return;

            newFiles.push(file);
            newFileStyles.push(defaultStyle());
            existing++;
            appendNewFileToStrip(file, newFiles.length - 1);
        });
    }

    function appendNewFileToStrip(file, newIdx) {
        var item = document.createElement('div');
        item.className = 'ce-strip-item ce-strip-item--new';
        item.setAttribute('data-new-index', newIdx);
        // No image-id: new files get a negative placeholder so refreshBadges skips the cover badge
        item.setAttribute('data-image-id', '-1');

        var thumbWrap = document.createElement('div');
        thumbWrap.className = 'ce-thumb-wrap';

        var img = document.createElement('img');
        img.className = 'ce-thumb';
        var reader = new FileReader();
        reader.onload = function (e) { img.src = e.target.result; };
        reader.readAsDataURL(file);

        var posBadge = document.createElement('span');
        posBadge.className = 'ce-pos-badge';

        var removeBtn = document.createElement('button');
        removeBtn.type      = 'button';
        removeBtn.className = 'ce-remove-btn';
        removeBtn.textContent = '×';
        removeBtn.addEventListener('click', function () {
            newFiles.splice(newIdx, 1);
            newFileStyles.splice(newIdx, 1);
            item.parentNode.removeChild(item);
            refreshBadges();
        });

        var label = document.createElement('div');
        label.className   = 'ce-item-label';
        label.textContent = file.name;

        thumbWrap.appendChild(img);
        thumbWrap.appendChild(posBadge);
        thumbWrap.appendChild(removeBtn);
        item.appendChild(thumbWrap);
        item.appendChild(label);

        // FRAME style panel for new images in per_image mode
        if (customizeLevel === 'per_image') {
            var styleToggleBtn = document.createElement('button');
            styleToggleBtn.type = 'button';
            styleToggleBtn.className = 'cp-exif-toggle';
            styleToggleBtn.style.marginTop = '4px';
            styleToggleBtn.textContent = 'FRAME ▸';
            item.appendChild(styleToggleBtn);

            var panelWrap = document.createElement('div');
            panelWrap.innerHTML = buildNewStylePanel(newIdx);
            var panel = panelWrap.firstChild;
            item.appendChild(panel);

            styleToggleBtn.addEventListener('click', function () {
                var isOpen = panel.style.display !== 'none';
                panel.style.display = isOpen ? 'none' : 'block';
                this.textContent = isOpen ? 'FRAME ▸' : 'FRAME ▾';
            });

            panel.querySelectorAll('.cp-style-input').forEach(function (input) {
                input.addEventListener('change', function () {
                    var field = this.getAttribute('data-style-field');
                    if (!newFileStyles[newIdx]) newFileStyles[newIdx] = defaultStyle();
                    var val = this.value;
                    if (field === 'border_color' || field === 'bg_color') {
                        newFileStyles[newIdx][field] = val;
                    } else {
                        newFileStyles[newIdx][field] = parseInt(val, 10) || 0;
                    }
                });
            });
        }

        strip.appendChild(item);
        initDragEvents(item);
        refreshBadges();
    }

    // -------------------------------------------------------------------------
    // FORM SUBMIT: sync hidden inputs
    // -------------------------------------------------------------------------

    function initFormSubmit() {
        form.addEventListener('submit', function (e) {
            syncSortOrder();
            syncRemovedIds();
            syncSplitIds();
            syncCrops();
            syncCoverId();

            // For per_image mode: sync new-image style arrays as hidden inputs
            // so PHP can apply styles to the newly-inserted snap_post_images rows.
            if (customizeLevel === 'per_image') {
                form.querySelectorAll('input[name^="new_img_size_pct"],' +
                    'input[name^="new_img_border_px"],input[name^="new_img_border_color"],' +
                    'input[name^="new_img_bg_color"],input[name^="new_img_shadow"]')
                    .forEach(function (el) { el.parentNode.removeChild(el); });

                newFileStyles.forEach(function (s) {
                    var fields = {
                        'new_img_size_pct[]':     s.size_pct,
                        'new_img_border_px[]':    s.border_px,
                        'new_img_border_color[]': s.border_color,
                        'new_img_bg_color[]':     s.bg_color,
                        'new_img_shadow[]':       s.shadow
                    };
                    Object.keys(fields).forEach(function (name) {
                        var inp   = document.createElement('input');
                        inp.type  = 'hidden';
                        inp.name  = name;
                        inp.value = fields[name];
                        form.appendChild(inp);
                    });
                });
            }

            // Attach new files to the form's file input for the PHP handler.
            // We use a DataTransfer object to populate a hidden <input type="file">.
            var newFileInput = form.querySelector('input[name="new_img_files[]"]');
            if (newFileInput && newFiles.length > 0) {
                try {
                    var dt = new DataTransfer();
                    newFiles.forEach(function (f) { dt.items.add(f); });
                    newFileInput.files = dt.files;
                } catch (err) {
                    // DataTransfer not supported in all browsers — new files will be ignored
                    console.warn('ce: DataTransfer not supported, new images will not be uploaded.');
                }
            }
        });
    }

    function syncSortOrder() {
        // Remove old sort_order inputs
        form.querySelectorAll('input[name="sort_order[]"]').forEach(function (el) {
            el.parentNode.removeChild(el);
        });
        // Write fresh order from strip DOM
        strip.querySelectorAll('.ce-strip-item').forEach(function (item) {
            var imageId = item.getAttribute('data-image-id');
            if (!imageId || imageId === '-1') return; // skip new files
            var input    = document.createElement('input');
            input.type   = 'hidden';
            input.name   = 'sort_order[]';
            input.value  = imageId;
            form.appendChild(input);
        });
    }

    function syncRemovedIds() {
        form.querySelectorAll('input[name="remove_image_ids[]"]').forEach(function (el) {
            el.parentNode.removeChild(el);
        });
        removedIds.forEach(function (id) {
            var input   = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'remove_image_ids[]';
            input.value = id;
            form.appendChild(input);
        });
    }

    function syncSplitIds() {
        form.querySelectorAll('input[name="split_image_ids[]"]').forEach(function (el) {
            el.parentNode.removeChild(el);
        });
        splitIds.forEach(function (id) {
            var input   = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'split_image_ids[]';
            input.value = id;
            form.appendChild(input);
        });
    }

    function syncCrops() {
        form.querySelectorAll('input[name^="img_focus_x["],' +
            'input[name^="img_focus_y["],input[name^="img_zoom["],' +
            'input[name^="img_crop_mode["],input[name^="img_size_pct["],' +
            'input[name^="img_border_px["],input[name^="img_border_color["],' +
            'input[name^="img_bg_color["],input[name^="img_shadow["]')
            .forEach(function (el) { el.parentNode.removeChild(el); });
        strip.querySelectorAll('.ce-strip-item').forEach(function (item) {
            var id = item.getAttribute('data-image-id');
            if (!id || id === '-1' || !item._crop) return;
            var c = item._crop;
            [['img_focus_x',     Math.round(c.fx)],
             ['img_focus_y',     Math.round(c.fy)],
             ['img_zoom',        c.zoom],
             ['img_crop_mode',   c.crop],
             ['img_size_pct',    c.size],
             ['img_border_px',   c.bpx],
             ['img_border_color', c.bcol],
             ['img_bg_color',    c.bg],
             ['img_shadow',      c.shadow]].forEach(function (pair) {
                var inp   = document.createElement('input');
                inp.type  = 'hidden';
                inp.name  = pair[0] + '[' + id + ']';
                inp.value = pair[1];
                form.appendChild(inp);
            });
        });
    }

    function syncCoverId() {
        var input = form.querySelector('input[name="cover_image_id"]');
        if (!input) {
            input       = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'cover_image_id';
            form.appendChild(input);
        }
        // If coverImageId is 0 (nothing explicitly chosen), use the first item
        if (!coverImageId) {
            var first = strip.querySelector('.ce-strip-item');
            if (first) coverImageId = parseInt(first.getAttribute('data-image-id'), 10) || 0;
        }
        input.value = coverImageId;
    }

})();
// ===== SNAPSMACK EOF =====
