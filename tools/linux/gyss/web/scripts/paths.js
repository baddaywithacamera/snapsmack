// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GET YOUR SHIT SORTED — shared-root path helpers
//
// GYSS now lives on the shared SnapSmack desktop-tool foundation (see
// tools/_shared/snap_home.py). One root — env SNAPSMACK_HOME, else C:\snapsmack —
// holds shared_library/<site_key>/… (the per-site store COLD SNAP / SYBU also
// use) and config_files/gyss/… (GYSS's own profiles + sessions). The Rust
// `shared_home` command resolves the root; the fs jail (resolve_in_root) is
// anchored to the SAME root, so every path built from here sits inside it.

import { invoke } from '@tauri-apps/api/core';

// ===== SNAPSMACK EOF =====  (header reference only — JS marker at bottom)

let _root = null;

/** Resolved SnapSmack root (cached). Matches Rust shared_home() / snap_home.home(). */
export async function sharedHome() {
    if (_root === null) _root = await invoke('shared_home');
    return _root;
}

/**
 * Normalise a site URL/hostname to a safe folder name — the SAME algorithm as
 * snap_home.site_key(): lowercase host; [^a-z0-9.-] → '-'; strip leading/trailing
 * '.-'; collapse repeated '-'. COLD SNAP / SYBU key the shared library this way
 * too, so a given blog lands in ONE shared folder across all tools.
 */
export function siteKey(site) {
    const raw = String(site || '').trim();
    if (!raw) throw new Error('site is empty');
    // Match Python snap_home.site_key() EXACTLY. It uses urlparse().hostname, which
    // keeps the raw unicode host — it does NOT apply IDNA/punycode. new URL().hostname
    // WOULD punycode an internationalized host (münchen.de -> xn--mnchen-3ya.de) and
    // so compute a different profile filename than the Python tools for the same blog.
    // Parse the host by hand to stay byte-identical with the Python peer.
    let host = raw.replace(/^[a-z][a-z0-9+.-]*:\/\//i, ''); // strip scheme
    host = host.split(/[/?#]/)[0];                          // drop path/query/fragment
    host = host.split('@').pop();                           // drop userinfo
    host = host.replace(/:\d+$/, '');                       // drop :port
    host = host.toLowerCase();
    if (!host) host = raw.toLowerCase();
    let key = host.replace(/[^a-z0-9.-]/g, '-').replace(/^[.-]+|[.-]+$/g, '');
    key = key.replace(/-{2,}/g, '-');
    if (!key) throw new Error(`site yields no usable folder name: ${site}`);
    return key;
}

// ===== SNAPSMACK EOF =====
