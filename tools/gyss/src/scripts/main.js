// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GET YOUR SHIT SORTED — Application controller
// Manages tab state, CONNECT/FILTER/SORT screens, and the Conflict Resolution Modal.

import { SnapSmackGYSSAPI } from './api.js';
import {
    listProfiles, loadProfile, saveProfile, deleteProfile, touchProfile
} from './profiles.js';
import {
    createSession, dirtyCount, markDirty, clearDirty,
    addConflicts, removeConflict,
    saveSession, listSessions, loadSession, deleteSession
} from './session.js';

// ===== SNAPSMACK EOF =====  (header reference only — JS marker at bottom)

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------
let state = {
    tab:            'connect',    // 'connect' | 'filter' | 'sort'
    profiles:       [],
    activeProfile:  null,         // { name, site_url, api_key, _path }
    api:            null,         // SnapSmackGYSSAPI instance
    meta:           null,         // { categories, albums }
    session:        null,         // current session object
    sessionPath:    null,         // path to current session file
    editPanelId:    null,         // currently open edit panel photo id
    selectedIds:    new Set(),    // multi-selected photo ids
    dragIds:        [],           // ids being dragged
};

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', async () => {
    await refreshProfiles();
    await refreshSessions();
    showTab('connect');
    bindConnectTab();
    bindFilterTab();
    bindSortTab();
    bindConflictModal();
});

// ---------------------------------------------------------------------------
// Tab switching
// ---------------------------------------------------------------------------
function showTab(name) {
    state.tab = name;
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === name);
    });
    document.querySelectorAll('.tab-pane').forEach(pane => {
        pane.classList.toggle('active', pane.id === `tab-${name}`);
    });
}

document.addEventListener('click', e => {
    const btn = e.target.closest('.tab-btn');
    if (btn) showTab(btn.dataset.tab);
});

// ---------------------------------------------------------------------------
// CONNECT TAB
// ---------------------------------------------------------------------------
async function refreshProfiles() {
    state.profiles = await listProfiles();
    renderProfileList();
}

function renderProfileList() {
    const list = document.getElementById('profile-list');
    if (!list) return;
    if (state.profiles.length === 0) {
        list.innerHTML = '<p class="empty-hint">No profiles yet. Add one on the right.</p>';
        return;
    }
    list.innerHTML = state.profiles.map(p => `
        <div class="profile-card ${state.activeProfile?._path === p._path ? 'active' : ''}"
             data-path="${escHtml(p._path)}">
            <div class="profile-name">${escHtml(p.name)}</div>
            <div class="profile-url">${escHtml(p.site_url)}</div>
            <div class="profile-meta">Last connected: ${p.last_connected ? fmtDate(p.last_connected) : 'never'}</div>
            <button class="btn-danger btn-sm profile-delete" data-path="${escHtml(p._path)}">DELETE</button>
        </div>
    `).join('');
}

function bindConnectTab() {
    document.getElementById('profile-list')?.addEventListener('click', async e => {
        const card = e.target.closest('.profile-card');
        const del  = e.target.closest('.profile-delete');
        if (del) {
            if (confirm('Delete this profile?')) {
                await deleteProfile(del.dataset.path);
                await refreshProfiles();
            }
            return;
        }
        if (card) {
            const profile = await loadProfile(card.dataset.path);
            await activateProfile(profile);
        }
    });

    document.getElementById('btn-test-connection')?.addEventListener('click', async () => {
        const url = document.getElementById('input-site-url').value.trim();
        const key = document.getElementById('input-api-key').value.trim();
        if (!url || !key) { toast('Enter site URL and API key first.', 'error'); return; }
        const api = new SnapSmackGYSSAPI(url, key);
        setStatus('connect-status', 'Testing...', 'info');
        try {
            const r = await api.ping();
            setStatus('connect-status', `Connected: ${r.site_name} (v${r.version})`, 'ok');
        } catch (err) {
            setStatus('connect-status', `Failed: ${err.message}`, 'error');
        }
    });

    document.getElementById('btn-save-profile')?.addEventListener('click', async () => {
        const url  = document.getElementById('input-site-url').value.trim();
        const key  = document.getElementById('input-api-key').value.trim();
        const name = document.getElementById('input-profile-name').value.trim() || new URL(url).hostname;
        if (!url || !key) { toast('Enter site URL and API key first.', 'error'); return; }
        try {
            const path = await saveProfile({ name, site_url: url, api_key: key });
            toast('Profile saved.', 'ok');
            await refreshProfiles();
        } catch (err) {
            toast(`Save failed: ${err.message}`, 'error');
        }
    });

    // Show/hide API key
    document.getElementById('btn-toggle-key')?.addEventListener('click', () => {
        const inp = document.getElementById('input-api-key');
        inp.type  = inp.type === 'password' ? 'text' : 'password';
    });
}

