//! Editable PIXEL TICKLER project document support.
//! SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical Rust marker.

use crate::processing::{replace_json_atomic, Operation, ProcessingError};
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};
use std::fs::File;
use std::io::Read;
use std::path::{Path, PathBuf};
use std::time::UNIX_EPOCH;

pub const PROJECT_FORMAT: &str = "snapsmack-pixel-tickler";
pub const PROJECT_VERSION: u32 = 1;

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct SourceFingerprint {
    pub path: PathBuf,
    pub bytes: u64,
    pub modified_unix_ms: u128,
    pub content_fnv1a64: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ProjectDocument {
    pub format: String,
    pub version: u32,
    pub source: SourceFingerprint,
    pub operations: Vec<Operation>,
    #[serde(flatten)]
    pub optional_metadata: Map<String, Value>,
}

pub fn fingerprint(path: &Path) -> Result<SourceFingerprint, ProcessingError> {
    let canonical = path
        .canonicalize()
        .map_err(|error| ProcessingError::Io(error.to_string()))?;
    let metadata = canonical
        .metadata()
        .map_err(|error| ProcessingError::Io(error.to_string()))?;
    let modified_unix_ms = metadata
        .modified()
        .ok()
        .and_then(|value| value.duration_since(UNIX_EPOCH).ok())
        .map_or(0, |value| value.as_millis());
    let mut file =
        File::open(&canonical).map_err(|error| ProcessingError::Io(error.to_string()))?;
    let mut buffer = [0_u8; 64 * 1024];
    let mut hash = 0xcbf29ce484222325_u64;
    loop {
        let read = file
            .read(&mut buffer)
            .map_err(|error| ProcessingError::Io(error.to_string()))?;
        if read == 0 {
            break;
        }
        for byte in &buffer[..read] {
            hash ^= u64::from(*byte);
            hash = hash.wrapping_mul(0x100000001b3);
        }
    }
    Ok(SourceFingerprint {
        path: canonical,
        bytes: metadata.len(),
        modified_unix_ms,
        content_fnv1a64: format!("{hash:016x}"),
    })
}

pub fn new_document(
    source: &Path,
    operations: Vec<Operation>,
) -> Result<ProjectDocument, ProcessingError> {
    Ok(ProjectDocument {
        format: PROJECT_FORMAT.into(),
        version: PROJECT_VERSION,
        source: fingerprint(source)?,
        operations,
        optional_metadata: Map::new(),
    })
}

pub fn save(document: &ProjectDocument, path: &Path) -> Result<(), ProcessingError> {
    validate(document)?;
    replace_json_atomic(document, path)
}

pub fn load(path: &Path) -> Result<ProjectDocument, ProcessingError> {
    let bytes = std::fs::read(path).map_err(|error| ProcessingError::Io(error.to_string()))?;
    let document: ProjectDocument = serde_json::from_slice(&bytes).map_err(|error| {
        ProcessingError::Invalid(format!("invalid PIXEL TICKLER project: {error}"))
    })?;
    validate(&document)?;
    Ok(document)
}

pub fn validate(document: &ProjectDocument) -> Result<(), ProcessingError> {
    if document.format != PROJECT_FORMAT || document.version != PROJECT_VERSION {
        return Err(ProcessingError::Unsupported(
            "unsupported PIXEL TICKLER project version".into(),
        ));
    }
    if !document.source.path.is_absolute() {
        return Err(ProcessingError::Invalid(
            "project source path must be absolute".into(),
        ));
    }
    if document.operations.len() > 4_096 {
        return Err(ProcessingError::Invalid(
            "project contains too many operations".into(),
        ));
    }
    Ok(())
}

pub fn source_matches(
    document: &ProjectDocument,
    candidate: &Path,
) -> Result<bool, ProcessingError> {
    let actual = fingerprint(candidate)?;
    Ok(actual.bytes == document.source.bytes
        && actual.content_fnv1a64 == document.source.content_fnv1a64)
}

// ===== SNAPSMACK EOF =====
