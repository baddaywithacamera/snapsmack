/**
 * SnapSmack Carousel Edit Engine
 * Alpha v0.7.4
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

(function () {
    'use strict';

    var MAX_FILES  = 20;
    var ACCEPTED   = ['image/jpeg', 'image/png', 'image/webp'];

    // Tracks image IDs removed in this session
    var removedIds    = [];
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
        });
        refreshBadges();
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
