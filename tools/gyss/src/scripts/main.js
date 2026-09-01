// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GET YOUR SHIT SORTED — Application controller
// Manages tab state, CONNECT/FILTER/SORT screens, and the Conflict Resolution Modal.

import { SnapSmackGYSSAPI } from './api.js';
import {
    listProfiles, loadProfile, saveProfile, deleteProfile, touchProfile, cacheProfileSiteMode
} from './profiles.js';
import {
    createSession, dirtyCount, markDirty, clearDirty,
    addConflicts, removeConflict,
    saveSession, listSessions, loadSession, deleteSession
} from './session.js';
import {
    syncLibrary, libraryImages, libraryStatus, hostnameFor
} from './library.js';
import { getCred } from './shared-creds.js';

// ===== SNAPSMACK EOF =====  (header reference only — JS marker at bottom)

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------
let state = {
    tab:            'connect',    // 'connect' | 'filter' | 'sort' | 'grid'
    profiles:       [],
    activeProfile:  null,         // { name, site_url, api_key, _path }
    api:            null,         // SnapSmackGYSSAPI instance
    meta:           null,         // { categories, albums }
    session:        null,         // current session object
    sessionPath:    null,         // path to current session file
    editPanelId:    null,         // currently open edit panel photo id
    selectedIds:    new Set(),    // multi-selected photo ids
    dragIds:        [],           // ids being dragged
    repairItems:    [],
    repairStop:     false,
    siteMode:       'photoblog',  // 'photoblog' (SMACKONEOUT) | 'carousel' (GRAMOFSMACK)
    gram:           null,         // GRAMOFSMACK grid: { posts:[], dirtyOrder:bool }
    gramSelected:   new Set(),    // selected post ids in the GRID tab
    sharedCreds:    {},           // shared secret store (gemini_api_key, drive_folder_id, google_credentials)
};
const profileModeChecks = new Set();

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', async () => {
    await loadSharedCreds();
    await refreshProfiles();
    await refreshSessions();
    showTab('connect');
    bindConnectTab();
    bindFilterTab();
    bindSortTab();
    bindGridTab();
    bindRepairTab();
    bindConflictModal();
});

// Load the shared secret store (…/shared_library/auth/shared_creds.json) — the
// same store COLD SNAP / SYBU read. GYSS's own enrichment runs server-side, so
// these values are surfaced for parity/future use rather than spent locally; the
// per-site import key stays per-profile.
async function loadSharedCreds() {
    try {
        state.sharedCreds = {
            gemini_api_key:     await getCred('gemini_api_key'),
            drive_folder_id:    await getCred('drive_folder_id'),
            google_credentials: await getCred('google_credentials'),
        };
        if (state.sharedCreds.gemini_api_key) {
            console.info('[shared-creds] shared Gemini API key is available.');
        }
    } catch { state.sharedCreds = {}; }
}

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
    classifyUnknownProfiles(state.profiles.filter(p => !p.site_mode));
}

