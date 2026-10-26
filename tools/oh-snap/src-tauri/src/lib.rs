// Oh Snap! — Tauri backend
//
// The Rust layer is intentionally thin. All application logic lives in the
// JS frontend. Rust handles: file system access (project load/save, skin
// export), native file dialogs, and opening URLs in the system browser.
//
// HTTP calls to the SnapSmack API are made directly from JS via fetch().
// The SnapSmack ohsnap-api.php handler emits CORS headers for tauri://
// origins so no Rust proxy is needed.

// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
use tauri::Manager;
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons};
use std::fs;
use std::io::Write;
use std::path::Path;
use serde::Deserialize;
use serde::Serialize;
use zip::write::SimpleFileOptions;
mod shared_credentials;

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
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
            if let Some(window) = app.get_webview_window("main") {
                let _ = window.unminimize();
                let _ = window.show();
                let _ = window.set_focus();
            }
        }))
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
            shared_profiles,
            shared_site_credential,
            confirm_action,
        ])
        .run(tauri::generate_context!())
        .expect("error while running Oh Snap!");
}

fn shared_root() -> std::path::PathBuf {
    std::env::var("SNAPSMACK_HOME").ok().filter(|v| !v.trim().is_empty())
        .map(std::path::PathBuf::from).unwrap_or_else(|| std::path::PathBuf::from(r"C:\snapsmack"))
}

#[derive(Serialize)]
struct SharedProfile { name: String, url: String }

#[tauri::command]
fn shared_profiles() -> Result<Vec<SharedProfile>, String> {
    let dir = shared_root().join("shared_library").join("profiles");
    if !dir.exists() { return Ok(vec![]); }
    let mut rows = Vec::new();
    for item in fs::read_dir(dir).map_err(|e| e.to_string())?.flatten() {
        if item.path().extension().and_then(|v| v.to_str()) != Some("json") { continue; }
        let value: serde_json::Value = match serde_json::from_slice(&fs::read(item.path()).unwrap_or_default()) { Ok(v) => v, Err(_) => continue };
        let url = value.get("site_url").and_then(|v| v.as_str()).unwrap_or("").to_string();
        if url.is_empty() { continue; }
        rows.push(SharedProfile { name: value.get("name").and_then(|v| v.as_str()).unwrap_or(&url).to_string(), url });
    }
    rows.sort_by(|a, b| a.name.to_lowercase().cmp(&b.name.to_lowercase())); Ok(rows)
}

