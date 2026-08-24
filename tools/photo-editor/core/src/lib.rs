//! PIXEL TICKLER sidecar protocol and security boundary.
//!
//! SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical Rust marker.

use image::ImageFormat;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::collections::HashSet;
use std::fs::File;
use std::io::Read;
use std::path::{Path, PathBuf};
use std::sync::{Arc, Mutex};

pub mod processing;
pub mod project;

pub const CONTRACT_VERSION: u64 = 1;
pub const ENGINE_VERSION: &str = env!("CARGO_PKG_VERSION");

pub mod error_code {
    pub const BAD_REQUEST: &str = "BAD_REQUEST";
    pub const CONTRACT_UNSUPPORTED: &str = "CONTRACT_UNSUPPORTED";
    pub const HEIC_UNSUPPORTED: &str = "HEIC_UNSUPPORTED";
    pub const PATH_NOT_APPROVED: &str = "PATH_NOT_APPROVED";
    pub const PATH_NOT_FOUND: &str = "PATH_NOT_FOUND";
    pub const METHOD_NOT_FOUND: &str = "METHOD_NOT_FOUND";
    pub const JOB_NOT_FOUND: &str = "JOB_NOT_FOUND";
    pub const IMAGE_INVALID: &str = "IMAGE_INVALID";
    pub const MEMORY_REFUSED: &str = "MEMORY_REFUSED";
}

#[derive(Debug, Deserialize)]
pub struct Request {
    pub id: Value,
    pub method: String,
    #[serde(default)]
    pub params: Value,
}

#[derive(Debug, Serialize)]
pub struct Response {
    pub id: Value,
    pub ok: bool,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub result: Option<Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub error: Option<RpcError>,
}

#[derive(Debug, Clone, Serialize)]
pub struct RpcError {
    pub code: &'static str,
    pub message: String,
}

impl RpcError {
    pub fn new(code: &'static str, message: impl Into<String>) -> Self {
        Self {
            code,
            message: message.into(),
        }
    }
}

impl Response {
    pub fn success(id: Value, result: Value) -> Self {
        Self {
            id,
            ok: true,
            result: Some(result),
            error: None,
        }
    }

    pub fn failure(id: Value, error: RpcError) -> Self {
        Self {
            id,
            ok: false,
            result: None,
            error: Some(error),
        }
    }
}

#[derive(Clone)]
pub struct SidecarState {
    allowed_roots: Arc<Vec<PathBuf>>,
    cancelled_jobs: Arc<Mutex<HashSet<String>>>,
}

impl SidecarState {
    pub fn new(roots: Vec<PathBuf>) -> Result<Self, RpcError> {
        if roots.is_empty() {
            return Err(RpcError::new(
                error_code::BAD_REQUEST,
                "at least one --allowed-root is required",
            ));
        }
        let mut approved = Vec::new();
        for root in roots {
            let canonical = root.canonicalize().map_err(|_| {
                RpcError::new(
                    error_code::PATH_NOT_FOUND,
                    format!("approved root does not exist: {}", root.display()),
                )
            })?;
            if !canonical.is_dir() {
                return Err(RpcError::new(
                    error_code::PATH_NOT_APPROVED,
                    "approved root is not a directory",
                ));
            }
            approved.push(canonical);
        }
        Ok(Self {
            allowed_roots: Arc::new(approved),
            cancelled_jobs: Arc::new(Mutex::new(HashSet::new())),
        })
    }

    pub fn path_is_approved(&self, path: &Path) -> bool {
        self.allowed_roots.iter().any(|root| path.starts_with(root))
    }

    pub fn approve_existing(&self, raw: &str, directory: bool) -> Result<PathBuf, RpcError> {
        let supplied = PathBuf::from(raw);
        if !supplied.is_absolute() {
            return Err(RpcError::new(
                error_code::PATH_NOT_APPROVED,
                "paths must be absolute",
            ));
        }
        let canonical = supplied.canonicalize().map_err(|_| {
            RpcError::new(
                error_code::PATH_NOT_FOUND,
                format!("path does not exist: {raw}"),
            )
        })?;
        if !self.path_is_approved(&canonical) {
            return Err(RpcError::new(
                error_code::PATH_NOT_APPROVED,
                "path is outside the host-approved roots",
            ));
        }
        if directory && !canonical.is_dir() {
            return Err(RpcError::new(
                error_code::PATH_NOT_APPROVED,
                "expected a directory",
            ));
        }
        if !directory && !canonical.is_file() {
            return Err(RpcError::new(
                error_code::PATH_NOT_APPROVED,
                "expected a file",
            ));
        }
        Ok(canonical)
    }

