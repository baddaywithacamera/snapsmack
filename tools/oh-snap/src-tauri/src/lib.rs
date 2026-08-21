// Oh Snap! — Tauri backend
//
// The Rust layer is intentionally thin. All application logic lives in the
// JS frontend. Rust handles: file system access (project load/save, skin
// export), native file dialogs, and opening URLs in the system browser.
//
// HTTP calls to the SnapSmack API are made directly from JS via fetch().
// The SnapSmack ohsnap-api.php handler emits CORS headers for tauri://
// origins so no Rust proxy is needed.

#[cfg(debug_assertions)]
use tauri::Manager;
use std::fs;
use std::io::Write;
use std::path::Path;
use serde::Deserialize;
use zip::write::SimpleFileOptions;

#[cfg(windows)]
fn replace_file(from: &Path, to: &Path) -> Result<(), String> {
    use std::os::windows::ffi::OsStrExt;
    use windows_sys::Win32::Storage::FileSystem::{MoveFileExW, MOVEFILE_REPLACE_EXISTING, MOVEFILE_WRITE_THROUGH};
    let from_w: Vec<u16> = from.as_os_str().encode_wide().chain(Some(0)).collect();
    let to_w: Vec<u16> = to.as_os_str().encode_wide().chain(Some(0)).collect();
    let ok = unsafe { MoveFileExW(from_w.as_ptr(), to_w.as_ptr(), MOVEFILE_REPLACE_EXISTING | MOVEFILE_WRITE_THROUGH) };
    if ok == 0 { Err(std::io::Error::last_os_error().to_string()) } else { Ok(()) }
}

#[cfg(not(windows))]
fn replace_file(from: &Path, to: &Path) -> Result<(), String> {
    fs::rename(from, to).map_err(|e| e.to_string())
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_fs::init())
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_shell::init())
        .setup(|_app| {
            #[cfg(debug_assertions)]
            {
                // Open DevTools automatically in dev mode.
                let window = _app.get_webview_window("main").unwrap();
                window.open_devtools();
            }
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            save_project,
            load_project,
            export_shareable_package,
            vault_set,
            vault_get,
            vault_delete,
        ])
        .run(tauri::generate_context!())
        .expect("error while running Oh Snap!");
}

/// Save a project JSON string to disk.
/// Path is chosen by the frontend (already resolved via the dialog plugin).
#[tauri::command]
fn save_project(path: String, content: String) -> Result<(), String> {
    let target = Path::new(&path);
    let parent = target.parent().ok_or_else(|| "Project path has no parent directory".to_string())?;
    let temp = parent.join(format!(".{}.tmp", target.file_name().and_then(|v| v.to_str()).unwrap_or("ohsnap")));
    let backup = parent.join(format!("{}.bak", target.file_name().and_then(|v| v.to_str()).unwrap_or("ohsnap")));
    let mut file = fs::File::create(&temp).map_err(|e| e.to_string())?;
    file.write_all(content.as_bytes()).map_err(|e| e.to_string())?;
    file.sync_all().map_err(|e| e.to_string())?;
    if target.exists() {
        let _ = fs::copy(target, &backup);
    }
    replace_file(&temp, target)
}

/// Load a project JSON string from disk.
#[tauri::command]
fn load_project(path: String) -> Result<String, String> {
    match fs::read_to_string(&path) {
        Ok(content) => Ok(content),
        Err(primary) => {
            let target = Path::new(&path);
            let parent = target.parent().ok_or_else(|| primary.to_string())?;
            let backup = parent.join(format!("{}.bak", target.file_name().and_then(|v| v.to_str()).unwrap_or("ohsnap")));
            fs::read_to_string(backup).map_err(|_| primary.to_string())
        }
    }
}

#[derive(Deserialize)]
struct PackageFile { path: String, content: String }

#[tauri::command]
fn export_shareable_package(path: String, files: Vec<PackageFile>) -> Result<(), String> {
    use std::io::Write as _;
    let target = Path::new(&path);
    let parent = target.parent().ok_or_else(|| "Package path has no parent directory".to_string())?;
    let temp = parent.join(format!(".{}.tmp", target.file_name().and_then(|v| v.to_str()).unwrap_or("ohsnap.zip")));
    let output = fs::File::create(&temp).map_err(|e| e.to_string())?;
    let mut archive = zip::ZipWriter::new(output);
    let options = SimpleFileOptions::default().compression_method(zip::CompressionMethod::Deflated).unix_permissions(0o644);
    let mut ordered = files;
    ordered.sort_by(|a, b| a.path.cmp(&b.path));
    for item in ordered {
        let normalized = item.path.replace('\\', "/");
        if normalized.starts_with('/') || normalized.contains("../") || normalized.contains(':') || normalized.is_empty() {
            return Err(format!("Unsafe package path: {}", item.path));
        }
        let ext = Path::new(&normalized).extension().and_then(|v| v.to_str()).unwrap_or("").to_ascii_lowercase();
        if matches!(ext.as_str(), "php"|"phtml"|"phar"|"exe"|"dll"|"bat"|"cmd"|"ps1"|"sh") || normalized.eq_ignore_ascii_case(".htaccess") {
            return Err(format!("Forbidden SHAREABLE file: {}", normalized));
        }
        archive.start_file(normalized, options).map_err(|e| e.to_string())?;
        archive.write_all(item.content.as_bytes()).map_err(|e| e.to_string())?;
    }
    let output = archive.finish().map_err(|e| e.to_string())?;
    output.sync_all().map_err(|e| e.to_string())?;
    replace_file(&temp, target)
}

const VAULT_SERVICE: &str = "ca.snapsmack.ohsnap";

#[tauri::command]
fn vault_set(account: String, secret: String) -> Result<(), String> {
    keyring::Entry::new(VAULT_SERVICE, &account).map_err(|e| e.to_string())?.set_password(&secret).map_err(|e| e.to_string())
}

#[tauri::command]
fn vault_get(account: String) -> Result<Option<String>, String> {
    let entry = keyring::Entry::new(VAULT_SERVICE, &account).map_err(|e| e.to_string())?;
    match entry.get_password() {
        Ok(value) => Ok(Some(value)),
        Err(keyring::Error::NoEntry) => Ok(None),
        Err(e) => Err(e.to_string()),
    }
}

#[tauri::command]
fn vault_delete(account: String) -> Result<(), String> {
    let entry = keyring::Entry::new(VAULT_SERVICE, &account).map_err(|e| e.to_string())?;
    match entry.delete_credential() { Ok(()) | Err(keyring::Error::NoEntry) => Ok(()), Err(e) => Err(e.to_string()) }
}
