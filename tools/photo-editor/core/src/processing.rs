//! Bounded image decode and deterministic basic recipe processing.
//! SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical Rust marker.

use base64::Engine;
use image::imageops::FilterType;
use image::{DynamicImage, GenericImageView, ImageDecoder, ImageFormat, ImageReader};
use serde::{Deserialize, Serialize};
use std::fs::{self, File, OpenOptions};
use std::io::{BufWriter, Write};
use std::path::{Path, PathBuf};

pub const MAX_DIMENSION: u32 = 65_535;
pub const DEFAULT_MEMORY_BUDGET: u64 = 1024 * 1024 * 1024;

#[derive(Debug)]
pub enum ProcessingError {
    Unsupported(String),
    Invalid(String),
    MemoryRefused { required: u64, budget: u64 },
    Io(String),
}

impl std::fmt::Display for ProcessingError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Unsupported(v) | Self::Invalid(v) | Self::Io(v) => f.write_str(v),
            Self::MemoryRefused { required, budget } => {
                write!(f, "image needs {required} bytes; memory budget is {budget}")
            }
        }
    }
}

impl std::error::Error for ProcessingError {}

#[derive(Debug, Clone, Serialize)]
pub struct ImageInfo {
    pub width: u32,
    pub height: u32,
    pub color_type: String,
    pub format: String,
    pub icc_profile_bytes: usize,
    pub orientation_applied: bool,
}

pub struct LoadedImage {
    pub pixels: DynamicImage,
    pub info: ImageInfo,
    pub icc_profile: Option<Vec<u8>>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "kebab-case")]
pub enum Operation {
    Crop {
        x: u32,
        y: u32,
        width: u32,
        height: u32,
    },
    Rotate90,
    Rotate180,
    Rotate270,
    FlipHorizontal,
    FlipVertical,
    Resize {
        width: u32,
        height: u32,
    },
}

#[derive(Debug, Clone, Serialize)]
pub struct Histogram {
    pub red: Vec<u64>,
    pub green: Vec<u64>,
    pub blue: Vec<u64>,
    pub alpha: Vec<u64>,
    pub shadows_clipped: u64,
    pub highlights_clipped: u64,
}

fn checked_allocation(width: u32, height: u32, budget: u64) -> Result<(), ProcessingError> {
    if width == 0 || height == 0 || width > MAX_DIMENSION || height > MAX_DIMENSION {
        return Err(ProcessingError::Invalid(
            "invalid or excessive image dimensions".into(),
        ));
    }
    let required = u64::from(width)
        .checked_mul(u64::from(height))
        .and_then(|v| v.checked_mul(16))
        .ok_or_else(|| ProcessingError::Invalid("image dimensions overflow".into()))?;
    if required > budget {
        return Err(ProcessingError::MemoryRefused { required, budget });
    }
    Ok(())
}

pub fn load(path: &Path, budget: u64) -> Result<LoadedImage, ProcessingError> {
    let reader = ImageReader::open(path)
        .map_err(|e| ProcessingError::Io(e.to_string()))?
        .with_guessed_format()
        .map_err(|e| ProcessingError::Invalid(e.to_string()))?;
    let format = reader
        .format()
        .ok_or_else(|| ProcessingError::Unsupported("unknown image format".into()))?;
    if !matches!(
        format,
        ImageFormat::Jpeg | ImageFormat::Png | ImageFormat::WebP | ImageFormat::Tiff
    ) {
        return Err(ProcessingError::Unsupported(
            "only JPEG, PNG, WebP and TIFF are supported".into(),
        ));
    }
    let mut decoder = reader
        .into_decoder()
        .map_err(|e| ProcessingError::Invalid(e.to_string()))?;
    let (width, height) = decoder.dimensions();
    checked_allocation(width, height, budget)?;
    let orientation = decoder
        .orientation()
        .map_err(|e| ProcessingError::Invalid(e.to_string()))?;
    let icc_profile = decoder
        .icc_profile()
        .map_err(|e| ProcessingError::Invalid(e.to_string()))?;
    let color_type = format!("{:?}", decoder.color_type());
    let mut pixels =
        DynamicImage::from_decoder(decoder).map_err(|e| ProcessingError::Invalid(e.to_string()))?;
    let orientation_applied = orientation != image::metadata::Orientation::NoTransforms;
    pixels.apply_orientation(orientation);
    let (width, height) = pixels.dimensions();
    Ok(LoadedImage {
        info: ImageInfo {
            width,
            height,
            color_type,
            format: format.extensions_str()[0].to_owned(),
            icc_profile_bytes: icc_profile.as_ref().map_or(0, Vec::len),
            orientation_applied,
        },
        pixels,
        icc_profile,
    })
}