async function activateProfile(profile) {
    state.activeProfile = profile;
    state.api           = new SnapSmackGYSSAPI(profile.site_url, profile.api_key);
    renderProfileList();

    // Test connection and load meta
    setStatus('connect-status', 'Connecting...', 'info');
    try {
        const r = await state.api.ping();
        await touchProfile(profile._path, new Date().toISOString());
        setStatus('connect-status', `Connected: ${r.site_name} (v${r.version})`, 'ok');

        state.meta = await state.api.meta();
        populateFilterDropdowns();
        await refreshSessions();
        showTab('filter');
    } catch (err) {
        setStatus('connect-status', `Connection failed: ${err.message}`, 'error');
    }
}

// ---------------------------------------------------------------------------
// FILTER TAB
// ---------------------------------------------------------------------------
async function refreshSessions() {
    const sessions = await listSessions();
    renderSessionList(sessions);
}

function renderSessionList(sessions) {
    const list = document.getElementById('session-list');
    if (!list) return;
    if (sessions.length === 0) {
        list.innerHTML = '<p class="empty-hint">No saved sessions.</p>';
        return;
    }
    list.innerHTML = sessions.map(s => `
        <div class="session-card ${s.dirty_count > 0 ? 'has-dirty' : ''}" data-path="${escHtml(s._path)}">
            <div class="session-info">
                <span class="session-profile">${escHtml(s.profile_name)}</span>
                <span class="session-date">${fmtDate(s.last_saved)}</span>
                ${s.dirty_count > 0 ? `<span class="dirty-badge">${s.dirty_count} unsaved</span>` : ''}
                ${s.unresolved_conflicts?.length > 0 ? `<span class="conflict-badge">${s.unresolved_conflicts.length} conflicts</span>` : ''}
            </div>
            <div class="session-filter">${fmtFilter(s.filter)} · ${s.photos?.length || 0} photos</div>
            <button class="btn-primary btn-sm session-resume" data-path="${escHtml(s._path)}">RESUME</button>
            <button class="btn-danger  btn-sm session-delete"  data-path="${escHtml(s._path)}">DELETE</button>
        </div>
    `).join('');
}

function populateFilterDropdowns() {
    if (!state.meta) return;

    const catSel = document.getElementById('filter-category');
    const albSel = document.getElementById('filter-album');
    if (catSel) {
        catSel.innerHTML = '<option value="">All categories</option>' +
            state.meta.categories.map(c =>
                `<option value="${c.id}">${escHtml(c.name)} (${c.count})</option>`
            ).join('');
    }
    if (albSel) {
        albSel.innerHTML = '<option value="">All albums</option>' +
            state.meta.albums.map(a =>
                `<option value="${a.id}">${escHtml(a.name)} (${a.count})</option>`
            ).join('');
    }
}

