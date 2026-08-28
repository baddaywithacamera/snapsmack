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

# ===== SNAPSMACK EOF =====
