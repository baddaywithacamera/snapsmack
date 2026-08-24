//! Bounded image decode and deterministic basic recipe processing.
//! SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical Rust marker.

use ab_glyph::{point, Font, FontRef, PxScale, ScaleFont};
use base64::Engine;
use image::imageops::FilterType;
use image::{
    DynamicImage, GenericImageView, ImageBuffer, ImageDecoder, ImageFormat, ImageReader, Rgba,
};
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
    Straighten {
        degrees: f32,
    },
    Resize {
        width: u32,
        height: u32,
    },
    Darkroom {
        settings: DarkroomSettings,
    },
    Levels {
        settings: LevelsSettings,
    },
    BlackAndWhite {
        red: f32,
        green: f32,
        blue: f32,
    },
    Vignette {
        amount: f32,
        midpoint: f32,
        feather: f32,
    },
    Sharpen {
        amount: f32,
        threshold: f32,
    },
    NoiseReduction {
        luminance: f32,
        chroma: f32,
    },
    LocalContrast {
        amount: f32,
    },
    HazeReduction {
        amount: f32,
    },
    ToneCurve {
        shadows: f32,
        midtones: f32,
        highlights: f32,
    },
    Hsl {
        hue_degrees: f32,
        saturation: f32,
        lightness: f32,
    },
    LensCorrection {
        distortion: f32,
        red_shift: f32,
        blue_shift: f32,
    },
    AdjustmentLayer {
        name: String,
        visible: bool,
        locked: bool,
        opacity: f32,
        fill_opacity: f32,
        blend_mode: BlendMode,
        operations: Vec<Operation>,
    },
    TextLayer {
        name: String,
        visible: bool,
        locked: bool,
        opacity: f32,
        fill_opacity: f32,
        blend_mode: BlendMode,
        text: String,
        font_family: FontFamily,
        font_size: f32,
        color: [u8; 4],
        x: i32,
        y: i32,
        alignment: TextAlignment,
        #[serde(default)]
        styles: Vec<LayerStyle>,
    },
    ImageOverlayLayer {
        name: String,
        visible: bool,
        locked: bool,
        opacity: f32,
        fill_opacity: f32,
        blend_mode: BlendMode,
        original_name: String,
        data_uri: String,
        width: u32,
        height: u32,
        rotation_degrees: f32,
        x: i32,
        y: i32,
        #[serde(default)]
        styles: Vec<LayerStyle>,
    },
    LayerGroup {
        name: String,
        visible: bool,
        locked: bool,
        opacity: f32,
        fill_opacity: f32,
        blend_mode: BlendMode,
        operations: Vec<Operation>,
    },
}

#[derive(Debug, Clone, Copy, Default, Serialize, Deserialize)]
#[serde(rename_all = "kebab-case")]
pub enum FontFamily {
    #[default]
    SansSerif,
    Serif,
    Monospace,
}

#[derive(Debug, Clone, Copy, Default, Serialize, Deserialize)]
#[serde(rename_all = "kebab-case")]
pub enum TextAlignment {
    #[default]
    Left,
    Center,
    Right,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "kebab-case")]
pub enum LayerStyle {
    Stroke {
        enabled: bool,
        color: [u8; 4],
        size: u32,
        position: StrokePosition,
    },
    DropShadow {
        enabled: bool,
        color: [u8; 4],
        offset_x: i32,
        offset_y: i32,
        blur: u32,
        spread: u32,
    },
    OuterGlow {
        enabled: bool,
        color: [u8; 4],
        size: u32,
        intensity: f32,
    },
}

#[derive(Debug, Clone, Copy, Default, Serialize, Deserialize)]
#[serde(rename_all = "kebab-case")]
pub enum StrokePosition {
    Inside,
    #[default]
    Center,
    Outside,
}

#[derive(Debug, Clone, Copy, Default, Serialize, Deserialize)]
#[serde(rename_all = "kebab-case")]
pub enum BlendMode {
    #[default]
    Normal,
    Multiply,
    Screen,
    Overlay,
    SoftLight,
}

#[derive(Debug, Clone, Copy, Serialize, Deserialize)]
#[serde(default)]
pub struct LevelsSettings {
    pub black: f32,
    pub gamma: f32,
    pub white: f32,
    pub output_black: f32,
    pub output_white: f32,
}

impl Default for LevelsSettings {
    fn default() -> Self {
        Self {
            black: 0.0,
            gamma: 1.0,
            white: 1.0,
            output_black: 0.0,
            output_white: 1.0,
        }
    }
}

#[derive(Debug, Clone, Copy, Default, Serialize, Deserialize)]
#[serde(default)]
pub struct DarkroomSettings {
    pub exposure: f32,
    pub contrast: f32,
    pub highlights: f32,
    pub shadows: f32,
    pub whites: f32,
    pub blacks: f32,
    pub temperature: f32,
    pub tint: f32,
    pub saturation: f32,
    pub vibrance: f32,
}

impl DarkroomSettings {
    fn is_neutral(self) -> bool {
        [
            self.exposure,
            self.contrast,
            self.highlights,
            self.shadows,
            self.whites,
            self.blacks,
            self.temperature,
            self.tint,
            self.saturation,
            self.vibrance,
        ]
        .iter()
        .all(|value| value.abs() < f32::EPSILON)
    }

    fn validate(self) -> Result<(), ProcessingError> {
        let unit = [
            self.contrast,
            self.highlights,
            self.shadows,
            self.whites,
            self.blacks,
            self.temperature,
            self.tint,
            self.saturation,
            self.vibrance,
        ];
        if !self.exposure.is_finite()
            || !(-5.0..=5.0).contains(&self.exposure)
            || unit
                .iter()
                .any(|value| !value.is_finite() || !(-1.0..=1.0).contains(value))
        {
            return Err(ProcessingError::Invalid(
                "darkroom control is outside its documented range".into(),
            ));
        }
        Ok(())
    }
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
    image: DynamicImage,
    operations: &[Operation],
    budget: u64,
) -> Result<DynamicImage, ProcessingError> {
    apply_with_depth(image, operations, budget, 0)
}

