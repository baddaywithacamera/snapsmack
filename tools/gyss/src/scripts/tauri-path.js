// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
// GYSS shim — resolves "@tauri-apps/api/path" in the bundler-less webview.
//
// SECAUDIT 054 (chokepoint 3): no window.__TAURI__ any more. The only consumer
// of this module is join() (building paths under the shared SnapSmack root for
// the jailed fs commands), which needs no native call at all — a plain string
// join is exact here, and the Rust side normalizes via PathBuf anyway.
// appDataDir stays exported for compatibility but routes through the shared
// root command that replaced it as the storage base.
import { invoke } from "@tauri-apps/api/core";

export const join = (...parts) =>
    parts
        .filter((p) => p !== null && p !== undefined && String(p) !== "")
        .map(String)
        .join("\\")
        .replace(/[\\/]+/g, "\\");

export const appDataDir = () => invoke("shared_home");
// ===== SNAPSMACK EOF =====