function bindFilterTab() {
    document.getElementById('session-list')?.addEventListener('click', async e => {
        const resume = e.target.closest('.session-resume');
        const del    = e.target.closest('.session-delete');
        if (resume) {
            const s = await loadSession(resume.dataset.path);
            state.session     = s;
            state.sessionPath = resume.dataset.path;
            renderSortGrid();
            if (s.unresolved_conflicts?.length > 0) {
                openConflictModal(s.unresolved_conflicts);
            }
            showTab('sort');
            return;
        }
        if (del) {
            if (confirm('Delete this session?')) {
                await deleteSession(del.dataset.path);
                await refreshSessions();
            }
            return;
        }
    });

    document.getElementById('btn-pull')?.addEventListener('click', async () => {
        if (!state.api) { toast('Connect to a site first.', 'error'); return; }
        const filter = {
            date_from:   document.getElementById('filter-date-from').value  || undefined,
            date_to:     document.getElementById('filter-date-to').value    || undefined,
            category_id: document.getElementById('filter-category').value   || undefined,
            album_id:    document.getElementById('filter-album').value      || undefined,
            limit:       parseInt(document.getElementById('filter-limit').value) || 200,
        };

        setStatus('filter-status', 'Pulling photos...', 'info');
        try {
            const r = await state.api.photos(filter);
            state.session     = createSession(state.activeProfile, filter, r.photos);
            state.sessionPath = await saveSession(state.session);
            setStatus('filter-status', `${r.total} total · ${r.photos.length} loaded`, 'ok');
            await refreshSessions();
            renderSortGrid();
            showTab('sort');
        } catch (err) {
            setStatus('filter-status', `Pull failed: ${err.message}`, 'error');
        }
    });
}

// ---------------------------------------------------------------------------
// SORT TAB — grid, edit panel, drag-to-reorder, push
// ---------------------------------------------------------------------------
function renderSortGrid() {
    const grid = document.getElementById('sort-grid');
    if (!grid || !state.session) return;

    const photos = [...state.session.photos].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    grid.innerHTML = photos.map((p, idx) => `
        <div class="photo-card ${p.dirty ? 'dirty' : ''} ${state.selectedIds.has(p.id) ? 'selected' : ''}"
             data-id="${p.id}" data-idx="${idx}" draggable="true">
            <div class="drag-handle" title="Drag to reorder">⠿</div>
            ${p.dirty ? '<div class="dirty-dot" title="Unsaved changes"></div>' : ''}
            <img src="${escHtml(p.thumb_url)}" alt="${escHtml(p.title)}" loading="lazy">
            <div class="card-title">${escHtml(p.title || '')}</div>
        </div>
    `).join('');

    updateSortTopBar();
    initDragAndDrop();
}

function updateSortTopBar() {
    if (!state.session) return;
    const dc = dirtyCount(state.session);
    const pushBtn = document.getElementById('btn-push');
    if (pushBtn) {
        pushBtn.disabled     = dc === 0;
        pushBtn.textContent  = dc > 0 ? `PUSH CHANGES (${dc})` : 'PUSH CHANGES';
    }
    const profileLabel = document.getElementById('sort-profile-label');
    if (profileLabel) {
        profileLabel.textContent = `${state.session.profile_name} — ${state.session.site_url}`;
    }
    const filterLabel = document.getElementById('sort-filter-label');
    if (filterLabel) {
        filterLabel.textContent = `${state.session.photos.length} photos · ${fmtFilter(state.session.filter)}`;
    }
    // Conflict banner
    const banner = document.getElementById('conflict-banner');
    if (banner) {
        const uc = state.session.unresolved_conflicts?.length || 0;
        banner.hidden    = uc === 0;
        banner.innerHTML = uc > 0
            ? `${uc} unresolved conflict${uc > 1 ? 's' : ''} from last push. <a href="#" id="btn-open-conflicts">REVIEW</a>`
            : '';
    }
}

