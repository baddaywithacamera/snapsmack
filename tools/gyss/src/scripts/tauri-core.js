// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GYSS shim — resolves the bare specifier "@tauri-apps/api/core" in the
// bundler-less webview.
//
// SECAUDIT 054 (chokepoint 3): this now binds to window.__TAURI_INTERNALS__ —
// the runtime bridge Tauri always injects — instead of the withGlobalTauri
// convenience global, so app.withGlobalTauri is OFF in tauri.conf.json. The
// bridge reference is captured ONCE at module load, before any remote data has
// been rendered, so later script injection cannot swap it out from under us.
const _internals = window.__TAURI_INTERNALS__;
if (!_internals) {
    throw new Error("GYSS must run inside its Tauri shell (no __TAURI_INTERNALS__).");
}
const _invoke = _internals.invoke.bind(_internals);
const _convert = _internals.convertFileSrc
    ? _internals.convertFileSrc.bind(_internals)
    : null;

export const invoke = (cmd, args) => _invoke(cmd, args);
// Turn an absolute filesystem path into a webview-loadable asset URL. Needed by
// the offline library to render locally-downloaded thumbnails from disk.
export const convertFileSrc = (filePath, protocol = "asset") => {
    if (!_convert) {
        throw new Error("convertFileSrc unavailable in this Tauri runtime.");
    }
    return _convert(filePath, protocol);
};
// ===== SNAPSMACK EOF =====
