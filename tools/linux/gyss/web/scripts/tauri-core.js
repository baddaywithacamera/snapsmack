// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GYSS (Blink port) — the "@tauri-apps/api/core" shim, retargeted at snap_blink.
//
// In the Tauri build this re-exported window.__TAURI__.core. On the Linux
// Chrome/Blink runtime there is no Tauri object; instead snap_blink serves
// /snap_blink.js which defines window.blink.call(method, ...positionalArgs).
//
// Tauri commands take NAMED params: invoke('write_file', { path, content }).
// snap_blink handlers take POSITIONAL args. We keep the Tauri shape — and avoid
// touching any of the reused caller scripts — by passing the single named-args
// OBJECT through as ONE positional arg. app.py's handlers each accept that one
// dict and read the same keys the Rust command did. Command NAMES are unchanged.

// invoke(cmd)              -> blink.call(cmd)              (e.g. shared_home)
// invoke(cmd, { ...args }) -> blink.call(cmd, { ...args }) (handler gets one dict)
export function invoke(cmd, args) {
    if (!window.blink || typeof window.blink.call !== 'function') {
        return Promise.reject(new Error('snap_blink bridge not loaded (window.blink missing)'));
    }
    return (args === undefined)
        ? window.blink.call(cmd)
        : window.blink.call(cmd, args);
}

// Tauri's convertFileSrc turned an absolute path into an asset:// URL the webview
// could load directly. snap_blink only serves files under web/, and thumbnails
// live under shared_library/, so instead we ask the Python side for the file as a
// data: URL (app.py `read_asset`). Returns a PROMISE (Tauri's was synchronous) —
// the one caller, library.js thumbSrc(), awaits it.
export async function convertFileSrc(absPath) {
    return invoke('read_asset', { path: absPath });
}
// ===== SNAPSMACK EOF =====
