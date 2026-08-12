// GET YOUR SHIT SORTED — Tauri backend
//
// The Rust layer is intentionally thin. All application logic lives in the
// JS frontend. Rust handles: file system access (profile/session/library
// load/save), the shared SQLite catalog, and native file dialogs.
//
// SHARED FOUNDATION. GYSS now lives on the same on-disk contract as the Python
// tools (COLD SNAP, SYBU): one root — env SNAPSMACK_HOME, else C:\snapsmack —
// under which shared_library/<site_key>/ holds the per-site store (index/meta/
// thumbs + db/catalog.sqlite) and config_files/gyss/ holds GYSS's own profiles
// and sessions. See tools/_shared/snap_home.py for the authoritative layout.
//
// HTTP calls to the SnapSmack gyss-api.php handler are made directly from JS
// via fetch(). The API emits CORS headers for tauri:// origins so no Rust
// proxy is needed.

use std::path::{Path, PathBuf};
use tauri::Manager;

// snap_library.py's _SCHEMA, VERBATIM — the shared catalog both the Python tools
// and this Rust tool open. Keep byte-for-byte in step with snap_library.py.
const CATALOG_SCHEMA: &str = "
CREATE TABLE IF NOT EXISTS meta       (key TEXT PRIMARY KEY, val TEXT);
CREATE TABLE IF NOT EXISTS categories (name TEXT PRIMARY KEY, description TEXT DEFAULT '');
CREATE TABLE IF NOT EXISTS albums     (name TEXT PRIMARY KEY, description TEXT DEFAULT '');
CREATE TABLE IF NOT EXISTS tags       (tag  TEXT PRIMARY KEY);
CREATE TABLE IF NOT EXISTS titles     (title TEXT PRIMARY KEY);
";

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_fs::init())
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_shell::init())
        .setup(|app| {
            // First-run migration off the old %APPDATA%\GetYourShitSorted store into
            // the shared root. Best-effort: never fatal, never clobbers new data.
            let handle = app.handle().clone();
            migrate_legacy(&handle);

            #[cfg(debug_assertions)]
            {
                let window = app.get_webview_window("main").unwrap();
                window.open_devtools();
            }
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            shared_home,
            read_file,
            write_file,
            list_dir,
            download_to,
            delete_file,
            catalog_sync,
            catalog_read,
        ])
        .run(tauri::generate_context!())
        .expect("error while running GET YOUR SHIT SORTED");
}

/// The SnapSmack root. SNAPSMACK_HOME overrides the C:\snapsmack default (matches
/// snap_home.home()). This is the base of the file-command jail below.
fn shared_root() -> PathBuf {
    let root = std::env::var("SNAPSMACK_HOME")
        .ok()
        .map(|s| s.trim().to_string())
        .filter(|s| !s.is_empty())
        .unwrap_or_else(|| r"C:\snapsmack".to_string());
    PathBuf::from(root)
}

/// SECURITY (SECAUDIT 039): confine every file command to the shared SnapSmack
/// root.
///
/// These commands are registered on `invoke_handler`, and app-defined commands
/// are NOT gated by the capability system — so anything running in the webview
/// can call them. Before the SECAUDIT-039 guard they took an arbitrary absolute
/// path, which made `write_file` an arbitrary-file-write primitive (it even
/// created parent dirs): any script injected into the webview could drop a
/// payload into, say, the user's Startup folder. The webview renders data fetched
/// from a user-supplied site URL, so that is a reachable path, not a theoretical
/// one.
///
/// The root widened from app_data_dir() to the shared SnapSmack root when GYSS
/// joined the shared foundation, but the guard is unchanged: reject any `..`
/// component, then require the path to sit inside the root. `Path::starts_with`
/// compares whole components, so a sibling directory sharing a name prefix cannot
/// slip through.
/// Residual risk: a symlink planted INSIDE the root could still point out. That
/// needs local filesystem access to arrange, which is already past the boundary
/// this guard defends.
fn resolve_in_root(path: &str) -> Result<PathBuf, String> {
    let base = shared_root();
    let p = PathBuf::from(path);
    if p.components().any(|c| matches!(c, std::path::Component::ParentDir)) {
        return Err("Refused: path traversal ('..') is not allowed.".into());
    }
    if !p.starts_with(&base) {
        return Err("Refused: path is outside the SnapSmack root.".into());
    }
    Ok(p)
}

/// Return the resolved shared root as a string so JS can build paths under it
/// (replaces the old appDataDir() base). Matches snap_home.home().
#[tauri::command]
fn shared_home() -> String {
    shared_root().to_string_lossy().to_string()
}

/// Read a UTF-8 file from disk. Used for profile and session JSON.
#[tauri::command]
fn read_file(path: String) -> Result<String, String> {
    let p = resolve_in_root(&path)?;
    std::fs::read_to_string(&p).map_err(|e| e.to_string())
}

