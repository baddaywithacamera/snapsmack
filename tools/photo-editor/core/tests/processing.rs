//! Packet 02 processing acceptance tests.
//! SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical Rust marker.

use image::{DynamicImage, ImageBuffer, ImageFormat, Rgba, RgbaImage};
use snapsmack_photo_core::processing::{
    apply, histogram, load, preview, preview_data_uri, replace_json_atomic, save_atomic, BlendMode,
    DarkroomSettings, FontFamily, LevelsSettings, Operation, ProcessingError, TextAlignment,
    DEFAULT_MEMORY_BUDGET,
};
use std::fs;

fn fixture() -> DynamicImage {
    DynamicImage::ImageRgba8(ImageBuffer::from_fn(8, 6, |x, y| {
        Rgba([
            (x * 20) as u8,
            (y * 30) as u8,
            ((x + y) * 10) as u8,
            if x == 0 { 0 } else { 255 },
        ])
    }))
}

#[test]
fn supported_formats_round_trip_and_report_metadata() {
    let directory = tempfile::tempdir().unwrap();
    for (name, format) in [
        ("a.jpg", ImageFormat::Jpeg),
        ("a.png", ImageFormat::Png),
        ("a.webp", ImageFormat::WebP),
        ("a.tiff", ImageFormat::Tiff),
    ] {
        let path = directory.path().join(name);
        fixture().to_rgb8().save_with_format(&path, format).unwrap();
        let loaded = load(&path, DEFAULT_MEMORY_BUDGET).unwrap();
        assert_eq!((loaded.info.width, loaded.info.height), (8, 6));
    }
}

#[test]
fn sixteen_bit_tiff_and_alpha_are_preserved_on_import() {
    let directory = tempfile::tempdir().unwrap();
    let tiff = directory.path().join("sixteen.tiff");
    let pixels = ImageBuffer::from_pixel(3, 2, image::Rgba([1024_u16, 2048, 4096, 32768]));
    DynamicImage::ImageRgba16(pixels)
        .save_with_format(&tiff, ImageFormat::Tiff)
        .unwrap();
    let loaded = load(&tiff, DEFAULT_MEMORY_BUDGET).unwrap();
    assert!(matches!(loaded.pixels, DynamicImage::ImageRgba16(_)));
    assert_eq!(loaded.pixels.to_rgba16().get_pixel(0, 0).0[3], 32768);
}

#[test]
fn recipe_geometry_is_deterministic_and_preview_is_bounded() {
    let operations = [
        Operation::Crop {
            x: 1,
            y: 1,
            width: 6,
            height: 4,
        },
        Operation::Rotate90,
        Operation::FlipHorizontal,
        Operation::Resize {
            width: 20,
            height: 30,
        },
    ];
    let first = apply(fixture(), &operations, DEFAULT_MEMORY_BUDGET).unwrap();
    let second = apply(fixture(), &operations, DEFAULT_MEMORY_BUDGET).unwrap();
    assert_eq!(first.to_rgba8(), second.to_rgba8());
    assert_eq!((first.width(), first.height()), (20, 30));
    let proxy = preview(&first, 12).unwrap();
    assert!(proxy.width().max(proxy.height()) <= 12);
}

#[test]
fn histogram_counts_every_pixel_and_clipping() {
    let image = DynamicImage::ImageRgba8(
        RgbaImage::from_vec(2, 1, vec![0, 0, 0, 255, 255, 255, 255, 128]).unwrap(),
    );
    let values = histogram(&image);
    assert_eq!(values.red.iter().sum::<u64>(), 2);
    assert_eq!(values.alpha[128], 1);
    assert_eq!(values.shadows_clipped, 1);
    assert_eq!(values.highlights_clipped, 1);
}

#[test]
fn corrupt_input_memory_refusal_and_existing_destination_fail_closed() {
    let directory = tempfile::tempdir().unwrap();
    let corrupt = directory.path().join("broken.png");
    fs::write(&corrupt, b"\x89PNG\r\n\x1a\ntruncated").unwrap();
    assert!(load(&corrupt, DEFAULT_MEMORY_BUDGET).is_err());

    let valid = directory.path().join("valid.png");
    fixture().save(&valid).unwrap();
    assert!(matches!(
        load(&valid, 1),
        Err(ProcessingError::MemoryRefused { .. })
    ));

    let output = directory.path().join("keep.png");
    fs::write(&output, b"original").unwrap();
    assert!(save_atomic(&fixture(), &output, ImageFormat::Png).is_err());
    assert_eq!(fs::read(output).unwrap(), b"original");
}

