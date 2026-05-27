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

/// Read a UTF-8 file from disk. Used for profile and session JSON.
#[tauri::command]
fn read_file(path: String) -> Result<String, String> {
    std::fs::read_to_string(&path).map_err(|e| e.to_string())
}

/// Write a UTF-8 string to disk (creates parent dirs if needed).
#[tauri::command]
fn write_file(path: String, content: String) -> Result<(), String> {
    if let Some(parent) = std::path::Path::new(&path).parent() {
        std::fs::create_dir_all(parent).map_err(|e| e.to_string())?;
    }
    std::fs::write(&path, content).map_err(|e| e.to_string())
}

/// List JSON files in a directory (for profile/session pickers).
#[tauri::command]
fn list_dir(path: String) -> Result<Vec<String>, String> {
    let dir = std::path::Path::new(&path);
    if !dir.exists() {
        return Ok(vec![]);
    }
    let entries = std::fs::read_dir(dir).map_err(|e| e.to_string())?;
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
