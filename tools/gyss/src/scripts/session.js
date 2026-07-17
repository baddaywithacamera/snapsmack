// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GET YOUR SHIT SORTED — Session manager
// A session is a snapshot of a filtered photo batch.
// Persisted to %APPDATA%\GetYourShitSorted\sessions\ as JSON.
// Sessions survive app restarts — resume mid-sort after close.

import { invoke } from '@tauri-apps/api/core';
import { appDataDir, join } from '@tauri-apps/api/path';

// ===== SNAPSMACK EOF =====  (header reference only — JS marker at bottom)

let _sessionsDir = null;

async function sessionsDir() {
    if (!_sessionsDir) {
        const appData = await appDataDir();
        _sessionsDir  = await join(appData, 'GetYourShitSorted', 'sessions');
    }
    return _sessionsDir;
}

/** Generate a simple UUID v4. */
function uuidv4() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = Math.random() * 16 | 0;
        return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
}

/**
 * Create a new session object (in-memory). Call saveSession() to persist.
 * @param {Object} profile   { name, site_url }
 * @param {Object} filter    { date_from, date_to, category_id, album_id }
 * @param {Array}  photos    Raw photo array from gyss/photos response
 */
export function createSession(profile, filter, photos) {
    const now = new Date().toISOString();
    return {
        session_id:           uuidv4(),
        profile_name:         profile.name,
        site_url:             profile.site_url,
        created_at:           now,
        last_saved:           now,
        filter:               { ...filter },
        photos:               photos.map(p => ({
            ...p,
            original_sort:  p.sort_order,
            original_modified_at: p.modified_at,
            dirty:          false,
        })),
        unresolved_conflicts: [],
    };
}

/** Return dirty count for a session. */
export function dirtyCount(session) {
    return session.photos.filter(p => p.dirty).length;
}

/** Mark a photo in the session as dirty. */
export function markDirty(session, photoId) {
    const p = session.photos.find(p => p.id === photoId);
    if (p) p.dirty = true;
}

/** Clear dirty flag and update original_sort after a successful push. */
export function clearDirty(session, appliedIds, newModifiedAtMap = {}) {
    for (const p of session.photos) {
        if (appliedIds.includes(p.id)) {
            p.dirty               = false;
            p.original_sort       = p.sort_order;
            if (newModifiedAtMap[p.id]) {
                p.modified_at             = newModifiedAtMap[p.id];
                p.original_modified_at    = newModifiedAtMap[p.id];
            }
        }
    }
}

/** Add unresolved conflicts to the session. */
export function addConflicts(session, conflicts) {
    const now = new Date().toISOString();
    for (const c of conflicts) {
        // Avoid duplicates
        const existing = session.unresolved_conflicts.findIndex(uc => uc.id === c.id);
        const entry = {
            id:              c.id,
            detected_at:     now,
            theirs_snapshot: {
                title:       c.theirs.title,
                description: c.theirs.description,
                category_id: c.theirs.category_id,
                sort_order:  c.theirs.sort_order,
                modified_at: c.current_modified_at,
            },
            mine_snapshot:   c.mine,
        };
        if (existing >= 0) {
            session.unresolved_conflicts[existing] = entry;
        } else {
            session.unresolved_conflicts.push(entry);
        }
    }
}

/** Remove a resolved conflict from the session. */
export function removeConflict(session, photoId) {
    session.unresolved_conflicts = session.unresolved_conflicts.filter(c => c.id !== photoId);
}

/** Save session to disk. */
export async function saveSession(session) {
    const dir  = await sessionsDir();
    const path = await join(dir, `${session.session_id}.json`);
    session.last_saved = new Date().toISOString();
    await invoke('write_file', { path, content: JSON.stringify(session, null, 2) });
    return path;
}

/** Load all sessions. Returns array sorted by last_saved desc, dirty sessions first. */
export async function listSessions() {
    const dir   = await sessionsDir();
    const paths = await invoke('list_dir', { path: dir });
    const sessions = [];
    for (const p of paths) {
        try {
            const raw = await invoke('read_file', { path: p });
            const obj = JSON.parse(raw);
            if (obj._deleted) continue;
            obj._path      = p;
            obj.dirty_count = dirtyCount(obj);
            sessions.push(obj);
        } catch { /* skip malformed */ }
    }
    // Dirty sessions first, then by last_saved desc
    return sessions.sort((a, b) => {
        if (a.dirty_count > 0 && b.dirty_count === 0) return -1;
        if (b.dirty_count > 0 && a.dirty_count === 0) return  1;
        return new Date(b.last_saved) - new Date(a.last_saved);
    });
}

/** Load a single session from disk. */
export async function loadSession(path) {
    const raw = await invoke('read_file', { path });
    return JSON.parse(raw);
}

/** Delete a session (soft-delete). */
export async function deleteSession(path) {
    try {
        await invoke('write_file', { path, content: JSON.stringify({ _deleted: true }) });
    } catch { /* ignore */ }
}

// ===== SNAPSMACK EOF =====