pub fn apply(
    mut image: DynamicImage,
    operations: &[Operation],
    budget: u64,
) -> Result<DynamicImage, ProcessingError> {
    for operation in operations {
        image = match *operation {
            Operation::Crop {
                x,
                y,
                width,
                height,
            } => {
                let (source_width, source_height) = image.dimensions();
                if width == 0
                    || height == 0
                    || x.checked_add(width).is_none_or(|v| v > source_width)
                    || y.checked_add(height).is_none_or(|v| v > source_height)
                {
                    return Err(ProcessingError::Invalid("crop is outside the image".into()));
                }
                image.crop_imm(x, y, width, height)
            }
            Operation::Rotate90 => image.rotate90(),
            Operation::Rotate180 => image.rotate180(),
            Operation::Rotate270 => image.rotate270(),
            Operation::FlipHorizontal => image.fliph(),
            Operation::FlipVertical => image.flipv(),
            Operation::Resize { width, height } => {
                checked_allocation(width, height, budget)?;
                image.resize_exact(width, height, FilterType::Lanczos3)
            }
        };
    }
    Ok(image)
}

pub fn preview(image: &DynamicImage, longest_edge: u32) -> Result<DynamicImage, ProcessingError> {
    if longest_edge == 0 || longest_edge > 4096 {
        return Err(ProcessingError::Invalid(
            "preview edge must be 1 to 4096 pixels".into(),
        ));
    }
    Ok(image.thumbnail(longest_edge, longest_edge))
}

pub fn preview_data_uri(
    image: &DynamicImage,
    longest_edge: u32,
) -> Result<String, ProcessingError> {
    let proxy = preview(image, longest_edge)?;
    let mut bytes = std::io::Cursor::new(Vec::new());
    proxy
        .write_to(&mut bytes, ImageFormat::Png)
        .map_err(|error| ProcessingError::Io(error.to_string()))?;
    Ok(format!(
        "data:image/png;base64,{}",
        base64::engine::general_purpose::STANDARD.encode(bytes.into_inner())
    ))
}

pub fn histogram(image: &DynamicImage) -> Histogram {
    let mut value = Histogram {
        red: vec![0; 256],
        green: vec![0; 256],
        blue: vec![0; 256],
        alpha: vec![0; 256],
        shadows_clipped: 0,
        highlights_clipped: 0,
    };
    for pixel in image.to_rgba8().pixels() {
        let [r, g, b, a] = pixel.0;
        value.red[usize::from(r)] += 1;
        value.green[usize::from(g)] += 1;
        value.blue[usize::from(b)] += 1;
        value.alpha[usize::from(a)] += 1;
        let low = r == 0 && g == 0 && b == 0;
        let high = r == 255 && g == 255 && b == 255;
        value.shadows_clipped += u64::from(low);
        value.highlights_clipped += u64::from(high);
    }
    value
}

fn temp_path(destination: &Path) -> Result<PathBuf, ProcessingError> {
    let name = destination
        .file_name()
        .and_then(|v| v.to_str())
        .ok_or_else(|| ProcessingError::Invalid("invalid output filename".into()))?;
    Ok(destination.with_file_name(format!(".{name}.snapsmack.tmp")))
}

pub fn save_atomic(
    image: &DynamicImage,
    destination: &Path,
    format: ImageFormat,
) -> Result<(), ProcessingError> {
    if !matches!(
        format,
        ImageFormat::Jpeg | ImageFormat::Png | ImageFormat::WebP | ImageFormat::Tiff
    ) {
        return Err(ProcessingError::Unsupported(
            "unsupported output format".into(),
        ));
    }
    let temporary = temp_path(destination)?;
    let file = OpenOptions::new()
        .write(true)
        .create_new(true)
        .open(&temporary)
        .map_err(|e| ProcessingError::Io(e.to_string()))?;
    let result = image
        .write_to(&mut BufWriter::new(&file), format)
        .map_err(|e| ProcessingError::Io(e.to_string()));
    if let Err(error) = result {
        drop(file);
        let _ = fs::remove_file(&temporary);
        return Err(error);
    }
    file.sync_all()
        .map_err(|e| ProcessingError::Io(e.to_string()))?;
    if destination.exists() {
        drop(file);
        let _ = fs::remove_file(&temporary);
        return Err(ProcessingError::Invalid(
            "destination exists; replacement requires explicit workflow".into(),
        ));
    }
    fs::rename(&temporary, destination).map_err(|e| ProcessingError::Io(e.to_string()))?;
    if let Some(parent) = destination.parent() {
        if let Ok(directory) = File::open(parent) {
            let _ = directory.sync_all();
        }
    }
    Ok(())
}

pub fn save_json_atomic<T: Serialize>(
    value: &T,
    destination: &Path,
) -> Result<(), ProcessingError> {
    let temporary = temp_path(destination)?;
    let mut file = OpenOptions::new()
        .write(true)
        .create_new(true)
        .open(&temporary)
        .map_err(|e| ProcessingError::Io(e.to_string()))?;
    serde_json::to_writer_pretty(&mut file, value)
        .map_err(|e| ProcessingError::Invalid(e.to_string()))?;
    file.write_all(b"\n")
        .map_err(|e| ProcessingError::Io(e.to_string()))?;
    file.sync_all()
        .map_err(|e| ProcessingError::Io(e.to_string()))?;
    if destination.exists() {
        let _ = fs::remove_file(&temporary);
        return Err(ProcessingError::Invalid("destination exists".into()));
    }
    fs::rename(temporary, destination).map_err(|e| ProcessingError::Io(e.to_string()))
}

// ===== SNAPSMACK EOF =====