fn apply_with_depth(
    mut image: DynamicImage,
    operations: &[Operation],
    budget: u64,
    depth: usize,
) -> Result<DynamicImage, ProcessingError> {
    if depth > 32 {
        return Err(ProcessingError::Invalid(
            "layer nesting exceeds 32 levels".into(),
        ));
    }
    if operations.len() > 4096 {
        return Err(ProcessingError::Invalid(
            "recipe contains too many operations".into(),
        ));
    }
    for operation in operations {
        image = match operation.clone() {
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
            Operation::Straighten { degrees } => straighten(&image, degrees, budget)?,
            Operation::Resize { width, height } => {
                checked_allocation(width, height, budget)?;
                image.resize_exact(width, height, FilterType::Lanczos3)
            }
            Operation::Darkroom { settings } => apply_darkroom(&image, settings)?,
            Operation::Levels { settings } => apply_levels(&image, settings)?,
            Operation::BlackAndWhite { red, green, blue } => {
                apply_black_and_white(&image, red, green, blue)?
            }
            Operation::Vignette {
                amount,
                midpoint,
                feather,
            } => apply_vignette(&image, amount, midpoint, feather)?,
            Operation::Sharpen { amount, threshold } => apply_sharpen(&image, amount, threshold)?,
            Operation::NoiseReduction { luminance, chroma } => {
                apply_noise_reduction(&image, luminance, chroma)?
            }
            Operation::LocalContrast { amount } => apply_local_contrast(&image, amount)?,
            Operation::HazeReduction { amount } => apply_haze_reduction(&image, amount)?,
            Operation::ToneCurve {
                shadows,
                midtones,
                highlights,
            } => apply_tone_curve(&image, shadows, midtones, highlights)?,
            Operation::Hsl {
                hue_degrees,
                saturation,
                lightness,
            } => apply_hsl(&image, hue_degrees, saturation, lightness)?,
            Operation::LensCorrection {
                distortion,
                red_shift,
                blue_shift,
            } => apply_lens_correction(&image, distortion, red_shift, blue_shift, budget)?,
            Operation::AdjustmentLayer {
                name,
                visible,
                locked: _,
                opacity,
                fill_opacity,
                blend_mode,
                operations,
            } => {
                if name.trim().is_empty()
                    || name.len() > 200
                    || !opacity.is_finite()
                    || !(0.0..=1.0).contains(&opacity)
                    || !fill_opacity.is_finite()
                    || !(0.0..=1.0).contains(&fill_opacity)
                {
                    return Err(ProcessingError::Invalid("invalid adjustment layer".into()));
                }
                if !visible || opacity == 0.0 || fill_opacity == 0.0 {
                    image
                } else {
                    let adjusted = apply_with_depth(image.clone(), &operations, budget, depth + 1)?;
                    if adjusted.dimensions() != image.dimensions() {
                        return Err(ProcessingError::Invalid(
                            "adjustment layers cannot change canvas geometry".into(),
                        ));
                    }
                    composite_adjustment(&image, &adjusted, opacity * fill_opacity, blend_mode)
                }
            }
            Operation::TextLayer {
                name,
                visible,
                locked: _,
                opacity,
                fill_opacity,
                blend_mode,
                text,
                font_family,
                font_size,
                color,
                x,
                y,
                alignment,
                styles,
            } => {
                validate_layer_header(&name, opacity, fill_opacity)?;
                if text.is_empty()
                    || text.chars().count() > 8_192
                    || !font_size.is_finite()
                    || !(4.0..=2_048.0).contains(&font_size)
                {
                    return Err(ProcessingError::Invalid("invalid text layer".into()));
                }
                if !visible || opacity == 0.0 || fill_opacity == 0.0 {
                    image
                } else {
                    let layer = render_text_layer(
                        image.width(),
                        image.height(),
                        &text,
                        font_family,
                        font_size,
                        color,
                        x,
                        y,
                        alignment,
                    )?;
                    let styled = apply_layer_styles(&layer, &styles)?;
                    composite_overlay(&image, &styled, opacity * fill_opacity, blend_mode)
                }
            }
            Operation::ImageOverlayLayer {
                name,
                visible,
                locked: _,
                opacity,
                fill_opacity,
                blend_mode,
                original_name,
                data_uri,
                width,
                height,
                rotation_degrees,
                x,
                y,
                styles,
            } => {
                validate_layer_header(&name, opacity, fill_opacity)?;
                if original_name.trim().is_empty()
                    || original_name.len() > 255
                    || width == 0
                    || height == 0
                    || !rotation_degrees.is_finite()
                    || !(-180.0..=180.0).contains(&rotation_degrees)
                {
                    return Err(ProcessingError::Invalid(
                        "invalid image-overlay layer".into(),
                    ));
                }
                checked_allocation(width, height, budget)?;
                if !visible || opacity == 0.0 || fill_opacity == 0.0 {
                    image
                } else {
                    let layer = render_image_overlay(
                        &image,
                        &data_uri,
                        width,
                        height,
                        rotation_degrees,
                        x,
                        y,
                        budget,
                    )?;
                    let styled = apply_layer_styles(&layer, &styles)?;
                    composite_overlay(&image, &styled, opacity * fill_opacity, blend_mode)
                }
            }
            Operation::LayerGroup {
                name,
                visible,
                locked: _,
                opacity,
                fill_opacity,
                blend_mode,
                operations,
            } => {
                validate_layer_header(&name, opacity, fill_opacity)?;
                if operations.is_empty() || operations.iter().any(|operation| !is_layer(operation))
                {
                    return Err(ProcessingError::Invalid(
                        "layer groups may contain layers only".into(),
                    ));
                }
                if !visible || opacity == 0.0 || fill_opacity == 0.0 {
                    image
                } else {
                    let grouped = apply_with_depth(image.clone(), &operations, budget, depth + 1)?;
                    if grouped.dimensions() != image.dimensions() {
                        return Err(ProcessingError::Invalid(
                            "layer groups cannot change canvas geometry".into(),
                        ));
                    }
                    composite_group(&image, &grouped, opacity * fill_opacity, blend_mode)
                }
            }
        };
    }
    Ok(image)
}

fn is_layer(operation: &Operation) -> bool {
    matches!(
        operation,
        Operation::AdjustmentLayer { .. }
            | Operation::TextLayer { .. }
            | Operation::ImageOverlayLayer { .. }
            | Operation::LayerGroup { .. }
    )
}

fn validate_layer_header(
    name: &str,
    opacity: f32,
    fill_opacity: f32,
) -> Result<(), ProcessingError> {
    if name.trim().is_empty()
        || name.len() > 200
        || !opacity.is_finite()
        || !(0.0..=1.0).contains(&opacity)
        || !fill_opacity.is_finite()
        || !(0.0..=1.0).contains(&fill_opacity)
    {
        return Err(ProcessingError::Invalid("invalid layer properties".into()));
    }
    Ok(())
}

fn blend_channel(base: f32, adjusted: f32, mode: BlendMode) -> f32 {
    match mode {
        BlendMode::Normal => adjusted,
        BlendMode::Multiply => base * adjusted,
        BlendMode::Screen => 1.0 - (1.0 - base) * (1.0 - adjusted),
        BlendMode::Overlay => {
            if base <= 0.5 {
                2.0 * base * adjusted
            } else {
                1.0 - 2.0 * (1.0 - base) * (1.0 - adjusted)
            }
        }
        BlendMode::SoftLight => (1.0 - 2.0 * adjusted) * base * base + 2.0 * adjusted * base,
    }
}

fn composite_adjustment(
    base: &DynamicImage,
    adjusted: &DynamicImage,
    opacity: f32,
    mode: BlendMode,
) -> DynamicImage {
    let base = base.to_rgba16();
    let adjusted = adjusted.to_rgba16();
    let mut output = base.clone();
    for ((destination, base_pixel), adjusted_pixel) in output
        .pixels_mut()
        .zip(base.pixels())
        .zip(adjusted.pixels())
    {
        for channel in 0..3 {
            let base_value = srgb_to_linear(base_pixel.0[channel] as f32 / 65535.0);
            let adjusted_value = srgb_to_linear(adjusted_pixel.0[channel] as f32 / 65535.0);
            let blended = blend_channel(base_value, adjusted_value, mode);
            destination.0[channel] = (linear_to_srgb(base_value + (blended - base_value) * opacity)
                * 65535.0)
                .round() as u16;
        }
        destination.0[3] = base_pixel.0[3];
    }
    DynamicImage::ImageRgba16(output)
}

fn composite_group(
    base: &DynamicImage,
    grouped: &DynamicImage,
    opacity: f32,
    mode: BlendMode,
) -> DynamicImage {
    let mut output = composite_adjustment(base, grouped, opacity, mode).to_rgba16();
    let base = base.to_rgba16();
    let grouped = grouped.to_rgba16();
    for ((destination, base_pixel), grouped_pixel) in
        output.pixels_mut().zip(base.pixels()).zip(grouped.pixels())
    {
        destination.0[3] = (base_pixel.0[3] as f32
            + (grouped_pixel.0[3] as f32 - base_pixel.0[3] as f32) * opacity)
            .round()
            .clamp(0.0, 65535.0) as u16;
    }
    DynamicImage::ImageRgba16(output)
}

