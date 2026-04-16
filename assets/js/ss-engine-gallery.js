/**
 * SNAPSMACK - Media Gallery Engine
 *
 * AJAX-driven image grid with lazy loading, search, filtering, bulk
 * operations, rubber-band selection, and quick-edit panel.
 * Operates in two modes:
 *   - Standalone: full admin page at smack-gallery.php
 *   - Picker: modal launched from editors via data-gallery-picker
 */
(function () {
    'use strict';

    // ── STATE ────────────────────────────────────────────────────────────
    var page       = 1;
    var perPage    = 50;
    var totalPages = 1;
    var total      = 0;
    var loading    = false;
    var selected   = {};         // id → true
    var images     = [];         // current page cache
    var allLoaded  = [];         // all loaded images across pages
    var searchTimer = null;
    var pickerMode = document.querySelector('[data-gallery-picker]') !== null;

    // ── DOM REFS ─────────────────────────────────────────────────────────
    var grid       = document.getElementById('gallery-grid');
    var search     = document.getElementById('gal-search');
    var albumSel   = document.getElementById('gal-album');
    var catSel     = document.getElementById('gal-cat');
    var statusSel  = document.getElementById('gal-status');
    var cameraSel  = document.getElementById('gal-camera');
    var dateFrom   = document.getElementById('gal-date-from');
    var dateTo     = document.getElementById('gal-date-to');
    var clearBtn   = document.getElementById('gal-clear');
    var loadMore   = document.getElementById('gallery-load-more');
    var loadBtn    = document.getElementById('gal-load-more');
    var pageInfo   = document.getElementById('gal-page-info');
    var bulkBar    = document.getElementById('gallery-bulk-bar');
    var selCount   = document.getElementById('gal-sel-count');
    var countLabel = document.getElementById('gallery-count');

    // Quick edit
    var qePanel    = document.getElementById('gallery-quickedit');
    var qeClose    = document.getElementById('qe-close');
    var qeImg      = document.getElementById('qe-img');
    var qeId       = document.getElementById('qe-id');
    var qeTitle    = document.getElementById('qe-title');
    var qeStatus   = document.getElementById('qe-status');
    var qeTags     = document.getElementById('qe-tags');
    var qeCats     = document.getElementById('qe-cats');
    var qeAlbums   = document.getElementById('qe-albums');
    var qeMeta     = document.getElementById('qe-meta');
    var qeSave     = document.getElementById('qe-save');
    var qeEditLink = document.getElementById('qe-edit-link');

    if (!grid) return; // not on gallery page

    // ── FETCH IMAGES ─────────────────────────────────────────────────────
    function buildQuery() {
        var params = ['ajax=1', 'page=' + page, 'per_page=' + perPage];
        var q = (search ? search.value.trim() : '');
        if (q)                             params.push('q=' + encodeURIComponent(q));
        if (albumSel && albumSel.value)    params.push('album=' + albumSel.value);
        if (catSel && catSel.value)        params.push('cat=' + catSel.value);
        if (statusSel && statusSel.value)  params.push('status=' + statusSel.value);
        if (cameraSel && cameraSel.value)  params.push('camera=' + encodeURIComponent(cameraSel.value));
        if (dateFrom && dateFrom.value)    params.push('date_from=' + dateFrom.value);
        if (dateTo && dateTo.value)        params.push('date_to=' + dateTo.value);
        return 'smack-gallery.php?' + params.join('&');
    }

    function fetchImages(append) {
        if (loading) return;
        loading = true;

        if (!append) {
            page = 1;
            grid.innerHTML = '<div class="gallery-loading">Loading…</div>';
        }

        var xhr = new XMLHttpRequest();
        xhr.open('GET', buildQuery());
        xhr.onload = function () {
            loading = false;
            if (xhr.status !== 200) {
                grid.innerHTML = '<div class="gallery-empty">Error loading images.</div>';
                return;
            }
            var data;
            try { data = JSON.parse(xhr.responseText); } catch (e) {
                grid.innerHTML = '<div class="gallery-empty">Invalid response.</div>';
                return;
            }

            total      = data.total;
            totalPages = data.pages;
            images     = data.images;

            if (!append) {
                allLoaded = [];
                grid.innerHTML = '';
            }

            if (data.images.length === 0 && !append) {
                grid.innerHTML = '<div class="gallery-empty">No images match your filters.</div>';
            }

            for (var i = 0; i < data.images.length; i++) {
                allLoaded.push(data.images[i]);
                grid.appendChild(buildCard(data.images[i]));
            }

            observeLazy();
            updateLoadMore();
            if (countLabel) countLabel.textContent = total.toLocaleString() + ' images';
        };
        xhr.onerror = function () {
            loading = false;
            grid.innerHTML = '<div class="gallery-empty">Network error.</div>';
        };
        xhr.send();
    }

    // ── BUILD CARD ───────────────────────────────────────────────────────
    function buildCard(img) {
        var card = document.createElement('div');
        card.className = 'gallery-card';
        card.setAttribute('data-id', img.id);
        if (selected[img.id]) card.classList.add('selected');

        var thumb = document.createElement('img');
        thumb.className = 'gallery-card-img';
        thumb.setAttribute('data-src', img.thumb);
        thumb.alt = img.img_title || '';
        thumb.loading = 'lazy';
        card.appendChild(thumb);

        if (img.img_status === 'draft') {
            var badge = document.createElement('span');
            badge.className = 'gallery-card-status gallery-card-status--draft';
            badge.textContent = 'Draft';
            card.appendChild(badge);
        }

        var info = document.createElement('div');
        info.className = 'gallery-card-info';
        info.textContent = img.img_title || '(untitled)';
        var dim = document.createElement('span');
        dim.className = 'dim';
        dim.textContent = (img.img_width || '?') + '×' + (img.img_height || '?');
        if (img.camera) dim.textContent += ' · ' + img.camera;
        info.appendChild(dim);
        card.appendChild(info);

        // Click handlers
        card.addEventListener('click', function (e) {
            if (e.ctrlKey || e.metaKey || e.shiftKey) {
                e.preventDefault();
                toggleSelect(img.id, card);
            } else if (pickerMode) {
                // Picker: fire event and close
                window.dispatchEvent(new CustomEvent('gallery-pick', { detail: img }));
            } else {
                openQuickEdit(img);
            }
        });

        return card;
    }

    // ── LAZY LOADING ─────────────────────────────────────────────────────
    var observer = null;
    function observeLazy() {
        if (!('IntersectionObserver' in window)) {
            // Fallback: load all
            var imgs = grid.querySelectorAll('img[data-src]');
            for (var i = 0; i < imgs.length; i++) loadImage(imgs[i]);
            return;
        }
        if (!observer) {
            observer = new IntersectionObserver(function (entries) {
                for (var i = 0; i < entries.length; i++) {
                    if (entries[i].isIntersecting) {
                        loadImage(entries[i].target);
                        observer.unobserve(entries[i].target);
                    }
                }
            }, { rootMargin: '200px' });
        }
        var imgs = grid.querySelectorAll('img[data-src]');
        for (var i = 0; i < imgs.length; i++) observer.observe(imgs[i]);
    }

    function loadImage(img) {
        var src = img.getAttribute('data-src');
        if (!src) return;
        img.onload = function () { img.classList.add('loaded'); };
        img.onerror = function () { img.classList.add('loaded'); };
        img.src = src;
        img.removeAttribute('data-src');
    }

    // ── SELECTION ────────────────────────────────────────────────────────
    function toggleSelect(id, card) {
        if (selected[id]) {
            delete selected[id];
            if (card) card.classList.remove('selected');
        } else {
            selected[id] = true;
            if (card) card.classList.add('selected');
        }
        updateBulkBar();
    }

    function deselectAll() {
        selected = {};
        var cards = grid.querySelectorAll('.gallery-card.selected');
        for (var i = 0; i < cards.length; i++) cards[i].classList.remove('selected');
        updateBulkBar();
    }

    function updateBulkBar() {
        var count = Object.keys(selected).length;
        if (bulkBar) bulkBar.style.display = count > 0 ? 'flex' : 'none';
        if (selCount) selCount.textContent = count;
    }

    // ── RUBBER BAND DRAG SELECT ─────────────────────────────────────────
    var rubberband = null;
    var rbStart = null;

    grid.addEventListener('mousedown', function (e) {
        // Only start rubberband on empty grid space or with shift
        if (e.button !== 0) return;
        if (e.target.closest('.gallery-card') && !e.shiftKey) return;

        rbStart = { x: e.clientX, y: e.clientY };
        rubberband = document.createElement('div');
        rubberband.className = 'gallery-rubberband';
        document.body.appendChild(rubberband);

        e.preventDefault();
    });

    document.addEventListener('mousemove', function (e) {
        if (!rubberband || !rbStart) return;
        var x1 = Math.min(rbStart.x, e.clientX);
        var y1 = Math.min(rbStart.y, e.clientY);
        var x2 = Math.max(rbStart.x, e.clientX);
        var y2 = Math.max(rbStart.y, e.clientY);
        rubberband.style.left   = x1 + 'px';
        rubberband.style.top    = y1 + 'px';
        rubberband.style.width  = (x2 - x1) + 'px';
        rubberband.style.height = (y2 - y1) + 'px';
    });

    document.addEventListener('mouseup', function (e) {
        if (!rubberband || !rbStart) return;
        var x1 = Math.min(rbStart.x, e.clientX);
        var y1 = Math.min(rbStart.y, e.clientY);
        var x2 = Math.max(rbStart.x, e.clientX);
        var y2 = Math.max(rbStart.y, e.clientY);

        // Only if dragged at least 10px
        if ((x2 - x1) > 10 || (y2 - y1) > 10) {
            var cards = grid.querySelectorAll('.gallery-card');
            for (var i = 0; i < cards.length; i++) {
                var rect = cards[i].getBoundingClientRect();
                if (rect.left < x2 && rect.right > x1 && rect.top < y2 && rect.bottom > y1) {
                    var id = parseInt(cards[i].getAttribute('data-id'));
                    selected[id] = true;
                    cards[i].classList.add('selected');
                }
            }
            updateBulkBar();
        }

        document.body.removeChild(rubberband);
        rubberband = null;
        rbStart = null;
    });

    // ── LOAD MORE ────────────────────────────────────────────────────────
    function updateLoadMore() {
        if (!loadMore) return;
        if (page < totalPages) {
            loadMore.style.display = 'block';
            if (pageInfo) pageInfo.textContent = 'Page ' + page + ' of ' + totalPages + ' (' + total + ' images)';
        } else {
            loadMore.style.display = 'none';
        }
    }

    if (loadBtn) {
        loadBtn.addEventListener('click', function () {
            page++;
            fetchImages(true);
        });
    }

    // ── FILTER EVENTS ────────────────────────────────────────────────────
    function onFilterChange() { fetchImages(false); }

    if (search) {
        search.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { fetchImages(false); }, 300);
        });
    }
    if (albumSel)  albumSel.addEventListener('change', onFilterChange);
    if (catSel)    catSel.addEventListener('change', onFilterChange);
    if (statusSel) statusSel.addEventListener('change', onFilterChange);
    if (cameraSel) cameraSel.addEventListener('change', onFilterChange);
    if (dateFrom)  dateFrom.addEventListener('change', onFilterChange);
    if (dateTo)    dateTo.addEventListener('change', onFilterChange);

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (search)    search.value    = '';
            if (albumSel)  albumSel.value  = '';
            if (catSel)    catSel.value    = '';
            if (statusSel) statusSel.value = '';
            if (cameraSel) cameraSel.value = '';
            if (dateFrom)  dateFrom.value  = '';
            if (dateTo)    dateTo.value    = '';
            fetchImages(false);
        });
    }

    // ── CAMERA LIST POPULATE ─────────────────────────────────────────────
    function loadCameras() {
        if (!cameraSel) return;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'smack-gallery.php?cameras=1');
        xhr.onload = function () {
            if (xhr.status !== 200) return;
            var cams;
            try { cams = JSON.parse(xhr.responseText); } catch (e) { return; }
            for (var i = 0; i < cams.length; i++) {
                var opt = document.createElement('option');
                opt.value = cams[i];
                opt.textContent = cams[i];
                cameraSel.appendChild(opt);
            }
        };
        xhr.send();
    }

    // ── QUICK EDIT ───────────────────────────────────────────────────────
    function openQuickEdit(img) {
        if (!qePanel) return;
        qePanel.style.display = 'flex';
        qeId.value = img.id;
        qeImg.src = img.img_file || img.thumb;
        qeTitle.value = img.img_title || '';
        qeStatus.value = img.img_status || 'published';
        qeTags.value = (img.tags || []).join(', ');

        // Check matching categories
        var catChecks = qeCats.querySelectorAll('input[type="checkbox"]');
        var imgCats = img.categories || [];
        for (var i = 0; i < catChecks.length; i++) {
            var label = catChecks[i].parentElement.textContent.trim();
            catChecks[i].checked = imgCats.indexOf(label) !== -1;
        }

        // Check matching albums
        var albumChecks = qeAlbums.querySelectorAll('input[type="checkbox"]');
        var imgAlbums = img.albums || [];
        for (var i = 0; i < albumChecks.length; i++) {
            var label = albumChecks[i].parentElement.textContent.trim();
            albumChecks[i].checked = imgAlbums.indexOf(label) !== -1;
        }

        // Meta info
        var meta = [];
        if (img.img_date) meta.push('Date: ' + img.img_date);
        if (img.img_width && img.img_height) meta.push('Size: ' + img.img_width + '×' + img.img_height);
        if (img.camera) meta.push('Camera: ' + img.camera);
        if (img.lens)   meta.push('Lens: ' + img.lens);
        qeMeta.innerHTML = meta.join('<br>');

        // Full edit link
        if (qeEditLink && img.post_id) {
            qeEditLink.href = 'smack-edit.php?id=' + img.post_id;
        }

        document.getElementById('qe-title-display').textContent = img.img_title || 'Quick Edit';
    }

    if (qeClose) {
        qeClose.addEventListener('click', function () {
            qePanel.style.display = 'none';
        });
    }

    if (qeSave) {
        qeSave.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('action', 'quick_edit');
            fd.append('id', qeId.value);
            fd.append('title', qeTitle.value);
            fd.append('status', qeStatus.value);
            fd.append('tags', qeTags.value);

            var catChecks = qeCats.querySelectorAll('input[type="checkbox"]:checked');
            for (var i = 0; i < catChecks.length; i++) fd.append('cat_ids[]', catChecks[i].value);

            var albumChecks = qeAlbums.querySelectorAll('input[type="checkbox"]:checked');
            for (var i = 0; i < albumChecks.length; i++) fd.append('album_ids[]', albumChecks[i].value);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'smack-gallery.php');
            xhr.onload = function () {
                if (xhr.status === 200) {
                    qePanel.style.display = 'none';
                    fetchImages(false);
                }
            };
            xhr.send(fd);
        });
    }

    // ── BULK OPERATIONS ──────────────────────────────────────────────────
    if (bulkBar) {
        bulkBar.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-bulk]');
            if (!btn) return;
            var op = btn.getAttribute('data-bulk');
            var ids = Object.keys(selected).map(Number);
            if (ids.length === 0) return;

            if (op === 'delete' && !confirm('Delete ' + ids.length + ' image(s)? This cannot be undone.')) return;

            var fd = new FormData();
            fd.append('action', 'bulk');
            fd.append('bulk_op', op);
            for (var i = 0; i < ids.length; i++) fd.append('ids[]', ids[i]);

            // Extra params for assign ops
            if (op === 'assign_cat') {
                var bulkCat = document.getElementById('gal-bulk-cat');
                if (!bulkCat || !bulkCat.value) return;
                fd.append('bulk_cat_id', bulkCat.value);
            }
            if (op === 'assign_album') {
                var bulkAlbum = document.getElementById('gal-bulk-album');
                if (!bulkAlbum || !bulkAlbum.value) return;
                fd.append('bulk_album_id', bulkAlbum.value);
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'smack-gallery.php');
            xhr.onload = function () {
                deselectAll();
                fetchImages(false);
            };
            xhr.send(fd);
        });

        // Bulk assign on select change
        var bulkCat = document.getElementById('gal-bulk-cat');
        if (bulkCat) {
            bulkCat.addEventListener('change', function () {
                if (!this.value) return;
                var ids = Object.keys(selected).map(Number);
                if (ids.length === 0) return;
                var fd = new FormData();
                fd.append('action', 'bulk');
                fd.append('bulk_op', 'assign_cat');
                fd.append('bulk_cat_id', this.value);
                for (var i = 0; i < ids.length; i++) fd.append('ids[]', ids[i]);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'smack-gallery.php');
                xhr.onload = function () { fetchImages(false); };
                xhr.send(fd);
                this.value = '';
            });
        }

        var bulkAlbum = document.getElementById('gal-bulk-album');
        if (bulkAlbum) {
            bulkAlbum.addEventListener('change', function () {
                if (!this.value) return;
                var ids = Object.keys(selected).map(Number);
                if (ids.length === 0) return;
                var fd = new FormData();
                fd.append('action', 'bulk');
                fd.append('bulk_op', 'assign_album');
                fd.append('bulk_album_id', this.value);
                for (var i = 0; i < ids.length; i++) fd.append('ids[]', ids[i]);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'smack-gallery.php');
                xhr.onload = function () { fetchImages(false); };
                xhr.send(fd);
                this.value = '';
            });
        }
    }

    // Deselect all button
    var deselectBtn = document.getElementById('gal-deselect');
    if (deselectBtn) deselectBtn.addEventListener('click', deselectAll);

    // ── KEYBOARD SHORTCUTS ───────────────────────────────────────────────
    document.addEventListener('keydown', function (e) {
        // Escape closes quick-edit panel
        if (e.key === 'Escape' && qePanel && qePanel.style.display !== 'none') {
            qePanel.style.display = 'none';
        }
        // Ctrl+A selects all visible
        if ((e.ctrlKey || e.metaKey) && e.key === 'a' && document.activeElement === document.body) {
            e.preventDefault();
            var cards = grid.querySelectorAll('.gallery-card');
            for (var i = 0; i < cards.length; i++) {
                var id = parseInt(cards[i].getAttribute('data-id'));
                selected[id] = true;
                cards[i].classList.add('selected');
            }
            updateBulkBar();
        }
    });

    // ── INIT ─────────────────────────────────────────────────────────────
    loadCameras();
    fetchImages(false);

})();