// Shared profiles are used by every desktop tool, including SMACKTALK. Identify
// unknown entries once in the background, cache their mode, then remove longform
// sites from GYSS without making the user click through each one.
async function classifyUnknownProfiles(profiles) {
    const pending = profiles.filter(p => !profileModeChecks.has(p._path));
    for (const summary of pending) profileModeChecks.add(summary._path);
    const workers = Array.from({ length: Math.min(4, pending.length) }, async () => {
        while (pending.length) {
            const summary = pending.shift();
            try {
                const profile = await loadProfile(summary._path);
                if (!profile.api_key) continue;
                const reply = await new SnapSmackGYSSAPI(profile.site_url, profile.api_key).ping();
                await cacheProfileSiteMode(summary._path, reply.site_mode || 'photoblog');
            } catch { /* offline/old sites stay visible and can be retried manually */ }
        }
    });
    await Promise.all(workers);
    if (workers.length) {
        state.profiles = await listProfiles();
        renderProfileList();
    }
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
        if (!confirmInsecureUrl(url)) return;
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
        if (!confirmInsecureUrl(url)) return;
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

// SECAUDIT 039: the API key travels as a Bearer header. Over plain http:// it is
// readable by anyone on the network path, who can then use it against the site —
// and can also rewrite the JSON this app renders. Warn loudly; allow only a
// deliberate override (localhost is exempt — it never leaves the machine).
function confirmInsecureUrl(url) {
    let u;
    try { u = new URL(/^https?:\/\//i.test(url) ? url : 'https://' + url); }
    catch { return true; }                      // malformed — the API call will fail anyway
    if (u.protocol !== 'http:') return true;
    const host = u.hostname.toLowerCase();
    if (host === 'localhost' || host === '127.0.0.1' || host === '::1') return true;
    return confirm(
        `WARNING — insecure connection\n\n` +
        `${u.origin} uses plain http://, so your API key is sent unencrypted and ` +
        `anyone on the network can read it, use it against your site, or tamper with ` +
        `what this app displays.\n\n` +
        `Use https:// instead. Continue anyway?`
    );
}

async function activateProfile(profile) {
    if (!confirmInsecureUrl(profile.site_url)) return;
    state.activeProfile = profile;
    state.api           = new SnapSmackGYSSAPI(profile.site_url, profile.api_key);
    renderProfileList();

    // Test connection and load meta
    setStatus('connect-status', 'Connecting...', 'info');
    try {
        const r = await state.api.ping();
        await touchProfile(profile._path, new Date().toISOString());
        state.siteMode = r.site_mode || 'photoblog';
        await cacheProfileSiteMode(profile._path, state.siteMode);
        applyModeTabs();

        if (state.siteMode === 'carousel') {
            // GRAMOFSMACK: straight to the grid (posts, not the photo sorter).
            setStatus('connect-status', `Connected: ${r.site_name} (v${r.version}) — GRAMOFSMACK`, 'ok');
            showTab('grid');
            await loadGrid();
        } else if (state.siteMode === 'photoblog') {
            // SMACKONEOUT: the original photo sorter.
            setStatus('connect-status', `Connected: ${r.site_name} (v${r.version}) — SMACKONEOUT`, 'ok');
            try {
                state.meta = await state.api.meta();
            } catch (metaErr) {
                // Old sites can sort photographs even when their optional
                // category/album membership schema cannot provide counts yet.
                state.meta = { categories: [], albums: [] };
                toast(`Connected, but category/album metadata is unavailable: ${metaErr.message}`, 'error');
            }
            populateFilterDropdowns();
            await refreshSessions();
            await refreshLibraryStatus();
            showTab('filter');
        } else {
            // SMACKTALK (longform) or an unknown mode: GYSS can't sort it. Longform
            // images live INSIDE essays (via [mosaic:ID] post-body shortcodes + a
            // single featured asset), not as sortable tiles — there is no feed to
            // arrange and no per-image order that means anything. Refuse cleanly and
            // stay on CONNECT rather than show a meaningless sorter.
            const modeName = state.siteMode === 'smacktalk' ? 'SMACKTALK (longform)' : `"${state.siteMode}"`;
            setStatus('connect-status',
                `Connected to ${r.site_name}, but GYSS doesn't support ${modeName} sites. ` +
                `Longform images live inside essays and aren't sortable — use SmackPress to manage longform.`,
                'error');
            state.profiles = await listProfiles();
            renderProfileList();
            showTab('connect');
        }
    } catch (err) {
        setStatus('connect-status', `Connection failed: ${err.message}`, 'error');
    }
}

// Show only the tabs that apply to the connected site's mode. CONNECT always
// shows. FILTER/SORT are photoblog-only; GRID is carousel-only (data-mode). REPAIR
// (image-metadata enrichment) applies to both photo modes but NOT to SMACKTALK
// longform — so on any unsupported mode, only CONNECT remains.
function applyModeTabs() {
    const supported = (state.siteMode === 'photoblog' || state.siteMode === 'carousel');
    document.querySelectorAll('.tab-btn[data-mode]').forEach(btn => {
        btn.hidden = !supported || (btn.dataset.mode !== state.siteMode);
    });
    const repairBtn = document.querySelector('.tab-btn[data-tab="repair"]');
    if (repairBtn) repairBtn.hidden = !supported;
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
            <div class="session-filter">${escHtml(fmtFilter(s.filter))} · ${s.photos?.length || 0} photos</div>
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

    document.getElementById('btn-sync-library')?.addEventListener('click', doSyncLibrary);
    document.getElementById('btn-browse-library')?.addEventListener('click', browseLibrary);
}

// ---------------------------------------------------------------------------
// OFFLINE LIBRARY — sync the per-blog local store, then sort against it offline
// ---------------------------------------------------------------------------

/** Show whether a local library exists for the active profile, and how fresh. */
async function refreshLibraryStatus() {
    if (!state.activeProfile) return;
    try {
        const st = await libraryStatus(hostnameFor(state.activeProfile.site_url));
        setStatus('library-status',
            st.exists
                ? `Local library: ${st.count} images · last synced ${fmtDate(st.synced_at)}`
                : 'No local library yet — SYNC LIBRARY to build it (works offline afterward).',
            'info');
    } catch { /* non-fatal — the button still works */ }
}

/** PULL the blog into the on-disk library (seed or incremental) + download thumbs. */
async function doSyncLibrary() {
    if (!state.api || !state.activeProfile) { toast('Connect to a site first.', 'error'); return; }
    const btn  = document.getElementById('btn-sync-library');
    const prog = document.getElementById('library-progress');
    if (btn) btn.disabled = true;
    setStatus('library-status', 'Syncing library…', 'info');
    try {
        const summary = await syncLibrary(state.api, state.activeProfile, {
            onProgress: (phase, done, total) => {
                if (!prog) return;
                prog.hidden = false;
                prog.textContent =
                    phase === 'thumbs' ? `Downloading thumbnails… ${done}/${total}` :
                    phase === 'pull'   ? 'Pulling records…' :
                    phase === 'done'   ? 'Finalising…' : '';
            },
        });
        if (prog) prog.hidden = true;
        setStatus('library-status',
            `Library ${summary.full ? 'seeded' : 'updated'}: ${summary.total} images` +
            ` · ${summary.upserted} changed · ${summary.pruned} removed` +
            ` · ${summary.thumbsDownloaded} thumbs` +
            (summary.thumbsFailed ? ` · ${summary.thumbsFailed} thumb failures (will use remote)` : ''),
            summary.thumbsFailed ? 'warn' : 'ok');
    } catch (err) {
        if (prog) prog.hidden = true;
        setStatus('library-status', `Sync failed: ${err.message}`, 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/** Build a normal sort session from the LOCAL library — no blog connection needed. */
async function browseLibrary() {
    if (!state.activeProfile) { toast('Connect to a site first.', 'error'); return; }
    const hostname = hostnameFor(state.activeProfile.site_url);
    const st = await libraryStatus(hostname);
    if (!st.exists) { toast('No local library yet — SYNC LIBRARY first.', 'error'); return; }

    const catRaw = document.getElementById('filter-category').value;
    const albRaw = document.getElementById('filter-album').value;
    const categoryId = catRaw ? parseInt(catRaw) : null;
    const albumId    = albRaw ? parseInt(albRaw) : null;
    const filter = { category_id: categoryId || undefined, album_id: albumId || undefined, offline: true };

    setStatus('filter-status', 'Loading from local library…', 'info');
    try {
        const rows = await libraryImages(hostname, { categoryId, albumId });
        // Map library rows onto the session photo shape. thumb_url is set to the
        // LOCAL asset URL (thumbSrc already fell back to remote on a cache miss),
        // so every existing render site shows local thumbs with no other change.
        const photos = rows.map(r => ({
            id:               r.id,
            title:            r.title,
            description:      r.description,
            sort_order:       r.sort_order,
            modified_at:      r.modified_at,
            category_id:      (r.category_ids || [])[0] ?? null,
            filename:         r.filename,
            thumb_url:        r.src,
            remote_thumb_url: r.thumb_url,
        }));
        state.session     = createSession(state.activeProfile, filter, photos);
        state.sessionPath = await saveSession(state.session);
        setStatus('filter-status', `Loaded ${photos.length} photos from library (offline).`, 'ok');
        await refreshSessions();
        renderSortGrid();
        showTab('sort');
    } catch (err) {
        setStatus('filter-status', `Library load failed: ${err.message}`, 'error');
    }
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
            color_mode:           p.color_mode || '',   // Colour/B&W classification (search/filter tag)
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
        <div class="conflict-item" data-conflict-id="${escHtml(c.id)}">
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
        <div class="cf-field"><span class="cf-label">Sort</span><span class="cf-val">${escHtml(snap.sort_order ?? '')}</span></div>
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
// REPAIR TAB — desktop-owned, resumable one-at-a-time enrichment
// ---------------------------------------------------------------------------
function bindRepairTab() {
    document.getElementById('btn-enrich-scan')?.addEventListener('click', scanEnrichment);
    document.getElementById('btn-enrich-run')?.addEventListener('click', runEnrichment);
    document.getElementById('btn-enrich-stop')?.addEventListener('click', () => {
        state.repairStop = true;
        setStatus('enrich-status', 'Stopping after the current image…', 'info');
    });
    document.querySelectorAll('.repair-field').forEach(box => {
        box.addEventListener('change', renderRepairCount);
    });
}

function selectedRepairFields() {
    return Array.from(document.querySelectorAll('.repair-field:checked')).map(el => el.value);
}

function repairQueue() {
    const fields = new Set(selectedRepairFields());
    return state.repairItems.filter(item => item.missing.some(field => fields.has(field)));
}

function renderRepairCount() {
    const queue = repairQueue();
    const count = document.getElementById('enrich-count');
    if (count) count.textContent = `${queue.length} post${queue.length === 1 ? '' : 's'} need selected fields`;
    const run = document.getElementById('btn-enrich-run');
    if (run) run.disabled = !state.api || queue.length === 0 || selectedRepairFields().length === 0;
}

async function scanEnrichment() {
    if (!state.api) {
        toast('Connect to a site first.', 'error');
        showTab('connect');
        return;
    }
    setStatus('enrich-status', 'Scanning published posts…', 'info');
    document.getElementById('btn-enrich-run').disabled = true;
    try {
        const result = await state.api.enrichmentAudit(1000);
        state.repairItems = result.items || [];
        const prompt = document.getElementById('enrich-prompt');
        if (prompt && (!prompt.value.trim() || prompt.dataset.loaded !== '1')) {
            prompt.value = result.prompt || '';
            prompt.dataset.loaded = '1';
        }
        renderRepairCount();
        setStatus(
            'enrich-status',
            result.ai_ready
                ? `Scan complete. ${state.repairItems.length} posts have missing metadata.`
                : 'Scan complete, but AI is not configured on this site.',
            result.ai_ready ? 'ok' : 'error'
        );
        if (!result.ai_ready) document.getElementById('btn-enrich-run').disabled = true;
    } catch (err) {
        setStatus('enrich-status', `Scan failed: ${err.message}`, 'error');
    }
}

async function runEnrichment() {
    const fields = selectedRepairFields();
    const queue = repairQueue();
    const prompt = document.getElementById('enrich-prompt').value.trim();
    const overwrite = document.getElementById('enrich-overwrite').checked;
    if (!queue.length || !fields.length || !prompt) {
        toast('Scan, select fields, and provide a prompt first.', 'error');
        return;
    }
    if (overwrite && !confirm('Replace populated metadata where selected? This is broader than filling blanks.')) return;

    // SECAUDIT 039: AI enrichment SPENDS MONEY — one paid vision call per image,
    // billed to the site owner's own provider account. The run used to start with
    // no cost warning at all (only the overwrite prompt above), so a 1000-image
    // sweep began silently. Name the count up front; the live counter below then
    // keeps the spend visible while it runs instead of arriving on a bill.
    if (!confirm(
        `Enrich ${queue.length} image${queue.length === 1 ? '' : 's'}?\n\n` +
        `This makes ONE paid AI call per image — ${queue.length} in total — charged to ` +
        `the AI provider account configured on this site. Cost depends on your provider ` +
        `and model.\n\n` +
        `Make sure you have a spending cap set with your provider. You can STOP at any ` +
        `point; images already done are kept.`
    )) return;

    state.repairStop = false;
    const run = document.getElementById('btn-enrich-run');
    const stop = document.getElementById('btn-enrich-stop');
    const results = document.getElementById('enrich-results');
    run.disabled = true;
    stop.disabled = false;
    results.textContent = '';
    let completed = 0;

    for (const item of queue) {
        if (state.repairStop) break;
        // Show paid calls made, not just progress — the spend stays visible.
        document.getElementById('enrich-progress').textContent =
            `${completed} / ${queue.length} · ${completed} paid AI call${completed === 1 ? '' : 's'}`;
        setStatus('enrich-status', `Enriching image ${item.id}…`, 'info');
        try {
            const wanted = overwrite ? fields : fields.filter(field => item.missing.includes(field));
            const result = await state.api.enrichOne(item.id, prompt, wanted, overwrite);
            completed++;
            item.missing = item.missing.filter(field => !(result.applied || []).includes(field));
            results.insertAdjacentHTML('afterbegin',
                `<div class="enrich-result"><img src="${escHtml(item.thumb_url)}" alt="">` +
                `<span>${escHtml(result.metadata?.title || item.title || `Image ${item.id}`)}</span>` +
                `<strong class="ok">${escHtml((result.applied || []).join(', ') || 'review')}</strong></div>`);
        } catch (err) {
            results.insertAdjacentHTML('afterbegin',
                `<div class="enrich-result"><img src="${escHtml(item.thumb_url)}" alt="">` +
                `<span>Image ${item.id}</span><strong class="error">${escHtml(err.message)}</strong></div>`);
            state.repairStop = true;
            setStatus('enrich-status', `Stopped: ${err.message}. Scan and resume after correcting it.`, 'error');
            break;
        }
        // Gentle client-side pacing; there is never a server-side batch loop.
        await new Promise(resolve => setTimeout(resolve, 500));
    }

    document.getElementById('enrich-progress').textContent =
        `${completed} / ${queue.length} · ${completed} paid AI call${completed === 1 ? '' : 's'}`;
    stop.disabled = true;
    if (!state.repairStop) {
        setStatus('enrich-status', `Enrichment complete — ${completed} posts processed. Scan again to verify.`, 'ok');
    }
    renderRepairCount();
}

// ---------------------------------------------------------------------------
// GRID TAB — GRAMOFSMACK: reorder the feed + combine singles into carousels
// Post-layer twin of the SORT tab. Kept separate from the proven solo path.
// ---------------------------------------------------------------------------
// The grid is a SNAPSHOT — the live blog can move under it (web lighttable, another
// device, the composer). Warn past this age, and gate writes on it.
const GRID_STALE_MS = 5 * 60 * 1000;   // 5 minutes

function bindGridTab() {
    document.getElementById('btn-grid-refresh')?.addEventListener('click', loadGrid);
    document.getElementById('btn-grid-push')?.addEventListener('click', doGramPushOrder);
    document.getElementById('btn-grid-combine')?.addEventListener('click', doGramCombine);
    document.getElementById('btn-grid-stale-refresh')?.addEventListener('click', loadGrid);

    // Keep the "loaded N ago" label and the stale banner honest while the tab sits
    // open. Cheap: text only, no network.
    setInterval(renderGridStaleness, 30 * 1000);

    // Drag listeners are attached ONCE here (delegated on the persistent grid),
    // not per-render — re-attaching each render would stack duplicate handlers.
    initGridDragAndDrop();

    // Card selection (ctrl/shift/plain all select — there is no edit panel here).
    // Only combinable posts (published singles) are selectable; carousels and
    // panoramas still drag-reorder but can't be selected for combining.
    document.getElementById('grid-grid')?.addEventListener('click', e => {
        const card = e.target.closest('.photo-card');
        if (!card) return;
        const id = parseInt(card.dataset.id);
        const post = state.gram?.posts.find(p => p.id === id);
        if (!post?.combinable) {
            toast('Only published single posts can be selected to combine.', 'info');
            return;
        }

        if (e.shiftKey) {
            // Range-select, but only the combinable posts in the span.
            const ids     = state.gram.posts.map(p => p.id);
            const lastSel = [...state.gramSelected].pop();
            const from    = lastSel ? ids.indexOf(lastSel) : 0;
            const to      = ids.indexOf(id);
            ids.slice(Math.min(from, to), Math.max(from, to) + 1).forEach(rid => {
                if (state.gram.posts.find(p => p.id === rid)?.combinable) state.gramSelected.add(rid);
            });
        } else {
            // plain / ctrl / meta — toggle this card
            if (state.gramSelected.has(id)) state.gramSelected.delete(id);
            else state.gramSelected.add(id);
        }
        renderGridGrid();
    });
}

async function loadGrid() {
    if (!state.api) { toast('Connect to a site first.', 'error'); return; }
    setStatus('grid-status', 'Loading grid…', 'info');
    try {
        const r = await state.api.gramPosts(1000);
        state.gram = { posts: r.posts || [], dirtyOrder: false, loadedAt: Date.now() };
        state.gramSelected.clear();
        renderGridGrid();
        setStatus('grid-status', `${r.total} posts`, 'ok');
    } catch (err) {
        setStatus('grid-status', `Load failed: ${err.message}`, 'error');
    }
}

function renderGridGrid() {
    const grid = document.getElementById('grid-grid');
    if (!grid || !state.gram) return;
    const posts = [...state.gram.posts].sort((a, b) => a.sort_order - b.sort_order || b.id - a.id);

    if (posts.length === 0) {
        grid.innerHTML = '<p class="empty-hint" style="padding:20px">No posts in this grid.</p>';
        updateGridTopBar();
        return;
    }

    grid.innerHTML = posts.map((p, idx) => `
        <div class="photo-card ${state.gramSelected.has(p.id) ? 'selected' : ''} ${p.combinable ? '' : 'not-combinable'}"
             data-id="${p.id}" data-idx="${idx}" draggable="true"
             title="${p.combinable ? 'Published single — selectable to combine' : escHtml(p.post_type) + (p.status !== 'published' ? ' · ' + escHtml(p.status) : '')}">
            <div class="drag-handle" title="Drag to reorder">⠿</div>
            ${p.image_count > 1 ? `<div class="gram-badge" title="${escHtml(p.image_count)} photos">⧉${escHtml(p.image_count)}</div>` : ''}
            ${p.status !== 'published' ? `<div class="gram-status-badge">${escHtml((p.status || '').toUpperCase())}</div>` : ''}
            <img src="${escHtml(p.thumb_url)}" alt="${escHtml(p.title)}" loading="lazy">
            <div class="card-title">${escHtml(p.title || '')}</div>
        </div>
    `).join('');

    updateGridTopBar();
}

function updateGridTopBar() {
    const profileLabel = document.getElementById('grid-profile-label');
    if (profileLabel && state.activeProfile) {
        profileLabel.textContent = `${state.activeProfile.name} — ${state.activeProfile.site_url}`;
    }
    const countLabel = document.getElementById('grid-count-label');
    if (countLabel && state.gram) {
        const sel = state.gramSelected.size;
        countLabel.textContent = `${state.gram.posts.length} posts` + (sel ? ` · ${sel} selected` : '');
    }
    const pushBtn = document.getElementById('btn-grid-push');
    if (pushBtn) {
        const dirty = !!state.gram?.dirtyOrder;
        pushBtn.disabled    = !dirty;
        pushBtn.textContent = dirty ? 'PUSH ORDER *' : 'PUSH ORDER';
    }
    renderGridCombineBar();
    renderGridStaleness();
}

// ── Staleness: front-stop warning ───────────────────────────────────────────
// The GRID is a point-in-time snapshot. Conflict detection catches collisions at
// write time (after the work); this tells you the copy has drifted BEFORE you
// arrange or combine anything.
function gridAgeMs() {
    return state.gram?.loadedAt ? (Date.now() - state.gram.loadedAt) : 0;
}

function fmtAge(ms) {
    const s = Math.round(ms / 1000);
    if (s < 60)    return `${s}s ago`;
    const m = Math.round(s / 60);
    if (m < 60)    return `${m} minute${m === 1 ? '' : 's'} ago`;
    const h = Math.round(m / 60);
    if (h < 24)    return `${h} hour${h === 1 ? '' : 's'} ago`;
    const d = Math.round(h / 24);
    return `${d} day${d === 1 ? '' : 's'} ago`;
}

function renderGridStaleness() {
    const banner = document.getElementById('grid-stale');
    const ageEl  = document.getElementById('grid-loaded-age');
    if (!state.gram?.loadedAt) {
        if (banner) banner.hidden = true;
        if (ageEl)  ageEl.textContent = '';
        return;
    }
    const age   = gridAgeMs();
    const stale = age > GRID_STALE_MS;

    if (ageEl) ageEl.textContent = ` · Loaded ${fmtAge(age)}.`;
    if (banner) {
        banner.hidden = !stale;
        const msg = document.getElementById('grid-stale-msg');
        if (msg && stale) {
            msg.textContent =
                `This grid was loaded ${fmtAge(age)} and may be out of date — the blog may have ` +
                `changed since (web lighttable, another device, or the composer). `;
        }
    }
}

// Gate a write on freshness. Returns true if the caller should proceed.
// If stale: offer a refresh. Refreshing ABORTS the pending action on purpose —
// the data changed underneath, so the selection/arrangement may no longer mean
// what the user intended. Better to make them look again than to be clever.
async function ensureGridFresh(actionLabel) {
    if (!state.gram?.loadedAt || gridAgeMs() <= GRID_STALE_MS) return true;
    const refresh = confirm(
        `This grid was loaded ${fmtAge(gridAgeMs())} and may be out of date.\n\n` +
        `OK = refresh now (recommended — your ${actionLabel} is cancelled so you can check it ` +
        `against the current blog).\n` +
        `Cancel = ${actionLabel} anyway with the copy you have.`
    );
    if (refresh) {
        await loadGrid();
        toast('Grid refreshed — check it, then try again.', 'info');
        return false;
    }
    return true;
}

// Cover chooser + MAKE CAROUSEL appear at 2+ selected. Options are the selected
// posts in selection order (a JS Set preserves insertion order); first = default
// cover. Only meaningful when every selected post is combinable.
function renderGridCombineBar() {
    const bar = document.getElementById('grid-combine-bar');
    const sel = [...state.gramSelected];
    const combinable = sel.length >= 2 && sel.every(id =>
        state.gram?.posts.find(p => p.id === id)?.combinable);
    if (bar) bar.hidden = !combinable;
    if (!combinable) return;

    const coverSel = document.getElementById('grid-cover-sel');
    if (!coverSel) return;
    const prev = coverSel.value;
    coverSel.innerHTML = sel.map((id, i) =>
        `<option value="${id}">${i === 0 ? 'Photo 1 (first)' : 'Photo ' + (i + 1)}</option>`).join('');
    if (sel.map(String).includes(prev)) coverSel.value = prev;
}

// Native HTML5 drag reorder (single card at a time — selection is for combining).
function initGridDragAndDrop() {
    const grid = document.getElementById('grid-grid');
    if (!grid) return;
    let draggedId = null;
    let dropTarget = null;

    grid.addEventListener('dragstart', e => {
        const card = e.target.closest('.photo-card');
        if (!card) return;
        draggedId = parseInt(card.dataset.id);
        e.dataTransfer.effectAllowed = 'move';
        card.classList.add('dragging');
    });
    grid.addEventListener('dragover', e => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const card = e.target.closest('.photo-card');
        if (card && parseInt(card.dataset.id) !== draggedId) {
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
        if (dropTarget && draggedId !== null) {
            dropTarget.classList.remove('drop-target');
            gridReorder(draggedId, parseInt(dropTarget.dataset.idx));
            dropTarget = null;
        }
        draggedId = null;
        grid.querySelectorAll('.dragging').forEach(c => c.classList.remove('dragging'));
    });
    grid.addEventListener('dragend', () => {
        grid.querySelectorAll('.dragging, .drop-target').forEach(c =>
            c.classList.remove('dragging', 'drop-target'));
        draggedId = null;
    });
}

function gridReorder(movedId, targetIdx) {
    if (!state.gram) return;
    const posts = [...state.gram.posts].sort((a, b) => a.sort_order - b.sort_order);
    const moved = posts.find(p => p.id === movedId);
    if (!moved) return;
    const rest = posts.filter(p => p.id !== movedId);
    const before = rest[targetIdx] || null;
    const insertIdx = before ? rest.indexOf(before) : rest.length;
    rest.splice(insertIdx, 0, moved);
    rest.forEach((p, i) => { p.sort_order = i + 1; });
    state.gram.posts = rest;
    state.gram.dirtyOrder = true;
    renderGridGrid();
}

async function doGramPushOrder() {
    if (!state.gram || !state.api || !state.gram.dirtyOrder) return;
    // Reordering is wholesale ("make the feed look like this") — a stale copy
    // would silently stomp anything published or rearranged since the pull.
    if (!await ensureGridFresh('order push')) return;
    const ids = [...state.gram.posts]
        .sort((a, b) => a.sort_order - b.sort_order).map(p => p.id);
    setStatus('grid-status', 'Pushing order…', 'info');
    try {
        await state.api.gramReorder(ids);
        setStatus('grid-status', 'Order saved.', 'ok');
        await loadGrid();   // reload authoritative order (trigrams stay anchored)
    } catch (err) {
        setStatus('grid-status', `Push failed: ${err.message}`, 'error');
    }
}

async function doGramCombine() {
    if (!state.gram || !state.api) return;
    const ids = [...state.gramSelected];
    if (ids.length < 2) return;
    if (!ids.every(id => state.gram.posts.find(p => p.id === id)?.combinable)) {
        toast('Only published single posts can be combined.', 'error');
        return;
    }
    // Combining DELETES the source singles and can retract them from followers —
    // never do that off a stale snapshot.
    if (!await ensureGridFresh('carousel')) return;

    const coverSel  = document.getElementById('grid-cover-sel');
    const coverPid  = coverSel && coverSel.value ? parseInt(coverSel.value) : ids[0];
    const coverPos  = ids.indexOf(coverPid) + 1;

    if (!confirm(
        `Combine ${ids.length} posts into ONE carousel?\n\n` +
        `Order = the order you selected them. Cover = Photo ${coverPos > 0 ? coverPos : 1}.\n\n` +
        `The original single posts are merged in and deleted. If federation is on, they're ` +
        `retracted from your followers and the carousel is re-posted. This cannot be undone.`
    )) return;

    const btn = document.getElementById('btn-grid-combine');
    if (btn) { btn.disabled = true; btn.textContent = 'Combining…'; }
    setStatus('grid-status', 'Combining…', 'info');
    try {
        const r = await state.api.gramCarousel(ids, coverPid);
        setStatus('grid-status', r.message || 'Carousel created.', 'ok');
        toast('Carousel created.', 'ok');
        await loadGrid();
    } catch (err) {
        setStatus('grid-status', `Combine failed: ${err.message}`, 'error');
        toast(`Combine failed: ${err.message}`, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = '⧉ MAKE CAROUSEL'; }
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

// SECAUDIT 039: every caller interpolates this into innerHTML, and the catch
// branch used to return the raw input — so a malformed timestamp in a session or
// profile file became an injection vector. Escape the fallback at the source.
function fmtDate(iso) {
    if (!iso) return '';
    try { return new Date(iso).toLocaleString(); } catch { return escHtml(iso); }
}

function fmtFilter(f = {}) {
    const parts = [];
    if (f.date_from || f.date_to) parts.push(`${f.date_from || '…'} – ${f.date_to || '…'}`);
    if (f.category_id) parts.push(`cat:${f.category_id}`);
    if (f.album_id)    parts.push(`album:${f.album_id}`);
    return parts.join(' · ') || 'All photos';
}

// ===== SNAPSMACK EOF =====
