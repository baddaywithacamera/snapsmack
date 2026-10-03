# SNAPSMACK_EOF_HEADER
"""A photograph with an alpha channel must still publish as a JPEG.

Reported from a real session: "Publish preparation failed - cannot write mode
RGBA as JPEG", at the point of sending a post to the blog. A render carries an
alpha channel whenever the photograph had one, or whenever geometry left
transparent edges behind, and JPEG cannot hold one. Every other JPEG path in the
editor converts first; the publishing path did not.

The conversion now happens in save_with_metadata, which every derivative save
goes through, so this cannot come back through a different door.
"""

import os
import sys

import numpy as np
import pytest
from PIL import Image

HUB = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, HUB)
sys.path.insert(0, os.path.join(os.path.dirname(HUB), "_shared"))

import photo_manager  # noqa: E402


@pytest.fixture
def source(tmp_path):
    """An ordinary photograph on disk, for the metadata to be read from."""
    path = tmp_path / "original.jpg"
    Image.fromarray(
        (np.random.default_rng(1).random((60, 80, 3)) * 255).astype(np.uint8),
        "RGB").save(path, quality=92)
    return str(path)


def _rgba(width=80, height=60):
    pixels = (np.random.default_rng(2).random((height, width, 4)) * 255).astype(np.uint8)
    pixels[:10, :, 3] = 0          # a transparent band, as a lens correction leaves
    return Image.fromarray(pixels, "RGBA")


def test_an_rgba_render_saves_as_jpeg(tmp_path, source):
    target = str(tmp_path / "blog.jpg")
    photo_manager.save_with_metadata(_rgba(), target, source, format="JPEG",
                                     quality=90, optimize=True)
    with Image.open(target) as saved:
        assert saved.format == "JPEG"
        assert saved.mode == "RGB"
        assert saved.size == (80, 60)


def test_other_awkward_modes_also_save_as_jpeg(tmp_path, source):
    for mode in ("LA", "P", "RGBa"):
        target = str(tmp_path / f"blog_{mode}.jpg")
        image = _rgba().convert(mode)
        photo_manager.save_with_metadata(image, target, source, format="JPEG",
                                         quality=90)
        with Image.open(target) as saved:
            assert saved.format == "JPEG", mode


def test_transparent_areas_become_white_not_black(tmp_path, source):
    """A transparent wedge must not arrive on the blog as a black wedge."""
    target = str(tmp_path / "wedge.jpg")
    photo_manager.save_with_metadata(_rgba(), target, source, format="JPEG",
                                     quality=95)
    with Image.open(target) as saved:
        band = np.asarray(saved.convert("RGB"))[:10]
        assert band.min() > 230, (
            f"the transparent band came out at {band.mean():.0f}, not white")


def test_an_ordinary_rgb_render_is_untouched(tmp_path, source):
    """The conversion must not interfere with the normal case."""
    pixels = (np.random.default_rng(3).random((60, 80, 3)) * 255).astype(np.uint8)
    target = str(tmp_path / "plain.jpg")
    photo_manager.save_with_metadata(Image.fromarray(pixels, "RGB"), target,
                                     source, format="JPEG", quality=95)
    with Image.open(target) as saved:
        assert saved.mode == "RGB"


def test_png_keeps_its_alpha(tmp_path, source):
    """PNG can hold alpha, so nothing may be thrown away there."""
    target = str(tmp_path / "keeps.png")
    photo_manager.save_with_metadata(_rgba(), target, source, format="PNG")
    with Image.open(target) as saved:
        assert saved.mode in ("RGBA", "LA", "PA"), saved.mode
        assert saved.getchannel("A").getextrema()[0] == 0, (
            "the transparent band must survive a PNG save")


def test_webp_keeps_its_alpha(tmp_path, source):
    target = str(tmp_path / "keeps.webp")
    photo_manager.save_with_metadata(_rgba(), target, source, format="WEBP",
                                     quality=90)
    with Image.open(target) as saved:
        assert saved.mode == "RGBA"


# ===== SNAPSMACK EOF =====
