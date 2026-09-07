"""SYBU keeps the per-image Colour/B&W classification visible and durable.

SNAPSMACK_EOF_HEADER: last non-empty line must be # ===== SNAPSMACK EOF =====
"""

from pathlib import Path
import sys

from PIL import Image


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import gemini


def test_per_image_picker_is_wired_to_post_model():
    source = (ROOT / "main.py").read_text(encoding="utf-8")
    assert 'text="colour/B&W"' in source
    assert "values=['—', 'Colour', 'B&W']" in source
    assert "setattr(self.entry, 'color_mode'" in source


def test_recovery_preserves_colour_mode():
    source = (ROOT / "recovery.py").read_text(encoding="utf-8")
    assert "'color_mode'" in source.partition("FIELDS =")[2].splitlines()[0]


def test_prompt_fields_are_parsed_into_model_values():
    parsed = gemini._parse_response(
        "COLOUR DROPDOWN: B&W\nORIENTATION: portrait\nCOLORS: #aa00cc #112233 #abcdef")
    assert parsed["color_mode"] == "bw"
    assert parsed["orientation"] == "1"
    assert parsed["colors"] == "#AA00CC #112233 #ABCDEF"


def test_orientation_comes_from_image_dimensions(tmp_path):
    landscape = tmp_path / "landscape.png"
    portrait = tmp_path / "portrait.png"
    square = tmp_path / "square.png"
    Image.new("RGB", (300, 200)).save(landscape)
    Image.new("RGB", (200, 300)).save(portrait)
    Image.new("RGB", (300, 294)).save(square)
    assert gemini._orientation_from_image(str(landscape)) == "0"
    assert gemini._orientation_from_image(str(portrait)) == "1"
    assert gemini._orientation_from_image(str(square)) == "2"


def test_colour_mode_comes_from_pixels(tmp_path):
    colour = tmp_path / "colour.png"
    mono = tmp_path / "mono.png"
    Image.new("RGB", (20, 20), (180, 30, 90)).save(colour)
    Image.new("RGB", (20, 20), (90, 90, 90)).save(mono)
    assert gemini._color_mode_from_image(str(colour)) == "color"
    assert gemini._color_mode_from_image(str(mono)) == "bw"

# ===== SNAPSMACK EOF =====