#[tauri::command]
fn shared_site_credential(site_url: String) -> Result<String, String> {
    shared_credentials::read(&shared_root(), &site_url, "ohsnap")
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
async fn confirm_action(app: tauri::AppHandle, message: String) -> Result<bool, String> {
    tauri::async_runtime::spawn_blocking(move || app.dialog().message(message).title("OH SNAP").buttons(MessageDialogButtons::OkCancel).blocking_show())
        .await.map_err(|e| e.to_string())
}

fn validate_package_files(files: &[PackageFile]) -> Result<(), String> {
    let allowed = ["manifest.json", "layout.php", "style.css", "README.md", "validation-report.json"];
    if files.len() != allowed.len() { return Err("Package must contain exactly the five reviewed starter files.".into()); }
    let mut seen = std::collections::HashSet::new();
    let mut total = 0;
    for item in files {
        if !allowed.contains(&item.path.as_str()) || !seen.insert(item.path.as_str()) { return Err("Unexpected or duplicate package file.".into()); }
        if item.content.len() > 5_000_000 { return Err("Package file exceeds 5 MB.".into()); }
        total += item.content.len();
    }
    if total > 25_000_000 { return Err("Package exceeds 25 MB.".into()); }
    let template = &files.iter().find(|f| f.path == "layout.php").unwrap().content;
    if template != include_str!("../../kit/layout.php") { return Err("The presentation template differs from the reviewed starter.".into()); }
    let manifest: serde_json::Value = serde_json::from_str(&files.iter().find(|f| f.path == "manifest.json").unwrap().content).map_err(|e| e.to_string())?;
    if manifest["schema_version"] != 2 || manifest["security_policy"] != 2 || manifest["cms_controller"] != "public" || manifest["view_model"] != "snapsmack.public.v1" { return Err("Invalid schema-v2 skin contract.".into()); }
    let templates = manifest["templates"].as_object().ok_or("Missing skin templates.")?;
    if templates.get("default").and_then(|v| v.as_str()) != Some("layout.php") || templates.values().any(|v| v.as_str() != Some("layout.php")) { return Err("Invalid presentation template declaration.".into()); }
    for field in ["require_scripts", "require_styles"] {
        if !manifest[field].as_array().is_some_and(|a| a.is_empty()) { return Err("This starter does not support additional engine dependencies.".into()); }
    }
    for item in files.iter().filter(|f| f.path == "style.css") {
        let clean: String = item.content.to_ascii_lowercase().chars().filter(|c| !c.is_whitespace()).collect();
        if ["<", ">", "\\", "@import", "url(", "javascript:", "expression(", "-moz-binding"].iter().any(|needle| clean.contains(needle)) { return Err("Unsupported CSS content in package.".into()); }
    }
    Ok(())
}

#[tauri::command]
fn export_shareable_package(path: String, files: Vec<PackageFile>) -> Result<(), String> {
    use std::io::Write as _;
    validate_package_files(&files)?;
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
        if matches!(ext.as_str(), "phtml"|"phar"|"exe"|"dll"|"bat"|"cmd"|"ps1"|"sh") || normalized.eq_ignore_ascii_case(".htaccess") {
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
        Err(keyring::Error::NoEntry) => {
            let shared = account.strip_prefix("ai:").and_then(|key| key.strip_suffix("_key"))
                .and_then(|provider| shared_credentials::read_ai(&shared_root(), provider).ok());
            Ok(shared)
        },
        Err(e) => Err(e.to_string()),
    }
}

#[tauri::command]
fn vault_delete(account: String) -> Result<(), String> {
    let entry = keyring::Entry::new(VAULT_SERVICE, &account).map_err(|e| e.to_string())?;
    match entry.delete_credential() { Ok(()) | Err(keyring::Error::NoEntry) => Ok(()), Err(e) => Err(e.to_string()) }
}

#[cfg(test)]
mod tests {
    use super::*;
    fn files() -> Vec<PackageFile> {
        let manifest=serde_json::json!({"schema_version":2,"security_policy":2,"cms_controller":"public","view_model":"snapsmack.public.v1","templates":{"default":"layout.php"},"require_scripts":[],"require_styles":[]});
        vec![PackageFile{path:"manifest.json".into(),content:manifest.to_string()},PackageFile{path:"layout.php".into(),content:include_str!("../../kit/layout.php").into()},PackageFile{path:"style.css".into(),content:"body {color: #123456;}".into()},PackageFile{path:"README.md".into(),content:"Reviewed skin".into()},PackageFile{path:"validation-report.json".into(),content:"{}".into()}]
    }
    #[test]
    fn template_and_manifest_cannot_be_replaced() {
        assert!(validate_package_files(&files()).is_ok());
        let mut changed=files();changed[1].content.push_str("<?php system('bad');");assert!(validate_package_files(&changed).is_err());
        let mut changed=files();changed[0].content="{\"schema_version\":1}".into();assert!(validate_package_files(&changed).is_err());
        let mut changed=files();changed[2].path="helper.php".into();assert!(validate_package_files(&changed).is_err());
        let mut changed=files();changed[2].content="body{background:url (//bad.invalid/x)}".into();assert!(validate_package_files(&changed).is_err());
    }
    #[test]
    fn actual_frontend_packages_export_to_readable_zips() {
        let Ok(input)=std::env::var("SNAP_OHSNAP_EXPORT_FIXTURE") else { return; };
        let fixtures: serde_json::Value=serde_json::from_slice(&fs::read(input).unwrap()).unwrap();
        for fixture in fixtures.as_array().unwrap() {
            let files:Vec<PackageFile>=serde_json::from_value(fixture["files"].clone()).unwrap();
            let output=Path::new(fixture["dir"].as_str().unwrap()).join("native-export.zip");
            export_shareable_package(output.to_string_lossy().into_owned(),files).unwrap();
            let mut zip=zip::ZipArchive::new(fs::File::open(output).unwrap()).unwrap();
            assert_eq!(zip.len(),5);
            use std::io::Read;
            let mut template=String::new();zip.by_name("layout.php").unwrap().read_to_string(&mut template).unwrap();
            assert_eq!(template,include_str!("../../kit/layout.php"));
        }
    }
}
// ===== SNAPSMACK EOF =====