// Drag-and-drop reorder (native HTML5)
function initDragAndDrop() {
    const grid  = document.getElementById('sort-grid');
    if (!grid) return;
    let draggedIds = [];
    let dropTarget = null;

    grid.addEventListener('dragstart', e => {
        const card = e.target.closest('.photo-card');
        if (!card) return;
        const id = parseInt(card.dataset.id);
        if (state.selectedIds.has(id)) {
            draggedIds = [...state.selectedIds];
        } else {
            draggedIds = [id];
        }
        e.dataTransfer.effectAllowed = 'move';
        card.classList.add('dragging');
    });

    grid.addEventListener('dragover', e => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const card = e.target.closest('.photo-card');
        if (card && !draggedIds.includes(parseInt(card.dataset.id))) {
            if (dropTarget !== card) {
                if (dropTarget) dropTarget.classList.remove('drop-target');
                card.classList.add('drop-target');
                dropTarget = card;
            }
        }
    });

    grid.addEventListener('dragleave', e => {
        if (dropTarget && !grid.contains(e.relatedTarget)) {
            dropTarget.classList.remove('drop-target');
            dropTarget = null;
        }
    });

    grid.addEventListener('drop', e => {
        e.preventDefault();
        if (dropTarget) {
            dropTarget.classList.remove('drop-target');
            const targetId  = parseInt(dropTarget.dataset.id);
            const targetIdx = parseInt(dropTarget.dataset.idx);
            reorderPhotos(draggedIds, targetIdx);
            dropTarget = null;
        }
        draggedIds = [];
        grid.querySelectorAll('.dragging').forEach(c => c.classList.remove('dragging'));
    });

    grid.addEventListener('dragend', () => {
        grid.querySelectorAll('.dragging, .drop-target').forEach(c => {
            c.classList.remove('dragging', 'drop-target');
        });
        draggedIds = [];
    });
}

function reorderPhotos(movedIds, targetIdx) {
    if (!state.session) return;
    const photos = [...state.session.photos].sort((a, b) => a.sort_order - b.sort_order);
    const moved  = photos.filter(p => movedIds.includes(p.id));
    const rest   = photos.filter(p => !movedIds.includes(p.id));
    // Insert moved photos before the card at targetIdx in the rest array
    const insertBefore = rest[targetIdx] || null;
    const insertIdx    = insertBefore ? rest.indexOf(insertBefore) : rest.length;
    rest.splice(insertIdx, 0, ...moved);

    // Reassign sort_order as 1-based integers
    rest.forEach((p, i) => {
        if (p.sort_order !== i + 1) {
            p.sort_order = i + 1;
            p.dirty      = true;
        }
    });
    state.session.photos = rest;
    renderSortGrid();
}