fn system_font_candidates(family: FontFamily) -> Vec<PathBuf> {
    #[cfg(target_os = "windows")]
    {
        let root = std::env::var_os("WINDIR")
            .map(PathBuf::from)
            .unwrap_or_else(|| PathBuf::from(r"C:\Windows"))
            .join("Fonts");
        let names: &[&str] = match family {
            FontFamily::SansSerif => &["segoeui.ttf", "arial.ttf"],
            FontFamily::Serif => &["times.ttf", "georgia.ttf"],
            FontFamily::Monospace => &["consola.ttf", "cour.ttf"],
        };
        names.iter().map(|name| root.join(name)).collect()
    }
    #[cfg(not(target_os = "windows"))]
    {
        let names: &[&str] = match family {
            FontFamily::SansSerif => &[
                "dejavu/DejaVuSans.ttf",
                "liberation2/LiberationSans-Regular.ttf",
            ],
            FontFamily::Serif => &[
                "dejavu/DejaVuSerif.ttf",
                "liberation2/LiberationSerif-Regular.ttf",
            ],
            FontFamily::Monospace => &[
                "dejavu/DejaVuSansMono.ttf",
                "liberation2/LiberationMono-Regular.ttf",
            ],
        };
        ["/usr/share/fonts/truetype", "/usr/local/share/fonts"]
            .iter()
            .flat_map(|root| names.iter().map(move |name| PathBuf::from(root).join(name)))
            .collect()
    }
}

fn load_system_font(family: FontFamily) -> Result<Vec<u8>, ProcessingError> {
    system_font_candidates(family)
        .into_iter()
        .find_map(|path| std::fs::read(path).ok())
        .or_else(|| {
            if matches!(family, FontFamily::SansSerif) {
                None
            } else {
                system_font_candidates(FontFamily::SansSerif)
                    .into_iter()
                    .find_map(|path| std::fs::read(path).ok())
            }
        })
        .ok_or_else(|| ProcessingError::Unsupported("no supported system font is installed".into()))
}

#[allow(clippy::too_many_arguments)]
fn render_text_layer(
    canvas_width: u32,
    canvas_height: u32,
    text: &str,
    family: FontFamily,
    size: f32,
    color: [u8; 4],
    x: i32,
    y: i32,
    alignment: TextAlignment,
) -> Result<DynamicImage, ProcessingError> {
    let bytes = load_system_font(family)?;
    let font = FontRef::try_from_slice(&bytes)
        .map_err(|_| ProcessingError::Invalid("installed fallback font is invalid".into()))?;
    let scale = PxScale::from(size);
    let scaled = font.as_scaled(scale);
    let line_height = (scaled.ascent() - scaled.descent() + scaled.line_gap()).max(size);
    let mut overlay = ImageBuffer::from_pixel(canvas_width, canvas_height, Rgba([0_u16; 4]));

    for (line_index, line) in text.lines().enumerate() {
        let mut width = 0.0_f32;
        let mut previous = None;
        for character in line.chars() {
            let id = font.glyph_id(character);
            if let Some(before) = previous {
                width += scaled.kern(before, id);
            }
            width += scaled.h_advance(id);
            previous = Some(id);
        }
        let start_x = match alignment {
            TextAlignment::Left => x as f32,
            TextAlignment::Center => x as f32 - width / 2.0,
            TextAlignment::Right => x as f32 - width,
        };
        let baseline = y as f32 + scaled.ascent() + line_index as f32 * line_height;
        let mut pen_x = start_x;
        let mut previous = None;
        for character in line.chars() {
            let id = font.glyph_id(character);
            if let Some(before) = previous {
                pen_x += scaled.kern(before, id);
            }
            let glyph = id.with_scale_and_position(scale, point(pen_x, baseline));
            if let Some(outlined) = font.outline_glyph(glyph) {
                let bounds = outlined.px_bounds();
                outlined.draw(|gx, gy, coverage| {
                    let px = bounds.min.x.floor() as i32 + gx as i32;
                    let py = bounds.min.y.floor() as i32 + gy as i32;
                    if px < 0
                        || py < 0
                        || px >= overlay.width() as i32
                        || py >= overlay.height() as i32
                    {
                        return;
                    }
                    let alpha = (coverage * f32::from(color[3]) / 255.0 * 65535.0).round() as u16;
                    let pixel = overlay.get_pixel_mut(px as u32, py as u32);
                    pixel.0 = [
                        u16::from(color[0]) * 257,
                        u16::from(color[1]) * 257,
                        u16::from(color[2]) * 257,
                        pixel.0[3].max(alpha),
                    ];
                });
            }
            pen_x += scaled.h_advance(id);
            previous = Some(id);
        }
    }
    Ok(DynamicImage::ImageRgba16(overlay))
}

fn composite_overlay(
    base: &DynamicImage,
    overlay: &DynamicImage,
    opacity: f32,
    mode: BlendMode,
) -> DynamicImage {
    let base = base.to_rgba16();
    let overlay = overlay.to_rgba16();
    let mut output = base.clone();
    for ((destination, base_pixel), overlay_pixel) in
        output.pixels_mut().zip(base.pixels()).zip(overlay.pixels())
    {
        let source_alpha = overlay_pixel.0[3] as f32 / 65535.0 * opacity;
        if source_alpha <= 0.0 {
            continue;
        }
        for channel in 0..3 {
            let base_value = srgb_to_linear(base_pixel.0[channel] as f32 / 65535.0);
            let source_value = srgb_to_linear(overlay_pixel.0[channel] as f32 / 65535.0);
            let blended = blend_channel(base_value, source_value, mode);
            destination.0[channel] =
                (linear_to_srgb(base_value + (blended - base_value) * source_alpha) * 65535.0)
                    .round() as u16;
        }
        let base_alpha = base_pixel.0[3] as f32 / 65535.0;
        destination.0[3] =
            ((source_alpha + base_alpha * (1.0 - source_alpha)) * 65535.0).round() as u16;
    }
    DynamicImage::ImageRgba16(output)
}