/// Write a UTF-8 string to disk (creates parent dirs if needed).
#[tauri::command]
fn write_file(path: String, content: String) -> Result<(), String> {
    let p = resolve_in_root(&path)?;
    if let Some(parent) = p.parent() {
        std::fs::create_dir_all(parent).map_err(|e| e.to_string())?;
    }
    std::fs::write(&p, content).map_err(|e| e.to_string())
}

/// List JSON files in a directory (for profile/session pickers).
#[tauri::command]
fn list_dir(path: String) -> Result<Vec<String>, String> {
    let dir = resolve_in_root(&path)?;
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

/// Download a URL's bytes and write them to a jailed path inside the shared root.
/// `write_file` is UTF-8-only; this is the binary-safe primitive the offline
/// library needs to store thumbnail files.
///
/// SECURITY: the destination goes through `resolve_in_root` (same jail as the
/// other fs commands), and only http/https URLs are accepted (no file://, data://
/// etc.). Native fetch is deliberate — it is NOT bound by the webview's CORS, so
/// it can pull static /uploads/ thumbnails that a JS fetch() would be blocked on.
/// A response is capped to guard against a hostile/oversized body.
#[tauri::command]
async fn download_to(url: String, path: String) -> Result<(), String> {
    const MAX_BYTES: usize = 64 * 1024 * 1024; // 64 MiB — a thumb is tens of KB; this is a sanity bound.

    let lower = url.to_ascii_lowercase();
    if !(lower.starts_with("http://") || lower.starts_with("https://")) {
        return Err("Refused: only http(s) URLs may be downloaded.".into());
    }
    // Resolve + jail the destination BEFORE spending a network round-trip.
    let dest = resolve_in_root(&path)?;

    let resp = reqwest::get(&url).await.map_err(|e| e.to_string())?;
    if !resp.status().is_success() {
        return Err(format!("Download failed: HTTP {}", resp.status()));
    }
    if let Some(len) = resp.content_length() {
        if len as usize > MAX_BYTES {
            return Err(format!("Refused: response too large ({len} bytes)."));
        }
    }
    let bytes = resp.bytes().await.map_err(|e| e.to_string())?;
    if bytes.len() > MAX_BYTES {
        return Err(format!("Refused: response too large ({} bytes).", bytes.len()));
    }

    if let Some(parent) = dest.parent() {
        std::fs::create_dir_all(parent).map_err(|e| e.to_string())?;
    }
    std::fs::write(&dest, &bytes[..]).map_err(|e| e.to_string())
}

/// Delete a file inside the jailed shared root — e.g. an orphaned thumbnail whose
/// image was pruned from the library. A missing file is a no-op, not an error
/// (idempotent prune).
#[tauri::command]
fn delete_file(path: String) -> Result<(), String> {
    let p = resolve_in_root(&path)?;
    match std::fs::remove_file(&p) {
        Ok(()) => Ok(()),
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(e) => Err(e.to_string()),
    }
}

// ── Shared SQLite catalog ────────────────────────────────────────────────────
// The same …/shared_library/<site_key>/db/catalog.sqlite that COLD SNAP / SYBU
// read via snap_library.py. `catalog_sync` mirrors GYSS's freshly-pulled vocab
// (categories/albums/tags/titles/site_mode) into it; `catalog_read` reads it
// back. Both go through the same jail.

/// Pull [{name, description?}, …] rows out of a payload array, dropping empties.
/// Mirrors snap_library.sync_from_sybu_data's `if c.get("name")` filter.
fn named_rows(payload: &serde_json::Value, key: &str) -> Vec<(String, String)> {
    let mut out = vec![];
    if let Some(arr) = payload.get(key).and_then(|v| v.as_array()) {
        for it in arr {
            let name = it.get("name").and_then(|v| v.as_str()).unwrap_or_default().to_string();
            if name.is_empty() {
                continue;
            }
            let desc = it
                .get("description")
                .and_then(|v| v.as_str())
                .unwrap_or_default()
                .to_string();
            out.push((name, desc));
        }
    }
    out
}

/// Pull scalar string rows out of a payload array, dropping blanks (matches the
/// `if str(t).strip()` filter for tags/titles).
fn scalar_rows(payload: &serde_json::Value, key: &str) -> Vec<String> {
    let mut out = vec![];
    if let Some(arr) = payload.get(key).and_then(|v| v.as_array()) {
        for it in arr {
            let s = it.as_str().unwrap_or_default().trim().to_string();
            if !s.is_empty() {
                out.push(s);
            }
        }
    }
    out
}

/// Wholesale-replace this site's cached catalog inside ONE transaction — exactly
/// like snap_library.sync_from_sybu_data. `path` is the absolute catalog.sqlite
/// path (jailed). `payload` carries site_url / site_mode / categories / albums /
/// tags / titles.
#[tauri::command]
fn catalog_sync(path: String, payload: serde_json::Value) -> Result<serde_json::Value, String> {
    let p = resolve_in_root(&path)?;
    if let Some(parent) = p.parent() {
        std::fs::create_dir_all(parent).map_err(|e| e.to_string())?;
    }
    let mut conn = rusqlite::Connection::open(&p).map_err(|e| e.to_string())?;
    conn.execute_batch(CATALOG_SCHEMA).map_err(|e| e.to_string())?;

    let site_url = payload.get("site_url").and_then(|v| v.as_str()).unwrap_or_default().to_string();
    let site_mode = payload
        .get("site_mode")
        .and_then(|v| v.as_str())
        .unwrap_or_default()
        .trim()
        .to_lowercase();
    let cats = named_rows(&payload, "categories");
    let albums = named_rows(&payload, "albums");
    let tags = scalar_rows(&payload, "tags");
    let titles = scalar_rows(&payload, "titles");

    let tx = conn.transaction().map_err(|e| e.to_string())?;
    {
        tx.execute("DELETE FROM categories", []).map_err(|e| e.to_string())?;
        tx.execute("DELETE FROM albums", []).map_err(|e| e.to_string())?;
        tx.execute("DELETE FROM tags", []).map_err(|e| e.to_string())?;
        tx.execute("DELETE FROM titles", []).map_err(|e| e.to_string())?;
        {
            let mut st = tx
                .prepare("INSERT OR REPLACE INTO categories(name, description) VALUES (?1, ?2)")
                .map_err(|e| e.to_string())?;
            for (n, d) in &cats {
                st.execute(rusqlite::params![n, d]).map_err(|e| e.to_string())?;
            }
        }
        {
            let mut st = tx
                .prepare("INSERT OR REPLACE INTO albums(name, description) VALUES (?1, ?2)")
                .map_err(|e| e.to_string())?;
            for (n, d) in &albums {
                st.execute(rusqlite::params![n, d]).map_err(|e| e.to_string())?;
            }
        }
        {
            let mut st = tx
                .prepare("INSERT OR REPLACE INTO tags(tag) VALUES (?1)")
                .map_err(|e| e.to_string())?;
            for t in &tags {
                st.execute(rusqlite::params![t]).map_err(|e| e.to_string())?;
            }
        }
        {
            let mut st = tx
                .prepare("INSERT OR REPLACE INTO titles(title) VALUES (?1)")
                .map_err(|e| e.to_string())?;
            for t in &titles {
                st.execute(rusqlite::params![t]).map_err(|e| e.to_string())?;
            }
        }
        tx.execute(
            "INSERT OR REPLACE INTO meta(key, val) VALUES ('site_url', ?1)",
            rusqlite::params![site_url],
        )
        .map_err(|e| e.to_string())?;
        if !site_mode.is_empty() {
            tx.execute(
                "INSERT OR REPLACE INTO meta(key, val) VALUES ('site_mode', ?1)",
                rusqlite::params![site_mode],
            )
            .map_err(|e| e.to_string())?;
        }
        // Local-time "%Y-%m-%d %H:%M:%S" — matches snap_library's time.strftime.
        tx.execute(
            "INSERT OR REPLACE INTO meta(key, val) VALUES ('synced_at', strftime('%Y-%m-%d %H:%M:%S','now','localtime'))",
            [],
        )
        .map_err(|e| e.to_string())?;
    }
    tx.commit().map_err(|e| e.to_string())?;

    Ok(serde_json::json!({
        "categories": cats.len(),
        "albums": albums.len(),
        "tags": tags.len(),
        "titles": titles.len(),
        "site_mode": site_mode,
    }))
}

fn read_col(conn: &rusqlite::Connection, sql: &str) -> Result<Vec<String>, String> {
    let mut st = conn.prepare(sql).map_err(|e| e.to_string())?;
    let rows = st
        .query_map([], |r| r.get::<_, String>(0))
        .map_err(|e| e.to_string())?;
    let mut out = vec![];
    for r in rows {
        out.push(r.map_err(|e| e.to_string())?);
    }
    Ok(out)
}

fn read_meta(conn: &rusqlite::Connection, key: &str) -> Result<String, String> {
    match conn.query_row(
        "SELECT val FROM meta WHERE key = ?1",
        rusqlite::params![key],
        |r| r.get::<_, String>(0),
    ) {
        Ok(v) => Ok(v),
        Err(rusqlite::Error::QueryReturnedNoRows) => Ok(String::new()),
        Err(e) => Err(e.to_string()),
    }
}

/// Read the shared catalog for a site: {categories, albums, tags, titles,
/// site_mode, synced_at}. Empty lists when no catalog exists yet.
#[tauri::command]
fn catalog_read(path: String) -> Result<serde_json::Value, String> {
    let p = resolve_in_root(&path)?;
    if !p.is_file() {
        return Ok(serde_json::json!({
            "categories": [], "albums": [], "tags": [], "titles": [],
            "site_mode": "", "synced_at": ""
        }));
    }
    let conn = rusqlite::Connection::open(&p).map_err(|e| e.to_string())?;
    conn.execute_batch(CATALOG_SCHEMA).map_err(|e| e.to_string())?;
    let categories = read_col(&conn, "SELECT name FROM categories ORDER BY name COLLATE NOCASE")?;
    let albums = read_col(&conn, "SELECT name FROM albums ORDER BY name COLLATE NOCASE")?;
    let tags = read_col(&conn, "SELECT tag FROM tags ORDER BY tag COLLATE NOCASE")?;
    let titles = read_col(&conn, "SELECT title FROM titles ORDER BY title COLLATE NOCASE")?;
    let site_mode = read_meta(&conn, "site_mode")?;
    let synced_at = read_meta(&conn, "synced_at")?;
    Ok(serde_json::json!({
        "categories": categories,
        "albums": albums,
        "tags": tags,
        "titles": titles,
        "site_mode": site_mode,
        "synced_at": synced_at,
    }))
}

// ── First-run migration (off %APPDATA%\GetYourShitSorted) ─────────────────────

/// snap_home.site_key() for an already-hostname-ish folder name: lowercase;
/// [^a-z0-9.-] -> '-'; strip leading/trailing '.-'; collapse repeated '-'.
fn site_key_from_host(name: &str) -> Option<String> {
    let sanitized: String = name
        .trim()
        .to_lowercase()
        .chars()
        .map(|c| {
            if c.is_ascii_lowercase() || c.is_ascii_digit() || c == '.' || c == '-' {
                c
            } else {
                '-'
            }
        })
        .collect();
    let stripped = sanitized.trim_matches(|c| c == '.' || c == '-');
    let mut out = String::new();
    let mut prev_dash = false;
    for c in stripped.chars() {
        if c == '-' {
            if !prev_dash {
                out.push(c);
            }
            prev_dash = true;
        } else {
            out.push(c);
            prev_dash = false;
        }
    }
    if out.is_empty() {
        None
    } else {
        Some(out)
    }
}

fn dir_has_entries(p: &Path) -> bool {
    std::fs::read_dir(p).map(|mut it| it.next().is_some()).unwrap_or(false)
}

fn copy_dir_all(src: &Path, dst: &Path) -> std::io::Result<()> {
    std::fs::create_dir_all(dst)?;
    for entry in std::fs::read_dir(src)? {
        let entry = entry?;
        let ft = entry.file_type()?;
        let from = entry.path();
        let to = dst.join(entry.file_name());
        if ft.is_dir() {
            copy_dir_all(&from, &to)?;
        } else if ft.is_file() {
            std::fs::copy(&from, &to)?;
        }
    }
    Ok(())
}

/// Copy old -> new only when new is absent/empty (never clobber). Best-effort.
fn adopt_tree(old: &Path, new: &Path) {
    if !old.is_dir() || !dir_has_entries(old) {
        return;
    }
    if new.is_dir() && dir_has_entries(new) {
        return;
    }
    let _ = copy_dir_all(old, new);
}

/// Adopt the legacy %APPDATA%\GetYourShitSorted store into the shared root on
/// first run. Copies (does not delete) so a downgrade still finds the originals.
/// Never fatal.
fn migrate_legacy(app: &tauri::AppHandle) {
    let old_base = match app.path().app_data_dir() {
        Ok(d) => d.join("GetYourShitSorted"),
        Err(_) => return,
    };
    if !old_base.is_dir() {
        return;
    }
    let root = shared_root();

    // library/<host> -> shared_library/<site_key> (per folder; no clobber).
    let old_lib = old_base.join("library");
    if old_lib.is_dir() {
        let new_lib = root.join("shared_library");
        if let Ok(entries) = std::fs::read_dir(&old_lib) {
            for e in entries.flatten() {
                if !e.file_type().map(|t| t.is_dir()).unwrap_or(false) {
                    continue;
                }
                let name = e.file_name().to_string_lossy().to_string();
                let key = match site_key_from_host(&name) {
                    Some(k) => k,
                    None => continue,
                };
                let dst = new_lib.join(&key);
                if dst.is_dir() && dir_has_entries(&dst) {
                    continue; // already populated — don't clobber
                }
                let _ = copy_dir_all(&e.path(), &dst);
            }
        }
    }

    // profiles + sessions -> config_files/gyss/{profiles,sessions}.
    let cfg = root.join("config_files").join("gyss");
    adopt_tree(&old_base.join("profiles"), &cfg.join("profiles"));
    adopt_tree(&old_base.join("sessions"), &cfg.join("sessions"));
}
