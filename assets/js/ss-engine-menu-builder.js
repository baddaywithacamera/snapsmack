/**
 * ss-engine-menu-builder.js
 * SnapSmack admin drag-and-drop menu builder.
 *
 * Reads SS_BUILTIN_ITEMS, SS_PAGE_ITEMS, SS_CURRENT_MENU from the page,
 * renders the interactive editor, and serialises the result back into
 * #menu_json_input before form submission.
 *
 * Supports one level of nesting. No third-party dependencies.
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
    // Deep-clone the current menu so we don't mutate the PHP-provided array.
    let menu = JSON.parse(JSON.stringify(SS_CURRENT_MENU));

    // Ensure every top-level item has a children array.
    menu.forEach(item => { if (!item.children) item.children = []; });

    // Track which items are "used" (present in the menu at any level).
    function usedIds() {
        const ids = new Set();
        menu.forEach(item => {
            ids.add(item.id);
            (item.children || []).forEach(child => ids.add(child.id));
        });
        return ids;
    }

    // Generate a unique id for custom links.
    function customId() {
        return 'custom_' + Math.random().toString(36).slice(2, 9);
    }

    // ── DRAG STATE ────────────────────────────────────────────────────────
    let dragItem    = null;  // { item, fromList, fromIndex, isChild, parentItem }
    let dragSource  = null;  // DOM element being dragged

    // ── RENDER ────────────────────────────────────────────────────────────
    function render() {
        renderPool();
        renderMenu();
        syncJson();
    }

    // POOL — available items not yet in the menu.
    function renderPool() {
        const used = usedIds();

        const builtinEl = document.getElementById('pool-builtin');
        const pagesEl   = document.getElementById('pool-pages');
        builtinEl.innerHTML = '';
        pagesEl.innerHTML   = '';

        SS_BUILTIN_ITEMS.forEach(item => {
            if (!used.has(item.id)) {
                builtinEl.appendChild(makePoolItem(item));
            }
        });
        SS_PAGE_ITEMS.forEach(item => {
            if (!used.has(item.id)) {
                pagesEl.appendChild(makePoolItem(item));
            }
        });

        if (!builtinEl.children.length) {
            builtinEl.innerHTML = '<span style="font-size:0.72rem;color:var(--text-dim)">All added</span>';
        }
        if (!pagesEl.children.length) {
            pagesEl.innerHTML = '<span style="font-size:0.72rem;color:var(--text-dim)">All added</span>';
        }
    }

    function makePoolItem(item) {
        const div = document.createElement('div');
        div.className = 'menu-pool-item';
        div.draggable  = true;
        div.dataset.id   = item.id;
        div.dataset.type = item.type;

        div.innerHTML = `
            <span class="menu-item-drag-handle">&#9776;</span>
            <span style="flex:1">${item.label}</span>
            <button type="button" class="pool-add-btn" title="Add to menu">&#43;</button>
        `;

        div.querySelector('.pool-add-btn').addEventListener('click', () => {
            addToMenu(item);
        });

        div.addEventListener('dragstart', e => {
            dragItem   = { item: cloneItem(item), fromPool: true };
            dragSource = div;
            e.dataTransfer.effectAllowed = 'move';
        });
        div.addEventListener('dragend', () => {
            dragItem = null; dragSource = null;
        });

        return div;
    }

    // MENU — current menu structure.
    function renderMenu() {
        const listEl = document.getElementById('menu-list');
        listEl.innerHTML = '';

        if (!menu.length) {
            listEl.innerHTML = '<div class="menu-empty-hint">Drag items here to build your menu.</div>';
        }

        menu.forEach((item, idx) => {
            listEl.appendChild(makeMenuRow(item, idx));
        });

        // Drop zone at bottom of top-level list.
        listEl.addEventListener('dragover', e => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            listEl.classList.add('drag-over');
        });
        listEl.addEventListener('dragleave', () => listEl.classList.remove('drag-over'));
        listEl.addEventListener('drop', e => {
            e.stopPropagation();
            listEl.classList.remove('drag-over');
            if (dragItem) {
                if (dragItem.fromPool) {
                    menu.push(cloneItem(dragItem.item));
                } else if (dragItem.isChild) {
                    // Child promoted back to top level.
                    dragItem.parentItem.children.splice(dragItem.fromIndex, 1);
                    const promoted = cloneItem(dragItem.item);
                    promoted.children = [];
                    menu.push(promoted);
                } else {
                    // Dropped at end — remove from current pos, append.
                    const removed = menu.splice(dragItem.fromIndex, 1)[0];
                    menu.push(removed);
                }
                dragItem = null;
                render();
            }
        });
    }

    function makeMenuRow(item, idx) {
        const row = document.createElement('div');
        row.className = 'menu-item-row';

        const main = makeMenuItemMain(item, idx, false, null);
        row.appendChild(main);

        // Children sublist.
        const childList = document.createElement('div');
        const hasKids = item.children && item.children.length > 0;
        childList.className = 'menu-children-list' + (hasKids ? ' has-children' : ' empty-children');
        if (!hasKids) {
            childList.innerHTML = '<div class="menu-empty-drop-hint">drop here to nest</div>';
        } else {
            item.children.forEach((child, cidx) => {
                childList.appendChild(makeMenuItemMain(child, cidx, true, item));
            });
        }

        // Drop onto childList to nest.
        childList.addEventListener('dragover', e => {
            e.preventDefault();
            e.stopPropagation();
            e.dataTransfer.dropEffect = 'move';
            childList.classList.add('drag-over');
        });
        childList.addEventListener('dragleave', () => childList.classList.remove('drag-over'));
        childList.addEventListener('drop', e => {
            e.preventDefault();
            e.stopPropagation();
            childList.classList.remove('drag-over');
            if (!dragItem) return;

            const newChild = cloneItem(dragItem.item || dragItem);
            if (!item.children) item.children = [];

            if (dragItem.fromPool) {
                item.children.push(newChild);
            } else if (dragItem.isChild && dragItem.parentItem === item) {
                // Reorder within same parent — remove and append.
                item.children.splice(dragItem.fromIndex, 1);
                item.children.push(newChild);
            } else if (dragItem.isChild) {
                // Move from different parent.
                dragItem.parentItem.children.splice(dragItem.fromIndex, 1);
                item.children.push(newChild);
            } else {
                // Top-level item being nested — remove from top level.
                if (dragItem.fromIndex !== undefined) {
                    menu.splice(dragItem.fromIndex, 1);
                }
                delete newChild.children; // children can't have children
                item.children.push(newChild);
            }
            dragItem = null;
            render();
        });

        row.appendChild(childList);
        return row;
    }

    function makeMenuItemMain(item, idx, isChild, parentItem) {
        const main = document.createElement('div');
        main.className = 'menu-item-main';
        main.draggable  = true;

        const typeLabel = item.type === 'custom' ? 'link'
                       : item.type === 'page'   ? (item.slug || 'page')
                       : item.type;

        const posLabel = isChild ? `${String.fromCharCode(97 + idx)}.` : `${idx + 1}.`;
        main.innerHTML = `
            <span class="menu-item-position">${posLabel}</span>
            <span class="menu-item-drag-handle">&#9776;</span>
            <span class="menu-item-label" data-field="label">${escHtml(item.label)}</span>
            <span class="menu-item-type-badge">${escHtml(typeLabel)}</span>
            <div class="menu-item-actions">
                <button type="button" class="btn-edit" title="Edit label">&#9998;</button>
                ${isChild ? '<button type="button" class="btn-promote" title="Move to top level">&#8593;</button>' : ''}
                <button type="button" class="btn-remove" title="Remove">&#215;</button>
            </div>
        `;

        // Edit label.
        main.querySelector('.btn-edit').addEventListener('click', () => {
            const labelEl = main.querySelector('.menu-item-label');
            const current = item.label;
            const input = document.createElement('input');
            input.type      = 'text';
            input.className = 'menu-item-label-edit';
            input.value     = current;
            labelEl.replaceWith(input);
            input.focus();
            input.select();

            function commit() {
                const val = input.value.trim() || current;
                item.label = val;
                render();
            }
            input.addEventListener('blur',  commit);
            input.addEventListener('keydown', e => {
                if (e.key === 'Enter') { e.preventDefault(); commit(); }
                if (e.key === 'Escape') { input.value = current; commit(); }
            });
        });

        // Promote child to top level.
        if (isChild && parentItem) {
            main.querySelector('.btn-promote').addEventListener('click', () => {
                parentItem.children.splice(idx, 1);
                const promoted = cloneItem(item);
                promoted.children = [];
                menu.push(promoted);
                render();
            });
        }

        // Remove.
        main.querySelector('.btn-remove').addEventListener('click', () => {
            if (isChild && parentItem) {
                parentItem.children.splice(idx, 1);
            } else {
                menu.splice(idx, 1);
            }
            render();
        });

        // Drag start — top-level or child.
        main.addEventListener('dragstart', e => {
            dragItem = {
                item:        cloneItem(item),
                fromPool:    false,
                isChild:     isChild,
                parentItem:  parentItem,
                fromIndex:   idx,
            };
            dragSource = main;
            main.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.stopPropagation();
        });
        main.addEventListener('dragend', () => {
            main.classList.remove('dragging');
            clearDropTargets();
            dragItem = null;
            dragSource = null;
        });

        // Drop-target indicators on dragover (reorder by dragging over items).
        if (!isChild) {
            main.addEventListener('dragover', e => {
                if (!dragItem || dragItem.isChild) return;
                e.preventDefault();
                e.stopPropagation();
                clearDropTargets();
                main.classList.add('drop-target-above');
            });
            main.addEventListener('dragleave', () => main.classList.remove('drop-target-above'));
            main.addEventListener('drop', e => {
                e.preventDefault();
                e.stopPropagation();
                clearDropTargets();
                if (!dragItem) return;

                if (dragItem.fromPool) {
                    // Insert from pool above this item.
                    menu.splice(idx, 0, cloneItem(dragItem.item));
                } else if (!dragItem.isChild && dragItem.fromIndex !== idx) {
                    const removed = menu.splice(dragItem.fromIndex, 1)[0];
                    const insertAt = dragItem.fromIndex < idx ? idx - 1 : idx;
                    menu.splice(insertAt, 0, removed);
                }
                dragItem = null;
                render();
            });
        } else {
            // Child reorder.
            main.addEventListener('dragover', e => {
                if (!dragItem || !dragItem.isChild || dragItem.parentItem !== parentItem) return;
                e.preventDefault();
                e.stopPropagation();
                clearDropTargets();
                main.classList.add('drop-target-above');
            });
            main.addEventListener('dragleave', () => main.classList.remove('drop-target-above'));
            main.addEventListener('drop', e => {
                e.preventDefault();
                e.stopPropagation();
                clearDropTargets();
                if (!dragItem || !dragItem.isChild || dragItem.parentItem !== parentItem) return;
                const removed = parentItem.children.splice(dragItem.fromIndex, 1)[0];
                const insertAt = dragItem.fromIndex < idx ? idx - 1 : idx;
                parentItem.children.splice(Math.max(0, insertAt), 0, removed);
                dragItem = null;
                render();
            });
        }

        return main;
    }

    function clearDropTargets() {
        document.querySelectorAll('.drop-target-above, .drop-target-nest').forEach(el => {
            el.classList.remove('drop-target-above', 'drop-target-nest');
        });
    }

    // ── CUSTOM LINK ───────────────────────────────────────────────────────
    document.getElementById('add-custom-btn').addEventListener('click', () => {
        const label = document.getElementById('custom-label').value.trim();
        const url   = document.getElementById('custom-url').value.trim();
        if (!label || !url) {
            alert('Enter both a label and a URL.');
            return;
        }
        menu.push({ id: customId(), type: 'custom', label: label.toUpperCase(), url: url, children: [] });
        document.getElementById('custom-label').value = '';
        document.getElementById('custom-url').value   = '';
        render();
    });

    // ── POOL HELPERS ──────────────────────────────────────────────────────
    function addToMenu(item) {
        menu.push(cloneItem(item));
        render();
    }

    function cloneItem(item) {
        const c = Object.assign({}, item);
        if (c.children) c.children = JSON.parse(JSON.stringify(c.children));
        return c;
    }

    // ── JSON SYNC ─────────────────────────────────────────────────────────
    function syncJson() {
        // Strip children from child items before serialising (they can't nest).
        const clean = menu.map(item => {
            const top = Object.assign({}, item);
            top.children = (item.children || []).map(child => {
                const c = Object.assign({}, child);
                delete c.children;
                return c;
            });
            return top;
        });
        document.getElementById('menu_json_input').value = JSON.stringify(clean);
    }

    // ── HTML ESCAPE ───────────────────────────────────────────────────────
    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── FORM SUBMIT — ensure JSON is synced ───────────────────────────────
    document.getElementById('menu-form').addEventListener('submit', () => syncJson());

    // ── INIT ──────────────────────────────────────────────────────────────
    render();

})();

// ===== SNAPSMACK EOF =====
