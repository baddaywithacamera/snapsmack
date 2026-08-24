// PIXEL TICKLER Windows host.
// SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical Rust marker.

use base64::Engine;
use image::ImageFormat;
use serde_json::{json, Value};
use snapsmack_photo_core::processing::{self, Operation, DEFAULT_MEMORY_BUDGET};
use std::io::Cursor;
use std::path::{Path, PathBuf};
use std::sync::Mutex;
use tauri::State;
use tauri_plugin_dialog::DialogExt;

#[derive(Default)]
struct EditorState {
    input: Mutex<Option<PathBuf>>,
    project: Mutex<Option<snapsmack_photo_core::project::ProjectDocument>>,
    render_generation: Mutex<u64>,
}

fn shared_root() -> PathBuf {
    std::env::var("SNAPSMACK_HOME")
        .ok()
        .map(|value| value.trim().to_owned())
        .filter(|value| !value.is_empty())
        .map(PathBuf::from)
        .unwrap_or_else(|| PathBuf::from(r"C:\snapsmack"))
}

fn autosave_path() -> PathBuf {
    shared_root()
        .join("config_files")
        .join("pixel-tickler")
        .join("autosave.json")
}

fn autosave(input: &Path, operations: &[Operation]) -> Result<(), String> {
    let path = autosave_path();
    std::fs::create_dir_all(path.parent().expect("autosave has parent"))
        .map_err(|error| error.to_string())?;
    processing::replace_json_atomic(
        &json!({"contract_version": 1, "input_path": input, "operations": operations}),
        &path,
    )
    .map_err(|error| error.to_string())
}

fn allowed_input(path: &Path) -> Result<(), String> {
    if snapsmack_photo_core::file_is_heic(path) {
        return Err("HEIC/HEIF is not supported because of HEVC patent risk.".into());
    }
    let extension = path
        .extension()
        .and_then(|v| v.to_str())
        .unwrap_or_default();
    if !matches!(
        extension.to_ascii_lowercase().as_str(),
        "jpg" | "jpeg" | "png" | "webp" | "tif" | "tiff"
    ) {
        return Err("Choose a JPEG, PNG, WebP, or TIFF image.".into());
    }
    Ok(())
}

fn operations(params: &Value) -> Result<Vec<Operation>, String> {
    serde_json::from_value(
        params
            .get("operations")
            .cloned()
            .unwrap_or_else(|| json!([])),
    )
    .map_err(|error| format!("Invalid edit recipe: {error}"))
}

fn current_input(state: &EditorState) -> Result<PathBuf, String> {
    state
        .input
        .lock()
        .map_err(|_| "Editor state is unavailable".to_string())?
        .clone()
        .ok_or_else(|| "Open an image first.".to_string())
}

fn preview_result(path: &Path, operations: &[Operation]) -> Result<Value, String> {
    let loaded =
        processing::load(path, DEFAULT_MEMORY_BUDGET).map_err(|error| error.to_string())?;
    let image = processing::apply(loaded.pixels, operations, DEFAULT_MEMORY_BUDGET)
        .map_err(|error| error.to_string())?;
    let proxy = processing::preview(&image, 1600).map_err(|error| error.to_string())?;
    let mut png = Cursor::new(Vec::new());
    proxy
        .write_to(&mut png, ImageFormat::Png)
        .map_err(|error| error.to_string())?;
    Ok(json!({
        "name": path.file_name().and_then(|v| v.to_str()).unwrap_or("image"),
        "width": image.width(), "height": image.height(),
        "preview": format!("data:image/png;base64,{}", base64::engine::general_purpose::STANDARD.encode(png.into_inner())),
        "histogram": processing::histogram(&image)
    }))
}

