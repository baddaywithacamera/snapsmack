//! Lighttable catalog acceptance tests.
//! SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical Rust marker.

use image::{ImageBuffer, Rgba, RgbaImage};
use snapsmack_photo_core::lighttable::{self, ColorLabel, Flag, Rating, CATALOG_FILE};

#[test]
fn folder_scan_and_ratings_are_portable_and_leave_originals_untouched() {
    let temp = tempfile::tempdir().unwrap();
    for name in ["b.png", "A.png"] {
        let image = RgbaImage::from_pixel(8, 6, Rgba([20, 40, 60, 255]));
        image.save(temp.path().join(name)).unwrap();
    }
    std::fs::write(temp.path().join("ignore.txt"), b"not an image").unwrap();
    let original = std::fs::read(temp.path().join("A.png")).unwrap();

    let first = lighttable::scan_page(temp.path(), 0, 50).unwrap();
    assert_eq!(
        first
            .iter()
            .map(|item| item.name.as_str())
            .collect::<Vec<_>>(),
        ["A.png", "b.png"]
    );
    assert!(first
        .iter()
        .all(|item| item.thumbnail.starts_with("data:image/png;base64,")));
    assert_eq!(
        lighttable::scan_page(temp.path(), 1, 1).unwrap()[0].name,
        "b.png"
    );
    assert!(lighttable::scan_page(temp.path(), 0, 101).is_err());

    lighttable::update_rating(
        temp.path(),
        "A.png",
        Rating {
            flag: Flag::Pick,
            stars: 5,
            color: ColorLabel::Green,
        },
    )
    .unwrap();
    let second = lighttable::scan_page(temp.path(), 0, 50).unwrap();
    assert_eq!(
        second[0].rating,
        Rating {
            flag: Flag::Pick,
            stars: 5,
            color: ColorLabel::Green
        }
    );
    assert_eq!(std::fs::read(temp.path().join("A.png")).unwrap(), original);
    assert!(temp.path().join(CATALOG_FILE).is_file());
    assert!(!temp.path().join(format!("{CATALOG_FILE}.bak")).is_file());
}

#[test]
fn catalog_refuses_escape_names_and_invalid_stars() {
    let temp = tempfile::tempdir().unwrap();
    let image: RgbaImage = ImageBuffer::from_pixel(1, 1, Rgba([0, 0, 0, 255]));
    image.save(temp.path().join("one.png")).unwrap();
    assert!(lighttable::update_rating(temp.path(), "../one.png", Rating::default()).is_err());
    assert!(lighttable::update_rating(
        temp.path(),
        "one.png",
        Rating {
            stars: 6,
            ..Rating::default()
        }
    )
    .is_err());
}

// ===== SNAPSMACK EOF =====
