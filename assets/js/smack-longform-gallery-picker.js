/**
 * SNAPSMACK - Longform Gallery pickers
 *
 * Drives two controls on smack-post-long.php, both sourcing POST images from
 * the Media Gallery (snap_images) via the smack-gallery.php?ajax=1 endpoint:
 *
 *   1. GALLERY toolbar button — inserts a Gallery-forced [img:gID|full|center]
 *      shortcode at the cursor (inline body image).
 *   2. COVER IMAGE picker — sets the post's cover/featured image
 *      (featured_image_id), shown as the banner + post-listing thumbnail.
 *
 * The 'g' prefix on the inline shortcode is required: plain [img:ID] resolves
 * the reusable-asset Library (snap_assets) first, and both tables number from
 * 1, so a bare id would frequently render the wrong image. core/parser.php
 * honours [img:gID] as snap_images-only.
 *
 * Covers are POST content, so they come from the Gallery — never the Library.
 *
 * Server data via data-* attributes only (no inline JS).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    var insertBtn = document.getElementById('gallery-insert-btn');
    var coverBtn  = document.getElementById('long-cover-btn');
    var coverDel  = document.getElementById('long-cover-remove');
    var modal     = document.getElementById('gallery-pick-modal');
    var grid      = document.getElementById('gallery-pick-grid');
    var search    = document.getElementById('gallery-pick-search');
    var closeB    = document.getElementById('gallery-pick-close');
    var emptyEl   = document.getElementById('gallery-pick-empty');
    var ta        = document.getElementById('long-content');
    var widthEl   = document.getElementById('gallery-pick-width');
    var widthOut  = document.getElementById('gallery-pick-width-value');
    var widthWrap = document.getElementById('gallery-width-control');
    var titleEl   = document.getElementById('gallery-pick-title');
    var scopeBar  = document.getElementById('gallery-pick-scope');
    var scopeButtons = scopeBar ? scopeBar.querySelectorAll('[data-scope]') : [];
    var orientationBar = document.getElementById('gallery-pick-orientation');
    var orientationButtons = orientationBar ? orientationBar.querySelectorAll('[data-orientation]') : [];
    if (!modal || !grid) return;

    var base = grid.getAttribute('data-base') || '';
    var postId = parseInt(grid.getAttribute('data-post-id'), 10) || 0;
    var searchTimer = null;
    var mode = 'insert'; // 'insert' (body shortcode) | 'cover' (featured image)
    var loadedImages = [];
    var orientationFilter = 'ALL';
    var sourceScope = 'all';

    // ── MODAL ────────────────────────────────────────────────────────────────
    function openModal(m) {
        mode = m;
        modal.style.display = 'block';
        if (titleEl) titleEl.textContent = mode === 'cover' ? 'SELECT COVER FROM GALLERY' : 'INSERT IMAGE FROM GALLERY';
        if (widthWrap) widthWrap.style.display = mode === 'cover' ? 'none' : 'flex';
        if (scopeBar) scopeBar.style.display = mode === 'cover' ? 'flex' : 'none';
        setSourceScope(mode === 'cover' && postId > 0 ? 'bucket' : 'all', false);
        search.value = '';
        setOrientationFilter('ALL');
        if (widthEl) widthEl.value = '100';
        if (widthOut) widthOut.textContent = '100%';
        fetchImages('');
        search.focus();
    }
    function closeModal() {
        modal.style.display = 'none';
    }

    function fetchImages(q) {
        if (emptyEl) emptyEl.style.display = 'none';
        grid.innerHTML = '<p class="dim" style="font-size:12px;padding:10px;grid-column:1/-1;">Loading…</p>';

        var url = 'smack-gallery.php?ajax=1&page=1&per_page=100' + (q ? '&q=' + encodeURIComponent(q) : '');
        if (mode === 'cover' && sourceScope === 'bucket' && postId > 0) {
            url += '&bucket_post_id=' + encodeURIComponent(postId);
        }
        if (orientationFilter !== 'ALL') url += '&orientation=' + encodeURIComponent(orientationFilter.toLowerCase());
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.onload = function () {
            if (xhr.status !== 200) {
                grid.innerHTML = '<p class="dim" style="font-size:12px;padding:10px;grid-column:1/-1;">Failed to load Gallery.</p>';
                return;
            }
            var data;
            try { data = JSON.parse(xhr.responseText); } catch (e) {
                grid.innerHTML = '<p class="dim" style="font-size:12px;padding:10px;grid-column:1/-1;">Invalid response.</p>';
                return;
            }
            loadedImages = data.images || [];
            renderFilteredGrid();
        };
        xhr.onerror = function () {
            grid.innerHTML = '<p class="dim" style="font-size:12px;padding:10px;grid-column:1/-1;">Network error.</p>';
        };
        xhr.send();
    }

    function thumbUrl(img) {
        return base + String(img.thumb || img.img_file || '').replace(/^\//, '');
    }

    function orientationLabel(img) {
        var width = parseInt(img.img_width, 10) || 0;
        var height = parseInt(img.img_height, 10) || 0;
        if (width > 0 && height > 0) {
            if (Math.abs(width - height) <= Math.max(width, height) * 0.02) return 'SQUARE';
            return height > width ? 'PORTRAIT' : 'LANDSCAPE';
        }
        var stored = parseInt(img.img_orientation, 10);
        if (stored === 2) return 'SQUARE';
        if (stored === 1) return 'PORTRAIT';
        return 'LANDSCAPE';
    }

    function setOrientationFilter(next) {
        orientationFilter = next || 'ALL';
        Array.prototype.forEach.call(orientationButtons, function (button) {
            var active = button.getAttribute('data-orientation') === orientationFilter;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        renderFilteredGrid();
    }

    function setSourceScope(next, reload) {
        sourceScope = next === 'bucket' && postId > 0 ? 'bucket' : 'all';
        Array.prototype.forEach.call(scopeButtons, function (button) {
            var active = button.getAttribute('data-scope') === sourceScope;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        if (reload) fetchImages(search ? search.value.trim() : '');
    }

    function renderFilteredGrid() {
        var images = loadedImages.filter(function (img) {
            return orientationFilter === 'ALL' || orientationLabel(img) === orientationFilter;
        });
        renderGrid(images, loadedImages.length > 0);
    }

    function renderGrid(images, filtered) {
        grid.innerHTML = '';
        if (!images.length) {
            if (emptyEl) {
                emptyEl.innerHTML = filtered
                    ? 'No ' + orientationFilter.toLowerCase() + ' images match this view.'
                    : (mode === 'cover' && sourceScope === 'bucket'
                        ? 'This post\'s bucket has no matching images. Choose ALL GALLERY IMAGES or add photographs to the bucket.'
                        : 'No images in the Gallery yet. <a href="smack-gallery.php" target="_blank" style="color:var(--link);">Upload some →</a>');
                emptyEl.style.display = 'block';
            }
            return;
        }
        if (emptyEl) emptyEl.style.display = 'none';

        images.forEach(function (img) {
            var cell = document.createElement('div');
            cell.style.cssText = 'cursor:pointer;border:2px solid transparent;border-radius:3px;overflow:hidden;aspect-ratio:1;background:#111;position:relative;';
            var orientation = orientationLabel(img);
            cell.title = (img.img_title || '') + (img.img_title ? ' — ' : '') + orientation.toLowerCase();

            var im = document.createElement('img');
            im.src = thumbUrl(img);
            im.alt = img.img_title || '';
            im.loading = 'lazy';
            im.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';
            cell.appendChild(im);

            var badge = document.createElement('span');
            badge.textContent = orientation;
            badge.style.cssText = 'position:absolute;right:5px;bottom:5px;padding:4px 6px;border-radius:2px;background:rgba(0,0,0,.88);color:#fff;border:1px solid rgba(255,255,255,.35);font-size:10px;font-weight:800;letter-spacing:.06em;line-height:1;pointer-events:none;';
            cell.appendChild(badge);

            cell.addEventListener('mouseenter', function () { cell.style.borderColor = 'var(--accent, #7aad5a)'; });
            cell.addEventListener('mouseleave', function () { cell.style.borderColor = 'transparent'; });
            cell.addEventListener('click', function () { pick(img); });

            grid.appendChild(cell);
        });
    }

    function pick(img) {
        if (mode === 'cover') {
            setCover(img);
        } else {
            insertShortcode(img.id);
        }
        closeModal();
    }

    // ── INLINE BODY INSERT ───────────────────────────────────────────────────
    function insertShortcode(id) {
        if (!ta) return;
        var width  = widthEl ? Math.max(20, Math.min(100, parseInt(widthEl.value, 10) || 100)) : 100;
        var tag    = '[img:g' + id + '|full|center|' + width + ']';
        var start  = ta.selectionStart;
        var end    = ta.selectionEnd;
        var before = ta.value.substring(0, start);
        var after  = ta.value.substring(end);
        var insert = '\n\n' + tag + '\n\n';
        ta.value = before + insert + after;
        ta.selectionStart = ta.selectionEnd = start + insert.length;
        ta.focus();
    }

    // ── COVER (featured image) ───────────────────────────────────────────────
    var coverInput   = document.getElementById('long-cover-image-id');
    var coverPreview = document.getElementById('long-cover-preview');

    function setCover(img) {
        if (!coverInput || !coverPreview) return;
        coverInput.value = img.id;
        var url  = thumbUrl(img);
        var name = (img.img_title || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        var orientation = orientationLabel(img);
        coverPreview.innerHTML =
            '<img src="' + url + '" style="width:100%;max-width:200px;height:auto;border-radius:3px;border:1px solid var(--border);" alt="">' +
            '<span class="dim" style="display:block;font-size:11px;margin-top:4px;">' + name + '</span>' +
            '<span style="display:inline-block;margin-top:4px;padding:3px 6px;border:1px solid var(--border);border-radius:2px;font-size:9px;font-weight:700;letter-spacing:.06em;">' + orientation + '</span>';
        if (coverBtn)  coverBtn.textContent = 'CHANGE';
        if (coverDel)  coverDel.style.display = '';
        // Re-point the cover framing cropper at the new (full) image and reset framing.
        if (window.SnapLongCoverCrop) {
            var full = base + String(img.img_file || img.thumb || '').replace(/^\//, '');
            window.SnapLongCoverCrop.setImage(full);
            window.SnapLongCoverCrop.reset();
            window.SnapLongCoverCrop.show();
        }
    }

    function clearCover() {
        if (!coverInput || !coverPreview) return;
        coverInput.value = '';
        coverPreview.innerHTML =
            '<div style="width:100%;max-width:200px;height:80px;background:var(--card-bg);border:1px dashed var(--border);border-radius:3px;display:flex;align-items:center;justify-content:center;">' +
            '<span class="dim" style="font-size:10px;text-align:center;padding:4px;">NO COVER</span></div>';
        if (coverBtn) coverBtn.textContent = 'SELECT COVER';
        if (coverDel) coverDel.style.display = 'none';
        if (window.SnapLongCoverCrop) window.SnapLongCoverCrop.hide();
    }

    // ── WIRING ───────────────────────────────────────────────────────────────
    if (insertBtn) insertBtn.addEventListener('click', function () { openModal('insert'); });
    if (coverBtn)  coverBtn.addEventListener('click',  function () { openModal('cover'); });
    if (coverDel)  coverDel.addEventListener('click',  clearCover);
    if (widthEl && widthOut) widthEl.addEventListener('input', function () {
        widthOut.textContent = this.value + '%';
    });
    Array.prototype.forEach.call(orientationButtons, function (button) {
        button.addEventListener('click', function () {
            setOrientationFilter(button.getAttribute('data-orientation'));
            fetchImages(search ? search.value.trim() : '');
        });
    });
    Array.prototype.forEach.call(scopeButtons, function (button) {
        button.addEventListener('click', function () {
            setSourceScope(button.getAttribute('data-scope'), true);
        });
    });

    closeB.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.style.display === 'block') closeModal();
    });
    search.addEventListener('input', function () {
        var q = this.value.trim();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { fetchImages(q); }, 300);
    });
}());
// ===== SNAPSMACK EOF =====
