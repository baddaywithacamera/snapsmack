"""SS-1025: real JPEG suffixes and useful empty-folder diagnostics.

SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
"""
import sys
from pathlib import Path

import pytest
from PIL import Image

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import sybu_core


def scanner():
    engine = sybu_core.Engine.__new__(sybu_core.Engine)
    # Exercise discovery and entry creation without credentials or recovery writes.
    engine._set_entries = lambda entries, folder: entries
    return engine


def test_real_jpeg_variants_and_existing_formats(tmp_path):
    formats = {"one.jpeg": "JPEG", "two.JPEG": "JPEG", "three.JpEg": "JPEG",
               "four.jpg": "JPEG", "five.JPG": "JPEG", "six.png": "PNG",
               "seven.webp": "WEBP"}
    for name, fmt in formats.items():
        Image.new("RGB", (12, 8), "red").save(tmp_path / name, format=fmt)
    (tmp_path / "notes.txt").write_text("not a photograph")
    entries = scanner().scan_folder(str(tmp_path), "Photos", "Batch", "portrait")
    assert [entry.file for entry in entries] == sorted(formats)
    assert all(entry.category == "Photos" and entry.album == "Batch" for entry in entries)
    assert all(entry.orientation == "1" for entry in entries)


def test_wrong_empty_folder_error_names_the_folder(tmp_path):
    # Photos in a different folder must not silently enter this site's queue.
    actual = tmp_path / "actual"
    actual.mkdir()
    Image.new("RGB", (12, 8)).save(actual / "photo.jpeg", format="JPEG")
    selected = tmp_path / "selected"
    selected.mkdir()
    with pytest.raises(RuntimeError) as error:
        scanner().scan_folder(str(selected), "", "", "auto")
    assert str(selected) in str(error.value)
    assert "JPEG" in str(error.value)
    assert "Subfolders are not scanned" in str(error.value)
    assert len(scanner().scan_folder(str(actual), "", "", "auto")) == 1


# ===== SNAPSMACK EOF =====
