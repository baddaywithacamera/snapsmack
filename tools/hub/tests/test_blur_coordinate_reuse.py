"""Blur sampling reuses coordinates without changing a single float pixel.
SNAPSMACK_EOF_HEADER: preserve the final marker.
"""
import sys
from pathlib import Path
import numpy as np
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import highbit_image as hb


def test_motion_blur_matches_legacy_samples():
    image = hb.FloatImage(np.random.default_rng(32).random((61, 93, 3), dtype=np.float32), ("R", "G", "B"))
    settings = {"length": 42, "angle": 37}
    original = hb._translated
    def legacy(image, dx, dy, coordinates=None):
        return original(image, dx, dy)
    with patch.object(hb, "_translated", side_effect=legacy):
        expected = hb.apply_filter(image, "motion_blur", settings)
    grids = []
    def tracked(image, dx, dy, coordinates=None):
        grids.append(coordinates)
        return original(image, dx, dy, coordinates)
    with patch.object(hb, "_translated", side_effect=tracked):
        actual = hb.apply_filter(image, "motion_blur", settings)
    assert np.array_equal(expected.pixels, actual.pixels)
    assert len(grids) > 20
    assert all(grid is grids[0] for grid in grids)
    assert grids[0] is not None

def test_zoom_blur_matches_legacy_samples():
    image = hb.FloatImage(np.random.default_rng(31).random((49, 73, 3), dtype=np.float32), ("R", "G", "B"))
    strength, cx, cy = 28.0, image.width * .4, image.height * .6
    samples = max(5, min(25, int(strength / 5) * 2 + 5))
    frames = []
    for index in range(samples):
        scale = max(.5, 1.0 + (index / (samples - 1) - .5) * strength / 125.0)
        yy, xx = np.mgrid[0:image.height, 0:image.width].astype(np.float32)
        frames.append(hb._rgb(hb.resample_coordinates(
            image, cx + (xx - cx) / scale, cy + (yy - cy) / scale))[0])
    expected = np.mean(frames, axis=0, dtype=np.float32)
    actual = hb.apply_filter(image, "radial_blur", {"strength": strength,
        "center_x": 40, "center_y": 60, "mode": "zoom"})
    assert np.array_equal(expected, actual.pixels)

# ===== SNAPSMACK EOF =====