    pub fn approve_new_file(&self, raw: &str) -> Result<PathBuf, RpcError> {
        let supplied = PathBuf::from(raw);
        if !supplied.is_absolute() || supplied.file_name().is_none() || supplied.exists() {
            return Err(RpcError::new(
                error_code::PATH_NOT_APPROVED,
                "output must be a new absolute file",
            ));
        }
        let parent = supplied.parent().ok_or_else(|| {
            RpcError::new(
                error_code::PATH_NOT_APPROVED,
                "output has no parent directory",
            )
        })?;
        let canonical_parent = parent.canonicalize().map_err(|_| {
            RpcError::new(
                error_code::PATH_NOT_FOUND,
                "output directory does not exist",
            )
        })?;
        if !self.path_is_approved(&canonical_parent) {
            return Err(RpcError::new(
                error_code::PATH_NOT_APPROVED,
                "output is outside the host-approved roots",
            ));
        }
        Ok(canonical_parent.join(supplied.file_name().expect("checked above")))
    }

    pub fn cancel(&self, job_id: &str) {
        self.cancelled_jobs
            .lock()
            .expect("cancel mutex poisoned")
            .insert(job_id.to_owned());
    }

    pub fn is_cancelled(&self, job_id: &str) -> bool {
        self.cancelled_jobs
            .lock()
            .expect("cancel mutex poisoned")
            .contains(job_id)
    }

    pub fn clear_cancelled(&self, job_id: &str) {
        self.cancelled_jobs
            .lock()
            .expect("cancel mutex poisoned")
            .remove(job_id);
    }
}

pub fn file_is_heic(path: &Path) -> bool {
    if path
        .extension()
        .and_then(|v| v.to_str())
        .is_some_and(|ext| ext.eq_ignore_ascii_case("heic") || ext.eq_ignore_ascii_case("heif"))
    {
        return true;
    }
    let mut head = [0_u8; 64];
    let Ok(mut file) = File::open(path) else {
        return false;
    };
    let Ok(read) = file.read(&mut head) else {
        return false;
    };
    if read < 12 || &head[4..8] != b"ftyp" {
        return false;
    }
    head[8..read].chunks_exact(4).any(|brand| {
        matches!(
            brand,
            b"heic" | b"heix" | b"hevc" | b"hevx" | b"heim" | b"heis" | b"hevm" | b"hevs"
        )
    })
}

fn required_string<'a>(params: &'a Value, key: &str) -> Result<&'a str, RpcError> {
    params
        .get(key)
        .and_then(Value::as_str)
        .filter(|v| !v.is_empty())
        .ok_or_else(|| RpcError::new(error_code::BAD_REQUEST, format!("missing or invalid {key}")))
}

pub fn validate_job(state: &SidecarState, params: &Value) -> Result<Value, RpcError> {
    if params.get("contract_version").and_then(Value::as_u64) != Some(CONTRACT_VERSION) {
        return Err(RpcError::new(
            error_code::CONTRACT_UNSUPPORTED,
            "job contract version is not supported",
        ));
    }
    let mode = required_string(params, "mode")?;
    if !matches!(mode, "edit-copy" | "edit-replace-working-copy") {
        return Err(RpcError::new(
            error_code::BAD_REQUEST,
            "unsupported edit mode",
        ));
    }
    let calling_app = required_string(params, "calling_app")?;
    if !matches!(calling_app, "standalone" | "hub" | "coldsnap" | "gyss") {
        return Err(RpcError::new(
            error_code::BAD_REQUEST,
            "unsupported calling_app",
        ));
    }
    let return_token = required_string(params, "return_token")?;
    if !(32..=256).contains(&return_token.len()) {
        return Err(RpcError::new(
            error_code::BAD_REQUEST,
            "return_token must contain 32 to 256 bytes",
        ));
    }
    let input = state.approve_existing(required_string(params, "input_path")?, false)?;
    if file_is_heic(&input) {
        return Err(RpcError::new(
            error_code::HEIC_UNSUPPORTED,
            "HEIC/HEIF is not supported because of HEVC patent risk",
        ));
    }
    let output =
        state.approve_existing(required_string(params, "permitted_output_directory")?, true)?;
    Ok(json!({
        "contract_version": CONTRACT_VERSION,
        "input_path": input,
        "permitted_output_directory": output,
        "mode": mode
    }))
}