#[test]
fn straighten_is_deterministic_and_preserves_sixteen_bit_precision() {
    let source = DynamicImage::ImageRgba16(ImageBuffer::from_fn(7, 5, |x, y| {
        image::Rgba([(x * 7000) as u16, (y * 9000) as u16, 12345, 65535])
    }));
    let recipe = [Operation::Straighten { degrees: 3.25 }];
    let first = apply(source.clone(), &recipe, DEFAULT_MEMORY_BUDGET).unwrap();
    let second = apply(source, &recipe, DEFAULT_MEMORY_BUDGET).unwrap();
    assert!(matches!(first, DynamicImage::ImageRgba16(_)));
    assert_eq!(first.to_rgba16(), second.to_rgba16());
    assert!(apply(
        fixture(),
        &[Operation::Straighten { degrees: 46.0 }],
        DEFAULT_MEMORY_BUDGET
    )
    .is_err());
}

#[test]
fn autosave_replacement_keeps_a_recovery_backup() {
    let directory = tempfile::tempdir().unwrap();
    let path = directory.path().join("autosave.json");
    replace_json_atomic(&serde_json::json!({"revision": 1}), &path).unwrap();
    replace_json_atomic(&serde_json::json!({"revision": 2}), &path).unwrap();
    assert_eq!(
        serde_json::from_slice::<serde_json::Value>(&fs::read(&path).unwrap()).unwrap()["revision"],
        2
    );
    let backup = directory.path().join("autosave.json.bak");
    assert_eq!(
        serde_json::from_slice::<serde_json::Value>(&fs::read(backup).unwrap()).unwrap()
            ["revision"],
        1
    );
}

#[test]
fn neutral_darkroom_is_identity_and_adjustments_are_deterministic() {
    let source = fixture();
    let neutral = [Operation::Darkroom {
        settings: DarkroomSettings::default(),
    }];
    assert_eq!(
        apply(source.clone(), &neutral, DEFAULT_MEMORY_BUDGET)
            .unwrap()
            .to_rgba8(),
        source.to_rgba8()
    );
    let settings = DarkroomSettings {
        exposure: 0.75,
        contrast: 0.2,
        shadows: 0.3,
        highlights: -0.2,
        temperature: 0.15,
        saturation: 0.25,
        vibrance: 0.2,
        ..DarkroomSettings::default()
    };
    let recipe = [Operation::Darkroom { settings }];
    let first = apply(source.clone(), &recipe, DEFAULT_MEMORY_BUDGET).unwrap();
    let second = apply(source, &recipe, DEFAULT_MEMORY_BUDGET).unwrap();
    assert_eq!(first.to_rgba16(), second.to_rgba16());
    assert!(apply(
        fixture(),
        &[Operation::Darkroom {
            settings: DarkroomSettings {
                exposure: 5.1,
                ..DarkroomSettings::default()
            },
        }],
        DEFAULT_MEMORY_BUDGET
    )
    .is_err());
}

#[test]
fn finishing_controls_have_identity_guards_and_preserve_alpha() {
    let source = fixture();
    let neutral = [
        Operation::Levels {
            settings: LevelsSettings::default(),
        },
        Operation::Vignette {
            amount: 0.0,
            midpoint: 0.5,
            feather: 0.5,
        },
        Operation::Sharpen {
            amount: 0.0,
            threshold: 0.0,
        },
        Operation::NoiseReduction {
            luminance: 0.0,
            chroma: 0.0,
        },
    ];
    assert_eq!(
        apply(source.clone(), &neutral, DEFAULT_MEMORY_BUDGET)
            .unwrap()
            .to_rgba8(),
        source.to_rgba8()
    );
    let recipe = [
        Operation::Levels {
            settings: LevelsSettings {
                black: 0.02,
                gamma: 1.1,
                white: 0.95,
                ..LevelsSettings::default()
            },
        },
        Operation::BlackAndWhite {
            red: 0.3,
            green: 0.6,
            blue: 0.1,
        },
        Operation::Vignette {
            amount: 0.4,
            midpoint: 0.35,
            feather: 0.5,
        },
        Operation::Sharpen {
            amount: 0.5,
            threshold: 0.01,
        },
        Operation::NoiseReduction {
            luminance: 0.2,
            chroma: 0.4,
        },
    ];
    let first = apply(source.clone(), &recipe, DEFAULT_MEMORY_BUDGET).unwrap();
    let second = apply(source, &recipe, DEFAULT_MEMORY_BUDGET).unwrap();
    assert_eq!(first.to_rgba16(), second.to_rgba16());
    assert_eq!(first.to_rgba16().get_pixel(0, 0).0[3], 0);
}

