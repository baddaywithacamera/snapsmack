// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GYSS shim — resolves the bare specifier "@tauri-apps/api/core" in the
// bundler-less webview by re-exporting from the global Tauri bundle
// (enabled via app.withGlobalTauri = true in tauri.conf.json).
const { core } = window.__TAURI__;
export const invoke = (...args) => core.invoke(...args);
// ===== SNAPSMACK EOF =====
