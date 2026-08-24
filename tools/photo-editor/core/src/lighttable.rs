//! Portable folder browsing, picks, rejects and ratings.
//! SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical Rust marker.

use crate::processing::{self, ProcessingError, DEFAULT_MEMORY_BUDGET};
use serde::{Deserialize, Serialize};
use std::collections::BTreeMap;
use std::path::{Path, PathBuf};

pub const CATALOG_FILE: &str = ".pixel-tickler-catalog.json";
pub const MAX_FOLDER_ITEMS: usize = 2_000;

#[derive(Debug, Clone, Copy, Default, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "kebab-case")]
pub enum Flag {
    #[default]
    None,
    Pick,
    Reject,
}

#[derive(Debug, Clone, Copy, Default, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "kebab-case")]
pub enum ColorLabel {
    #[default]
    None,
    Red,
    Yellow,
    Green,
    Blue,
    Purple,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize, PartialEq, Eq)]
pub struct Rating {
    pub flag: Flag,
    pub stars: u8,
    pub color: ColorLabel,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
struct CatalogDocument {
    format: String,
    version: u32,
    #[serde(default)]
    ratings: BTreeMap<String, Rating>,
}

#[derive(Debug, Clone, Serialize)]
pub struct CatalogItem {
    pub path: PathBuf,
    pub name: String,
    pub width: u32,
    pub height: u32,
    pub thumbnail: String,
    pub rating: Rating,
}

fn supported(path: &Path) -> bool {
    path.extension()
        .and_then(|value| value.to_str())
        .is_some_and(|extension| {
            matches!(
                extension.to_ascii_lowercase().as_str(),
                "jpg" | "jpeg" | "png" | "webp" | "tif" | "tiff"
            )
        })
}

fn catalog_path(folder: &Path) -> PathBuf {
    folder.join(CATALOG_FILE)
}

fn load_document(folder: &Path) -> Result<CatalogDocument, ProcessingError> {
    let path = catalog_path(folder);
    if !path.is_file() {
        return Ok(CatalogDocument {
            format: "snapsmack-pixel-tickler-catalog".into(),
            version: 1,
            ratings: BTreeMap::new(),
        });
    }
    let document: CatalogDocument = serde_json::from_slice(
        &std::fs::read(path).map_err(|error| ProcessingError::Io(error.to_string()))?,
    )
    .map_err(|error| ProcessingError::Invalid(format!("invalid PIXEL TICKLER catalog: {error}")))?;
    if document.format != "snapsmack-pixel-tickler-catalog" || document.version != 1 {
        return Err(ProcessingError::Unsupported(
            "unsupported PIXEL TICKLER catalog version".into(),
        ));
    }
    Ok(document)
}

pub fn scan_page(
    folder: &Path,
    offset: usize,
    limit: usize,
) -> Result<Vec<CatalogItem>, ProcessingError> {
    if limit == 0 || limit > 100 {
        return Err(ProcessingError::Invalid(
            "lighttable page size must be 1 to 100".into(),
        ));
    }
    let folder = folder
        .canonicalize()
        .map_err(|error| ProcessingError::Io(error.to_string()))?;
    if !folder.is_dir() {
        return Err(ProcessingError::Invalid(
            "lighttable path is not a folder".into(),
        ));
    }
    let document = load_document(&folder)?;
    let mut paths: Vec<PathBuf> = std::fs::read_dir(&folder)
        .map_err(|error| ProcessingError::Io(error.to_string()))?
        .filter_map(Result::ok)
        .map(|entry| entry.path())
        .filter(|path| path.is_file() && supported(path) && !crate::file_is_heic(path))
        .collect();
    paths.sort_by_key(|path| {
        path.file_name()
            .map(|value| value.to_string_lossy().to_ascii_lowercase())
    });
    if paths.len() > MAX_FOLDER_ITEMS {
        return Err(ProcessingError::Invalid(format!(
            "folder contains more than {MAX_FOLDER_ITEMS} supported images"
        )));
    }
    let mut items = Vec::with_capacity(limit);
    for path in paths.into_iter().skip(offset).take(limit) {
        let Ok(loaded) = processing::load(&path, DEFAULT_MEMORY_BUDGET) else {
            continue;
        };
        let thumbnail = processing::preview_data_uri(&loaded.pixels, 320)?;
        let name = path
            .file_name()
            .and_then(|value| value.to_str())
            .unwrap_or("image")
            .to_owned();
        let rating = document.ratings.get(&name).cloned().unwrap_or_default();
        items.push(CatalogItem {
            path,
            name,
            width: loaded.info.width,
            height: loaded.info.height,
            thumbnail,
            rating,
        });
    }
    Ok(items)
}

pub fn update_rating(folder: &Path, name: &str, rating: Rating) -> Result<(), ProcessingError> {
    if name.is_empty()
        || Path::new(name).file_name().and_then(|value| value.to_str()) != Some(name)
        || rating.stars > 5
    {
        return Err(ProcessingError::Invalid("invalid catalog rating".into()));
    }
    let image = folder.join(name);
    if !image.is_file() || !supported(&image) || crate::file_is_heic(&image) {
        return Err(ProcessingError::Invalid(
            "catalog image no longer exists".into(),
        ));
    }
    let mut document = load_document(folder)?;
    if rating == Rating::default() {
        document.ratings.remove(name);
    } else {
        document.ratings.insert(name.to_owned(), rating);
    }
    processing::replace_json_atomic(&document, &catalog_path(folder))
}

// ===== SNAPSMACK EOF =====