fn apply_layer_styles(
    layer: &DynamicImage,
    styles: &[LayerStyle],
) -> Result<DynamicImage, ProcessingError> {
    if styles.len() > 32 {
        return Err(ProcessingError::Invalid(
            "a layer may contain at most 32 styles".into(),
        ));
    }
    let mut output = layer.to_rgba16();
    for style in styles {
        match *style {
            LayerStyle::Stroke { enabled: false, .. }
            | LayerStyle::DropShadow { enabled: false, .. }
            | LayerStyle::OuterGlow { enabled: false, .. } => {}
            LayerStyle::Stroke {
                color,
                size,
                position,
                ..
            } => {
                if size == 0 || size > 64 {
                    return Err(ProcessingError::Invalid(
                        "stroke size must be 1 to 64".into(),
                    ));
                }
                let alpha = alpha_mask(&output);
                let dilated = max_filter(&alpha, output.width(), output.height(), size);
                let eroded = min_filter(&alpha, output.width(), output.height(), size);
                let mask: Vec<u16> = alpha
                    .iter()
                    .enumerate()
                    .map(|(index, value)| match position {
                        StrokePosition::Outside => dilated[index].saturating_sub(*value),
                        StrokePosition::Inside => value.saturating_sub(eroded[index]),
                        StrokePosition::Center => dilated[index].saturating_sub(eroded[index]),
                    })
                    .collect();
                let stroke = color_layer(output.width(), output.height(), &mask, color);
                output = match position {
                    StrokePosition::Outside => rgba_over(&stroke, &output),
                    StrokePosition::Inside | StrokePosition::Center => rgba_over(&output, &stroke),
                };
            }
            LayerStyle::DropShadow {
                color,
                offset_x,
                offset_y,
                blur,
                spread,
                ..
            } => {
                if blur > 64
                    || spread > 64
                    || offset_x.unsigned_abs() > 65_535
                    || offset_y.unsigned_abs() > 65_535
                {
                    return Err(ProcessingError::Invalid(
                        "invalid drop-shadow settings".into(),
                    ));
                }
                let alpha = alpha_mask(&output);
                let spread_mask = if spread == 0 {
                    alpha
                } else {
                    max_filter(&alpha, output.width(), output.height(), spread)
                };
                let blurred = box_blur_alpha(&spread_mask, output.width(), output.height(), blur);
                let shifted = shift_mask(
                    &blurred,
                    output.width(),
                    output.height(),
                    offset_x,
                    offset_y,
                );
                let shadow = color_layer(output.width(), output.height(), &shifted, color);
                output = rgba_over(&shadow, &output);
            }
            LayerStyle::OuterGlow {
                color,
                size,
                intensity,
                ..
            } => {
                if size == 0
                    || size > 64
                    || !intensity.is_finite()
                    || !(0.0..=2.0).contains(&intensity)
                {
                    return Err(ProcessingError::Invalid(
                        "invalid outer-glow settings".into(),
                    ));
                }
                let alpha = alpha_mask(&output);
                let dilated = max_filter(&alpha, output.width(), output.height(), size);
                let blurred =
                    box_blur_alpha(&dilated, output.width(), output.height(), (size / 2).max(1));
                let mask: Vec<u16> = blurred
                    .iter()
                    .zip(alpha.iter())
                    .map(|(wide, source)| {
                        (wide.saturating_sub(*source) as f32 * intensity)
                            .round()
                            .clamp(0.0, 65535.0) as u16
                    })
                    .collect();
                let glow = color_layer(output.width(), output.height(), &mask, color);
                output = rgba_over(&glow, &output);
            }
        }
    }
    Ok(DynamicImage::ImageRgba16(output))
}

fn alpha_mask(image: &ImageBuffer<Rgba<u16>, Vec<u16>>) -> Vec<u16> {
    image.pixels().map(|pixel| pixel.0[3]).collect()
}

fn color_layer(
    width: u32,
    height: u32,
    mask: &[u16],
    color: [u8; 4],
) -> ImageBuffer<Rgba<u16>, Vec<u16>> {
    ImageBuffer::from_fn(width, height, |x, y| {
        let alpha = u32::from(mask[(y * width + x) as usize]) * u32::from(color[3]) / 255;
        Rgba([
            u16::from(color[0]) * 257,
            u16::from(color[1]) * 257,
            u16::from(color[2]) * 257,
            alpha as u16,
        ])
    })
}

fn rgba_over(
    bottom: &ImageBuffer<Rgba<u16>, Vec<u16>>,
    top: &ImageBuffer<Rgba<u16>, Vec<u16>>,
) -> ImageBuffer<Rgba<u16>, Vec<u16>> {
    ImageBuffer::from_fn(bottom.width(), bottom.height(), |x, y| {
        let under = bottom.get_pixel(x, y).0;
        let over = top.get_pixel(x, y).0;
        let top_alpha = over[3] as f32 / 65535.0;
        let bottom_alpha = under[3] as f32 / 65535.0;
        let output_alpha = top_alpha + bottom_alpha * (1.0 - top_alpha);
        if output_alpha <= f32::EPSILON {
            return Rgba([0, 0, 0, 0]);
        }
        let mut result = [0_u16; 4];
        for channel in 0..3 {
            let value = (over[channel] as f32 * top_alpha
                + under[channel] as f32 * bottom_alpha * (1.0 - top_alpha))
                / output_alpha;
            result[channel] = value.round().clamp(0.0, 65535.0) as u16;
        }
        result[3] = (output_alpha * 65535.0).round() as u16;
        Rgba(result)
    })
}

fn max_filter(mask: &[u16], width: u32, height: u32, radius: u32) -> Vec<u16> {
    neighbourhood_filter(mask, width, height, radius, true)
}

fn min_filter(mask: &[u16], width: u32, height: u32, radius: u32) -> Vec<u16> {
    neighbourhood_filter(mask, width, height, radius, false)
}

fn neighbourhood_filter(
    mask: &[u16],
    width: u32,
    height: u32,
    radius: u32,
    maximum: bool,
) -> Vec<u16> {
    let mut horizontal = vec![0_u16; mask.len()];
    for y in 0..height {
        let start = (y * width) as usize;
        let line = extrema_line(
            &mask[start..start + width as usize],
            radius as usize,
            maximum,
        );
        horizontal[start..start + width as usize].copy_from_slice(&line);
    }
    let mut output = vec![0_u16; mask.len()];
    for x in 0..width {
        let column: Vec<u16> = (0..height)
            .map(|y| horizontal[(y * width + x) as usize])
            .collect();
        let filtered = extrema_line(&column, radius as usize, maximum);
        for (y, value) in filtered.into_iter().enumerate() {
            output[y * width as usize + x as usize] = value;
        }
    }
    output
}

fn extrema_line(values: &[u16], radius: usize, maximum: bool) -> Vec<u16> {
    use std::collections::VecDeque;
    let mut output = vec![0_u16; values.len()];
    let mut deque = VecDeque::new();
    let mut next = 0_usize;
    for (center, destination) in output.iter_mut().enumerate() {
        let end = center.saturating_add(radius).min(values.len() - 1);
        while next <= end {
            while deque.back().is_some_and(|index| {
                if maximum {
                    values[*index] <= values[next]
                } else {
                    values[*index] >= values[next]
                }
            }) {
                deque.pop_back();
            }
            deque.push_back(next);
            next += 1;
        }
        let start = center.saturating_sub(radius);
        while deque.front().is_some_and(|index| *index < start) {
            deque.pop_front();
        }
        *destination = values[*deque.front().expect("non-empty filter window")];
    }
    output
}

fn box_blur_alpha(mask: &[u16], width: u32, height: u32, radius: u32) -> Vec<u16> {
    if radius == 0 {
        return mask.to_vec();
    }
    let mut horizontal = vec![0_u16; mask.len()];
    for y in 0..height {
        let start = (y * width) as usize;
        let line = blur_line(&mask[start..start + width as usize], radius as usize);
        horizontal[start..start + width as usize].copy_from_slice(&line);
    }
    let mut output = vec![0_u16; mask.len()];
    for x in 0..width {
        let column: Vec<u16> = (0..height)
            .map(|y| horizontal[(y * width + x) as usize])
            .collect();
        let filtered = blur_line(&column, radius as usize);
        for (y, value) in filtered.into_iter().enumerate() {
            output[y * width as usize + x as usize] = value;
        }
    }
    output
}

fn blur_line(values: &[u16], radius: usize) -> Vec<u16> {
    let mut output = vec![0_u16; values.len()];
    let mut sum = 0_u64;
    let mut next = 0_usize;
    let mut previous_start = 0_usize;
    for (center, destination) in output.iter_mut().enumerate() {
        let end = center.saturating_add(radius).min(values.len() - 1);
        while next <= end {
            sum += u64::from(values[next]);
            next += 1;
        }
        let start = center.saturating_sub(radius);
        while previous_start < start {
            sum -= u64::from(values[previous_start]);
            previous_start += 1;
        }
        *destination = (sum / (end - start + 1) as u64) as u16;
    }
    output
}