#[test]
fn finishing_controls_reject_unsafe_ranges() {
    assert!(apply(
        fixture(),
        &[Operation::Levels {
            settings: LevelsSettings {
                black: 0.8,
                white: 0.2,
                ..LevelsSettings::default()
            }
        }],
        DEFAULT_MEMORY_BUDGET
    )
    .is_err());
    assert!(apply(
        fixture(),
        &[Operation::BlackAndWhite {
            red: 0.0,
            green: 0.0,
            blue: 0.0
        }],
        DEFAULT_MEMORY_BUDGET
    )
    .is_err());
    assert!(apply(
        fixture(),
        &[Operation::Vignette {
            amount: 2.0,
            midpoint: 0.5,
            feather: 0.5
        }],
        DEFAULT_MEMORY_BUDGET
    )
    .is_err());
    assert!(apply(
        fixture(),
        &[Operation::Sharpen {
            amount: 3.0,
            threshold: 0.0
        }],
        DEFAULT_MEMORY_BUDGET
    )
    .is_err());
    assert!(apply(
        fixture(),
        &[Operation::NoiseReduction {
            luminance: -0.1,
            chroma: 0.0
        }],
        DEFAULT_MEMORY_BUDGET
    )
    .is_err());
}

#[test]
fn creative_controls_are_bounded_deterministic_and_neutral_safe() {
    let source = fixture();
    let neutral = [
        Operation::LocalContrast { amount: 0.0 },
        Operation::HazeReduction { amount: 0.0 },
        Operation::ToneCurve {
            shadows: 0.0,
            midtones: 0.0,
            highlights: 0.0,
        },
        Operation::Hsl {
            hue_degrees: 0.0,
            saturation: 0.0,
            lightness: 0.0,
        },
    ];
    assert_eq!(
        apply(source.clone(), &neutral, DEFAULT_MEMORY_BUDGET)
            .unwrap()
            .to_rgba8(),
        source.to_rgba8()
    );
    let recipe = [
        Operation::LocalContrast { amount: 0.4 },
        Operation::HazeReduction { amount: 0.25 },
        Operation::ToneCurve {
            shadows: 0.1,
            midtones: 0.15,
            highlights: -0.05,
        },
        Operation::Hsl {
            hue_degrees: 12.0,
            saturation: 0.1,
            lightness: 0.02,
        },
    ];
    let first = apply(source.clone(), &recipe, DEFAULT_MEMORY_BUDGET).unwrap();
    let second = apply(source, &recipe, DEFAULT_MEMORY_BUDGET).unwrap();
    assert_eq!(first.to_rgba16(), second.to_rgba16());
    assert!(apply(
        fixture(),
        &[Operation::Hsl {
            hue_degrees: 181.0,
            saturation: 0.0,
            lightness: 0.0
        }],
        DEFAULT_MEMORY_BUDGET
    )
    .is_err());
}

#[test]
fn adjustment_layers_composite_hide_and_reject_geometry_changes() {
    let source = fixture();
    let hidden = [Operation::AdjustmentLayer {
        name: "Hidden".into(),
        visible: false,
        locked: false,
        opacity: 1.0,
        fill_opacity: 1.0,
        blend_mode: BlendMode::Normal,
        operations: vec![Operation::Hsl {
            hue_degrees: 90.0,
            saturation: 0.5,
            lightness: 0.0,
        }],
    }];
    assert_eq!(
        apply(source.clone(), &hidden, DEFAULT_MEMORY_BUDGET)
            .unwrap()
            .to_rgba8(),
        source.to_rgba8()
    );
    let layer = [Operation::AdjustmentLayer {
        name: "Punch".into(),
        visible: true,
        locked: false,
        opacity: 0.5,
        fill_opacity: 1.0,
        blend_mode: BlendMode::Overlay,
        operations: vec![Operation::Darkroom {
            settings: DarkroomSettings {
                contrast: 0.3,
                vibrance: 0.2,
                ..DarkroomSettings::default()
            },
        }],
    }];
    let first = apply(source.clone(), &layer, DEFAULT_MEMORY_BUDGET).unwrap();
    let second = apply(source.clone(), &layer, DEFAULT_MEMORY_BUDGET).unwrap();
    assert_eq!(first.to_rgba16(), second.to_rgba16());
    let geometry = [Operation::AdjustmentLayer {
        name: "Bad crop".into(),
        visible: true,
        locked: false,
        opacity: 1.0,
        fill_opacity: 1.0,
        blend_mode: BlendMode::Normal,
        operations: vec![Operation::Crop {
            x: 0,
            y: 0,
            width: 2,
            height: 2,
        }],
    }];
    assert!(apply(source, &geometry, DEFAULT_MEMORY_BUDGET).is_err());
}

