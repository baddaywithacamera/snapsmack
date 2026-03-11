/**
 * SNAPSMACK - Carousel Post Engine
 * Alpha v0.7.1
 *
 * JavaScript engine for the multi-image carousel posting page
 * (smack-post-carousel.php). Handles:
 *
 *   - Drag-and-drop + click-to-browse file selection (up to 10 images)
 *   - FileReader thumbnail preview strip
 *   - Drag-to-reorder within the preview strip (touch + mouse)
 *   - Per-image EXIF panel expand/collapse
 *   - Sort position hidden inputs kept in sync with visual order
 *   - File removal with index renumbering
 *   - Post type selector UI (single / carousel / panorama)
 *   - XHR upload with progress bar
 *   - Client-side validation before submit
 */

(function () {
    'use strict';

    var MAX_FILES  = 10;
    var ACCEPTED   = ['image/jpeg', 'image/png', 'image/webp'];

    // --- STATE ---

    var fileList   = [];   // ordered array of File objects, mirrors the strip
    var dragSrcIdx = null; // index of strip item being dragged

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

        initDropZone();
        initFileInput();
        initPostTypeSelector();
        initFormSubmit();
    });

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
            added++;
        });

        if (added > 0) renderStrip();
        updateDropZoneState();
        validateForm();
    }

    function removeFile(idx) {
        fileList.splice(idx, 1);
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

            // Carousel indicator on non-first items when multiple files
            var posBadge = fileList.length > 1
                ? '<span class="cp-pos-badge">' + (idx + 1) + '</span>'
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
                var moved = fileList.splice(dragSrcIdx, 1)[0];
                fileList.splice(idx, 0, moved);
                dragSrcIdx = null;
                renderStrip();
                validateForm();
            });

            stripEl.appendChild(item);
        });

        // Hidden inputs that carry file order to PHP (parallel array to $_FILES['img_files'])
        // We also need to carry EXIF overrides. The actual File objects are collected
        // at submit time in order from fileList, so sort_order[] just tells PHP which
        // file index is cover (always 0 after reorder).
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
                fileList = fileList.slice(0, 1);
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
            'carousel': 'Multi-image post. Viewers swipe through up to 10 images.',
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

            // Remove any previously-attached file fields and re-attach in strip order
            // (fileList is already sorted by the drag-to-reorder logic)
            formData.delete('img_files[]');
            fileList.forEach(function (f) {
                formData.append('img_files[]', f);
            });

            // Replace sort_order[] with the definitive ordered indices
            formData.delete('sort_order[]');
            fileList.forEach(function (_, idx) {
                formData.append('sort_order[]', idx);
            });

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
