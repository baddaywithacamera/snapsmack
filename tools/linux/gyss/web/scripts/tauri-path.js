// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GYSS (Blink port) — the "@tauri-apps/api/path" shim, without Tauri.
//
// The reused scripts only use join() (to build paths under the shared root that
// sharedHome() returns). Tauri's path.join() joined with the OS separator and
// normalised; on the Linux target that is '/'. This is a pure-JS POSIX join — no
// backend round-trip needed. It keeps a leading '/' (absolute root from
// shared_home) and collapses duplicate separators.
export async function join(...segments) {
    const parts = [];
    for (const seg of segments) {
        if (seg === undefined || seg === null) continue;
        parts.push(String(seg));
    }
    if (parts.length === 0) return '';
    const joined = parts
        .map((p, i) => {
            let s = p.replace(/\\/g, '/');
            if (i > 0) s = s.replace(/^\/+/, '');   // no leading slash on later parts
            if (i < parts.length - 1) s = s.replace(/\/+$/, ''); // no trailing slash mid-way
            return s;
        })
        .filter(s => s.length > 0)
        .join('/');
    // Collapse any accidental double slashes, but preserve a single leading one.
    const lead = joined.startsWith('/') ? '/' : '';
    return lead + joined.replace(/^\/+/, '').replace(/\/{2,}/g, '/');
}

// appDataDir is not used by any ported script (GYSS keeps everything under the
// shared root via shared_home). Kept as a faithful stub so the import specifier
// still resolves; calling it is a clear port gap rather than a silent wrong path.
// TODO(port): appDataDir — unused; wire to a real OS app-data path only if a
// future caller needs it (the Tauri migrate_legacy step that used it is gone).
export async function appDataDir() {
    throw new Error('appDataDir is not available in the GYSS Blink port (unused).');
}
// ===== SNAPSMACK EOF =====