#[test]
fn adjustment_layer_nesting_is_bounded() {
    let mut operation = Operation::Darkroom {
        settings: DarkroomSettings::default(),
    };
    for depth in 0..34 {
        operation = Operation::AdjustmentLayer {
            name: format!("Layer {depth}"),
            visible: true,
            locked: false,
            opacity: 1.0,
            fill_opacity: 1.0,
            blend_mode: BlendMode::Normal,
            operations: vec![operation],
        };
    }
    assert!(apply(fixture(), &[operation], DEFAULT_MEMORY_BUDGET).is_err());
}

#[test]
fn text_layers_render_deterministically_and_respect_visibility() {
    let source =
        DynamicImage::ImageRgba8(RgbaImage::from_pixel(320, 120, Rgba([255, 255, 255, 255])));
    let mut layer = Operation::TextLayer {
        name: "Caption".into(),
        visible: true,
        locked: false,
        opacity: 1.0,
        fill_opacity: 1.0,
        blend_mode: BlendMode::Normal,
        text: "PIXEL".into(),
        font_family: FontFamily::SansSerif,
        font_size: 48.0,
        color: [0, 0, 0, 255],
        x: 20,
        y: 20,
        alignment: TextAlignment::Left,
    };
    let first = apply(source.clone(), &[layer.clone()], DEFAULT_MEMORY_BUDGET).unwrap();
    let second = apply(source.clone(), &[layer.clone()], DEFAULT_MEMORY_BUDGET).unwrap();
    assert_eq!(first.to_rgba16(), second.to_rgba16());
    assert_ne!(first.to_rgba8(), source.to_rgba8());
    if let Operation::TextLayer { visible, .. } = &mut layer {
        *visible = false;
    }
    assert_eq!(
        apply(source.clone(), &[layer], DEFAULT_MEMORY_BUDGET)
            .unwrap()
            .to_rgba8(),
        source.to_rgba8()
    );
}

#[test]
fn embedded_image_overlays_transform_composite_and_validate() {
    let source = fixture();
    let overlay = DynamicImage::ImageRgba8(RgbaImage::from_pixel(3, 2, Rgba([255, 20, 10, 200])));
    let data_uri = preview_data_uri(&overlay, 16).unwrap();
    let mut layer = Operation::ImageOverlayLayer {
        name: "Watermark".into(),
        visible: true,
        locked: false,
        opacity: 0.7,
        fill_opacity: 1.0,
        blend_mode: BlendMode::Screen,
        original_name: "mark.png".into(),
        data_uri,
        width: 4,
        height: 3,
        rotation_degrees: 25.0,
        x: 2,
        y: -1,
    };
    let first = apply(source.clone(), &[layer.clone()], DEFAULT_MEMORY_BUDGET).unwrap();
    let second = apply(source.clone(), &[layer.clone()], DEFAULT_MEMORY_BUDGET).unwrap();
    assert_eq!(first.to_rgba16(), second.to_rgba16());
    assert_ne!(first.to_rgba8(), source.to_rgba8());
    if let Operation::ImageOverlayLayer { visible, .. } = &mut layer {
        *visible = false;
    }
    assert_eq!(
        apply(source.clone(), &[layer], DEFAULT_MEMORY_BUDGET)
            .unwrap()
            .to_rgba8(),
        source.to_rgba8()
    );

    let invalid = Operation::ImageOverlayLayer {
        name: "Bad".into(),
        visible: true,
        locked: false,
        opacity: 1.0,
        fill_opacity: 1.0,
        blend_mode: BlendMode::Normal,
        original_name: "bad.svg".into(),
        data_uri: "data:image/svg+xml;base64,PHN2Zy8+".into(),
        width: 2,
        height: 2,
        rotation_degrees: 0.0,
        x: 0,
        y: 0,
    };
    assert!(apply(source, &[invalid], DEFAULT_MEMORY_BUDGET).is_err());
}

// ===== SNAPSMACK EOF =====
