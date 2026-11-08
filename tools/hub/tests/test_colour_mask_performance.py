"""Byte-exact colour selection against the previous scalar calculation.
SNAPSMACK_EOF_HEADER: keep the canonical EOF marker last.
"""
import sys
from pathlib import Path
import numpy as np
from PIL import Image, ImageOps
import pytest
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from slapper_qt.masks import colour_range_mask


def scalar(image, hue, hue_range, minimum_saturation=0,
           minimum_luminance=0, maximum_luminance=100, softness=10, invert=False):
    centre = (float(hue) % 360) / 360 * 255
    reach = max(1.0, float(hue_range) / 360 * 255)
    feather = max(1.0, float(softness) / 100 * 64)
    min_sat = float(minimum_saturation) / 100 * 255
    min_lum = float(minimum_luminance) / 100 * 255
    max_lum = float(maximum_luminance) / 100 * 255
    values = []
    for h, saturation, luminance in image.convert("RGB").convert("HSV").getdata():
        distance = min(abs(h - centre), 255 - abs(h - centre))
        hw = 1.0 if distance <= reach else max(0.0, 1.0 - (distance - reach) / feather)
        sw = 1.0 if saturation >= min_sat else max(0.0, 1.0 - (min_sat - saturation) / feather)
        low = 1.0 if luminance >= min_lum else max(0.0, 1.0 - (min_lum - luminance) / feather)
        high = 1.0 if luminance <= max_lum else max(0.0, 1.0 - (luminance - max_lum) / feather)
        values.append(int(255 * hw * sw * (low * high)))
    out = Image.new("L", image.size)
    out.putdata(values)
    return ImageOps.invert(out) if invert else out


@pytest.mark.parametrize("hue,reach,sat,low,high,soft", [
    (0, 0, 0, 0, 100, 0), (359, 12, 40, 20, 80, 15),
    (180, 60, 100, 0, 100, 100), (720, 360, 15, 51, 50, 3),
    (-12.3, 17.9, 34.5, 16.7, 89.2, 6.7),
])
@pytest.mark.parametrize("invert", [False, True])
def test_byte_exact_selection(hue, reach, sat, low, high, soft, invert):
    pixels = np.random.default_rng(823).integers(0, 256, (128, 256, 3), dtype=np.uint8)
    image = Image.fromarray(pixels, "RGB")
    args = (hue, reach, sat, low, high, soft, invert)
    assert colour_range_mask(image, *args).tobytes() == scalar(image, *args).tobytes()

# ===== SNAPSMACK EOF =====