fn shift_mask(mask: &[u16], width: u32, height: u32, offset_x: i32, offset_y: i32) -> Vec<u16> {
    let mut output = vec![0_u16; mask.len()];
    for y in 0..height {
        for x in 0..width {
            let target_x = i64::from(x) + i64::from(offset_x);
            let target_y = i64::from(y) + i64::from(offset_y);
            if target_x >= 0
                && target_y >= 0
                && target_x < i64::from(width)
                && target_y < i64::from(height)
            {
                output[(target_y as u32 * width + target_x as u32) as usize] =
                    mask[(y * width + x) as usize];
            }
        }
    }
    output
}

fn decode_embedded_overlay(data_uri: &str, budget: u64) -> Result<DynamicImage, ProcessingError> {
    const MAX_DATA_URI_BYTES: usize = 12 * 1024 * 1024;
    if data_uri.len() > MAX_DATA_URI_BYTES {
        return Err(ProcessingError::MemoryRefused {
            required: data_uri.len() as u64,
            budget: MAX_DATA_URI_BYTES as u64,
        });
    }
    let encoded = [
        "data:image/jpeg;base64,",
        "data:image/png;base64,",
        "data:image/webp;base64,",
        "data:image/tiff;base64,",
    ]
    .iter()
    .find_map(|prefix| data_uri.strip_prefix(prefix))
    .ok_or_else(|| {
        ProcessingError::Unsupported("overlay must be embedded JPEG, PNG, WebP or TIFF".into())
    })?;
    let bytes = base64::engine::general_purpose::STANDARD
        .decode(encoded)
        .map_err(|_| ProcessingError::Invalid("overlay contains invalid base64 data".into()))?;
    let reader = ImageReader::new(std::io::Cursor::new(bytes))
        .with_guessed_format()
        .map_err(|error| ProcessingError::Invalid(error.to_string()))?;
    let format = reader
        .format()
        .filter(|format| {
            matches!(
                format,
                ImageFormat::Jpeg | ImageFormat::Png | ImageFormat::WebP | ImageFormat::Tiff
            )
        })
        .ok_or_else(|| {
            ProcessingError::Unsupported("overlay bytes are not JPEG, PNG, WebP or TIFF".into())
        })?;
    let mut decoder = reader
        .into_decoder()
        .map_err(|error| ProcessingError::Invalid(error.to_string()))?;
    let (width, height) = decoder.dimensions();
    checked_allocation(width, height, budget)?;
    let orientation = decoder
        .orientation()
        .map_err(|error| ProcessingError::Invalid(error.to_string()))?;
    let mut image = DynamicImage::from_decoder(decoder)
        .map_err(|error| ProcessingError::Invalid(error.to_string()))?;
    image.apply_orientation(orientation);
    let _ = format;
    Ok(image)
}

fn rotate_overlay(
    image: &DynamicImage,
    degrees: f32,
    budget: u64,
) -> Result<DynamicImage, ProcessingError> {
    if degrees.abs() < f32::EPSILON {
        return Ok(image.clone());
    }
    let source = image.to_rgba16();
    let radians = degrees.to_radians();
    let (sin, cos) = radians.sin_cos();
    let width = source.width() as f32;
    let height = source.height() as f32;
    let output_width = (width * cos.abs() + height * sin.abs()).ceil() as u32;
    let output_height = (width * sin.abs() + height * cos.abs()).ceil() as u32;
    checked_allocation(output_width, output_height, budget)?;
    let mut output = ImageBuffer::from_pixel(output_width, output_height, Rgba([0_u16; 4]));
    let source_center = ((width - 1.0) / 2.0, (height - 1.0) / 2.0);
    let output_center = (
        (output_width as f32 - 1.0) / 2.0,
        (output_height as f32 - 1.0) / 2.0,
    );
    for y in 0..output_height {
        for x in 0..output_width {
            let dx = x as f32 - output_center.0;
            let dy = y as f32 - output_center.1;
            let source_x = cos * dx + sin * dy + source_center.0;
            let source_y = -sin * dx + cos * dy + source_center.1;
            if source_x < 0.0 || source_y < 0.0 || source_x > width - 1.0 || source_y > height - 1.0
            {
                continue;
            }
            let x0 = source_x.floor() as u32;
            let y0 = source_y.floor() as u32;
            let x1 = (x0 + 1).min(source.width() - 1);
            let y1 = (y0 + 1).min(source.height() - 1);
            let fx = source_x - x0 as f32;
            let fy = source_y - y0 as f32;
            let samples = [
                source.get_pixel(x0, y0),
                source.get_pixel(x1, y0),
                source.get_pixel(x0, y1),
                source.get_pixel(x1, y1),
            ];
            let mut channels = [0_u16; 4];
            for (channel, value) in channels.iter_mut().enumerate() {
                let top =
                    samples[0].0[channel] as f32 * (1.0 - fx) + samples[1].0[channel] as f32 * fx;
                let bottom =
                    samples[2].0[channel] as f32 * (1.0 - fx) + samples[3].0[channel] as f32 * fx;
                *value = (top * (1.0 - fy) + bottom * fy).round() as u16;
            }
            output.put_pixel(x, y, Rgba(channels));
        }
    }
    Ok(DynamicImage::ImageRgba16(output))
}

#[allow(clippy::too_many_arguments)]
fn render_image_overlay(
    base: &DynamicImage,
    data_uri: &str,
    width: u32,
    height: u32,
    rotation_degrees: f32,
    x: i32,
    y: i32,
    budget: u64,
) -> Result<DynamicImage, ProcessingError> {
    let decoded = decode_embedded_overlay(data_uri, budget)?;
    let resized = decoded.resize_exact(width, height, FilterType::Lanczos3);
    let rotated = rotate_overlay(&resized, rotation_degrees, budget)?.to_rgba16();
    let mut canvas = ImageBuffer::from_pixel(base.width(), base.height(), Rgba([0_u16; 4]));
    for (overlay_x, overlay_y, pixel) in rotated.enumerate_pixels() {
        let destination_x = i64::from(x) + i64::from(overlay_x);
        let destination_y = i64::from(y) + i64::from(overlay_y);
        if destination_x >= 0
            && destination_y >= 0
            && destination_x < i64::from(base.width())
            && destination_y < i64::from(base.height())
        {
            canvas.put_pixel(destination_x as u32, destination_y as u32, *pixel);
        }
    }
    Ok(DynamicImage::ImageRgba16(canvas))
}

fn srgb_to_linear(value: f32) -> f32 {
    if value <= 0.04045 {
        value / 12.92
    } else {
        ((value + 0.055) / 1.055).powf(2.4)
    }
}

fn linear_to_srgb(value: f32) -> f32 {
    let value = value.clamp(0.0, 1.0);
    if value <= 0.003_130_8 {
        value * 12.92
    } else {
        1.055 * value.powf(1.0 / 2.4) - 0.055
    }
}

