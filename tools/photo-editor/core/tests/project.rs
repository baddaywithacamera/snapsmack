//! Editable project acceptance tests.
//! SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical Rust marker.

use serde_json::json;
use snapsmack_photo_core::processing::{BlendMode, FontFamily, Operation, TextAlignment};
use snapsmack_photo_core::project::{self, ProjectDocument};
use std::io::Write;

#[test]
fn project_round_trip_preserves_edits_and_optional_metadata() {
    let temp = tempfile::tempdir().unwrap();
    let source = temp.path().join("source.png");
    std::fs::write(&source, b"stable source bytes").unwrap();
    let path = temp.path().join("work.spt");
    let mut document = project::new_document(
        &source,
        vec![
            Operation::Rotate90,
            Operation::TextLayer {
                name: "Title".into(),
                visible: true,
                locked: false,
                opacity: 0.8,
                fill_opacity: 1.0,
                blend_mode: BlendMode::Normal,
                text: "Hello".into(),
                font_family: FontFamily::SansSerif,
                font_size: 48.0,
                color: [57, 255, 20, 255],
                x: 12,
                y: 24,
                alignment: TextAlignment::Left,
            },
            Operation::ImageOverlayLayer {
                name: "Logo".into(),
                visible: true,
                locked: false,
                opacity: 0.6,
                fill_opacity: 1.0,
                blend_mode: BlendMode::Overlay,
                original_name: "logo.png".into(),
                data_uri: "data:image/png;base64,cGxhY2Vob2xkZXI=".into(),
                width: 320,
                height: 100,
                rotation_degrees: -5.0,
                x: 40,
                y: 50,
            },
            Operation::LayerGroup {
                name: "Finish".into(),
                visible: true,
                locked: false,
                opacity: 0.9,
                fill_opacity: 1.0,
                blend_mode: BlendMode::SoftLight,
                operations: vec![Operation::AdjustmentLayer {
                    name: "Tone".into(),
                    visible: true,
                    locked: false,
                    opacity: 1.0,
                    fill_opacity: 1.0,
                    blend_mode: BlendMode::Normal,
                    operations: vec![Operation::Rotate180, Operation::Rotate180],
                }],
            },
        ],
    )
    .unwrap();
    document
        .optional_metadata
        .insert("future-field".into(), json!({"kept": true}));

    project::save(&document, &path).unwrap();
    let loaded = project::load(&path).unwrap();

    assert_eq!(
        loaded.optional_metadata["future-field"],
        json!({"kept": true})
    );
    assert_eq!(
        serde_json::to_value(&loaded.operations).unwrap(),
        serde_json::to_value(&document.operations).unwrap()
    );
    assert!(project::source_matches(&loaded, &source).unwrap());
}

#[test]
fn changed_original_is_detected_by_content() {
    let temp = tempfile::tempdir().unwrap();
    let source = temp.path().join("source.tif");
    std::fs::write(&source, b"original").unwrap();
    let document = project::new_document(&source, vec![]).unwrap();
    let mut file = std::fs::OpenOptions::new()
        .write(true)
        .truncate(true)
        .open(&source)
        .unwrap();
    file.write_all(b"modified").unwrap();
    assert!(!project::source_matches(&document, &source).unwrap());
}

#[test]
fn relative_source_paths_are_refused() {
    let document: ProjectDocument = serde_json::from_value(json!({
        "format": "snapsmack-pixel-tickler",
        "version": 1,
        "source": {"path": "../../secret.png", "bytes": 1, "modified_unix_ms": 0, "content_fnv1a64": "00"},
        "operations": []
    })).unwrap();
    assert!(project::validate(&document).is_err());
}

// ===== SNAPSMACK EOF =====
