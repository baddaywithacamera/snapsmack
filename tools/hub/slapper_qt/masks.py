"""Gradient mask generators for layer local-adjustments (pure PIL).

A mask is an L image: white = full layer effect, black = none. These build the
two masks photographers reach for most — a radial (centre emphasis / spotlight)
and a linear graduated mask (graduated-ND-style, e.g. darken a sky). They are
drawn with ImageDraw + Gaussian blur so they stay dependency-free (no numpy).
"""

from PIL import Image, ImageDraw, ImageFilter, ImageOps


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


def colour_range_mask(image, hue, hue_range, minimum_saturation=0,
                      minimum_luminance=0, maximum_luminance=100,
                      softness=10, invert=False):
    """Select pixels by circular hue distance, saturation, and luminance."""
    hsv = image.convert("RGB").convert("HSV")
    h_band, s_band, v_band = hsv.split()
    output = Image.new("L", image.size)
    centre = (float(hue) % 360) / 360 * 255
    reach = max(1.0, float(hue_range) / 360 * 255)
    feather = max(1.0, float(softness) / 100 * 64)
    min_sat = float(minimum_saturation) / 100 * 255
    min_lum = float(minimum_luminance) / 100 * 255
    max_lum = float(maximum_luminance) / 100 * 255
    values = []
    for h, saturation, luminance in zip(h_band.getdata(), s_band.getdata(),
                                        v_band.getdata()):
        distance = min(abs(h - centre), 255 - abs(h - centre))
        hue_weight = 1.0 if distance <= reach else max(
            0.0, 1.0 - (distance - reach) / feather)
        sat_weight = 1.0 if saturation >= min_sat else max(
            0.0, 1.0 - (min_sat - saturation) / feather)
        lower_weight = 1.0 if luminance >= min_lum else max(
            0.0, 1.0 - (min_lum - luminance) / feather)
        upper_weight = 1.0 if luminance <= max_lum else max(
            0.0, 1.0 - (luminance - max_lum) / feather)
        lum_weight = lower_weight * upper_weight
        values.append(int(255 * hue_weight * sat_weight * lum_weight))
    output.putdata(values)
    return ImageOps.invert(output) if invert else output

# ===== SNAPSMACK EOF =====
