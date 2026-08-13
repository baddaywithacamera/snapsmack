// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GET YOUR SHIT SORTED — Profile manager
// One JSON file per site under the shared root: config_files\gyss\profiles\
// (snap_home's config_dir('gyss') contract — a tool's own config in the shared
// root). The per-site import API key is base64-obfuscated (not encrypted — just
// keeps it off plaintext) and stays PER-PROFILE; it is NOT a shared secret.
// Same obfuscation convention as SYBU.

import { invoke } from '@tauri-apps/api/core';
import { join } from '@tauri-apps/api/path';
import { sharedHome } from './paths.js';

// ===== SNAPSMACK EOF =====  (header reference only — JS marker at bottom)

let _profilesDir = null;

async function profilesDir() {
    if (!_profilesDir) {
        _profilesDir = await join(await sharedHome(), 'config_files', 'gyss', 'profiles');
    }
    return _profilesDir;
}

/** Obfuscate API key (base64). */
function obfuscate(raw) {
    return btoa(raw);
}

/** Deobfuscate API key. */
function deobfuscate(encoded) {
    try { return atob(encoded); } catch { return encoded; }
}

/** List all saved profiles. Returns array of profile objects. */
export async function listProfiles() {
    const dir   = await profilesDir();
    const paths = await invoke('list_dir', { path: dir });
    const profiles = [];
    for (const p of paths) {
        try {
            const raw = await invoke('read_file', { path: p });
            const obj = JSON.parse(raw);
            obj._path = p;
            // Never expose the raw key
            obj.api_key_display = obj.api_key_prefix || '(key saved)';
            profiles.push(obj);
        } catch { /* skip malformed */ }
    }
    return profiles.sort((a, b) => a.name.localeCompare(b.name));
}

/** Load a single profile by path. Returns profile with raw api_key. */
export async function loadProfile(path) {
    const raw = await invoke('read_file', { path });
    const obj = JSON.parse(raw);
    obj.api_key = deobfuscate(obj.api_key_enc || '');
    obj._path = path;
    return obj;
}

/** Save a profile. Obfuscates the key before writing. */
export async function saveProfile(profile) {
    const dir = await profilesDir();
    // Filename derived from site_url hostname
    const hostname = new URL(profile.site_url).hostname.replace(/[^a-z0-9\-\.]/gi, '_');
    const path = await join(dir, `${hostname}.json`);

    const toWrite = {
        name:           profile.name,
        site_url:       profile.site_url,
        api_key_enc:    obfuscate(profile.api_key),
        api_key_prefix: profile.api_key.substring(0, 8),
        last_connected: profile.last_connected || null,
    };
    await invoke('write_file', { path, content: JSON.stringify(toWrite, null, 2) });
    return path;
}

/** Delete a profile by path. */
export async function deleteProfile(path) {
    // Actually remove the file via the fs-jailed Rust `delete_file` command.
    // The previous build wrote a {_deleted:true} tombstone and trusted
    // listProfiles to skip it — but listProfiles never skipped it, and a
    // nameless tombstone then threw in the `.name` sort, taking the whole
    // profile list down. A real delete leaves nothing for the list to trip on.
    await invoke('delete_file', { path });
}

/** Touch last_connected timestamp on a profile. */
export async function touchProfile(path, isoTimestamp) {
    try {
        const raw = await invoke('read_file', { path });
        const obj = JSON.parse(raw);
        obj.last_connected = isoTimestamp;
        await invoke('write_file', { path, content: JSON.stringify(obj, null, 2) });
    } catch { /* non-fatal */ }
}

// ===== SNAPSMACK EOF =====
