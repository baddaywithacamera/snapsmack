// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GET YOUR SHIT SORTED — shared secret store (parity with snap_creds.py)
//
// The ONE shared credential store the whole SnapSmack desktop-tool family reads:
//     <root>\shared_library\auth\shared_creds.json
// A flat JSON dict { key: "b64:<base64-of-utf8-value>" }, byte-compatible with
// tools/_shared/snap_creds.py — enter a secret in ANY tool and every tool sees it.
//
// At-rest forms (match snap_creds):
//   b64:<b64>   base64 of the UTF-8 value — the default obfuscated form.
//   enc1:<...>  vault-sealed (snap_vault). GYSS has no vault; such a value is
//               treated as UNAVAILABLE (returns '') and a note is logged.
//
// Well-known keys: gemini_api_key, google_credentials, drive_folder_id.
//
// NOTE: the per-site GYSS *import* API key is NOT a shared secret — it stays
// per-profile in config_files/gyss/profiles/ (see profiles.js). Only the
// genuinely-shared secrets above live here.

import { invoke } from '@tauri-apps/api/core';
import { join } from '@tauri-apps/api/path';
import { sharedHome } from './paths.js';

// ===== SNAPSMACK EOF =====  (header reference only — JS marker at bottom)

const B64_PREFIX = 'b64:';
const ENC1_PREFIX = 'enc1:';

async function storePath() {
    return join(await sharedHome(), 'shared_library', 'auth', 'shared_creds.json');
}

/** base64 of a string's UTF-8 bytes (matches Python base64.b64encode(...utf-8)). */
function b64EncodeUtf8(s) {
    const bytes = new TextEncoder().encode(String(s ?? ''));
    let bin = '';
    for (const b of bytes) bin += String.fromCharCode(b);
    return btoa(bin);
}

/** Inverse of b64EncodeUtf8. */
function b64DecodeUtf8(b64) {
    const bin = atob(b64);
    const bytes = Uint8Array.from(bin, c => c.charCodeAt(0));
    return new TextDecoder().decode(bytes);
}

async function readStore() {
    try {
        const raw = await invoke('read_file', { path: await storePath() });
        const data = JSON.parse(raw);
        return (data && typeof data === 'object') ? data : {};
    } catch { return {}; }
}

async function writeStore(data) {
    await invoke('write_file', { path: await storePath(), content: JSON.stringify(data, null, 2) });
}

/** Decode one stored blob to its plaintext value ('' if sealed/unreadable). */
function openBlob(blob) {
    if (typeof blob !== 'string') return '';
    if (blob.startsWith(ENC1_PREFIX)) {
        console.warn('[shared-creds] value is vault-sealed (enc1:) — unavailable in GYSS.');
        return '';
    }
    if (blob.startsWith(B64_PREFIX)) {
        try { return b64DecodeUtf8(blob.slice(B64_PREFIX.length)); } catch { return ''; }
    }
    return blob; // tolerate legacy plaintext
}

/** Read a shared secret. Returns `fallback` if unset/unreadable/vault-sealed. */
export async function getCred(key, fallback = '') {
    const data = await readStore();
    if (!(key in data)) return fallback;
    const v = openBlob(data[key]);
    return v || fallback;
}

/** Store a shared secret (base64 form), preserving the other keys. */
export async function setCred(key, value) {
    const data = await readStore();
    data[key] = B64_PREFIX + b64EncodeUtf8(value || '');
    await writeStore(data);
}

// ===== SNAPSMACK EOF =====
