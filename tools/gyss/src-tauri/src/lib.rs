// GET YOUR SHIT SORTED — Tauri backend
//
// The Rust layer is intentionally thin. All application logic lives in the
// JS frontend. Rust handles: file system access (profile/session load/save)
// and native file dialogs.
//
// HTTP calls to the SnapSmack gyss-api.php handler are made directly from JS
// via fetch(). The API emits CORS headers for tauri:// origins so no Rust
// proxy is needed.

use tauri::Manager;

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_fs::init())
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_shell::init())
        .setup(|app| {
            #[cfg(debug_assertions)]
            {
                let window = app.get_webview_window("main").unwrap();
                window.open_devtools();
            }
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            read_file,
            write_file,
            list_dir,
        ])
        .run(tauri::generate_context!())
        .expect("error while running GET YOUR SHIT SORTED");
}

/// SECURITY (SECAUDIT 039): confine every file command to this app's own data
/// directory.
///
/// These three commands are registered on `invoke_handler`, and app-defined
/// commands are NOT gated by the capability system — so anything running in the
/// webview can call them. Before this guard they took an arbitrary absolute path,
/// which made `write_file` an arbitrary-file-write primitive (it even created
/// parent dirs): any script injected into the webview could drop a payload into,
/// say, the user's Startup folder. The webview renders data fetched from a
/// user-supplied site URL, so that is a reachable path, not a theoretical one.
///
/// Rule: reject any `..` component, then require the path to sit inside the app
/// data dir. `Path::starts_with` compares whole components, so a sibling
/// directory sharing a name prefix cannot slip through.
/// Residual risk: a symlink planted INSIDE the app data dir could still point
/// out. That needs local filesystem access to arrange, which is already past the
/// boundary this guard defends.
fn resolve_in_app_dir(app: &tauri::AppHandle, path: &str) -> Result<std::path::PathBuf, String> {
    let base = app
        .path()
        .app_data_dir()
        .map_err(|e| format!("Cannot resolve the app data directory: {e}"))?;
    let p = std::path::PathBuf::from(path);
    if p.components().any(|c| matches!(c, std::path::Component::ParentDir)) {
        return Err("Refused: path traversal ('..') is not allowed.".into());
    }
    if !p.starts_with(&base) {
        return Err("Refused: path is outside the app data directory.".into());
    }
    Ok(p)
}

/// Read a UTF-8 file from disk. Used for profile and session JSON.
#[tauri::command]
fn read_file(app: tauri::AppHandle, path: String) -> Result<String, String> {
    let p = resolve_in_app_dir(&app, &path)?;
    std::fs::read_to_string(&p).map_err(|e| e.to_string())
}

/// Write a UTF-8 string to disk (creates parent dirs if needed).
#[tauri::command]
fn write_file(app: tauri::AppHandle, path: String, content: String) -> Result<(), String> {
    let p = resolve_in_app_dir(&app, &path)?;
    if let Some(parent) = p.parent() {
        std::fs::create_dir_all(parent).map_err(|e| e.to_string())?;
    }
    std::fs::write(&p, content).map_err(|e| e.to_string())
}

/// List JSON files in a directory (for profile/session pickers).
#[tauri::command]
fn list_dir(app: tauri::AppHandle, path: String) -> Result<Vec<String>, String> {
    let dir = resolve_in_app_dir(&app, &path)?;
    if !dir.exists() {
        return Ok(vec![]);
    }
    let entries = std::fs::read_dir(&dir).map_err(|e| e.to_string())?;
    let mut files = vec![];
    for entry in entries.flatten() {
        let p = entry.path();
        if p.extension().map(|e| e == "json").unwrap_or(false) {
            if let Some(s) = p.to_str() {
                files.push(s.to_string());
            }
        }
    }
    files.sort();
    Ok(files)
}
