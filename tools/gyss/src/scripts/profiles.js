// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GET YOUR SHIT SORTED — Profile manager
//
// Connection profiles live in the ONE shared cross-tool store:
//     <root>\shared_library\profiles\<site_key>.json
// keyed by hostname via siteKey() — the SAME path snap_profiles.py (SYBU / SUYB /
// COLD SNAP) and the Hub's Discover Fleet write. Set a blog up in ANY tool, or hit
// Discover Fleet in the Hub, and it shows up here; save one here and the others see
// it. Before this, GYSS kept its own private profiles in config_files\gyss\profiles\
// and a blog set up elsewhere was invisible — that's what this closes.
//
// CANONICAL ON-DISK SHAPE (byte-compatible with snap_profiles.py):
//     { schema:1, name, site_url, api_key_enc:<base64 utf-8>, last_connected, extras:{} }
// api_key_enc is base64 OBFUSCATION, not encryption (matches the whole family). We
// preserve `extras` on save so a profile another tool enriched (SYBU drive/gemini
// defaults, etc.) is never clobbered by GYSS re-saving it.

import { invoke } from '@tauri-apps/api/core';
import { join } from '@tauri-apps/api/path';
import { sharedHome, siteKey } from './paths.js';

// ===== SNAPSMACK EOF =====  (header reference only — JS marker at bottom)

const SCHEMA = 1;

let _profilesDir = null;
let _legacyDir = null;
let _migrated = false;

/** Shared cross-tool profiles dir (cached). */
async function profilesDir() {
    if (!_profilesDir) {
        _profilesDir = await join(await sharedHome(), 'shared_library', 'profiles');
    }
    return _profilesDir;
}

/** Legacy GYSS-private profiles dir (pre-consolidation) — migration source only. */
async function legacyDir() {
    if (!_legacyDir) {
        _legacyDir = await join(await sharedHome(), 'config_files', 'gyss', 'profiles');
    }
    return _legacyDir;
}

// ── base64 of UTF-8 bytes (matches Python base64.b64encode(...utf-8)) ─────────
function b64EncodeUtf8(s) {
    const bytes = new TextEncoder().encode(String(s ?? ''));
    let bin = '';
    for (const b of bytes) bin += String.fromCharCode(b);
    return btoa(bin);
}
function b64DecodeUtf8(b64) {
    try {
        const bin = atob(String(b64 || ''));
        const bytes = Uint8Array.from(bin, c => c.charCodeAt(0));
        return new TextDecoder().decode(bytes);
    } catch { return ''; }
}

/** On-disk canonical -> in-memory profile (plaintext api_key). */
function fromDisk(data, path) {
    const extras = (data.extras && typeof data.extras === 'object') ? data.extras : {};
    return {
        name:           data.name || '',
        site_url:       data.site_url || '',
        // GYSS must not mistake another tool's shared-profile key for its own.
        api_key:        String(extras.api_key_gyss || '') || b64DecodeUtf8(data.api_key_enc || ''),
        last_connected: data.last_connected ?? null,
        extras,
        _path:          path,
    };
}

async function readProfile(path) {
    const raw = await invoke('read_file', { path });
    const data = JSON.parse(raw);
    if (!data || typeof data !== 'object' || !String(data.site_url || '').trim()) {
        throw new Error('not a profile');
    }
    return data;
}

/** List all saved profiles (no raw key). Runs the one-time legacy migration first. */
export async function listProfiles() {
    await migrateLegacyOnce();
    let paths = [];
    try {
        paths = await invoke('list_dir', { path: await profilesDir() });
    } catch { return []; }               // dir not created yet — no profiles
    const profiles = [];
    for (const p of paths) {
        if (!/\.json$/i.test(p)) continue;
        try {
            const data = await readProfile(p);
            const extras = (data.extras && typeof data.extras === 'object') ? data.extras : {};
            if (extras.gyss_site_mode === 'smacktalk') continue;
            profiles.push({
                _path:          p,
                name:           data.name || '',
                site_url:       data.site_url || '',
                last_connected: data.last_connected ?? null,
                site_mode:      extras.gyss_site_mode || '',
            });
        } catch { /* skip malformed / non-profile json */ }
    }
    return profiles.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
}

/** Cache the site's mode without disturbing keys or settings owned by other tools. */
export async function cacheProfileSiteMode(path, mode) {
    try {
        const data = await readProfile(path);
        data.extras = (data.extras && typeof data.extras === 'object') ? data.extras : {};
        data.extras.gyss_site_mode = String(mode || '');
        await invoke('write_file', { path, content: JSON.stringify(data, null, 2) });
    } catch { /* classification is best-effort */ }
}