// Click card → open/close edit panel; shift/ctrl click → multi-select
function bindSortTab() {
    document.getElementById('sort-grid')?.addEventListener('click', e => {
        const card = e.target.closest('.photo-card');
        if (!card) return;
        const id = parseInt(card.dataset.id);

        if (e.shiftKey) {
            // Range select
            const photos  = [...document.querySelectorAll('.photo-card')].map(c => parseInt(c.dataset.id));
            const lastSel = [...state.selectedIds].pop();
            const from    = lastSel ? photos.indexOf(lastSel) : 0;
            const to      = photos.indexOf(id);
            const range   = photos.slice(Math.min(from, to), Math.max(from, to) + 1);
            range.forEach(rid => state.selectedIds.add(rid));
        } else if (e.ctrlKey || e.metaKey) {
            if (state.selectedIds.has(id)) state.selectedIds.delete(id);
            else state.selectedIds.add(id);
        } else {
            state.selectedIds.clear();
            openEditPanel(id);
            return;
        }
        renderSortGrid();
    });

    // PUSH CHANGES
    document.getElementById('btn-push')?.addEventListener('click', async () => {
        if (!state.session || !state.api) return;
        const dirty = state.session.photos.filter(p => p.dirty);
        if (dirty.length === 0) return;
        if (!confirm(`Push ${dirty.length} changes to ${state.session.site_url}?`)) return;

        const updates = dirty.map(p => ({
            id:                   p.id,
            sort_order:           p.sort_order,
            title:                p.title,
            description:          p.description,
            category_id:          p.category_id,
            expected_modified_at: p.original_modified_at,
        }));

        setStatus('sort-status', 'Pushing...', 'info');
        try {
            const r = await state.api.batchUpdate(updates);
            // Mark applied records as clean
            const appliedIds = updates
                .filter((u, i) => !r.failed.find(f => f.id === u.id) && !r.conflicts.find(c => c.id === u.id))
                .map(u => u.id);
            clearDirty(state.session, appliedIds);

            if (r.conflicts.length > 0) {
                addConflicts(state.session, r.conflicts);
                openConflictModal(state.session.unresolved_conflicts);
            }

            state.sessionPath = await saveSession(state.session);
            renderSortGrid();

            const msg = `Applied: ${r.applied}` +
                (r.failed.length    ? ` · Failed: ${r.failed.length}` : '') +
                (r.conflicts.length ? ` · Conflicts: ${r.conflicts.length}` : '');
            setStatus('sort-status', msg, r.failed.length || r.conflicts.length ? 'warn' : 'ok');
        } catch (err) {
            setStatus('sort-status', `Push failed: ${err.message}`, 'error');
        }
    });

    // SAVE SESSION
    document.getElementById('btn-save-session')?.addEventListener('click', async () => {
        if (!state.session) return;
        state.sessionPath = await saveSession(state.session);
        toast('Session saved.', 'ok');
    });

    // START NEW SESSION
    document.getElementById('btn-new-session')?.addEventListener('click', () => {
        if (dirtyCount(state.session) > 0) {
            if (!confirm('You have unsaved changes. Start a new session anyway?')) return;
        }
        state.session     = null;
        state.sessionPath = null;
        state.editPanelId = null;
        state.selectedIds.clear();
        closeEditPanel();
        showTab('filter');
    });

    // Conflict banner review button (delegated)
    document.getElementById('conflict-banner')?.addEventListener('click', e => {
        if (e.target.id === 'btn-open-conflicts') {
            e.preventDefault();
            openConflictModal(state.session.unresolved_conflicts);
        }
    });
}

// ---------------------------------------------------------------------------
// EDIT PANEL (right rail)
// ---------------------------------------------------------------------------
function openEditPanel(photoId) {
    state.editPanelId = photoId;
    const p     = state.session?.photos.find(ph => ph.id === photoId);
    if (!p) return;
    const panel = document.getElementById('edit-panel');
    if (!panel) return;

    panel.classList.add('open');
    panel.querySelector('#ep-thumb').src         = p.thumb_url;
    panel.querySelector('#ep-title').value        = p.title || '';
    panel.querySelector('#ep-description').value  = p.description || '';
    panel.querySelector('#ep-sort-orig').textContent = `Original: ${p.original_sort}`;

    // Populate category dropdown
    const catSel = panel.querySelector('#ep-category');
    if (catSel && state.meta) {
        catSel.innerHTML = '<option value="">— no category —</option>' +
            state.meta.categories.map(c =>
                `<option value="${c.id}" ${c.id === p.category_id ? 'selected' : ''}>${escHtml(c.name)}</option>`
            ).join('');
    }
}

function closeEditPanel() {
    state.editPanelId = null;
    document.getElementById('edit-panel')?.classList.remove('open');
}

