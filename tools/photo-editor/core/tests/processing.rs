//! Packet 02 processing acceptance tests.
//! SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical Rust marker.

use image::{DynamicImage, ImageBuffer, ImageFormat, Rgba, RgbaImage};
use snapsmack_photo_core::processing::{
    apply, histogram, load, preview, save_atomic, Operation, ProcessingError, DEFAULT_MEMORY_BUDGET,
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

// ===== SNAPSMACK EOF =====