fn apply_darkroom(
    image: &DynamicImage,
    settings: DarkroomSettings,
) -> Result<DynamicImage, ProcessingError> {
    settings.validate()?;
    if settings.is_neutral() {
        return Ok(image.clone());
    }
    let mut output = image.to_rgba16();
    let exposure = 2.0_f32.powf(settings.exposure);
    for pixel in output.pixels_mut() {
        let alpha = pixel.0[3];
        let mut rgb = [0.0_f32; 3];
        for (index, channel) in rgb.iter_mut().enumerate() {
            *channel = srgb_to_linear(pixel.0[index] as f32 / 65535.0) * exposure;
        }
        rgb[0] *= 1.0 + settings.temperature * 0.20 + settings.tint * 0.075;
        rgb[1] *= 1.0 - settings.tint * 0.15;
        rgb[2] *= 1.0 - settings.temperature * 0.20 + settings.tint * 0.075;
        let luminance = (rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722).clamp(0.0, 1.0);
        let tonal = settings.shadows * (1.0 - luminance).powi(2) * 0.25
            + settings.highlights * luminance.powi(2) * 0.25
            + settings.blacks * (1.0 - luminance).powi(6) * 0.12
            + settings.whites * luminance.powi(6) * 0.12;
        for channel in &mut rgb {
            *channel = ((*channel + tonal - 0.18) * (1.0 + settings.contrast) + 0.18).max(0.0);
        }
        let luminance = rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
        let maximum = rgb.iter().copied().fold(f32::NEG_INFINITY, f32::max);
        let minimum = rgb.iter().copied().fold(f32::INFINITY, f32::min);
        let chroma = (maximum - minimum).clamp(0.0, 1.0);
        let saturation = (1.0 + settings.saturation) * (1.0 + settings.vibrance * (1.0 - chroma));
        for (index, channel) in rgb.iter().enumerate() {
            pixel.0[index] = (linear_to_srgb(luminance + (*channel - luminance) * saturation)
                * 65535.0)
                .round() as u16;
        }
        pixel.0[3] = alpha;
    }
    Ok(DynamicImage::ImageRgba16(output))
}

fn apply_levels(
    image: &DynamicImage,
    settings: LevelsSettings,
) -> Result<DynamicImage, ProcessingError> {
    let values = [
        settings.black,
        settings.gamma,
        settings.white,
        settings.output_black,
        settings.output_white,
    ];
    if values.iter().any(|value| !value.is_finite())
        || settings.black < 0.0
        || settings.white > 1.0
        || settings.black >= settings.white
        || !(0.1..=10.0).contains(&settings.gamma)
        || settings.output_black < 0.0
        || settings.output_white > 1.0
        || settings.output_black > settings.output_white
    {
        return Err(ProcessingError::Invalid("invalid levels settings".into()));
    }
    if settings.black == 0.0
        && settings.gamma == 1.0
        && settings.white == 1.0
        && settings.output_black == 0.0
        && settings.output_white == 1.0
    {
        return Ok(image.clone());
    }
    let mut output = image.to_rgba16();
    for pixel in output.pixels_mut() {
        for channel in &mut pixel.0[..3] {
            let linear = srgb_to_linear(*channel as f32 / 65535.0);
            let normalized = ((linear - settings.black) / (settings.white - settings.black))
                .clamp(0.0, 1.0)
                .powf(1.0 / settings.gamma);
            let mapped = settings.output_black
                + normalized * (settings.output_white - settings.output_black);
            *channel = (linear_to_srgb(mapped) * 65535.0).round() as u16;
        }
    }
    Ok(DynamicImage::ImageRgba16(output))
}

fn apply_black_and_white(
    image: &DynamicImage,
    red: f32,
    green: f32,
    blue: f32,
) -> Result<DynamicImage, ProcessingError> {
    if [red, green, blue]
        .iter()
        .any(|value| !value.is_finite() || !(0.0..=2.0).contains(value))
        || red + green + blue <= f32::EPSILON
    {
        return Err(ProcessingError::Invalid(
            "B&W channel weights must be between 0 and 2".into(),
        ));
    }
    let sum = red + green + blue;
    let mut output = image.to_rgba16();
    for pixel in output.pixels_mut() {
        let grey = (srgb_to_linear(pixel.0[0] as f32 / 65535.0) * red
            + srgb_to_linear(pixel.0[1] as f32 / 65535.0) * green
            + srgb_to_linear(pixel.0[2] as f32 / 65535.0) * blue)
            / sum;
        let encoded = (linear_to_srgb(grey) * 65535.0).round() as u16;
        pixel.0[0] = encoded;
        pixel.0[1] = encoded;
        pixel.0[2] = encoded;
    }
    Ok(DynamicImage::ImageRgba16(output))
}

fn smoothstep(edge0: f32, edge1: f32, value: f32) -> f32 {
    let t = ((value - edge0) / (edge1 - edge0)).clamp(0.0, 1.0);
    t * t * (3.0 - 2.0 * t)
}

fn apply_vignette(
    image: &DynamicImage,
    amount: f32,
    midpoint: f32,
    feather: f32,
) -> Result<DynamicImage, ProcessingError> {
    if !amount.is_finite()
        || !(-1.0..=1.0).contains(&amount)
        || !midpoint.is_finite()
        || !(0.0..=1.0).contains(&midpoint)
        || !feather.is_finite()
        || !(0.01..=1.0).contains(&feather)
    {
        return Err(ProcessingError::Invalid("invalid vignette settings".into()));
    }
    if amount.abs() < f32::EPSILON {
        return Ok(image.clone());
    }
    let mut output = image.to_rgba16();
    let (width, height) = output.dimensions();
    let cx = (width.saturating_sub(1)) as f32 / 2.0;
    let cy = (height.saturating_sub(1)) as f32 / 2.0;
    for (x, y, pixel) in output.enumerate_pixels_mut() {
        let dx = if cx > 0.0 { (x as f32 - cx) / cx } else { 0.0 };
        let dy = if cy > 0.0 { (y as f32 - cy) / cy } else { 0.0 };
        let distance = (dx * dx + dy * dy).sqrt() / 2.0_f32.sqrt();
        let mask = smoothstep(midpoint, (midpoint + feather).min(1.0001), distance);
        for channel in &mut pixel.0[..3] {
            let linear = srgb_to_linear(*channel as f32 / 65535.0);
            *channel = (linear_to_srgb((linear * (1.0 - amount * mask)).clamp(0.0, 1.0)) * 65535.0)
                .round() as u16;
        }
    }
    Ok(DynamicImage::ImageRgba16(output))
}

fn linear_rgb(pixel: &Rgba<u16>) -> [f32; 3] {
    [
        srgb_to_linear(pixel.0[0] as f32 / 65535.0),
        srgb_to_linear(pixel.0[1] as f32 / 65535.0),
        srgb_to_linear(pixel.0[2] as f32 / 65535.0),
    ]
}

fn neighbourhood_average(source: &ImageBuffer<Rgba<u16>, Vec<u16>>, x: u32, y: u32) -> [f32; 3] {
    let mut total = [0.0; 3];
    let mut count = 0.0;
    for sample_y in y.saturating_sub(1)..=(y + 1).min(source.height() - 1) {
        for sample_x in x.saturating_sub(1)..=(x + 1).min(source.width() - 1) {
            let sample = linear_rgb(source.get_pixel(sample_x, sample_y));
            for channel in 0..3 {
                total[channel] += sample[channel];
            }
            count += 1.0;
        }
    }
    for channel in &mut total {
        *channel /= count;
    }
    total
}

fn apply_sharpen(
    image: &DynamicImage,
    amount: f32,
    threshold: f32,
) -> Result<DynamicImage, ProcessingError> {
    if !amount.is_finite()
        || !(0.0..=2.0).contains(&amount)
        || !threshold.is_finite()
        || !(0.0..=1.0).contains(&threshold)
    {
        return Err(ProcessingError::Invalid(
            "invalid sharpening settings".into(),
        ));
    }
    if amount == 0.0 {
        return Ok(image.clone());
    }
    let source = image.to_rgba16();
    let mut output = source.clone();
    for (x, y, pixel) in output.enumerate_pixels_mut() {
        let center = linear_rgb(source.get_pixel(x, y));
        let average = neighbourhood_average(&source, x, y);
        for channel in 0..3 {
            let detail = center[channel] - average[channel];
            let value = if detail.abs() >= threshold {
                center[channel] + detail * amount
            } else {
                center[channel]
            };
            pixel.0[channel] = (linear_to_srgb(value) * 65535.0).round() as u16;
        }
    }
    Ok(DynamicImage::ImageRgba16(output))
}