document.addEventListener('DOMContentLoaded', () => {
    // Edit panel save on field change
    const panel = document.getElementById('edit-panel');
    if (!panel) return;

    const saveEdit = () => {
        const id = state.editPanelId;
        if (!id || !state.session) return;
        const p = state.session.photos.find(ph => ph.id === id);
        if (!p) return;
        p.title       = panel.querySelector('#ep-title').value;
        p.description = panel.querySelector('#ep-description').value;
        p.category_id = parseInt(panel.querySelector('#ep-category').value) || null;
        p.dirty       = true;
        // Update card dirty indicator without full re-render
        const card = document.querySelector(`.photo-card[data-id="${id}"]`);
        if (card) card.classList.add('dirty');
        updateSortTopBar();
    };

    panel.querySelector('#ep-title')?.addEventListener('input', saveEdit);
    panel.querySelector('#ep-description')?.addEventListener('input', saveEdit);
    panel.querySelector('#ep-category')?.addEventListener('change', saveEdit);

    panel.querySelector('#btn-ep-revert')?.addEventListener('click', () => {
        const id = state.editPanelId;
        if (!id || !state.session) return;
        const p = state.session.photos.find(ph => ph.id === id);
        if (!p) return;
        p.title       = p._orig_title       ?? p.title;
        p.description = p._orig_description ?? p.description;
        p.category_id = p._orig_category_id ?? p.category_id;
        p.sort_order  = p.original_sort;
        p.dirty       = false;
        openEditPanel(id);
        renderSortGrid();
    });

    // USE FILENAME AS TITLE — fill the title from the photo's filename,
    // extension stripped. Needs `filename` from gyss/photos (basename only).
    panel.querySelector('#btn-ep-filename-title')?.addEventListener('click', () => {
        const id = state.editPanelId;
        if (!id || !state.session) return;
        const p = state.session.photos.find(ph => ph.id === id);
        if (!p) return;
        // Fall back to the thumb URL's basename for sessions pulled before the
        // API exposed `filename` (thumbs are named a_<original file>).
        let fname = p.filename || '';
        if (!fname && p.thumb_url) {
            const tail = decodeURIComponent(p.thumb_url.split('/').pop() || '');
            fname = tail.replace(/^a_/, '');
        }
        if (!fname) return;
        const base = fname.replace(/\.[^.]+$/, '');
        if (!base) return;
        const input = panel.querySelector('#ep-title');
        input.value = base;
        input.dispatchEvent(new Event('input', { bubbles: true }));  // runs saveEdit
    });

    panel.querySelector('#btn-ep-close')?.addEventListener('click', closeEditPanel);
});

// ---------------------------------------------------------------------------
// CONFLICT RESOLUTION MODAL
// ---------------------------------------------------------------------------
function bindConflictModal() {
    const modal = document.getElementById('conflict-modal');
    if (!modal) return;

    modal.addEventListener('click', async e => {
        const id = parseInt(e.target.closest('[data-conflict-id]')?.dataset.conflictId);
        if (!id) return;

        if (e.target.classList.contains('btn-keep-mine')) {
            await resolveConflict(id, 'keep-mine');
        } else if (e.target.classList.contains('btn-take-theirs')) {
            resolveConflict(id, 'take-theirs');
        } else if (e.target.classList.contains('btn-merge')) {
            closeConflictModal();
            openEditPanel(id);
        } else if (e.target.classList.contains('btn-skip-conflict')) {
            resolveConflict(id, 'skip');
        }
    });

    document.getElementById('btn-close-conflict-modal')?.addEventListener('click', closeConflictModal);
}

function openConflictModal(conflicts) {
    const modal = document.getElementById('conflict-modal');
    const list  = document.getElementById('conflict-list');
    if (!modal || !list) return;

    list.innerHTML = conflicts.map(c => {
        const p     = state.session?.photos.find(ph => ph.id === c.id);
        const thumb = p?.thumb_url || '';
        return `
        <div class="conflict-item" data-conflict-id="${c.id}">
            <img class="conflict-thumb" src="${escHtml(thumb)}" alt="">
            <div class="conflict-cols">
                <div class="conflict-col">
                    <div class="conflict-col-label">YOUR EDIT</div>
                    ${conflictFields(c.mine_snapshot || {})}
                </div>
                <div class="conflict-col">
                    <div class="conflict-col-label">LIVE SITE</div>
                    ${conflictFields(c.theirs_snapshot || {})}
                </div>
            </div>
            <div class="conflict-detected">Detected: ${fmtDate(c.detected_at)}</div>
            <div class="conflict-actions">
                <button class="btn-primary btn-sm btn-keep-mine">KEEP MINE</button>
                <button class="btn-secondary btn-sm btn-take-theirs">TAKE THEIRS</button>
                <button class="btn-secondary btn-sm btn-merge">MERGE</button>
                <button class="btn-ghost btn-sm btn-skip-conflict">SKIP</button>
            </div>
        </div>`;
    }).join('');

    modal.classList.add('open');
}

