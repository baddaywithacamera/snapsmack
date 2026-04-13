// Oh Snap! — Tauri backend
//
// The Rust layer is intentionally thin. All application logic lives in the
// JS frontend. Rust handles: file system access (project load/save, skin
// export), native file dialogs, and opening URLs in the system browser.
//
// HTTP calls to the SnapSmack API are made directly from JS via fetch().
// The SnapSmack ohsnap-api.php handler emits CORS headers for tauri://
// origins so no Rust proxy is needed.

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
                // Open DevTools automatically in dev mode.
                let window = app.get_webview_window("main").unwrap();
                window.open_devtools();
            }
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            save_project,
            load_project,
        ])
        .run(tauri::generate_context!())
        .expect("error while running Oh Snap!");
}

/// Save a project JSON string to disk.
/// Path is chosen by the frontend (already resolved via the dialog plugin).
#[tauri::command]
fn save_project(path: String, content: String) -> Result<(), String> {
    std::fs::write(&path, content).map_err(|e| e.to_string())
}

/// Load a project JSON string from disk.
#[tauri::command]
fn load_project(path: String) -> Result<String, String> {
    std::fs::read_to_string(&path).map_err(|e| e.to_string())
}