fn apply_noise_reduction(
    image: &DynamicImage,
    luminance: f32,
    chroma: f32,
) -> Result<DynamicImage, ProcessingError> {
    if [luminance, chroma]
        .iter()
        .any(|value| !value.is_finite() || !(0.0..=1.0).contains(value))
    {
        return Err(ProcessingError::Invalid(
            "invalid noise-reduction settings".into(),
        ));
    }
    if luminance == 0.0 && chroma == 0.0 {
        return Ok(image.clone());
    }
    let source = image.to_rgba16();
    let mut output = source.clone();
    for (x, y, pixel) in output.enumerate_pixels_mut() {
        let center = linear_rgb(source.get_pixel(x, y));
        let average = neighbourhood_average(&source, x, y);
        let center_luma = center[0] * 0.2126 + center[1] * 0.7152 + center[2] * 0.0722;
        let average_luma = average[0] * 0.2126 + average[1] * 0.7152 + average[2] * 0.0722;
        let new_luma = center_luma + (average_luma - center_luma) * luminance;
        for channel in 0..3 {
            let center_chroma = center[channel] - center_luma;
            let average_chroma = average[channel] - average_luma;
            let value = new_luma + center_chroma * (1.0 - chroma) + average_chroma * chroma;
            pixel.0[channel] = (linear_to_srgb(value) * 65535.0).round() as u16;
        }
    }
    Ok(DynamicImage::ImageRgba16(output))
}

fn apply_local_contrast(
    image: &DynamicImage,
    amount: f32,
) -> Result<DynamicImage, ProcessingError> {
    if !amount.is_finite() || !(0.0..=2.0).contains(&amount) {
        return Err(ProcessingError::Invalid(
            "local contrast must be between 0 and 2".into(),
        ));
    }
    if amount == 0.0 {
        return Ok(image.clone());
    }
    let source = image.to_rgba16();
    let mut output = source.clone();
    for (x, y, pixel) in output.enumerate_pixels_mut() {
        let center = linear_rgb(source.get_pixel(x, y));
        let average = neighbourhood_average(&source, x, y);
        let center_luma = center[0] * 0.2126 + center[1] * 0.7152 + center[2] * 0.0722;
        let average_luma = average[0] * 0.2126 + average[1] * 0.7152 + average[2] * 0.0722;
        let adjusted_luma = (center_luma + (center_luma - average_luma) * amount).max(0.0);
        let scale = if center_luma > 0.000_001 {
            adjusted_luma / center_luma
        } else {
            1.0
        };
        for (channel, value) in center.iter().enumerate() {
            pixel.0[channel] = (linear_to_srgb(*value * scale) * 65535.0).round() as u16;
        }
    }
    Ok(DynamicImage::ImageRgba16(output))
}

fn apply_haze_reduction(
    image: &DynamicImage,
    amount: f32,
) -> Result<DynamicImage, ProcessingError> {
    if !amount.is_finite() || !(-1.0..=1.0).contains(&amount) {
        return Err(ProcessingError::Invalid(
            "haze reduction must be between -1 and 1".into(),
        ));
    }
    if amount == 0.0 {
        return Ok(image.clone());
    }
    let mut output = image.to_rgba16();
    let floor = output
        .pixels()
        .map(|pixel| {
            let rgb = linear_rgb(pixel);
            rgb[0].min(rgb[1]).min(rgb[2])
        })
        .fold(1.0_f32, f32::min);
    let removal = floor.min(0.45) * amount.max(0.0);
    let addition = 0.15 * (-amount).max(0.0);
    for pixel in output.pixels_mut() {
        for channel in &mut pixel.0[..3] {
            let linear = srgb_to_linear(*channel as f32 / 65535.0);
            let corrected = if amount >= 0.0 {
                (linear - removal) / (1.0 - removal).max(0.01)
            } else {
                linear * (1.0 - addition) + addition
            };
            *channel = (linear_to_srgb(corrected) * 65535.0).round() as u16;
        }
    }
    Ok(DynamicImage::ImageRgba16(output))
}

fn apply_tone_curve(
    image: &DynamicImage,
    shadows: f32,
    midtones: f32,
    highlights: f32,
) -> Result<DynamicImage, ProcessingError> {
    if [shadows, midtones, highlights]
        .iter()
        .any(|value| !value.is_finite() || !(-1.0..=1.0).contains(value))
    {
        return Err(ProcessingError::Invalid(
            "tone-curve regions must be between -1 and 1".into(),
        ));
    }
    if shadows == 0.0 && midtones == 0.0 && highlights == 0.0 {
        return Ok(image.clone());
    }
    let mut output = image.to_rgba16();
    for pixel in output.pixels_mut() {
        for channel in &mut pixel.0[..3] {
            let value = srgb_to_linear(*channel as f32 / 65535.0);
            let offset = shadows * (1.0 - value).powi(2) * 0.20
                + midtones * (4.0 * value * (1.0 - value)) * 0.20
                + highlights * value.powi(2) * 0.20;
            *channel = (linear_to_srgb(value + offset) * 65535.0).round() as u16;
        }
    }
    Ok(DynamicImage::ImageRgba16(output))
}

fn rgb_to_hsl(rgb: [f32; 3]) -> [f32; 3] {
    let maximum = rgb.iter().copied().fold(f32::NEG_INFINITY, f32::max);
    let minimum = rgb.iter().copied().fold(f32::INFINITY, f32::min);
    let lightness = (maximum + minimum) / 2.0;
    let delta = maximum - minimum;
    if delta < f32::EPSILON {
        return [0.0, 0.0, lightness];
    }
    let saturation = delta / (1.0 - (2.0 * lightness - 1.0).abs()).max(f32::EPSILON);
    let hue = if maximum == rgb[0] {
        ((rgb[1] - rgb[2]) / delta).rem_euclid(6.0)
    } else if maximum == rgb[1] {
        (rgb[2] - rgb[0]) / delta + 2.0
    } else {
        (rgb[0] - rgb[1]) / delta + 4.0
    } / 6.0;
    [hue, saturation, lightness]
}

fn hue_channel(p: f32, q: f32, t: f32) -> f32 {
    let t = t.rem_euclid(1.0);
    if t < 1.0 / 6.0 {
        p + (q - p) * 6.0 * t
    } else if t < 0.5 {
        q
    } else if t < 2.0 / 3.0 {
        p + (q - p) * (2.0 / 3.0 - t) * 6.0
    } else {
        p
    }
}

fn hsl_to_rgb(hsl: [f32; 3]) -> [f32; 3] {
    if hsl[1] < f32::EPSILON {
        return [hsl[2]; 3];
    }
    let q = if hsl[2] < 0.5 {
        hsl[2] * (1.0 + hsl[1])
    } else {
        hsl[2] + hsl[1] - hsl[2] * hsl[1]
    };
    let p = 2.0 * hsl[2] - q;
    [
        hue_channel(p, q, hsl[0] + 1.0 / 3.0),
        hue_channel(p, q, hsl[0]),
        hue_channel(p, q, hsl[0] - 1.0 / 3.0),
    ]
}