function conflictFields(snap) {
    return `
        <div class="cf-field"><span class="cf-label">Title</span><span class="cf-val">${escHtml(snap.title || '')}</span></div>
        <div class="cf-field"><span class="cf-label">Sort</span><span class="cf-val">${snap.sort_order ?? ''}</span></div>
        <div class="cf-field"><span class="cf-label">Description</span><span class="cf-val cf-desc">${escHtml(snap.description || '')}</span></div>
    `;
}

function closeConflictModal() {
    document.getElementById('conflict-modal')?.classList.remove('open');
}

async function resolveConflict(photoId, action) {
    if (!state.session) return;
    const conflict = state.session.unresolved_conflicts.find(c => c.id === photoId);
    const p        = state.session.photos.find(ph => ph.id === photoId);

    if (action === 'keep-mine') {
        // Re-push with force:true
        if (!state.api || !p) return;
        try {
            const updates = [{
                id:         photoId,
                sort_order: p.sort_order,
                title:      p.title,
                description: p.description,
                category_id: p.category_id,
                force:       true,
            }];
            await state.api.batchUpdate(updates);
            clearDirty(state.session, [photoId]);
            removeConflict(state.session, photoId);
            toast('Your version pushed.', 'ok');
        } catch (err) {
            toast(`Force push failed: ${err.message}`, 'error');
            return;
        }
    } else if (action === 'take-theirs') {
        if (p && conflict?.theirs_snapshot) {
            p.title       = conflict.theirs_snapshot.title;
            p.description = conflict.theirs_snapshot.description;
            p.category_id = conflict.theirs_snapshot.category_id;
            p.sort_order  = conflict.theirs_snapshot.sort_order;
            p.modified_at = conflict.theirs_snapshot.modified_at;
            p.original_modified_at = conflict.theirs_snapshot.modified_at;
            p.dirty       = false;
        }
        removeConflict(state.session, photoId);
        toast('Reverted to live version.', 'ok');
    } else {
        // skip — leave conflict in list
    }

    state.sessionPath = await saveSession(state.session);
    renderSortGrid();

    // Refresh modal with remaining conflicts
    if (state.session.unresolved_conflicts.length > 0) {
        openConflictModal(state.session.unresolved_conflicts);
    } else {
        closeConflictModal();
    }
}

// ---------------------------------------------------------------------------
// UI helpers
// ---------------------------------------------------------------------------
function toast(msg, type = 'info') {
    const t = document.createElement('div');
    t.className = `toast toast-${type}`;
    t.textContent = msg;
    document.getElementById('toast-container')?.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

function setStatus(elId, msg, type = 'info') {
    const el = document.getElementById(elId);
    if (!el) return;
    el.textContent  = msg;
    el.className    = `status-line status-${type}`;
    el.hidden       = false;
}

function escHtml(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtDate(iso) {
    if (!iso) return '';
    try { return new Date(iso).toLocaleString(); } catch { return iso; }
}

function fmtFilter(f = {}) {
    const parts = [];
    if (f.date_from || f.date_to) parts.push(`${f.date_from || '…'} – ${f.date_to || '…'}`);
    if (f.category_id) parts.push(`cat:${f.category_id}`);
    if (f.album_id)    parts.push(`album:${f.album_id}`);
    return parts.join(' · ') || 'All photos';
}

// ===== SNAPSMACK EOF =====
