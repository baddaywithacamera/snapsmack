"""Gradient mask generators for layer local-adjustments (pure PIL).

A mask is an L image: white = full layer effect, black = none. These build the
two masks photographers reach for most — a radial (centre emphasis / spotlight)
and a linear graduated mask (graduated-ND-style, e.g. darken a sky). They are
drawn with ImageDraw + Gaussian blur so they stay dependency-free (no numpy).
"""

from PIL import Image, ImageDraw, ImageFilter, ImageOps
import numpy as np


def radial_mask(size, center_x, center_y, radius, softness, invert=False):
    """A soft-edged ellipse: white inside, feathering to black."""
    width, height = size
    mask = Image.new("L", size, 0)
    reach = max(1.0, radius * min(width, height))
    cx, cy = center_x * width, center_y * height
    ImageDraw.Draw(mask).ellipse(
        [cx - reach, cy - reach, cx + reach, cy + reach], fill=255)
    blur = max(1.0, softness * min(width, height))
    mask = mask.filter(ImageFilter.GaussianBlur(blur))
    return ImageOps.invert(mask) if invert else mask


def linear_mask(size, direction, position, softness, invert=False):
    """A graduated mask: white on one side of a line, black on the other."""
    width, height = size
    mask = Image.new("L", size, 0)
    draw = ImageDraw.Draw(mask)
    if direction in ("top", "bottom"):
        line = position * height
        if direction == "top":
            draw.rectangle([0, 0, width, line], fill=255)
        else:
            draw.rectangle([0, line, width, height], fill=255)
        blur = max(1.0, softness * height)
    else:  # left / right
        line = position * width
        if direction == "left":
            draw.rectangle([0, 0, line, height], fill=255)
        else:
            draw.rectangle([line, 0, width, height], fill=255)
        blur = max(1.0, softness * width)
    mask = mask.filter(ImageFilter.GaussianBlur(blur))
    return ImageOps.invert(mask) if invert else mask


def drawn_linear_mask(size, start_x, start_y, end_x, end_y, invert=False):
    """A directional gradient defined by a drag across normalized image space."""
    width, height = size
    sx, sy = start_x * width, start_y * height
    ex, ey = end_x * width, end_y * height
    dx, dy = ex - sx, ey - sy
    length_squared = dx * dx + dy * dy
    if length_squared < 1:
        return Image.new("L", size, 255 if not invert else 0)
    # Project every pixel onto the drag vector and clamp. A former finite-ramp
    # affine implementation turned white back to black when a corner projected
    # far beyond a short diagonal drag, creating a false triangular wedge.
    yy, xx = np.mgrid[0:height, 0:width].astype(np.float32)
    amount = ((xx - sx) * dx + (yy - sy) * dy) / length_squared
    values = np.uint8(np.rint(np.clip(amount, 0.0, 1.0) * 255.0))
    mask = Image.fromarray(values, "L")
    return ImageOps.invert(mask) if invert else mask


def colour_range_mask(image, hue, hue_range, minimum_saturation=0,
                      minimum_luminance=0, maximum_luminance=100,
                      softness=10, invert=False):
    """Select pixels by circular hue distance, saturation, and luminance."""
    hsv = image.convert("RGB").convert("HSV")
    h_band, s_band, v_band = hsv.split()
    centre = (float(hue) % 360) / 360 * 255
    reach = max(1.0, float(hue_range) / 360 * 255)
    feather = max(1.0, float(softness) / 100 * 64)
    min_sat = float(minimum_saturation) / 100 * 255
    min_lum = float(minimum_luminance) / 100 * 255
    max_lum = float(maximum_luminance) / 100 * 255
    # The weights depend on byte-valued channels, so calculate each of the
    # 256 possibilities once rather than running Python for every pixel.
    hue_weights = []
    sat_weights = []
    lum_weights = []
    for value in range(256):
        distance = min(abs(value - centre), 255 - abs(value - centre))
        hue_weights.append(1.0 if distance <= reach else max(
            0.0, 1.0 - (distance - reach) / feather))
        sat_weights.append(1.0 if value >= min_sat else max(
            0.0, 1.0 - (min_sat - value) / feather))
        lower = 1.0 if value >= min_lum else max(
            0.0, 1.0 - (min_lum - value) / feather)
        upper = 1.0 if value <= max_lum else max(
            0.0, 1.0 - (value - max_lum) / feather)
        lum_weights.append(lower * upper)
    values = (255 * np.asarray(hue_weights)[np.asarray(h_band)]
              * np.asarray(sat_weights)[np.asarray(s_band)]
              * np.asarray(lum_weights)[np.asarray(v_band)])
    output = Image.fromarray(values.astype(np.uint8), "L")
    return ImageOps.invert(output) if invert else output

# ===== SNAPSMACK EOF =====