pub fn handle_sync(state: &SidecarState, request: Request) -> (Response, bool) {
    let id = request.id;
    let result = match request.method.as_str() {
        "hello" => {
            let offered = request
                .params
                .get("contract_versions")
                .and_then(Value::as_array);
            if !offered
                .is_some_and(|items| items.iter().any(|v| v.as_u64() == Some(CONTRACT_VERSION)))
            {
                Err(RpcError::new(
                    error_code::CONTRACT_UNSUPPORTED,
                    "no mutually supported contract version",
                ))
            } else {
                Ok(json!({
                    "engine": "snapsmack-photo-core",
                    "engine_version": ENGINE_VERSION,
                    "contract_version": CONTRACT_VERSION,
                    "capabilities": ["validate-job", "inspect-image", "process-export", "cancel", "progress"]
                }))
            }
        }
        "validate_job" => validate_job(state, &request.params),
        "inspect_image" => (|| {
            let input =
                state.approve_existing(required_string(&request.params, "input_path")?, false)?;
            if file_is_heic(&input) {
                return Err(RpcError::new(
                    error_code::HEIC_UNSUPPORTED,
                    "HEIC/HEIF is not supported because of HEVC patent risk",
                ));
            }
            processing::load(&input, request.params.get("memory_budget_bytes").and_then(Value::as_u64).unwrap_or(processing::DEFAULT_MEMORY_BUDGET))
                .map(|loaded| json!({"image": loaded.info, "histogram": processing::histogram(&loaded.pixels)}))
                .map_err(processing_rpc_error)
        })(),
        "render_preview" => (|| {
            let input =
                state.approve_existing(required_string(&request.params, "input_path")?, false)?;
            if file_is_heic(&input) {
                return Err(RpcError::new(
                    error_code::HEIC_UNSUPPORTED,
                    "HEIC/HEIF is not supported because of HEVC patent risk",
                ));
            }
            let budget = request
                .params
                .get("memory_budget_bytes")
                .and_then(Value::as_u64)
                .unwrap_or(processing::DEFAULT_MEMORY_BUDGET);
            let loaded = processing::load(&input, budget).map_err(processing_rpc_error)?;
            let operations: Vec<processing::Operation> = serde_json::from_value(
                request
                    .params
                    .get("operations")
                    .cloned()
                    .unwrap_or_else(|| json!([])),
            )
            .map_err(|e| RpcError::new(error_code::BAD_REQUEST, e.to_string()))?;
            let image = processing::apply(loaded.pixels, &operations, budget)
                .map_err(processing_rpc_error)?;
            let preview =
                processing::preview_data_uri(&image, 1600).map_err(processing_rpc_error)?;
            Ok(
                json!({"name": input.file_name().and_then(|v| v.to_str()).unwrap_or("image"), "width": image.width(), "height": image.height(), "preview": preview, "histogram": processing::histogram(&image)}),
            )
        })(),
        "process_export" => (|| {
            let input =
                state.approve_existing(required_string(&request.params, "input_path")?, false)?;
            if file_is_heic(&input) {
                return Err(RpcError::new(
                    error_code::HEIC_UNSUPPORTED,
                    "HEIC/HEIF is not supported because of HEVC patent risk",
                ));
            }
            let output =
                state.approve_new_file(required_string(&request.params, "output_path")?)?;
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
                .ok_or_else(|| {
                    RpcError::new(
                        error_code::IMAGE_INVALID,
                        "output must use jpg, png, webp, tif or tiff",
                    )
                })?;
            let budget = request
                .params
                .get("memory_budget_bytes")
                .and_then(Value::as_u64)
                .unwrap_or(processing::DEFAULT_MEMORY_BUDGET);
            let loaded = processing::load(&input, budget).map_err(processing_rpc_error)?;
            let operations: Vec<processing::Operation> = serde_json::from_value(
                request
                    .params
                    .get("operations")
                    .cloned()
                    .unwrap_or_else(|| json!([])),
            )
            .map_err(|e| RpcError::new(error_code::BAD_REQUEST, e.to_string()))?;
            let image = processing::apply(loaded.pixels, &operations, budget)
                .map_err(processing_rpc_error)?;
            processing::save_atomic(&image, &output, format).map_err(processing_rpc_error)?;
            Ok(json!({"output_path": output, "width": image.width(), "height": image.height()}))
        })(),
        "cancel" => required_string(&request.params, "job_id").map(|job_id| {
            state.cancel(job_id);
            json!({"job_id": job_id, "cancel_requested": true})
        }),
        "shutdown" => Ok(json!({"shutting_down": true})),
        _ => Err(RpcError::new(
            error_code::METHOD_NOT_FOUND,
            "unknown method",
        )),
    };
    let shutdown = request.method == "shutdown" && result.is_ok();
    match result {
        Ok(value) => (Response::success(id, value), shutdown),
        Err(error) => (Response::failure(id, error), false),
    }
}

fn processing_rpc_error(error: processing::ProcessingError) -> RpcError {
    let code = if matches!(error, processing::ProcessingError::MemoryRefused { .. }) {
        error_code::MEMORY_REFUSED
    } else {
        error_code::IMAGE_INVALID
    };
    RpcError::new(code, error.to_string())
}

// ===== SNAPSMACK EOF =====
