/**
 * SNAPSMACK - Image Sorter Engine (ss-engine-sorter.js)
 *
 * Handles desktop detection, left-rail slide-in, accordion expand/collapse,
 * fjGallery grid, multi-select, drag-and-drop, drop handlers, visual feedback,
 * membership badges, collection cap (30), swap modal, filters, context menu,
 * and auto-scroll.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------
    var COL_CAP = 30;
    var PER_PAGE = 60;
    var AJAX_URL = 'smack-sorter.php';

    // -----------------------------------------------------------------------
    // State
    // -----------------------------------------------------------------------
    var selected   = {};        // { image_id: { id, thumb, title } }
    var containers = { albums: [], cats: [], collections: [] };
    var currentPage   = 1;
    var totalPages    = 1;
    var currentFilter = {};
    var dragging      = false;
    var dragGhost     = null;
    var swapQueue     = [];     // pending swap items: { add_id, col_id, col_name }
    var ctxImageId    = null;   // image under context menu
    var ctxImageUrl   = null;   // img_file URL for "open original"
    var hoverExpTimer = null;   // hover-to-expand timer
    var gallery       = null;   // fjGallery instance
    var railOpen      = true;

    // -----------------------------------------------------------------------
    // DOM refs — assigned after DOMContentLoaded
    // -----------------------------------------------------------------------
    var root, shell, touchBlock, rail, body, grid, gridLoading, pagination;
    var selCount, selClear, photoCount;
    var dragGhostEl, ctxMenu, popover, popoverBody;
    var swapOverlay, swapModal, swapTitle, swapDesc, swapGrid;
    var railToggle;

    // -----------------------------------------------------------------------
    // Utility
    // -----------------------------------------------------------------------
    function ajax(params, callback) {
        var form = new FormData();
        for (var k in params) {
            if (Array.isArray(params[k])) {
                params[k].forEach(function (v) { form.append(k + '[]', v); });
            } else {
                form.append(k, params[k]);
            }
        }
        fetch(AJAX_URL, { method: 'POST', body: form })
            .then(function (r) { return r.json(); })
            .then(callback)
            .catch(function (e) { console.error('[SORTER]', e); });
    }

    function $(id) { return document.getElementById(id); }
    function selCount_() { return Object.keys(selected).length; }

    // Flash a drop-target row
    function flashTarget(el, ok) {
        el.classList.remove('flash-ok', 'flash-err');
        void el.offsetWidth;
        el.classList.add(ok ? 'flash-ok' : 'flash-err');
        setTimeout(function () { el.classList.remove('flash-ok', 'flash-err'); }, 500);
    }

    // Show a brief tooltip near an element
    function showTip(el, msg, ok) {
        var tip = document.createElement('div');
        tip.textContent = msg;
        tip.style.cssText = 'position:fixed;z-index:9999;background:' + (ok ? '#2a7a2a' : '#8b1a1a') + ';color:#fff;padding:4px 10px;border-radius:4px;font-size:0.78rem;pointer-events:none;';
        var r = el.getBoundingClientRect();
        tip.style.left = (r.left + r.width / 2) + 'px';
        tip.style.top  = (r.top - 32) + 'px';
        tip.style.transform = 'translateX(-50%)';
        document.body.appendChild(tip);
        setTimeout(function () { tip.remove(); }, 2800);
    }

    // -----------------------------------------------------------------------
    // Desktop detection
    // -----------------------------------------------------------------------
    function checkDesktop() {
        var narrow = window.innerWidth < 1024;
        var touch  = window.matchMedia && window.matchMedia('(pointer:coarse)').matches;
        if (narrow || touch) {
            touchBlock.style.display = 'flex';
            shell.style.display = 'none';
        } else {
            touchBlock.style.display = 'none';
            shell.style.display = 'flex';
            init();
        }
    }

    // -----------------------------------------------------------------------
    // Init sequence: slide in rail → load containers → load photos
    // -----------------------------------------------------------------------
    function init() {
        // Phase 1: rail off-screen
        rail.style.transition = 'none';
        rail.style.transform  = 'translateX(-240px)';
        rail.classList.remove('open');

        // Phase 2: slide in
        setTimeout(function () {
            rail.style.transition = 'transform 0.3s ease';
            rail.style.transform  = 'translateX(0)';
            rail.classList.add('open');
        }, 80);

        // Phase 3: after rail lands, load containers then photos
        setTimeout(function () {
            loadContainers(function () {
                loadPhotos();
            });
        }, 420);
    }

    // -----------------------------------------------------------------------
    // Rail toggle
    // -----------------------------------------------------------------------
    function toggleRail() {
        railOpen = !railOpen;
        if (railOpen) {
            rail.style.transform = 'translateX(0)';
            rail.classList.add('open');
            body.classList.remove('rail-hidden');
        } else {
            rail.style.transform = 'translateX(-240px)';
            rail.classList.remove('open');
            body.classList.add('rail-hidden');
        }
    }

    // -----------------------------------------------------------------------
    // Accordion
    // -----------------------------------------------------------------------
    function bindAccordion() {
        document.querySelectorAll('.sorter-section-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var section = btn.closest('.sorter-section');
                section.classList.toggle('open');
            });
        });
    }

    // Hover-to-expand during drag
    function startHoverExpand(section) {
        if (section.classList.contains('open')) return;
        hoverExpTimer = setTimeout(function () {
            section.classList.add('open');
        }, 500);
    }
    function cancelHoverExpand() {
        clearTimeout(hoverExpTimer);
        hoverExpTimer = null;
    }

    // -----------------------------------------------------------------------
    // Load containers (albums, cats, collections)
    // -----------------------------------------------------------------------
    function loadContainers(callback) {
        ajax({ action: 'load_containers' }, function (data) {
            if (!data.ok) return;
            if (data.col_cap) COL_CAP = data.col_cap;
            containers.albums      = data.albums      || [];
            containers.cats        = data.cats         || [];
            containers.collections = data.collections  || [];
            renderRail();
            populateFilterSelects();
            if (callback) callback();
        });
    }

    function renderRail() {
        renderTargetList('album',      containers.albums,      $('sorter-targets-album'));
        renderTargetList('cat',        containers.cats,         $('sorter-targets-cat'));
        renderTargetList('collection', containers.collections,  $('sorter-targets-collection'));
    }

    function renderTargetList(type, items, ul) {
        ul.innerHTML = '';
        if (!items.length) {
            var empty = document.createElement('li');
            empty.className = 'sorter-target';
            empty.style.color = 'var(--text-muted)';
            empty.textContent = '(none)';
            ul.appendChild(empty);
            return;
        }
        items.forEach(function (item) {
            var li = document.createElement('li');
            li.className = 'sorter-target';
            li.dataset.type = type;
            li.dataset.id   = item.id;
            li.dataset.name = item.name;
            li.dataset.cnt  = item.cnt || 0;

            var nameSpan = document.createElement('span');
            nameSpan.className   = 'sorter-target-name';
            nameSpan.textContent = item.name;

            var cntSpan = document.createElement('span');
            cntSpan.className   = 'sorter-target-cnt';
            cntSpan.id          = 'sorter-cnt-' + type + '-' + item.id;
            cntSpan.textContent = item.cnt || '0';

            li.appendChild(nameSpan);
            li.appendChild(cntSpan);
            ul.appendChild(li);
            bindDropTarget(li);
        });
    }

    function populateFilterSelects() {
        populateSelect('sorter-filter-album',      containers.albums,      'Album…');
        populateSelect('sorter-filter-cat',         containers.cats,         'Category…');
        populateSelect('sorter-filter-collection',  containers.collections,  'Collection…');
    }

    function populateSelect(id, items, placeholder) {
        var sel = $(id);
        if (!sel) return;
        sel.innerHTML = '<option value="">' + placeholder + '</option>';
        items.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value       = item.id;
            opt.textContent = item.name;
            sel.appendChild(opt);
        });
    }

    // -----------------------------------------------------------------------
    // Load photos
    // -----------------------------------------------------------------------
    function loadPhotos(page) {
        page = page || 1;
        currentPage = page;

        gridLoading.style.display = 'block';
        grid.style.display = 'none';
        pagination.innerHTML = '';

        var params = Object.assign({ action: 'load_photos', page: page }, currentFilter);

        ajax(params, function (data) {
            gridLoading.style.display = 'none';
            if (!data.ok) { gridLoading.textContent = 'Error loading photos.'; return; }
            totalPages = data.pages || 1;
            photoCount.textContent = data.total + ' photo' + (data.total !== 1 ? 's' : '');
            renderGrid(data.photos);
            renderPagination(data.page, data.pages);
            grid.style.display = '';
        });
    }

    function renderGrid(photos) {
        // Destroy existing fjGallery instance
        if (gallery) {
            try { fjGallery(grid, 'destroy'); } catch(e) {}
            gallery = null;
        }
        grid.innerHTML = '';

        if (!photos || !photos.length) {
            gridLoading.textContent = 'No photos match this filter.';
            gridLoading.style.display = 'block';
            return;
        }

        photos.forEach(function (p) {
            var item = document.createElement('div');
            item.className      = 'justified-item';
            item.dataset.id     = p.id;
            item.dataset.thumb  = p.thumb;
            item.dataset.title  = p.title || '';
            item.dataset.url    = p.url   || '';
            item.dataset.albumCnt = p.album_cnt || 0;
            item.dataset.catCnt   = p.cat_cnt   || 0;
            item.dataset.colCnt   = p.col_cnt   || 0;
            // fjGallery needs natural dimensions on the wrapper
            item.dataset.width  = p.w || 4;
            item.dataset.height = p.h || 3;

            var check = document.createElement('span');
            check.className = 'sorter-card-check';
            check.textContent = '✓';

            var img = document.createElement('img');
            img.src   = p.thumb;
            img.alt   = p.title || '';
            img.draggable = false; // we handle drag ourselves

            var badge = buildBadge(p.album_cnt, p.cat_cnt, p.col_cnt);

            item.appendChild(check);
            item.appendChild(img);
            item.appendChild(badge);
            grid.appendChild(item);

            bindPhotoCard(item);
        });

        // Re-init fjGallery
        if (typeof window.fjGallery !== 'undefined') {
            gallery = fjGallery(grid, {
                itemSelector: '.justified-item',
                imageSelector: 'img',
                rowHeight: 200,
                gutter: 4,
                rowHeightTolerance: 0.25,
                lastRow: 'left',
                transitionDuration: '0.2s',
                resizeDebounce: 80
            });
            setTimeout(function () { try { fjGallery(grid, 'resize'); } catch(e) {} }, 60);
        }
    }

    function buildBadge(a, c, co) {
        var badge = document.createElement('span');
        badge.className = 'sorter-card-badge';
        updateBadgeContent(badge, a, c, co);
        return badge;
    }

    function updateBadgeContent(badge, a, c, co) {
        var total = (+a) + (+c) + (+co);
        if (!total) {
            badge.classList.add('empty');
            badge.title = '';
            badge.textContent = '';
        } else {
            badge.classList.remove('empty');
            badge.textContent = a + 'A / ' + c + 'C / ' + co + 'Co';
            badge.title = a + ' album' + (a !== 1 ? 's' : '') + ', ' +
                          c + ' categor' + (c !== 1 ? 'ies' : 'y') + ', ' +
                          co + ' collection' + (co !== 1 ? 's' : '');
        }
    }

    // -----------------------------------------------------------------------
    // Pagination
    // -----------------------------------------------------------------------
    function renderPagination(current, total) {
        pagination.innerHTML = '';
        if (total <= 1) return;
        var start = Math.max(1, current - 3);
        var end   = Math.min(total, current + 3);
        if (current > 1) addPageBtn('‹', current - 1);
        for (var p = start; p <= end; p++) addPageBtn(p, p, p === current);
        if (current < total) addPageBtn('›', current + 1);
    }

    function addPageBtn(label, page, active) {
        var btn = document.createElement('button');
        btn.textContent = label;
        if (active) btn.classList.add('active');
        btn.addEventListener('click', function () { loadPhotos(page); });
        pagination.appendChild(btn);
    }

    // -----------------------------------------------------------------------
    // Selection
    // -----------------------------------------------------------------------
    function toggleSelect(card) {
        var id = +card.dataset.id;
        if (selected[id]) {
            delete selected[id];
            card.classList.remove('selected');
        } else {
            selected[id] = { id: id, thumb: card.dataset.thumb, title: card.dataset.title };
            card.classList.add('selected');
        }
        updateSelUI();
    }

    function rangeSelect(targetCard) {
        var cards = Array.from(grid.querySelectorAll('.justified-item'));
        var lastSel = grid.querySelector('.justified-item.selected:last-of-type');
        // Find most-recently selected card by checking selection map order
        var selIds = Object.keys(selected).map(Number);
        if (!selIds.length) { toggleSelect(targetCard); return; }
        var targetIdx = cards.indexOf(targetCard);
        // Find nearest already-selected card
        var nearestIdx = -1, nearestDist = Infinity;
        cards.forEach(function (c, i) {
            if (selected[+c.dataset.id]) {
                var dist = Math.abs(i - targetIdx);
                if (dist < nearestDist) { nearestDist = dist; nearestIdx = i; }
            }
        });
        if (nearestIdx < 0) { toggleSelect(targetCard); return; }
        var lo = Math.min(nearestIdx, targetIdx);
        var hi = Math.max(nearestIdx, targetIdx);
        for (var i = lo; i <= hi; i++) {
            var c = cards[i];
            var cid = +c.dataset.id;
            selected[cid] = { id: cid, thumb: c.dataset.thumb, title: c.dataset.title };
            c.classList.add('selected');
        }
        updateSelUI();
    }

    function deselectAll() {
        selected = {};
        grid.querySelectorAll('.justified-item.selected').forEach(function (c) {
            c.classList.remove('selected');
        });
        updateSelUI();
    }

    function updateSelUI() {
        var n = selCount_();
        if (n) {
            selCount.style.display = '';
            selCount.textContent   = n + ' selected';
            selClear.style.display = '';
        } else {
            selCount.style.display = 'none';
            selClear.style.display = 'none';
        }
    }

    // -----------------------------------------------------------------------
    // Photo card event binding
    // -----------------------------------------------------------------------
    function bindPhotoCard(card) {
        // Click → select / deselect
        card.addEventListener('click', function (e) {
            if (e.shiftKey) { rangeSelect(card); return; }
            if (e.ctrlKey || e.metaKey) { toggleSelect(card); return; }
            // Plain click: if something is selected, toggle this one; else show memberships
            if (selCount_() > 0) { toggleSelect(card); return; }
            // No selection active: just select this card
            toggleSelect(card);
        });

        // Right-click → context menu
        card.addEventListener('contextmenu', function (e) {
            e.preventDefault();
            ctxImageId  = +card.dataset.id;
            ctxImageUrl = card.dataset.url;
            showContextMenu(e.clientX, e.clientY);
        });

        // Drag start
        card.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            if (e.target.closest('.sorter-card-badge')) return;
            startDrag(e, card);
        });

        // Badge click → membership popover
        var badge = card.querySelector('.sorter-card-badge');
        if (badge) {
            badge.addEventListener('click', function (e) {
                e.stopPropagation();
                ctxImageId = +card.dataset.id;
                showMembershipPopover(card, badge);
            });
        }
    }

    // -----------------------------------------------------------------------
    // Drag and drop
    // -----------------------------------------------------------------------
    var dragStartX, dragStartY, dragMoved;
    var DRAG_THRESHOLD = 6;

    function startDrag(e, card) {
        dragStartX = e.clientX;
        dragStartY = e.clientY;
        dragMoved  = false;

        function onMove(ev) {
            if (!dragMoved) {
                var dx = Math.abs(ev.clientX - dragStartX);
                var dy = Math.abs(ev.clientY - dragStartY);
                if (dx < DRAG_THRESHOLD && dy < DRAG_THRESHOLD) return;
                dragMoved = true;

                // If card not already selected, make it the sole selection
                var id = +card.dataset.id;
                if (!selected[id]) {
                    deselectAll();
                    selected[id] = { id: id, thumb: card.dataset.thumb, title: card.dataset.title };
                    card.classList.add('selected');
                    updateSelUI();
                }

                dragging = true;
                showDragGhost(ev.clientX, ev.clientY);
                grid.querySelectorAll('.justified-item.selected').forEach(function (c) {
                    c.classList.add('drag-source');
                });
                document.body.style.cursor = 'grabbing';
            }

            if (dragging) {
                moveDragGhost(ev.clientX, ev.clientY);
                handleDragOver(ev);
                autoScroll(ev);
            }
        }

        function onUp(ev) {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup',   onUp);
            document.body.style.cursor = '';

            if (dragging) {
                endDrag(ev);
            }
            dragging = false;
        }

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup',   onUp);
    }

    function showDragGhost(x, y) {
        dragGhostEl.innerHTML = '';
        dragGhostEl.style.display = '';

        var ids = Object.keys(selected);
        var first = selected[ids[0]];

        var img = document.createElement('img');
        img.src = first ? first.thumb : '';
        dragGhostEl.appendChild(img);

        if (ids.length > 1) {
            var cnt = document.createElement('div');
            cnt.className   = 'sorter-ghost-count';
            cnt.textContent = ids.length;
            dragGhostEl.appendChild(cnt);
        }

        moveDragGhost(x, y);
    }

    function moveDragGhost(x, y) {
        dragGhostEl.style.left = x + 'px';
        dragGhostEl.style.top  = y + 'px';
    }

    function hideDragGhost() {
        dragGhostEl.style.display = 'none';
    }

    var lastHoverTarget = null;

    function handleDragOver(ev) {
        var el = document.elementFromPoint(ev.clientX, ev.clientY);
        var targetLi = el ? el.closest('.sorter-target') : null;
        var sectionDiv = el ? el.closest('.sorter-section') : null;

        // Clear previous hover
        if (lastHoverTarget && lastHoverTarget !== targetLi) {
            lastHoverTarget.classList.remove('drag-over');
        }

        if (targetLi) {
            targetLi.classList.add('drag-over');
            lastHoverTarget = targetLi;
            cancelHoverExpand();
        } else if (sectionDiv) {
            lastHoverTarget = null;
            startHoverExpand(sectionDiv);
        } else {
            cancelHoverExpand();
            lastHoverTarget = null;
        }
    }

    function endDrag(ev) {
        hideDragGhost();
        cancelHoverExpand();

        grid.querySelectorAll('.justified-item.drag-source').forEach(function (c) {
            c.classList.remove('drag-source');
        });

        if (lastHoverTarget) {
            lastHoverTarget.classList.remove('drag-over');
            var target = lastHoverTarget;
            lastHoverTarget = null;
            handleDrop(target, ev.shiftKey);
        } else {
            lastHoverTarget = null;
        }
    }

    function handleDrop(targetEl, shiftHeld) {
        var type = targetEl.dataset.type;
        var cid  = +targetEl.dataset.id;
        var ids  = Object.keys(selected).map(Number).filter(Boolean);
        if (!ids.length || !cid) return;

        var action = type === 'album'      ? 'add_to_album'
                   : type === 'cat'        ? 'add_to_cat'
                   : type === 'collection' ? 'add_to_collection'
                   : null;
        if (!action) return;

        // Show loading on target
        var cntEl = $('sorter-cnt-' + type + '-' + cid);

        ajax({ action: action, container_id: cid, image_ids: ids }, function (data) {
            if (!data.ok) {
                flashTarget(targetEl, false);
                showTip(targetEl, data.err || 'Error', false);
                return;
            }

            // Update count in rail
            if (cntEl) cntEl.textContent = data.count;
            // Update containers cache
            var arr = type === 'album' ? containers.albums
                    : type === 'cat'   ? containers.cats
                    : containers.collections;
            var item = arr.find(function (a) { return a.id == cid; });
            if (item) item.cnt = data.count;

            if (type === 'collection' && data.refused && data.refused.length) {
                if (shiftHeld) {
                    // Queue swap modal for each refused image
                    data.refused.forEach(function (rid) {
                        swapQueue.push({ add_id: rid, col_id: cid, col_name: targetEl.dataset.name });
                    });
                    processSwapQueue();
                } else {
                    flashTarget(targetEl, false);
                    showTip(targetEl,
                        'Collection is full (' + COL_CAP + '/' + COL_CAP + '). Hold Shift and drop to choose which image to replace.',
                        false
                    );
                }
            } else {
                flashTarget(targetEl, true);
            }

            // Update badges on dropped cards
            ids.forEach(function (iid) {
                var card = grid.querySelector('.justified-item[data-id="' + iid + '"]');
                if (!card) return;
                var a  = +card.dataset.albumCnt;
                var c  = +card.dataset.catCnt;
                var co = +card.dataset.colCnt;
                if (type === 'album' && !data.dupes.includes(iid)) {
                    a++;
                    card.dataset.albumCnt = a;
                } else if (type === 'cat' && !data.dupes.includes(iid)) {
                    c++;
                    card.dataset.catCnt = c;
                } else if (type === 'collection' && !data.refused.includes(iid) && !data.dupes.includes(iid)) {
                    co++;
                    card.dataset.colCnt = co;
                }
                var badge = card.querySelector('.sorter-card-badge');
                if (badge) updateBadgeContent(badge, a, c, co);
            });
        });
    }

    // -----------------------------------------------------------------------
    // Auto-scroll during drag
    // -----------------------------------------------------------------------
    var autoScrollRAF = null;
    function autoScroll(ev) {
        cancelAnimationFrame(autoScrollRAF);
        var ZONE = 60, MAX_SPEED = 14;

        function scroll(el) {
            var r = el.getBoundingClientRect();
            var dy = 0;
            if (ev.clientY < r.top + ZONE)    dy = -MAX_SPEED * (1 - (ev.clientY - r.top)    / ZONE);
            if (ev.clientY > r.bottom - ZONE)  dy =  MAX_SPEED * (1 - (r.bottom - ev.clientY) / ZONE);
            if (dy) el.scrollTop += dy;
        }

        autoScrollRAF = requestAnimationFrame(function () {
            var gridWrap = $('sorter-grid-wrap');
            var railEl   = $('sorter-rail');
            if (gridWrap) scroll(gridWrap);
            if (railEl)   scroll(railEl);
        });
    }

    // -----------------------------------------------------------------------
    // Drop target binding
    // -----------------------------------------------------------------------
    function bindDropTarget(li) {
        // Hover handled via mousemove during drag; nothing extra needed here
        // (targets respond to drag-over class set by handleDragOver)
    }

    // -----------------------------------------------------------------------
    // Context menu
    // -----------------------------------------------------------------------
    function showContextMenu(x, y) {
        var removeList = $('sorter-ctx-remove-list');
        removeList.innerHTML = '<li style="color:var(--text-muted);font-size:0.78rem;">Loading…</li>';

        ajax({ action: 'get_memberships', image_id: ctxImageId }, function (data) {
            removeList.innerHTML = '';
            if (!data.ok) return;
            var all = [];
            (data.albums || []).forEach(function (a) { all.push({ type: 'album', id: a.id, name: a.name }); });
            (data.cats   || []).forEach(function (c) { all.push({ type: 'cat',   id: c.id, name: c.name }); });
            (data.collections || []).forEach(function (c) { all.push({ type: 'collection', id: c.id, name: c.name }); });

            if (!all.length) {
                var none = document.createElement('li');
                none.textContent = '(none)';
                none.style.color = 'var(--text-muted)';
                removeList.appendChild(none);
            } else {
                all.forEach(function (m) {
                    var li = document.createElement('li');
                    li.textContent = m.name + ' (' + m.type + ')';
                    li.addEventListener('click', function () {
                        hideContextMenu();
                        doRemoveFrom(m.type, m.id, ctxImageId);
                    });
                    removeList.appendChild(li);
                });
            }
        });

        ctxMenu.style.display = '';
        // Position — keep on screen
        var vw = window.innerWidth, vh = window.innerHeight;
        var w  = 180, h = 200;
        ctxMenu.style.left = Math.min(x, vw - w - 8) + 'px';
        ctxMenu.style.top  = Math.min(y, vh - h - 8) + 'px';
    }

    function hideContextMenu() {
        ctxMenu.style.display = 'none';
    }

    function bindContextMenu() {
        document.addEventListener('click', function (e) {
            if (!ctxMenu.contains(e.target)) hideContextMenu();
        });

        ctxMenu.querySelectorAll('li[data-action]').forEach(function (li) {
            li.addEventListener('click', function () {
                var act = li.dataset.action;
                hideContextMenu();
                if (act === 'memberships') {
                    var card = grid.querySelector('.justified-item[data-id="' + ctxImageId + '"]');
                    var badge = card ? card.querySelector('.sorter-card-badge') : null;
                    showMembershipPopover(card, badge || li);
                } else if (act === 'edit_photo') {
                    window.open('smack-edit.php?id=' + ctxImageId, '_blank');
                } else if (act === 'open_original') {
                    window.open(ctxImageUrl, '_blank');
                } else if (act === 'delete_photo') {
                    if (confirm('Delete this photo? This cannot be undone.')) {
                        ajax({ action: 'delete_photo', image_id: ctxImageId }, function () {
                            loadPhotos(currentPage);
                        });
                    }
                }
            });
        });
    }

    // -----------------------------------------------------------------------
    // Remove from
    // -----------------------------------------------------------------------
    function doRemoveFrom(type, containerId, imageId) {
        ajax({ action: 'remove_from', type: type, container_id: containerId, image_id: imageId },
            function (data) {
                if (!data.ok) return;
                // Update badge on card
                var card = grid.querySelector('.justified-item[data-id="' + imageId + '"]');
                if (card) {
                    var a  = +card.dataset.albumCnt;
                    var c  = +card.dataset.catCnt;
                    var co = +card.dataset.colCnt;
                    if (type === 'album'      && a  > 0) { a--;  card.dataset.albumCnt = a; }
                    if (type === 'cat'        && c  > 0) { c--;  card.dataset.catCnt   = c; }
                    if (type === 'collection' && co > 0) { co--; card.dataset.colCnt   = co; }
                    var badge = card.querySelector('.sorter-card-badge');
                    if (badge) updateBadgeContent(badge, a, c, co);
                }
                // Update rail count
                var cntEl = $('sorter-cnt-' + type + '-' + containerId);
                if (cntEl) {
                    var n = Math.max(0, +cntEl.textContent - 1);
                    cntEl.textContent = n;
                }
            }
        );
    }

    // -----------------------------------------------------------------------
    // Membership popover
    // -----------------------------------------------------------------------
    function showMembershipPopover(anchor, near) {
        popoverBody.innerHTML = '<em style="color:var(--text-muted)">Loading…</em>';
        popover.style.display = '';

        // Position near anchor
        var r = (near || anchor).getBoundingClientRect();
        var vw = window.innerWidth, vh = window.innerHeight;
        var pw = 280, ph = 200;
        var left = r.right + 8;
        var top  = r.top;
        if (left + pw > vw) left = r.left - pw - 8;
        if (top  + ph > vh) top  = vh - ph - 8;
        popover.style.left = Math.max(8, left) + 'px';
        popover.style.top  = Math.max(8, top)  + 'px';

        ajax({ action: 'get_memberships', image_id: ctxImageId }, function (data) {
            if (!data.ok) { popoverBody.innerHTML = '<em>Error</em>'; return; }
            popoverBody.innerHTML = '';
            renderPopoverSection('Albums',      'album',      data.albums      || []);
            renderPopoverSection('Categories',  'cat',         data.cats         || []);
            renderPopoverSection('Collections', 'collection',  data.collections  || []);
        });
    }

    function renderPopoverSection(label, type, items) {
        if (!items.length) return;
        var h = document.createElement('div');
        h.className   = 'sorter-popover-type';
        h.textContent = label.toUpperCase();
        popoverBody.appendChild(h);
        items.forEach(function (m) {
            var row = document.createElement('div');
            row.className = 'sorter-popover-item';

            var name = document.createElement('span');
            name.textContent = m.name;

            var btn = document.createElement('button');
            btn.className   = 'sorter-popover-remove';
            btn.textContent = 'Remove';
            btn.addEventListener('click', function () {
                doRemoveFrom(type, m.id, ctxImageId);
                row.remove();
            });

            row.appendChild(name);
            row.appendChild(btn);
            popoverBody.appendChild(row);
        });
    }

    // -----------------------------------------------------------------------
    // Swap modal
    // -----------------------------------------------------------------------
    function processSwapQueue() {
        if (!swapQueue.length) return;
        var item = swapQueue.shift();
        openSwapModal(item);
    }

    function openSwapModal(item) {
        swapTitle.textContent = 'Collection “' + item.col_name + '” is full';
        swapDesc.textContent  = 'To add this image, choose which existing image to remove.';
        swapGrid.innerHTML    = '<em style="color:var(--text-muted)">Loading collection images…</em>';
        swapOverlay.style.display = '';

        // Load collection contents via filter
        ajax({ action: 'load_photos', page: 1, in_collection: item.col_id }, function (data) {
            swapGrid.innerHTML = '';
            if (!data.ok || !data.photos.length) {
                swapGrid.innerHTML = '<em>Could not load collection images.</em>';
                return;
            }
            data.photos.forEach(function (p) {
                var thumb = document.createElement('div');
                thumb.className = 'sorter-swap-thumb';
                var img = document.createElement('img');
                img.src = p.thumb;
                img.alt = p.title || '';
                thumb.appendChild(img);
                thumb.addEventListener('click', function () {
                    swapOverlay.style.display = 'none';
                    ajax({
                        action: 'swap_collection',
                        container_id: item.col_id,
                        remove_id: p.id,
                        add_id: item.add_id
                    }, function (data) {
                        var cntEl = $('sorter-cnt-collection-' + item.col_id);
                        if (cntEl) cntEl.textContent = data.count || '';
                        // Update badges
                        var newCard = grid.querySelector('.justified-item[data-id="' + item.add_id + '"]');
                        if (newCard) {
                            var co = +newCard.dataset.colCnt + 1;
                            newCard.dataset.colCnt = co;
                            var b = newCard.querySelector('.sorter-card-badge');
                            if (b) updateBadgeContent(b, +newCard.dataset.albumCnt, +newCard.dataset.catCnt, co);
                        }
                        var oldCard = grid.querySelector('.justified-item[data-id="' + p.id + '"]');
                        if (oldCard) {
                            var co2 = Math.max(0, +oldCard.dataset.colCnt - 1);
                            oldCard.dataset.colCnt = co2;
                            var b2 = oldCard.querySelector('.sorter-card-badge');
                            if (b2) updateBadgeContent(b2, +oldCard.dataset.albumCnt, +oldCard.dataset.catCnt, co2);
                        }
                        // Continue queue
                        processSwapQueue();
                    });
                });
                swapGrid.appendChild(thumb);
            });
        });
    }

    // -----------------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------------
    function readFilters() {
        var f = {};
        var membership = $('sorter-filter-membership').value;
        if (membership) f.filter = membership;

        var alb = $('sorter-filter-album').value;
        var cat = $('sorter-filter-cat').value;
        var col = $('sorter-filter-collection').value;
        if (alb) f.in_album      = alb;
        if (cat) f.in_cat        = cat;
        if (col) f.in_collection = col;

        var q    = $('sorter-filter-q').value.trim();
        var dfr  = $('sorter-filter-date-from').value;
        var dto  = $('sorter-filter-date-to').value;
        if (q)   f.q         = q;
        if (dfr) f.date_from = dfr;
        if (dto) f.date_to   = dto;

        return f;
    }

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        root        = $('sorter-root');
        shell       = $('sorter-shell');
        touchBlock  = $('sorter-touch-block');
        rail        = $('sorter-rail');
        body        = $('sorter-body');
        grid        = $('sorter-grid');
        gridLoading = $('sorter-grid-loading');
        pagination  = $('sorter-pagination');
        selCount    = $('sorter-sel-count');
        selClear    = $('sorter-sel-clear');
        photoCount  = $('sorter-photo-count');
        dragGhostEl = $('sorter-drag-ghost');
        ctxMenu     = $('sorter-context-menu');
        popover     = $('sorter-membership-popover');
        popoverBody = $('sorter-popover-body');
        swapOverlay = $('sorter-swap-overlay');
        swapModal   = $('sorter-swap-modal');
        swapTitle   = $('sorter-swap-title');
        swapDesc    = $('sorter-swap-desc');
        swapGrid    = $('sorter-swap-grid');
        railToggle  = $('sorter-rail-toggle');

        // Desktop check
        checkDesktop();
        window.addEventListener('resize', function () {
            if (shell.style.display !== 'none') {
                try { fjGallery(grid, 'resize'); } catch(e) {}
            }
        });

        // Rail toggle
        if (railToggle) railToggle.addEventListener('click', toggleRail);

        // Accordion
        bindAccordion();

        // Clear selection
        if (selClear) selClear.addEventListener('click', deselectAll);

        // Filter apply
        var applyBtn = $('sorter-filter-apply');
        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                currentFilter = readFilters();
                deselectAll();
                loadPhotos(1);
            });
        }

        // Enter key on search field
        var qField = $('sorter-filter-q');
        if (qField) {
            qField.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { currentFilter = readFilters(); loadPhotos(1); }
            });
        }

        // Context menu
        bindContextMenu();

        // Popover close
        $('sorter-popover-close').addEventListener('click', function () {
            popover.style.display = 'none';
        });
        document.addEventListener('click', function (e) {
            if (popover.style.display !== 'none' && !popover.contains(e.target)) {
                popover.style.display = 'none';
            }
        });

        // Swap modal close / cancel
        $('sorter-swap-close').addEventListener('click', function () {
            swapOverlay.style.display = 'none';
            swapQueue = [];
        });
        $('sorter-swap-cancel').addEventListener('click', function () {
            swapOverlay.style.display = 'none';
            swapQueue = [];
        });

        // Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                hideContextMenu();
                popover.style.display      = 'none';
                swapOverlay.style.display  = 'none';
                swapQueue = [];
            }
        });
    });

})();
// ===== SNAPSMACK EOF =====
