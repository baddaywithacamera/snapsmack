/**
 * ss-engine-menu-builder.js
 * SnapSmack admin drag-and-drop menu builder.
 *
 * Reads SS_BUILTIN_ITEMS, SS_PAGE_ITEMS, SS_CURRENT_MENU (and optionally
 * SS_ALBUM_ITEMS, SS_CATEGORY_ITEMS, SS_COLLECTION_ITEMS) from the page,
 * renders the interactive editor, and serialises the result back into
 * #menu_json_input before form submission.
 *
 * Supports three levels of nesting (root → child → grandchild).
 * Drag-and-drop at all levels. No third-party dependencies.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    // ── STATE ─────────────────────────────────────────────────────────────
    var menu = JSON.parse(JSON.stringify(SS_CURRENT_MENU));

    // Normalise all items at all depths so fields are consistent.
    function norm(items, depth) {
        return (items || []).map(function (item) {
            return {
                id:        item.id        || uid(),
                type:      item.type      || 'custom',
                label:     item.label     || '',
                url:       item.url       || '',
                target:    item.target    || '_self',
                active:    item.active    !== false,
                target_id: item.target_id || null,
                slug:      item.slug      || null,
                children:  depth < 2 ? norm(item.children, depth + 1) : []
            };
        });
    }
    menu = norm(menu, 0);

    // IDs of all items currently in the menu (any depth).
    function usedIds() {
        var ids = new Set();
        function collect(items) {
            (items || []).forEach(function (item) {
                ids.add(item.id);
                collect(item.children);
            });
        }
        collect(menu);
        return ids;
    }

    function uid() { return 'c_' + Math.random().toString(36).slice(2, 9); }

    // ── DRAG STATE ────────────────────────────────────────────────────────
    // { item, fromPool, depth, parent, grandparent, fromIndex }
    var drag = null;

    // ── MASTER RENDER ─────────────────────────────────────────────────────
    function render() {
        renderPool();
        renderMenu();
        syncJson();
    }

    // ── POOL ──────────────────────────────────────────────────────────────
    function renderPool() {
        var used = usedIds();
        poolSection('pool-builtin',     SS_BUILTIN_ITEMS,                             used);
        poolSection('pool-pages',       SS_PAGE_ITEMS,                                used);
        poolSection('pool-albums',      window.SS_ALBUM_ITEMS      || [],             used);
        poolSection('pool-categories',  window.SS_CATEGORY_ITEMS   || [],             used);
        poolSection('pool-collections', window.SS_COLLECTION_ITEMS || [],             used);
    }

    function poolSection(elId, items, used) {
        var el = document.getElementById(elId);
        if (!el) return;
        el.innerHTML = '';
        var shown = 0;
        items.forEach(function (item) {
            if (!used.has(item.id)) { el.appendChild(poolItem(item)); shown++; }
        });
        if (!shown) el.innerHTML = '<span style="font-size:0.72rem;color:var(--text-dim)">All added</span>';
    }

    function poolItem(item) {
        var div = document.createElement('div');
        div.className  = 'menu-pool-item';
        div.draggable  = true;
        div.dataset.id = item.id;
        var icon = item.type === 'container' ? '&#9783;' : '&#9776;';
        div.innerHTML  = '<span class="menu-item-drag-handle">' + icon + '</span>'
                       + '<span style="flex:1">' + esc(item.label) + '</span>'
                       + '<button type="button" class="pool-add-btn" title="Add">&#43;</button>';
        div.querySelector('.pool-add-btn').addEventListener('click', function () {
            var c = deepClone(item);
            if (!c.children) c.children = [];
            menu.push(c);
            render();
        });
        div.addEventListener('dragstart', function (e) {
            drag = { item: deepClone(item), fromPool: true, depth: -1 };
            e.dataTransfer.effectAllowed = 'move';
        });
        div.addEventListener('dragend', function () { drag = null; });
        return div;
    }

    // ── MENU ──────────────────────────────────────────────────────────────
    function renderMenu() {
        var listEl = document.getElementById('menu-list');
        // Detach event listeners by replacing the node
        var fresh = listEl.cloneNode(false);
        listEl.parentNode.replaceChild(fresh, listEl);
        listEl = fresh;

        if (!menu.length) {
            listEl.innerHTML = '<div class="menu-empty-hint">Drag items here to build your menu.</div>';
        }
        menu.forEach(function (item, idx) {
            listEl.appendChild(makeTopRow(item, idx));
        });

        // Drop zone: bottom of top-level list (append or promote)
        listEl.addEventListener('dragover', function (e) {
            e.preventDefault(); listEl.classList.add('drag-over');
        });
        listEl.addEventListener('dragleave', function () { listEl.classList.remove('drag-over'); });
        listEl.addEventListener('drop', function (e) {
            e.stopPropagation(); listEl.classList.remove('drag-over');
            if (!drag) return;
            dropInto(menu, null, null, 0);
        });
    }

    // Generic drop handler: adds drag.item into targetList.
    // parentItem and grandparentItem tell us where to remove from.
    function dropInto(targetList, parentItem, grandparentItem, targetDepth) {
        if (!drag) return;
        var newItem = deepClone(drag.item);
        if (targetDepth >= 2) { newItem.children = []; }
        else if (!newItem.children) { newItem.children = []; }

        // Remove from original location first
        if (!drag.fromPool) {
            if (drag.depth === 0) {
                menu.splice(drag.fromIndex, 1);
            } else if (drag.depth === 1 && drag.parent) {
                drag.parent.children.splice(drag.fromIndex, 1);
            } else if (drag.depth === 2 && drag.parent) {
                drag.parent.children.splice(drag.fromIndex, 1);
            }
        }

        targetList.push(newItem);
        drag = null;
        render();
    }

    function makeTopRow(item, idx) {
        var row = document.createElement('div');
        row.className = 'menu-item-row';
        row.appendChild(itemBar(item, idx, 0, null, null));

        // Children zone
        var czone = dropZone('menu-children-list', item.children, item, null, 1);
        if (item.children && item.children.length) {
            item.children.forEach(function (child, cidx) {
                czone.appendChild(makeChildRow(child, cidx, item));
            });
        } else {
            czone.appendChild(emptyHint('drop here to nest'));
        }
        row.appendChild(czone);
        return row;
    }

    function makeChildRow(child, cidx, parentItem) {
        var row = document.createElement('div');
        row.className = 'menu-child-row';
        row.appendChild(itemBar(child, cidx, 1, parentItem, null));

        // Grandchildren zone
        var gzone = dropZone('menu-grandchildren-list', child.children, child, parentItem, 2);
        if (child.children && child.children.length) {
            child.children.forEach(function (grand, gidx) {
                gzone.appendChild(itemBar(grand, gidx, 2, child, parentItem));
            });
        } else {
            gzone.appendChild(emptyHint('drop to nest deeper'));
        }
        row.appendChild(gzone);
        return row;
    }

    function dropZone(className, childrenArr, parentItem, grandparentItem, depth) {
        var zone = document.createElement('div');
        var hasKids = childrenArr && childrenArr.length > 0;
        zone.className = className + (hasKids ? ' has-children' : ' empty-children');
        zone.addEventListener('dragover', function (e) {
            e.preventDefault(); e.stopPropagation(); zone.classList.add('drag-over');
        });
        zone.addEventListener('dragleave', function () { zone.classList.remove('drag-over'); });
        zone.addEventListener('drop', function (e) {
            e.preventDefault(); e.stopPropagation(); zone.classList.remove('drag-over');
            if (!drag) return;
            dropInto(childrenArr, parentItem, grandparentItem, depth);
        });
        return zone;
    }

    function emptyHint(text) {
        var d = document.createElement('div');
        d.className = 'menu-empty-drop-hint';
        d.textContent = text;
        return d;
    }

    // ── ITEM BAR ──────────────────────────────────────────────────────────
    function itemBar(item, idx, depth, parent, grandparent) {
        var bar = document.createElement('div');
        bar.className = 'menu-item-main depth-' + depth;
        bar.draggable = true;

        var TYPE_LABELS = {
            custom: 'link', external: 'ext', container: 'folder',
            page: 'page', album: 'album', category: 'cat', collection: 'coll',
            home: 'home', archive: 'archive', albums: 'albums',
            wall: 'wall', blogroll: 'blogroll', blog: 'blog'
        };
        var typeLabel = TYPE_LABELS[item.type] || item.type;
        var posLabel  = depth === 0 ? (idx + 1) + '.'
                      : depth === 1 ? String.fromCharCode(97 + idx) + '.'
                      : '– ';

        var inactiveClass = item.active === false ? ' btn-inactive' : '';
        var promoteBtn    = depth > 0
            ? '<button type="button" class="btn-promote" title="Move up a level">&#8593;</button>' : '';
        var inactiveBadge = item.active === false
            ? '<span class="menu-item-inactive-badge">off</span>' : '';

        bar.innerHTML =
            '<span class="menu-item-position">' + posLabel + '</span>'
          + '<span class="menu-item-drag-handle">&#9776;</span>'
          + '<span class="menu-item-label" data-label>' + esc(item.label) + '</span>'
          + inactiveBadge
          + '<span class="menu-item-type-badge">' + esc(typeLabel) + '</span>'
          + '<div class="menu-item-actions">'
          + '<button type="button" class="btn-toggle-active' + inactiveClass + '" title="Toggle visibility">&#128065;</button>'
          + '<button type="button" class="btn-edit" title="Edit label">&#9998;</button>'
          + promoteBtn
          + '<button type="button" class="btn-remove" title="Remove">&#215;</button>'
          + '</div>';

        // Edit label
        bar.querySelector('.btn-edit').addEventListener('click', function () {
            var labelEl = bar.querySelector('[data-label]');
            var orig = item.label;
            var inp = document.createElement('input');
            inp.type = 'text'; inp.className = 'menu-item-label-edit'; inp.value = orig;
            labelEl.replaceWith(inp); inp.focus(); inp.select();
            function commit() { item.label = inp.value.trim() || orig; render(); }
            inp.addEventListener('blur', commit);
            inp.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); commit(); }
                if (e.key === 'Escape') { inp.value = orig; commit(); }
            });
        });

        // Active toggle
        bar.querySelector('.btn-toggle-active').addEventListener('click', function () {
            item.active = (item.active === false) ? true : false;
            render();
        });

        // Promote
        if (depth > 0) {
            bar.querySelector('.btn-promote').addEventListener('click', function () {
                var promoted = deepClone(item);
                if (depth === 1 && parent) {
                    parent.children.splice(idx, 1);
                    promoted.children = promoted.children || [];
                    menu.push(promoted);
                } else if (depth === 2 && parent && grandparent) {
                    parent.children.splice(idx, 1);
                    promoted.children = [];
                    grandparent.children.push(promoted);
                }
                render();
            });
        }

        // Remove
        bar.querySelector('.btn-remove').addEventListener('click', function () {
            if (depth === 0) menu.splice(idx, 1);
            else if (parent) parent.children.splice(idx, 1);
            render();
        });

        // Drag events
        bar.addEventListener('dragstart', function (e) {
            drag = { item: deepClone(item), fromPool: false, depth: depth,
                     parent: parent, grandparent: grandparent, fromIndex: idx };
            bar.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.stopPropagation();
        });
        bar.addEventListener('dragend', function () {
            bar.classList.remove('dragging'); drag = null;
        });

        // Reorder: drag over same-level bar → insert before
        bar.addEventListener('dragover', function (e) {
            if (!drag || drag.depth !== depth) return;
            if (depth > 0 && drag.parent !== parent) return;
            e.preventDefault(); e.stopPropagation();
            clearDropTargets();
            bar.classList.add('drop-target-above');
        });
        bar.addEventListener('dragleave', function () { bar.classList.remove('drop-target-above'); });
        bar.addEventListener('drop', function (e) {
            e.preventDefault(); e.stopPropagation();
            clearDropTargets();
            if (!drag || drag.depth !== depth) return;
            if (depth > 0 && drag.parent !== parent) return;

            var list = depth === 0 ? menu : parent.children;
            var removed = list.splice(drag.fromIndex, 1)[0];
            var insertAt = drag.fromIndex < idx ? idx - 1 : idx;
            list.splice(Math.max(0, insertAt), 0, removed);
            drag = null;
            render();
        });

        return bar;
    }

    function clearDropTargets() {
        document.querySelectorAll('.drop-target-above').forEach(function (el) {
            el.classList.remove('drop-target-above');
        });
    }

    // ── CUSTOM LINK ───────────────────────────────────────────────────────
    document.getElementById('add-custom-btn').addEventListener('click', function () {
        var label = document.getElementById('custom-label').value.trim();
        var url   = document.getElementById('custom-url').value.trim();
        if (!label || !url) { alert('Enter both a label and a URL.'); return; }
        menu.push({ id: uid(), type: 'custom', label: label.toUpperCase(), url: url, active: true, children: [] });
        document.getElementById('custom-label').value = '';
        document.getElementById('custom-url').value   = '';
        render();
    });

    // Container button
    var cBtn = document.getElementById('add-container-btn');
    if (cBtn) {
        cBtn.addEventListener('click', function () {
            var label = (document.getElementById('container-label').value.trim() || 'MENU').toUpperCase();
            menu.push({ id: uid(), type: 'container', label: label, url: '', active: true, children: [] });
            document.getElementById('container-label').value = '';
            render();
        });
    }

    // ── JSON SYNC ─────────────────────────────────────────────────────────
    function syncJson() {
        function clean(items, depth) {
            return (items || []).map(function (item) {
                var c = {
                    id:     item.id,
                    type:   item.type,
                    label:  item.label,
                    active: item.active !== false,
                    url:    item.url    || '',
                    target: item.target || '_self',
                };
                if (item.target_id) c.target_id = item.target_id;
                if (item.slug)      c.slug      = item.slug;
                c.children = depth < 2 ? clean(item.children, depth + 1) : [];
                return c;
            });
        }
        document.getElementById('menu_json_input').value = JSON.stringify(clean(menu, 0));
    }

    // ── HELPERS ───────────────────────────────────────────────────────────
    function deepClone(o) { return JSON.parse(JSON.stringify(o)); }

    function esc(s) {
        return String(s || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    document.getElementById('menu-form').addEventListener('submit', function () { syncJson(); });

    // ── INIT ──────────────────────────────────────────────────────────────
    render();

})();

// ===== SNAPSMACK EOF =====