#[tauri::command]
fn editor_call(
    app: tauri::AppHandle,
    state: State<'_, EditorState>,
    method: String,
    params: Value,
) -> Result<Value, String> {
    match method.as_str() {
        "hello" => Ok(json!({"contract_version": 1, "host": "tauri-windows"})),
        "choose_image" => {
            let selected = app
                .dialog()
                .file()
                .add_filter(
                    "Supported images",
                    &["jpg", "jpeg", "png", "webp", "tif", "tiff"],
                )
                .blocking_pick_file();
            let Some(selected) = selected else {
                return Ok(Value::Null);
            };
            let path = selected
                .into_path()
                .map_err(|error| error.to_string())?
                .canonicalize()
                .map_err(|error| error.to_string())?;
            allowed_input(&path)?;
            let result = preview_result(&path, &[])?;
            *state
                .input
                .lock()
                .map_err(|_| "Editor state is unavailable".to_string())? = Some(path);
            *state
                .project
                .lock()
                .map_err(|_| "Editor state is unavailable".to_string())? = None;
            autosave(&current_input(&state)?, &[])?;
            Ok(result)
        }
        "choose_overlay" => {
            let selected = app
                .dialog()
                .file()
                .add_filter(
                    "Overlay image",
                    &["jpg", "jpeg", "png", "webp", "tif", "tiff"],
                )
                .blocking_pick_file();
            let Some(selected) = selected else {
                return Ok(Value::Null);
            };
            let path = selected.into_path().map_err(|error| error.to_string())?;
            allowed_input(&path)?;
            let metadata = path.metadata().map_err(|error| error.to_string())?;
            if metadata.len() > 8 * 1024 * 1024 {
                return Err("Overlay files are limited to 8 MB so projects stay portable.".into());
            }
            let loaded = processing::load(&path, DEFAULT_MEMORY_BUDGET)
                .map_err(|error| error.to_string())?;
            let extension = path
                .extension()
                .and_then(|value| value.to_str())
                .unwrap_or_default()
                .to_ascii_lowercase();
            let mime = match extension.as_str() {
                "jpg" | "jpeg" => "image/jpeg",
                "png" => "image/png",
                "webp" => "image/webp",
                "tif" | "tiff" => "image/tiff",
                _ => return Err("Unsupported overlay image.".into()),
            };
            let bytes = std::fs::read(&path).map_err(|error| error.to_string())?;
            Ok(json!({
                "name": path.file_name().and_then(|value| value.to_str()).unwrap_or("Overlay"),
                "width": loaded.info.width, "height": loaded.info.height,
                "data_uri": format!("data:{mime};base64,{}", base64::engine::general_purpose::STANDARD.encode(bytes))
            }))
        }
        "open_project" => {
            let selected = app
                .dialog()
                .file()
                .add_filter("PIXEL TICKLER project", &["spt"])
                .blocking_pick_file();
            let Some(selected) = selected else {
                return Ok(Value::Null);
            };
            let project_path = selected.into_path().map_err(|error| error.to_string())?;
            let mut document = snapsmack_photo_core::project::load(&project_path)
                .map_err(|error| error.to_string())?;
            let mut input = document.source.path.clone();
            if !input.is_file() {
                let relocated = app
                    .dialog()
                    .file()
                    .set_title("Locate the original image")
                    .add_filter(
                        "Supported images",
                        &["jpg", "jpeg", "png", "webp", "tif", "tiff"],
                    )
                    .blocking_pick_file();
                let Some(relocated) = relocated else {
                    return Ok(Value::Null);
                };
                input = relocated.into_path().map_err(|error| error.to_string())?;
                allowed_input(&input)?;
                if !snapsmack_photo_core::project::source_matches(&document, &input)
                    .map_err(|error| error.to_string())?
                {
                    return Err("That file is not the original image used by this project.".into());
                }
                document.source.path = input.canonicalize().map_err(|error| error.to_string())?;
            } else if !snapsmack_photo_core::project::source_matches(&document, &input)
                .map_err(|error| error.to_string())?
            {
                return Err("The original image has changed since this project was saved. The project was not opened.".into());
            }
            allowed_input(&input)?;
            let mut result = preview_result(&input, &document.operations)?;
            result["operations"] =
                serde_json::to_value(&document.operations).map_err(|error| error.to_string())?;
            result["project_name"] = json!(project_path
                .file_name()
                .and_then(|value| value.to_str())
                .unwrap_or("project.spt"));
            *state
                .input
                .lock()
                .map_err(|_| "Editor state is unavailable".to_string())? = Some(input);
            *state
                .project
                .lock()
                .map_err(|_| "Editor state is unavailable".to_string())? = Some(document);
            Ok(result)
        }
        "save_project" => {
            let input = current_input(&state)?;
            let selected = app
                .dialog()
                .file()
                .set_file_name(format!(
                    "{}.spt",
                    input
                        .file_stem()
                        .and_then(|value| value.to_str())
                        .unwrap_or("project")
                ))
                .add_filter("PIXEL TICKLER project", &["spt"])
                .blocking_save_file();
            let Some(selected) = selected else {
                return Ok(Value::Null);
            };
            let path = selected.into_path().map_err(|error| error.to_string())?;
            let edits = operations(&params)?;
            let mut document = state
                .project
                .lock()
                .map_err(|_| "Editor state is unavailable".to_string())?
                .clone()
                .map_or_else(
                    || snapsmack_photo_core::project::new_document(&input, edits.clone()),
                    Ok,
                )
                .map_err(|error| error.to_string())?;
            document.source = snapsmack_photo_core::project::fingerprint(&input)
                .map_err(|error| error.to_string())?;
            document.operations = edits;
            snapsmack_photo_core::project::save(&document, &path)
                .map_err(|error| error.to_string())?;
            *state
                .project
                .lock()
                .map_err(|_| "Editor state is unavailable".to_string())? = Some(document);
            Ok(json!({"project_path": path}))
        }
        "render_preview" => {
            let input = current_input(&state)?;
            let operations = operations(&params)?;
            let generation = params
                .get("generation")
                .and_then(Value::as_u64)
                .unwrap_or(0);
            {
                let mut latest = state
                    .render_generation
                    .lock()
                    .map_err(|_| "Editor state is unavailable".to_string())?;
                *latest = (*latest).max(generation);
            }
            let result = preview_result(&input, &operations)?;
            let latest = *state
                .render_generation
                .lock()
                .map_err(|_| "Editor state is unavailable".to_string())?;
            if generation >= latest {
                autosave(&input, &operations)?;
            }
            Ok(result)
        }
        "recover" => {
            let path = autosave_path();
            if !path.is_file() {
                return Ok(Value::Null);
            }
            let saved: Value =
                serde_json::from_slice(&std::fs::read(&path).map_err(|error| error.to_string())?)
                    .map_err(|error| error.to_string())?;
            let input = PathBuf::from(
                saved
                    .get("input_path")
                    .and_then(Value::as_str)
                    .ok_or_else(|| "Recovery file has no input path".to_string())?,
            )
            .canonicalize()
            .map_err(|error| error.to_string())?;
            allowed_input(&input)?;
            let operations = operations(&saved)?;
            let mut result = preview_result(&input, &operations)?;
            result["operations"] =
                serde_json::to_value(&operations).map_err(|error| error.to_string())?;
            *state
                .input
                .lock()
                .map_err(|_| "Editor state is unavailable".to_string())? = Some(input);
            Ok(result)
        }
        "export_copy" => {
            let input = current_input(&state)?;
            let suggested = format!(
                "{}-edited.png",
                input
                    .file_stem()
                    .and_then(|v| v.to_str())
                    .unwrap_or("photo")
            );
            let selected = app
                .dialog()
                .file()
                .set_file_name(suggested)
                .add_filter("PNG image", &["png"])
                .add_filter("JPEG image", &["jpg", "jpeg"])
                .add_filter("WebP image", &["webp"])
                .add_filter("TIFF image", &["tif", "tiff"])
                .blocking_save_file();
            let Some(selected) = selected else {
                return Ok(Value::Null);
            };
            let output = selected.into_path().map_err(|error| error.to_string())?;
            let format = output
                .extension()
                .and_then(|v| v.to_str())
                .and_then(ImageFormat::from_extension)
                .filter(|v| {
                    matches!(
                        v,
                        ImageFormat::Jpeg
                            | ImageFormat::Png
                            | ImageFormat::WebP
                            | ImageFormat::Tiff
                    )
                })
                .ok_or_else(|| "Save as JPEG, PNG, WebP, or TIFF.".to_string())?;
            let loaded = processing::load(&input, DEFAULT_MEMORY_BUDGET)
                .map_err(|error| error.to_string())?;
            let image =
                processing::apply(loaded.pixels, &operations(&params)?, DEFAULT_MEMORY_BUDGET)
                    .map_err(|error| error.to_string())?;
            processing::save_atomic(&image, &output, format).map_err(|error| error.to_string())?;
            Ok(json!({"output_path": output, "width": image.width(), "height": image.height()}))
        }
        _ => Err(format!("Unsupported editor command: {method}")),
    }
}

fn main() {
    tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .manage(EditorState::default())
        .invoke_handler(tauri::generate_handler![editor_call])
        .run(tauri::generate_context!())
        .expect("PIXEL TICKLER failed to start");
}

// ===== SNAPSMACK EOF =====
