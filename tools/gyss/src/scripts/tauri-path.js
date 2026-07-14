// GYSS shim — resolves "@tauri-apps/api/path" against the global Tauri bundle.
const { path } = window.__TAURI__;
export const appDataDir = (...args) => path.appDataDir(...args);
export const join       = (...args) => path.join(...args);
