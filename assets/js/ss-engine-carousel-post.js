/**
 * SNAPSMACK - Carousel Post Engine
 * Alpha v0.7.4
 *
 * JavaScript engine for the multi-image carousel posting page
 * (smack-post-carousel.php). Handles:
 *
 *   - Drag-and-drop + click-to-browse file selection (up to 20 images)
 *   - FileReader thumbnail preview strip
 *   - Drag-to-reorder within the preview strip (touch + mouse)
 *   - Per-image EXIF panel expand/collapse
 *   - Per-image FRAME style panel (size, border px, border colour, bg colour, shadow)
 *     shown only when the form's data-customize-level === "per_image"
 *   - styleList[] kept in sync with fileList[] through add/remove/reorder
 *   - Sort position hidden inputs kept in sync with visual order
 *   - File removal with index renumbering
 *   - Post type selector UI (single / carousel / panorama)
 *   - XHR upload with progress bar
 *   - Client-side validation before submit
 */

(function () {
    'use strict';

    var MAX_FILES  = 20;
    var ACCEPTED   = ['image/jpeg', 'image/png', 'image/webp'];

    // --- STATE ---

    var fileList      = [];   // ordered array of File objects, mirrors the strip
    var styleList     = [];   // parallel array of style objects, mirrors fileList
    var dragSrcIdx    = null; // index of strip item being dragged
    var customizeLevel = 'per_grid'; // read from form data attribute on init

    // --- DOM REFS (populated on init) ---

    var dropZone, fileInput, stripEl, postTypeSelect, panoramaRowsRow,
        progressWrap, progressBar, submitBtn, form;

    // =========================================================================
    // INIT
    // =========================================================================

    document.addEventListener('DOMContentLoaded', function () {
        dropZone       = document.getElementById('cp-drop-zone');
        fileInput      = document.getElementById('cp-file-input');
        stripEl        = document.getElementById('cp-strip');
        postTypeSelect = document.getElementById('cp-post-type');
        panoramaRowsRow= document.getElementById('cp-panorama-rows-row');
        progressWrap   = document.getElementById('cp-progress-wrap');
        progressBar    = document.getElementById('cp-progress-bar');
        submitBtn      = document.getElementById('cp-submit');
        form           = document.getElementById('cp-form');

        if (!dropZone) return; // not on the carousel post page

        customizeLevel = (form && form.getAttribute('data-customize-level')) || 'per_grid';

        initDropZone();
        initFileInput();
        initPostTypeSelector();
        initFormSubmit();
    });

    // =========================================================================
    // STYLE HELPERS
    // =========================================================================

    function defaultStyle() {
        return { size_pct: 100, border_px: 0, border_color: '#000000', bg_color: '#ffffff', shadow: 0 };
    }

    // =========================================================================
    // DROP ZONE
    // =========================================================================

    function initDropZone() {
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
            addFiles(Array.from(e.dataTransfer.files));
        });

        dropZone.addEventListener('click', function () {
            fileInput.click();
        });
    }

    function initFileInput() {
        fileInput.addEventListener('change', function () {
            addFiles(Array.from(fileInput.files));
            fileInput.value = ''; // reset so same file can be re-added after removal
        });
    }

    // =========================================================================
    // FILE MANAGEMENT
    // =========================================================================

    function addFiles(incoming) {
        var added = 0;
        incoming.forEach(function (f) {
            if (fileList.length >= MAX_FILES) return;
            if (!ACCEPTED.includes(f.type)) return;
            // Deduplicate by name + size + lastModified
            var isDupe = fileList.some(function (existing) {
                return existing.name === f.name &&
                       existing.size === f.size &&
                       existing.lastModified === f.lastModified;
            });
            if (isDupe) return;
            fileList.push(f);
            styleList.push(defaultStyle());
            added++;
        });

        if (added > 0) renderStrip();
        updateDropZoneState();
        validateForm();
    }

    function removeFile(idx) {
        fileList.splice(idx, 1);
        styleList.splice(idx, 1);
        renderStrip();
        updateDropZoneState();
        validateForm();
    }

    // =========================================================================
    // PREVIEW STRIP RENDER
    // =========================================================================

    function renderStrip() {
        stripEl.innerHTML = '';

        fileList.forEach(function (file, idx) {
            var item = document.createElement('div');
            item.className = 'cp-strip-item';
            item.dataset.idx = idx;
            item.draggable = true;

            // Cover badge on first item
            var coverBadge = idx === 0
                ? '<span class="cp-cover-badge">COVER</span>'
                : '';

            // Position badge on all items when multiple files
            var posBadge = fileList.length > 1
                ? '<span class="cp-pos-badge">' + (idx + 1) + '</span>'
                : '';

            var styleToggle = customizeLevel === 'per_image'
                ? '<button type="button" class="cp-style-toggle cp-exif-toggle" style="margin-top:4px;">FRAME ▸</button>' +
                  buildStylePanel(idx)
                : '';

            item.innerHTML =
                '<div class="cp-thumb-wrap">' +
                    '<img class="cp-thumb" alt="">' +
                    coverBadge +
                    posBadge +
                    '<button type="button" class="cp-remove-btn" title="Remove">✕</button>' +
                '</div>' +
                '<div class="cp-item-label">' + escHtml(file.name) + '</div>' +
                '<button type="button" class="cp-exif-toggle">EXIF ▾</button>' +
                buildExifPanel(idx) +
                styleToggle +
                '<input type="hidden" name="sort_order[]" value="' + idx + '">';

            // Generate preview thumbnail
            var img = item.querySelector('.cp-thumb');
            var reader = new FileReader();
            reader.onload = function (e) { img.src = e.target.result; };
            reader.readAsDataURL(file);

            // Remove button
            item.querySelector('.cp-remove-btn').addEventListener('click', function (e) {
                e.stopPropagation();
                removeFile(idx);
            });

            // EXIF toggle
            item.querySelector('.cp-exif-toggle').addEventListener('click', function () {
                var panel = item.querySelector('.cp-exif-panel');
                var isOpen = panel.style.display !== 'none';
                panel.style.display = isOpen ? 'none' : 'block';
                this.textContent = isOpen ? 'EXIF ▾' : 'EXIF ▴';
            });

            // FRAME style toggle
            var styleToggleBtn = item.querySelector('.cp-style-toggle');
            if (styleToggleBtn) {
                styleToggleBtn.addEventListener('click', function () {
                    var panel = item.querySelector('.cp-style-panel');
                    var isOpen = panel.style.display !== 'none';
                    panel.style.display = isOpen ? 'none' : 'block';
                    this.textContent = isOpen ? 'FRAME ▸' : 'FRAME ▾';
                });
                // Sync styleList when user changes a style input
                item.querySelectorAll('.cp-style-input').forEach(function (input) {
                    input.addEventListener('change', function () {
                        var field = this.getAttribute('data-style-field');
                        if (!styleList[idx]) styleList[idx] = defaultStyle();
                        var val = this.value;
                        if (field === 'border_color' || field === 'bg_color') {
                            styleList[idx][field] = val;
                        } else {
                            styleList[idx][field] = parseInt(val, 10) || 0;
                        }
                    });
                });
            }

            // Drag-to-reorder (mouse)
            item.addEventListener('dragstart', function (e) {
                dragSrcIdx = idx;
                e.dataTransfer.effectAllowed = 'move';
                item.classList.add('is-dragging');
            });

            item.addEventListener('dragend', function () {
                item.classList.remove('is-dragging');
                stripEl.querySelectorAll('.cp-strip-item').forEach(function (el) {
                    el.classList.remove('drag-over');
                });
            });

            item.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                item.classList.add('drag-over');
            });

            item.addEventListener('dragleave', function () {
                item.classList.remove('drag-over');
            });

            item.addEventListener('drop', function (e) {
                e.preventDefault();
                item.classList.remove('drag-over');
                if (dragSrcIdx === null || dragSrcIdx === idx) return;
                // Move file and style in tandem
                var movedFile  = fileList.splice(dragSrcIdx, 1)[0];
                var movedStyle = styleList.splice(dragSrcIdx, 1)[0];
                fileList.splice(idx, 0, movedFile);
                styleList.splice(idx, 0, movedStyle);
                dragSrcIdx = null;
                renderStrip();
                validateForm();
            });

            stripEl.appendChild(item);
        });

        updateSortInputs();
    }

    function buildExifPanel(idx) {
        var n = 'exif[' + idx + ']';
        return '<div class="cp-exif-panel" style="display:none">' +
            '<div class="cp-exif-grid">' +
                exifField(n + '[camera]',  'CAMERA MODEL', 'text',   'Auto-detected...') +
                exifField(n + '[lens]',    'LENS',         'text',   'Auto-detected...') +
                exifField(n + '[focal]',   'FOCAL LENGTH', 'text',   'Auto-detected...') +
                exifField(n + '[film]',    'FILM STOCK',   'text',   'e.g. Kodak Portra 400') +
                exifField(n + '[iso]',     'ISO',          'text',   'Auto-detected...') +
                exifField(n + '[aperture]','APERTURE',     'text',   'Auto-detected...') +
                exifField(n + '[shutter]', 'SHUTTER',      'text',   'Auto-detected...') +
                '<div class="lens-input-wrapper">' +
                    '<label>FLASH</label>' +
                    '<select name="' + escHtml(n + '[flash]') + '" class="full-width-select">' +
                        '<option value="">Auto-detect</option>' +
                        '<option value="No">No</option>' +
                        '<option value="Yes">Yes</option>' +
                    '</select>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    function buildStylePanel(idx) {
        var s = styleList[idx] || defaultStyle();
        function opt(val, label, cur) {
            return '<option value="' + val + '"' + (cur == val ? ' selected' : '') + '>' + label + '</option>';
        }
        return '<div class="cp-style-panel cp-exif-panel" style="display:none;">' +
            '<div class="cp-exif-grid">' +
                '<div class="lens-input-wrapper">' +
                    '<label>IMAGE SIZE</label>' +
                    '<select name="img_size_pct[]" class="full-width-select cp-style-input" data-style-field="size_pct">' +
                        opt(100, '100% — edge to edge', s.size_pct) +
                        opt(95, '95%', s.size_pct) + opt(90, '90%', s.size_pct) +
                        opt(85, '85%', s.size_pct) + opt(80, '80%', s.size_pct) +
                        opt(75, '75%', s.size_pct) +
                    '</select>' +
                '</div>' +
                '<div class="lens-input-wrapper">' +
                    '<label>BORDER THICKNESS</label>' +
                    '<select name="img_border_px[]" class="full-width-select cp-style-input" data-style-field="border_px">' +
                        opt(0,'None',s.border_px) + opt(1,'1px',s.border_px) + opt(2,'2px',s.border_px) +
                        opt(3,'3px',s.border_px)  + opt(5,'5px',s.border_px) + opt(8,'8px',s.border_px) +
                        opt(10,'10px',s.border_px)+ opt(15,'15px',s.border_px)+ opt(20,'20px',s.border_px) +
                    '</select>' +
                '</div>' +
                '<div class="lens-input-wrapper">' +
                    '<label>BORDER COLOUR</label>' +
                    '<input type="color" name="img_border_color[]" value="' + escHtml(s.border_color) + '"' +
                           ' class="cp-style-input" data-style-field="border_color"' +
                           ' style="height:32px; width:100%; padding:2px 4px;">' +
                '</div>' +
                '<div class="lens-input-wrapper">' +
                    '<label>BACKGROUND COLOUR</label>' +
                    '<input type="color" name="img_bg_color[]" value="' + escHtml(s.bg_color) + '"' +
                           ' class="cp-style-input" data-style-field="bg_color"' +
                           ' style="height:32px; width:100%; padding:2px 4px;">' +
                '</div>' +
                '<div class="lens-input-wrapper">' +
                    '<label>SHADOW</label>' +
                    '<select name="img_shadow[]" class="full-width-select cp-style-input" data-style-field="shadow">' +
                        opt(0,'None',s.shadow) + opt(1,'Soft',s.shadow) +
                        opt(2,'Medium',s.shadow) + opt(3,'Heavy',s.shadow) +
                    '</select>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    function exifField(name, label, type, placeholder) {
        return '<div class="lens-input-wrapper">' +
            '<label>' + label + '</label>' +
            '<input type="' + type + '" name="' + escHtml(name) + '" placeholder="' + escHtml(placeholder) + '">' +
            '</div>';
    }

    function updateSortInputs() {
        // Sort inputs are already rendered inline; this syncs cover badge visibility only.
        stripEl.querySelectorAll('.cp-cover-badge').forEach(function (badge, i) {
            badge.style.display = i === 0 ? '' : 'none';
        });
    }

    function updateDropZoneState() {
        var isFull = fileList.length >= MAX_FILES;
        dropZone.style.display = isFull ? 'none' : '';
        var counter = document.getElementById('cp-file-count');
        if (counter) {
            counter.textContent = fileList.length + ' / ' + MAX_FILES + ' images';
        }
    }

    // =========================================================================
    // POST TYPE SELECTOR
    // =========================================================================

    function initPostTypeSelector() {
        if (!postTypeSelect) return;
        postTypeSelect.addEventListener('change', function () {
            var type = this.value;
            if (panoramaRowsRow) {
                panoramaRowsRow.style.display = (type === 'panorama') ? '' : 'none';
            }
            // Enforce single-file for panorama (one wide image to split)
            if (type === 'panorama' && fileList.length > 1) {
                fileList  = fileList.slice(0, 1);
                styleList = styleList.slice(0, 1);
                renderStrip();
                updateDropZoneState();
            }
            updatePostTypeHints();
        });
    }

    function updatePostTypeHints() {
        var type = postTypeSelect ? postTypeSelect.value : 'single';
        var hint = document.getElementById('cp-type-hint');
        if (!hint) return;
        var messages = {
            'single':   'Standard single-image post. Works with all skins.',
            'carousel': 'Multi-image post. Viewers swipe through up to 20 images.',
            'panorama': 'Upload one wide image. The system slices it into 3, 6, or 9 grid tiles.'
        };
        hint.textContent = messages[type] || '';
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    function validateForm() {
        if (!submitBtn) return;
        var titleOk = (document.getElementById('cp-title') || {value:''}).value.trim().length > 0;
        var filesOk = fileList.length > 0;
        submitBtn.disabled = !(titleOk && filesOk);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var titleInput = document.getElementById('cp-title');
        if (titleInput) {
            titleInput.addEventListener('input', validateForm);
        }
    });

    // =========================================================================
    // FORM SUBMIT — XHR with progress
    // =========================================================================

    function initFormSubmit() {
        if (!form) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (fileList.length === 0) return;

            var formData = new FormData(form);

            // Re-attach files in strip order
            formData.delete('img_files[]');
            fileList.forEach(function (f) {
                formData.append('img_files[]', f);
            });

            // Replace sort_order[] with the definitive ordered indices
            formData.delete('sort_order[]');
            fileList.forEach(function (_, idx) {
                formData.append('sort_order[]', idx);
            });

            // For per_image mode, re-sync style arrays to match strip order.
            // (Inputs are rebuilt by renderStrip() on reorder so DOM order is
            // already correct, but we sync from styleList for safety.)
            if (customizeLevel === 'per_image') {
                formData.delete('img_size_pct[]');
                formData.delete('img_border_px[]');
                formData.delete('img_border_color[]');
                formData.delete('img_bg_color[]');
                formData.delete('img_shadow[]');
                styleList.forEach(function (s) {
                    formData.append('img_size_pct[]',     s.size_pct);
                    formData.append('img_border_px[]',    s.border_px);
                    formData.append('img_border_color[]', s.border_color);
                    formData.append('img_bg_color[]',     s.bg_color);
                    formData.append('img_shadow[]',       s.shadow);
                });
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'smack-post-carousel.php');

            xhr.upload.addEventListener('progress', function (e) {
                if (!e.lengthComputable) return;
                var pct = Math.round((e.loaded / e.total) * 100);
                progressWrap.style.display = '';
                progressBar.style.width = pct + '%';
                progressBar.textContent = pct + '%';
            });

            xhr.addEventListener('load', function () {
                if (xhr.responseText.trim() === 'success') {
                    window.location.href = 'smack-manage.php?msg=CAROUSEL_LIVE';
                } else {
                    showError(xhr.responseText || 'UPLOAD_FAILURE');
                }
            });

            xhr.addEventListener('error', function () {
                showError('NETWORK_ERROR — check server logs.');
            });

            submitBtn.disabled = true;
            submitBtn.textContent = 'TRANSMITTING...';
            xhr.send(formData);
        });
    }

    function showError(msg) {
        var err = document.getElementById('cp-error');
        if (err) {
            err.textContent = msg;
            err.style.display = '';
        }
        submitBtn.disabled = false;
        submitBtn.textContent = 'COMMIT TRANSMISSION';
        progressWrap.style.display = 'none';
    }

    // =========================================================================
    // UTILITIES
    // =========================================================================

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})();