/** Load a single profile by path. Returns profile with raw api_key + extras. */
export async function loadProfile(path) {
    const data = await readProfile(path);
    const extras = (data.extras && typeof data.extras === 'object') ? data.extras : {};
    // Fleet discovery retains the full hub→spoke key locally. Use it once to
    // mint a restricted GYSS-only key, then persist that scoped key separately.
    if (!String(extras.api_key_gyss || '').trim() && String(extras.api_key_local || '').trim()) {
        try {
            extras.api_key_gyss = await invoke('provision_gyss_key', {
                siteUrl: String(data.site_url || ''),
                apiKeyLocal: String(extras.api_key_local),
            });
            data.extras = extras;
            await invoke('write_file', { path, content: JSON.stringify(data, null, 2) });
        } catch (err) {
            // The fleet hub cannot provision itself through its spoke-only route.
            // Keep loading so a manually created GYSS key remains usable.
            console.warn('Automatic GYSS key provisioning failed:', err);
        }
    }
    return fromDisk(data, path);
}

/** Save a profile. Keyed by site (siteKey), so re-saving the same blog updates it
 *  in place and every other tool sees the change. Preserves existing extras. */
export async function saveProfile(profile) {
    const site = String(profile.site_url || '').trim();
    if (!site) throw new Error('profile has no site_url');
    const dir  = await profilesDir();
    const path = await join(dir, `${siteKey(site)}.json`);

    // Merge over any existing on-disk profile so we never drop another tool's extras.
    let existing = null;
    try { existing = await readProfile(path); } catch { /* new profile */ }
    let extras = (profile.extras && typeof profile.extras === 'object') ? profile.extras : null;
    if (!extras) {
        extras = existing?.extras || {};
    }
    extras.api_key_gyss = String(profile.api_key || '');

    const toWrite = {
        schema:         SCHEMA,
        name:           profile.name || site,
        site_url:       site,
        // Preserve the shared profile's primary key; GYSS owns its scoped entry.
        api_key_enc:    existing?.api_key_enc || b64EncodeUtf8(profile.api_key || ''),
        last_connected: profile.last_connected ?? null,
        extras:         (extras && typeof extras === 'object') ? extras : {},
    };
    await invoke('write_file', { path, content: JSON.stringify(toWrite, null, 2) });
    return path;
}

/** Delete a profile by path (fs-jailed real delete). */
export async function deleteProfile(path) {
    await invoke('delete_file', { path });
}

/** Touch last_connected timestamp, preserving the canonical shape. */
export async function touchProfile(path, isoTimestamp) {
    try {
        const data = await readProfile(path);
        data.last_connected = isoTimestamp;
        await invoke('write_file', { path, content: JSON.stringify(data, null, 2) });
    } catch { /* non-fatal */ }
}

// ── one-time migration of the legacy config_files\gyss\profiles\ folder ──────
// Non-destructive: a blog that already has a shared profile (set up in SYBU or the
// Hub) is left untouched; only GYSS-only blogs get promoted. The old files stay as
// a backup, and a marker stops us rescanning every launch.
async function migrateLegacyOnce() {
    if (_migrated) return;
    _migrated = true;                     // attempt once per process regardless of outcome
    try {
        const ldir   = await legacyDir();
        const marker = await join(ldir, '.migrated_to_shared');
        try { await invoke('read_file', { path: marker }); return; } catch { /* not migrated */ }

        let oldPaths = [];
        try { oldPaths = await invoke('list_dir', { path: ldir }); } catch { oldPaths = []; }
        const sharedDir = await profilesDir();
        for (const op of oldPaths) {
            if (!/\.json$/i.test(op)) continue;
            // Per-file idempotency: once a legacy profile has been reconciled we stamp
            // it, so it is NEVER re-migrated — even if the global marker write below
            // failed transiently. Without this, a user who deletes a migrated profile
            // could see it resurrected on the next launch. The stamp is `<file>.migrated`
            // (not a .json, so it never shows up as a profile).
            const perFileDone = `${op}.migrated`;
            try { await invoke('read_file', { path: perFileDone }); continue; } catch { /* not yet */ }
            let old;
            try { old = JSON.parse(await invoke('read_file', { path: op })); } catch { continue; }
            const site = String(old && old.site_url || '').trim();
            if (!site) continue;
            const dest = await join(sharedDir, `${siteKey(site)}.json`);
            let already = false;
            try { await invoke('read_file', { path: dest }); already = true; } catch { /* absent — migrate */ }
            if (!already) {
                const toWrite = {
                    schema:         SCHEMA,
                    name:           old.name || site,
                    site_url:       site,
                    // Legacy GYSS also stored the key under api_key_enc (btoa) — same
                    // bytes as our UTF-8 base64 for ASCII keys; re-encode to be safe.
                    api_key_enc:    b64EncodeUtf8(b64DecodeUtf8(old.api_key_enc || '')),
                    last_connected: old.last_connected ?? null,
                    extras:         {},
                };
                try { await invoke('write_file', { path: dest, content: JSON.stringify(toWrite, null, 2) }); }
                catch { continue; }   // write failed — leave unstamped, retry next launch
            }
            // Reconciled (freshly written, or the site already had a shared profile):
            // stamp so this legacy file is never migrated again.
            try { await invoke('write_file', { path: perFileDone, content: 'migrated\n' }); } catch { /* best effort */ }
        }
        try { await invoke('write_file', { path: marker, content: 'GYSS profiles migrated to shared_library/profiles\n' }); } catch { /* non-fatal */ }
    } catch { /* migration is best-effort; never block the app */ }
}

// ===== SNAPSMACK EOF =====