fn apply_hsl(
    image: &DynamicImage,
    hue_degrees: f32,
    saturation: f32,
    lightness: f32,
) -> Result<DynamicImage, ProcessingError> {
    if !hue_degrees.is_finite()
        || !(-180.0..=180.0).contains(&hue_degrees)
        || [saturation, lightness]
            .iter()
            .any(|value| !value.is_finite() || !(-1.0..=1.0).contains(value))
    {
        return Err(ProcessingError::Invalid("invalid HSL settings".into()));
    }
    if hue_degrees == 0.0 && saturation == 0.0 && lightness == 0.0 {
        return Ok(image.clone());
    }
    let mut output = image.to_rgba16();
    for pixel in output.pixels_mut() {
        let rgb = [
            pixel.0[0] as f32 / 65535.0,
            pixel.0[1] as f32 / 65535.0,
            pixel.0[2] as f32 / 65535.0,
        ];
        let mut hsl = rgb_to_hsl(rgb);
        hsl[0] = (hsl[0] + hue_degrees / 360.0).rem_euclid(1.0);
        hsl[1] = (hsl[1] + saturation).clamp(0.0, 1.0);
        hsl[2] = (hsl[2] + lightness).clamp(0.0, 1.0);
        let adjusted = hsl_to_rgb(hsl);
        for (channel, value) in adjusted.iter().enumerate() {
            pixel.0[channel] = (*value * 65535.0).round() as u16;
        }
    }
    Ok(DynamicImage::ImageRgba16(output))
}

fn straighten(
    image: &DynamicImage,
    degrees: f32,
    budget: u64,
) -> Result<DynamicImage, ProcessingError> {
    if !degrees.is_finite() || !(-45.0..=45.0).contains(&degrees) {
        return Err(ProcessingError::Invalid(
            "straighten angle must be between -45 and 45 degrees".into(),
        ));
    }
    if degrees.abs() < f32::EPSILON {
        return Ok(image.clone());
    }
    let source = image.to_rgba16();
    let (width, height) = source.dimensions();
    checked_allocation(width, height, budget)?;
    let mut output = ImageBuffer::from_pixel(width, height, Rgba([0_u16; 4]));
    let radians = -degrees.to_radians();
    let (sin, cos) = radians.sin_cos();
    let center_x = (width as f32 - 1.0) / 2.0;
    let center_y = (height as f32 - 1.0) / 2.0;
    for y in 0..height {
        for x in 0..width {
            let dx = x as f32 - center_x;
            let dy = y as f32 - center_y;
            let source_x = cos * dx - sin * dy + center_x;
            let source_y = sin * dx + cos * dy + center_y;
            if source_x < 0.0
                || source_y < 0.0
                || source_x > (width - 1) as f32
                || source_y > (height - 1) as f32
            {
                continue;
            }
            let x0 = source_x.floor() as u32;
            let y0 = source_y.floor() as u32;
            let x1 = (x0 + 1).min(width - 1);
            let y1 = (y0 + 1).min(height - 1);
            let fx = source_x - x0 as f32;
            let fy = source_y - y0 as f32;
            let samples = [
                source.get_pixel(x0, y0),
                source.get_pixel(x1, y0),
                source.get_pixel(x0, y1),
                source.get_pixel(x1, y1),
            ];
            let mut channels = [0_u16; 4];
            for (channel, output_channel) in channels.iter_mut().enumerate() {
                let top =
                    samples[0].0[channel] as f32 * (1.0 - fx) + samples[1].0[channel] as f32 * fx;
                let bottom =
                    samples[2].0[channel] as f32 * (1.0 - fx) + samples[3].0[channel] as f32 * fx;
                *output_channel =
                    (top * (1.0 - fy) + bottom * fy).round().clamp(0.0, 65535.0) as u16;
            }
            output.put_pixel(x, y, Rgba(channels));
        }
    }
    Ok(DynamicImage::ImageRgba16(output))
}

fn sample_channel(image: &ImageBuffer<Rgba<u16>, Vec<u16>>, x: f32, y: f32, channel: usize) -> u16 {
    if x < 0.0 || y < 0.0 || x > (image.width() - 1) as f32 || y > (image.height() - 1) as f32 {
        return 0;
    }
    let x0 = x.floor() as u32;
    let y0 = y.floor() as u32;
    let x1 = (x0 + 1).min(image.width() - 1);
    let y1 = (y0 + 1).min(image.height() - 1);
    let fx = x - x0 as f32;
    let fy = y - y0 as f32;
    let top = image.get_pixel(x0, y0).0[channel] as f32 * (1.0 - fx)
        + image.get_pixel(x1, y0).0[channel] as f32 * fx;
    let bottom = image.get_pixel(x0, y1).0[channel] as f32 * (1.0 - fx)
        + image.get_pixel(x1, y1).0[channel] as f32 * fx;
    (top * (1.0 - fy) + bottom * fy).round().clamp(0.0, 65535.0) as u16
}

fn apply_lens_correction(
    image: &DynamicImage,
    distortion: f32,
    red_shift: f32,
    blue_shift: f32,
    budget: u64,
) -> Result<DynamicImage, ProcessingError> {
    if [distortion, red_shift, blue_shift]
        .iter()
        .any(|value| !value.is_finite() || !(-1.0..=1.0).contains(value))
    {
        return Err(ProcessingError::Invalid(
            "optics controls must be between -1 and 1".into(),
        ));
    }
    if distortion == 0.0 && red_shift == 0.0 && blue_shift == 0.0 {
        return Ok(image.clone());
    }
    let source = image.to_rgba16();
    let (width, height) = source.dimensions();
    checked_allocation(width, height, budget)?;
    let mut output = ImageBuffer::from_pixel(width, height, Rgba([0_u16; 4]));
    let center_x = (width as f32 - 1.0) / 2.0;
    let center_y = (height as f32 - 1.0) / 2.0;
    let scale_x = center_x.max(1.0);
    let scale_y = center_y.max(1.0);
    for y in 0..height {
        for x in 0..width {
            let nx = (x as f32 - center_x) / scale_x;
            let ny = (y as f32 - center_y) / scale_y;
            let radius_squared = nx * nx + ny * ny;
            let base_factor = 1.0 + distortion * radius_squared * 0.35;
            let coordinates = |shift: f32| {
                let factor = base_factor + shift * radius_squared * 0.02;
                (
                    center_x + nx * factor * scale_x,
                    center_y + ny * factor * scale_y,
                )
            };
            let green = coordinates(0.0);
            let red = coordinates(red_shift);
            let blue = coordinates(blue_shift);
            output.put_pixel(
                x,
                y,
                Rgba([
                    sample_channel(&source, red.0, red.1, 0),
                    sample_channel(&source, green.0, green.1, 1),
                    sample_channel(&source, blue.0, blue.1, 2),
                    sample_channel(&source, green.0, green.1, 3),
                ]),
            );
        }
    }
    Ok(DynamicImage::ImageRgba16(output))
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

pub fn replace_json_atomic<T: Serialize>(
    value: &T,
    destination: &Path,
) -> Result<(), ProcessingError> {
    let temporary = temp_path(destination)?;
    let backup = destination.with_extension(format!(
        "{}bak",
        destination
            .extension()
            .and_then(|v| v.to_str())
            .map_or(String::new(), |v| format!("{v}."))
    ));
    if temporary.exists() {
        fs::remove_file(&temporary).map_err(|e| ProcessingError::Io(e.to_string()))?;
    }
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
        if backup.exists() {
            fs::remove_file(&backup).map_err(|e| ProcessingError::Io(e.to_string()))?;
        }
        fs::rename(destination, &backup).map_err(|e| ProcessingError::Io(e.to_string()))?;
    }
    if let Err(error) = fs::rename(&temporary, destination) {
        if backup.exists() {
            let _ = fs::rename(&backup, destination);
        }
        return Err(ProcessingError::Io(error.to_string()));
    }
    Ok(())
}

// ===== SNAPSMACK EOF =====
