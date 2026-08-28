"""Non-destructive SNAP SLAPPER editing document and Pillow renderer.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import base64
import copy
import io
import json
import math
import os
import time
import tempfile
import textwrap
import zipfile

from PIL import (Image, ImageChops, ImageDraw, ImageEnhance, ImageFilter,
                 ImageFont, ImageMath, ImageOps)

import photo_manager


PROJECT_VERSION = 1
MAX_PROJECT_BYTES = 512 * 1024 * 1024
MAX_PROJECT_LAYERS = 500
MAX_RETOUCH_POINTS = 100000
MAX_ENCODED_MASK_BYTES = 128 * 1024 * 1024
MAX_TEXT_LAYER_CHARS = 1_000_000
PROJECT_DOCUMENT_NAME = "project.json"
PROJECT_README_NAME = "README.txt"
DEFAULT_ADJUSTMENTS = {
    "exposure": 0.0, "brightness": 0.0, "contrast": 0.0,
    "highlights": 0.0, "shadows": 0.0, "whites": 0.0, "blacks": 0.0,
    "temperature": 0.0, "tint": 0.0, "saturation": 0.0, "vibrance": 0.0,
    "clarity": 0.0, "texture": 0.0, "dehaze": 0.0, "sharpen": 0.0,
    # Smart-sharpen controls. amount == the sharpen slider above. Lens mode
    # edge-limits the sharpen (finer detail, no haloes, noise left alone);
    # gaussian is the classic unsharp mask. Defaults keep sharpen off (0).
    "sharpen_radius": 1.2, "sharpen_reduce_noise": 0.0, "sharpen_mode": "lens",
    "level_black": 0.0, "level_gamma": 1.0, "level_white": 255.0,
    "black_white": False, "vignette": 0.0, "grain": 0.0,
    # Vignette edge softness (50 == the classic look) and a darken-only grain
    # mode (False == the original soft-light grain). Defaults preserve every
    # existing LEWK and project unchanged.
    "vignette_feather": 50.0, "grain_darken": False,
    # Split toning — colour the shadows and highlights independently (the
    # teal-and-orange / warm-cool look most film emulations rely on). Both
    # amounts default to 0, so this is off until dialled up.
    "split_shadow": [60, 90, 150], "split_shadow_amount": 0.0,
    "split_midtone": [128, 128, 128], "split_midtone_amount": 0.0,
    "split_highlight": [255, 200, 120], "split_highlight_amount": 0.0,
    # Per-channel tone curves (independent R / G / B). Identity == no change,
    # so an unset LEWK renders exactly as before. This is what the colour-cast
    # looks (cross-process, film) are built from.
    "curve_red": [[0, 0], [255, 255]],
    "curve_green": [[0, 0], [255, 255]],
    "curve_blue": [[0, 0], [255, 255]],
    # Colour HSL mix — per-hue saturation and luminance (like the B&W mixer,
    # but in colour). All zero == unchanged.
    "col_sat_red": 0.0, "col_sat_orange": 0.0, "col_sat_yellow": 0.0,
    "col_sat_green": 0.0, "col_sat_aqua": 0.0, "col_sat_blue": 0.0,
    "col_sat_purple": 0.0, "col_sat_magenta": 0.0,
    "col_lum_red": 0.0, "col_lum_orange": 0.0, "col_lum_yellow": 0.0,
    "col_lum_green": 0.0, "col_lum_aqua": 0.0, "col_lum_blue": 0.0,
    "col_lum_purple": 0.0, "col_lum_magenta": 0.0,
    # Positional colour glow (a placed bloom, screened over the photo).
    "glow_amount": 0.0, "glow_colour": [255, 220, 170],
    "glow_x": 50.0, "glow_y": 40.0, "glow_size": 45.0,
    # Black & white colour mix — per-hue luminance, Lightroom-style.
    # All zero == the classic neutral grayscale (backward compatible).
    "bw_red": 0.0, "bw_orange": 0.0, "bw_yellow": 0.0, "bw_green": 0.0,
    "bw_aqua": 0.0, "bw_blue": 0.0, "bw_purple": 0.0, "bw_magenta": 0.0,
    # Photo filter — a coloured "gel" over the lens (the classic warming/cooling
    # and colour filters). Density 0 == off, so this is backward compatible.
    # Preserve-luminosity keeps the photo's brightness and changes only colour.
    "photo_filter_color": [236, 138, 0], "photo_filter_density": 0.0,
    "photo_filter_preserve_lum": True,
    "curve": [[0, 0], [255, 255]],
}


# Photo Filter presets — the classic Photoshop set (a coloured gel over the lens
# at a density) plus a few faux-infrared washes. Each entry is (label, RGB,
# suggested density %). Selecting one sets the layer's filter colour + density.
PHOTO_FILTER_PRESETS = [
    ("Warming Filter (85)",  (236, 138, 0),   25),
    ("Warming Filter (LBA)", (250, 150, 40),  25),
    ("Warming Filter (81)",  (235, 177, 19),  25),
    ("Cooling Filter (80)",  (0, 109, 255),   25),
    ("Cooling Filter (LBB)", (0, 93, 255),    25),
    ("Cooling Filter (82)",  (0, 181, 255),   25),
    ("Red",      (234, 26, 26),   25),
    ("Orange",   (243, 128, 30),  25),
    ("Yellow",   (237, 232, 23),  25),
    ("Green",    (25, 201, 25),   25),
    ("Cyan",     (26, 229, 229),  25),
    ("Blue",     (29, 53, 255),   25),
    ("Violet",   (155, 25, 229),  25),
    ("Magenta",  (255, 25, 255),  25),
    ("Sepia",    (172, 122, 51),  25),
    ("Deep Red",     (235, 0, 0),    25),
    ("Deep Blue",    (0, 0, 235),    25),
    ("Deep Emerald", (0, 140, 0),    25),
    ("Deep Yellow",  (255, 198, 0),  25),
    ("Underwater",   (0, 194, 177),  25),
    # Faux infrared — a coloured wash in the spirit of the deep filters IR
    # shooters screw onto the lens. A colour wash gives the IR *cast*, not the
    # full white-foliage / black-sky conversion (that needs a channel swap — a
    # separate effect).
    ("Faux IR — R72 Deep Red",    (120, 0, 0),    90),
    ("Faux IR — Aerochrome Pink", (226, 40, 150),  80),
    ("Faux IR — Green Window",    (0, 150, 90),    80),
]

# Pillow 10.3+ renamed ImageMath.eval -> unsafe_eval (same behaviour, our
# expression is a fixed literal). Fall back to eval on older Pillow.
_image_math_eval = getattr(ImageMath, "unsafe_eval", None) or getattr(ImageMath, "eval")

# Monotonic counter so layer ids are unique even when created faster than the
# system clock's resolution (time.time_ns() is coarse on Windows, so two quick
# add-layer calls would otherwise collide and make layers indistinguishable).
_layer_id_counter = 0


def _new_layer_id():
    global _layer_id_counter
    _layer_id_counter += 1
    return f"{time.time_ns()}-{_layer_id_counter}"

# Hue centres (degrees) for the black & white colour mix, in wheel order.
BW_BANDS = [("bw_red", 0.0), ("bw_orange", 30.0), ("bw_yellow", 60.0),
            ("bw_green", 120.0), ("bw_aqua", 180.0), ("bw_blue", 240.0),
            ("bw_purple", 270.0), ("bw_magenta", 300.0)]


def _clamp(value, low=0, high=255):
    return max(low, min(high, value))


def _mask_to_text(mask):
    if mask is None:
        return ""
    stream = io.BytesIO()
    mask.convert("L").save(stream, "PNG")
    return base64.b64encode(stream.getvalue()).decode("ascii")


def _mask_from_text(value):
    if not value:
        return None
    return Image.open(io.BytesIO(base64.b64decode(value))).convert("L")


def _write_project_archive(path, value):
    """Atomically publish an ordinary ZIP container with a .slapper extension."""
    target = os.path.abspath(path)
    directory = os.path.dirname(target)
    os.makedirs(directory, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix=".snap-project-", suffix=".tmp",
                                             dir=directory)
    os.close(descriptor)
    document = json.dumps(value, indent=2, sort_keys=True, allow_nan=False).encode("utf-8")
    readme = ("SNAP SLAPPER project archive\n\n"
              "Rename this file from .slapper to .zip to inspect it with any ZIP tool.\n"
              "project.json contains the versioned, human-readable editing document.\n"
              "The original photograph is referenced, not imprisoned inside this archive.\n")
    try:
        with zipfile.ZipFile(temporary, "w", compression=zipfile.ZIP_DEFLATED,
                             allowZip64=True) as archive:
            archive.writestr(PROJECT_DOCUMENT_NAME, document)
            archive.writestr(PROJECT_README_NAME, readme)
        photo_manager.fsync_file(temporary)
        os.replace(temporary, target)
    except Exception:
        try:
            os.remove(temporary)
        except OSError:
            pass
        raise


def _read_project_document(path):
    """Read the ZIP container, retaining compatibility with legacy bare JSON projects."""
    if zipfile.is_zipfile(path):
        try:
            with zipfile.ZipFile(path, "r") as archive:
                try:
                    info = archive.getinfo(PROJECT_DOCUMENT_NAME)
                except KeyError as exc:
                    raise ValueError("Invalid SNAP SLAPPER project: project.json is missing") from exc
                if info.file_size > MAX_PROJECT_BYTES:
                    raise ValueError("SNAP SLAPPER project document is too large to open safely")
                raw = archive.read(info)
            return json.loads(raw.decode("utf-8"),
                              parse_constant=photo_manager.reject_json_constant)
        except zipfile.BadZipFile as exc:
            raise ValueError("Invalid SNAP SLAPPER project archive") from exc
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle, parse_constant=photo_manager.reject_json_constant)


def _curve_lut(points):
    points = sorted((int(_clamp(x)), int(_clamp(y))) for x, y in points)
    if not points or points[0][0] != 0:
        points.insert(0, (0, 0))
    if points[-1][0] != 255:
        points.append((255, 255))
    result = []
    segment = 0
    for x in range(256):
        while segment + 1 < len(points) - 1 and x > points[segment + 1][0]:
            segment += 1
        x0, y0 = points[segment]
        x1, y1 = points[segment + 1]
        amount = 0 if x1 == x0 else (x - x0) / (x1 - x0)
        result.append(int(_clamp(round(y0 + (y1 - y0) * amount))))
    return result


def _tonal_lut(adjustments):
    exposure = 2 ** float(adjustments.get("exposure", 0))
    brightness = float(adjustments.get("brightness", 0)) * 1.28
    contrast = float(adjustments.get("contrast", 0)) / 100.0
    shadows = float(adjustments.get("shadows", 0)) / 100.0
    highlights = float(adjustments.get("highlights", 0)) / 100.0
    whites = float(adjustments.get("whites", 0)) / 100.0
    blacks = float(adjustments.get("blacks", 0)) / 100.0
    level_black = float(adjustments.get("level_black", 0))
    level_white = max(level_black + 1, float(adjustments.get("level_white", 255)))
    level_gamma = max(.1, float(adjustments.get("level_gamma", 1)))
    lut = []
    for source in range(256):
        value = source * exposure + brightness
        normalized = _clamp(value) / 255.0
        value += shadows * 70.0 * (1.0 - normalized) ** 2
        value += highlights * 70.0 * normalized ** 2
        value += whites * 45.0 * max(0.0, (normalized - .65) / .35)
        value += blacks * 45.0 * max(0.0, (.35 - normalized) / .35)
        value = (value - 127.5) * (1.0 + contrast) + 127.5
        normalized = max(0.0, min(1.0, (value - level_black) / (level_white - level_black)))
        value = 255.0 * normalized ** (1.0 / level_gamma)
        lut.append(int(_clamp(round(value))))
    return lut


def _bw_multiplier_lut(settings):
    """A 256-entry luminance-multiplier lookup indexed by PIL hue (0-255).

    Each colour band's slider (-100..100) brightens or darkens pixels of that
    hue in the black & white conversion. Values interpolate around the wheel,
    so a hue between two bands blends their sliders. All-zero -> all 1.0.
    """
    centres = [(deg, float(settings.get(key, 0.0))) for key, deg in BW_BANDS]
    lut = []
    for index in range(256):
        hue = index * 360.0 / 255.0
        # nearest band on each side of this hue, around the circle
        below = max(((deg - 360.0 if deg > hue else deg, value)
                     for deg, value in centres), key=lambda item: item[0])
        above = min(((deg + 360.0 if deg < hue else deg, value)
                     for deg, value in centres), key=lambda item: item[0])
        span = above[0] - below[0]
        weight = 0.0 if span == 0 else (hue - below[0]) / span
        slider = below[1] * (1 - weight) + above[1] * weight
        multiplier = 1.0 + (slider / 100.0) * 0.7   # +-100 -> 1.7 / 0.3
        lut.append(max(0, min(255, int(round(multiplier * 128.0)))))
    return lut


def _bw_mono(output, settings):
    """Return the greyscale L image for black & white, using the colour mix.

    With every band at 0 this equals ``ImageOps.grayscale`` exactly, so old
    projects render identically.
    """
    base = ImageOps.grayscale(output)
    if not any(float(settings.get(key, 0.0)) for key, _deg in BW_BANDS):
        return base
    hsv = output.convert("HSV")
    hue = hsv.getchannel("H").point(_bw_multiplier_lut(settings))
    saturation = hsv.getchannel("S")
    # grey * (1 + (multiplier-1) * saturation); saturation weights the effect
    # so neutral (grey) pixels are untouched.
    return _image_math_eval(
        "convert(min(max(float(g) * (1 + (float(m) / 128.0 - 1) "
        "* (float(s) / 255.0)), 0.0), 255.0), 'L')",
        g=base, m=hue, s=saturation)


def auto_adjustments(image, percentile=0.005):
    """Compute a gentle one-click 'auto enhance' as *editable* adjustment values.

    Sets black/white levels from the tonal range (auto-levels) and a mild
    grey-world white-balance nudge, plus a small contrast/vibrance lift. It
    returns adjustment keys so the result stays non-destructive and the user can
    fine-tune afterwards.
    """
    from PIL import ImageStat
    rgb = image.convert("RGB")
    result = {}

    # Auto-levels: stretch to the 0.5% / 99.5% tonal points.
    lum = ImageOps.grayscale(rgb)
    histogram = lum.histogram()
    total = sum(histogram) or 1
    cutoff = total * percentile
    low, running = 0, 0
    for value in range(256):
        running += histogram[value]
        if running >= cutoff:
            low = value
            break
    running = 0
    high = 255
    for value in range(255, -1, -1):
        running += histogram[value]
        if running >= cutoff:
            high = value
            break
    if high - low >= 8:                       # only if there is range to recover
        result["level_black"] = float(max(0, min(80, low)))
        result["level_white"] = float(min(255, max(175, high)))

    # Grey-world white balance: nudge temperature/tint toward neutral.
    mean_r, mean_g, mean_b = ImageStat.Stat(rgb).mean
    result["temperature"] = float(max(-40, min(40, round((mean_b - mean_r) * 0.5))))
    result["tint"] = float(max(-30, min(30, round(((mean_r + mean_b) / 2 - mean_g) * 0.4))))

    # A little life.
    result["contrast"] = 8.0
    result["vibrance"] = 10.0
    return result


def apply_adjustments(image, adjustments):
    settings = dict(DEFAULT_ADJUSTMENTS)
    settings.update(adjustments or {})
    output = image.convert("RGB").point(_tonal_lut(settings) * 3)

    temperature = float(settings["temperature"]) / 100.0
    tint = float(settings["tint"]) / 100.0
    if temperature or tint:
        red, green, blue = output.split()
        red = red.point(lambda value: _clamp(value * (1.0 + temperature * .22 + tint * .06)))
        green = green.point(lambda value: _clamp(value * (1.0 - abs(tint) * .05)))
        blue = blue.point(lambda value: _clamp(value * (1.0 - temperature * .22 + tint * .06)))
        output = Image.merge("RGB", (red, green, blue))

    saturation = 1.0 + float(settings["saturation"]) / 100.0
    if saturation != 1.0:
        output = ImageEnhance.Color(output).enhance(max(0.0, saturation))
    vibrance = float(settings["vibrance"]) / 100.0
    if vibrance:
        gray = ImageOps.grayscale(output)
        saturation_map = ImageChops.difference(output, Image.merge("RGB", (gray, gray, gray))).convert("L")
        protected = saturation_map.point(lambda value: int(_clamp(255 - value)))
        boosted = ImageEnhance.Color(output).enhance(max(0.0, 1.0 + vibrance * 1.4))
        output = Image.composite(boosted, output, protected)

    clarity = float(settings["clarity"])
    if clarity:
        blurred = output.filter(ImageFilter.GaussianBlur(radius=max(2, min(output.size) / 180)))
        local = ImageChops.subtract(output, blurred, scale=1.0, offset=128)
        local = ImageEnhance.Contrast(local).enhance(abs(clarity) / 35.0)
        method = ImageChops.overlay if clarity > 0 else ImageChops.soft_light
        output = method(output, local)
    texture = float(settings["texture"])
    if texture:
        if texture > 0:
            output = output.filter(ImageFilter.UnsharpMask(radius=.65, percent=int(texture * 1.8), threshold=2))
        else:
            softened = output.filter(ImageFilter.GaussianBlur(radius=min(2.0, abs(texture) / 45.0)))
            output = Image.blend(output, softened, min(.75, abs(texture) / 100.0))
    dehaze = float(settings["dehaze"])
    if dehaze:
        output = ImageEnhance.Contrast(output).enhance(max(.2, 1.0 + dehaze / 130.0))
        output = ImageEnhance.Color(output).enhance(max(0.0, 1.0 + dehaze / 350.0))
    output = _smart_sharpen(output, settings)

    output = _colour_mix(output, settings)

    curve = settings.get("curve") or [[0, 0], [255, 255]]
    output = output.point(_curve_lut(curve) * 3)

    # Per-channel curves (independent R / G / B) — where the colour casts live.
    identity = [[0, 0], [255, 255]]
    r_pts = settings.get("curve_red") or identity
    g_pts = settings.get("curve_green") or identity
    b_pts = settings.get("curve_blue") or identity
    if r_pts != identity or g_pts != identity or b_pts != identity:
        red, green, blue = output.split()
        red = red.point(_curve_lut(r_pts))
        green = green.point(_curve_lut(g_pts))
        blue = blue.point(_curve_lut(b_pts))
        output = Image.merge("RGB", (red, green, blue))
    if settings.get("black_white"):
        mono = _bw_mono(output, settings)
        output = Image.merge("RGB", (mono, mono, mono))

    output = _photo_filter(output, settings)
    output = _split_tone(output, settings)
    output = _colour_glow(output, settings)

    vignette = float(settings["vignette"])
    if vignette:
        width, height = output.size
        mask = Image.new("L", (width, height), 0)
        draw = ImageDraw.Draw(mask)
        inset_x, inset_y = int(width * .08), int(height * .08)
        draw.ellipse((inset_x, inset_y, width - inset_x, height - inset_y), fill=255)
        # Feather: 50 reproduces the classic .16 blur; 0 = hard edge, 100 = very soft.
        feather = float(settings.get("vignette_feather", 50)) / 100.0
        blur = max(1.0, max(width, height) * feather * .32)
        mask = mask.filter(ImageFilter.GaussianBlur(radius=blur))
        strength = abs(vignette) / 100.0
        edge = Image.new("RGB", output.size, (0, 0, 0) if vignette < 0 else (255, 255, 255))
        blend_mask = mask.point(lambda value: int(255 - (255 - value) * strength))
        output = Image.composite(output, edge, blend_mask)

    grain = float(settings["grain"])
    if grain > 0:
        amount = grain / 100.0 * 32
        noise = Image.effect_noise(output.size, amount)
        noise_rgb = Image.merge("RGB", (noise, noise, noise))
        if settings.get("grain_darken"):
            # Darken-only film grain: the noise only ever subtracts light, so
            # specks sit in the image like real silver grain instead of also
            # brightening it the way soft-light does.
            darken = noise.point(lambda v: 255 if v >= 128 else int(255 - (128 - v)))
            darken_rgb = Image.merge("RGB", (darken, darken, darken))
            output = ImageChops.multiply(output, darken_rgb)
        else:
            output = ImageChops.soft_light(output, noise_rgb)
    return output


_HUE_BAND_DEG = [("red", 0.0), ("orange", 30.0), ("yellow", 60.0),
                 ("green", 120.0), ("aqua", 180.0), ("blue", 240.0),
                 ("purple", 270.0), ("magenta", 300.0)]


def _band_multiplier_lut(settings, prefix, scale):
    """A 256-entry multiplier LUT indexed by PIL hue, from 8 per-band sliders
    (-100..100). Bands interpolate around the wheel; all-zero -> flat 1.0."""
    centres = [(deg, float(settings.get(f"{prefix}_{name}", 0.0)))
               for name, deg in _HUE_BAND_DEG]
    lut = []
    for index in range(256):
        hue = index * 360.0 / 255.0
        below = max(((deg - 360.0 if deg > hue else deg, value)
                     for deg, value in centres), key=lambda item: item[0])
        above = min(((deg + 360.0 if deg < hue else deg, value)
                     for deg, value in centres), key=lambda item: item[0])
        span = above[0] - below[0]
        weight = 0.0 if span == 0 else (hue - below[0]) / span
        slider = below[1] * (1 - weight) + above[1] * weight
        multiplier = 1.0 + (slider / 100.0) * scale
        lut.append(max(0, min(255, int(round(multiplier * 128.0)))))
    return lut


def _colour_mix(image, settings):
    """Per-hue saturation and luminance (HSL), like the B&W mixer but in colour.
    A pixel's hue picks its multiplier; all bands at 0 leaves the image alone."""
    sat_on = any(float(settings.get(f"col_sat_{n}", 0)) for n, _ in _HUE_BAND_DEG)
    lum_on = any(float(settings.get(f"col_lum_{n}", 0)) for n, _ in _HUE_BAND_DEG)
    if not sat_on and not lum_on:
        return image
    hue, sat, val = image.convert("HSV").split()
    if sat_on:
        mult = hue.point(_band_multiplier_lut(settings, "col_sat", 0.9))
        sat = _image_math_eval(
            "convert(min(max(float(s) * (float(m) / 128.0), 0.0), 255.0), 'L')",
            s=sat, m=mult)
    if lum_on:
        mult = hue.point(_band_multiplier_lut(settings, "col_lum", 0.5))
        val = _image_math_eval(
            "convert(min(max(float(v) * (float(m) / 128.0), 0.0), 255.0), 'L')",
            v=val, m=mult)
    return Image.merge("HSV", (hue, sat, val)).convert("RGB")


def _colour_glow(image, settings):
    """A placed colour bloom, screened over the photo (soft centre spotlight or
    coloured glow). Amount 0 == off."""
    amount = float(settings.get("glow_amount", 0)) / 100.0
    if amount <= 0:
        return image
    output = image.convert("RGB")
    width, height = output.size
    colour = tuple(int(c) for c in settings.get("glow_colour", [255, 220, 170]))[:3]
    cx = float(settings.get("glow_x", 50)) / 100.0 * width
    cy = float(settings.get("glow_y", 40)) / 100.0 * height
    reach = max(1.0, max(width, height) * (float(settings.get("glow_size", 45)) / 100.0))
    glow = Image.new("L", (width, height), 0)
    ImageDraw.Draw(glow).ellipse([cx - reach, cy - reach, cx + reach, cy + reach],
                                 fill=int(255 * amount))
    glow = glow.filter(ImageFilter.GaussianBlur(reach * 0.5))
    bloom = Image.composite(Image.new("RGB", (width, height), colour),
                            Image.new("RGB", (width, height), (0, 0, 0)), glow)
    return ImageChops.screen(output, bloom)


def _smart_sharpen(image, settings):
    """Unsharp-mask sharpening with Smart-Sharpen-style controls: Amount (the
    sharpen slider), Radius, Reduce Noise, and a Lens/Gaussian edge model.
    Lens mode confines the sharpen to real edge detail via a high-pass mask, so
    smooth gradients (sky, skin) keep their pixels — finer detail, far fewer
    haloes, and noise left alone. Gaussian mode is the classic unsharp mask."""
    amount = float(settings.get("sharpen", 0.0))
    if amount <= 0:
        return image
    radius = max(0.1, min(6.0, float(settings.get("sharpen_radius", 1.2))))
    reduce_noise = max(0.0, min(1.0, float(settings.get("sharpen_reduce_noise", 0.0)) / 100.0))
    mode = settings.get("sharpen_mode", "lens")
    percent = int(max(0.0, min(500.0, amount * 2.5)))
    # Reduce Noise raises the unsharp-mask threshold so low-contrast noise is
    # ignored; at 0 it is the classic threshold of 3.
    threshold = int(3 + reduce_noise * 10)

    sharpened = image.filter(ImageFilter.UnsharpMask(radius=radius, percent=percent, threshold=threshold))

    # Lens mode always edge-limits; Gaussian mode does so only as Reduce Noise
    # is dialled up. The high-pass detail mask lets the sharpen land on edges
    # and protects flat areas from haloing / noise amplification.
    gate = reduce_noise
    if mode == "lens":
        gate = max(gate, 0.4)
    if gate > 0:
        blur = image.filter(ImageFilter.GaussianBlur(radius=radius))
        detail = ImageChops.difference(image, blur).convert("L")
        floor = int(gate * 28)
        slope = 6 + int(gate * 6)
        mask = detail.point(lambda v, f=floor, s=slope: 0 if v <= f else min(255, (v - f) * s))
        mask = mask.filter(ImageFilter.GaussianBlur(radius=max(0.4, radius * 0.5)))
        sharpened = Image.composite(sharpened, image, mask)
    return sharpened


def _photo_filter(image, settings):
    """Lay a coloured gel over the photo — the classic warming/cooling/colour
    photo filters. Density is the strength; preserve-luminosity keeps the
    original brightness and lets the filter change only the colour."""
    density = float(settings.get("photo_filter_density", 0.0)) / 100.0
    if density <= 0:
        return image
    colour = settings.get("photo_filter_color") or [236, 138, 0]
    r, g, b = [max(0, min(255, int(c))) for c in colour[:3]]
    tint = Image.new("RGB", image.size, (r, g, b))
    # A photographic filter absorbs the light complementary to its colour —
    # multiply reproduces that "gel over the lens" look.
    filtered = ImageChops.multiply(image, tint)
    result = Image.blend(image, filtered, density)
    if settings.get("photo_filter_preserve_lum", True):
        # Keep the original brightness, take only the filter's colour: put the
        # filtered chroma (Cb/Cr) back onto the untouched luma (Y).
        y = image.convert("YCbCr").split()[0]
        _, cb, cr = result.convert("YCbCr").split()
        result = Image.merge("YCbCr", (y, cb, cr)).convert("RGB")
    return result


def _split_tone(image, settings):
    """Colour the shadows and highlights independently.

    Each tone is soft-light blended (so brightness is preserved and only the
    hue shifts) and confined by a luminance mask — the shadow colour where the
    photo is dark, the highlight colour where it is bright. Amount 0 == off.
    """
    shadow_amount = float(settings.get("split_shadow_amount", 0)) / 100.0
    midtone_amount = float(settings.get("split_midtone_amount", 0)) / 100.0
    highlight_amount = float(settings.get("split_highlight_amount", 0)) / 100.0
    if shadow_amount <= 0 and midtone_amount <= 0 and highlight_amount <= 0:
        return image
    output = image.convert("RGB")
    luminance = ImageOps.grayscale(output)
    if shadow_amount > 0:
        colour = tuple(int(c) for c in settings.get("split_shadow", [60, 90, 150]))[:3]
        weight = luminance.point(lambda v: int((255 - v) * shadow_amount * .6))
        toned = ImageChops.soft_light(output, Image.new("RGB", output.size, colour))
        output = Image.composite(toned, output, weight)
    if midtone_amount > 0:
        colour = tuple(int(c) for c in settings.get("split_midtone", [128, 128, 128]))[:3]
        # a triangular weight that peaks at mid grey and falls to the extremes
        weight = luminance.point(
            lambda v: max(0, int((255 - abs(v - 128) * 2) * midtone_amount * .6)))
        toned = ImageChops.soft_light(output, Image.new("RGB", output.size, colour))
        output = Image.composite(toned, output, weight)
    if highlight_amount > 0:
        colour = tuple(int(c) for c in settings.get("split_highlight", [255, 200, 120]))[:3]
        weight = luminance.point(lambda v: int(v * highlight_amount * .6))
        toned = ImageChops.soft_light(output, Image.new("RGB", output.size, colour))
        output = Image.composite(toned, output, weight)
    return output


FILTER_TYPES = ("orton", "grain", "light_leak", "pastel")


def _filter_orton(image, settings):
    """Luminous soft-focus: a sharp base screened with a blurred, brightened copy,
    keeping enough detail to avoid looking like a plain blur."""
    radius = max(1.0, float(settings.get("radius", 6.0)))
    glow = float(settings.get("glow", 0.6))
    blurred = image.filter(ImageFilter.GaussianBlur(radius))
    bright = ImageEnhance.Brightness(blurred).enhance(1.0 + 0.45 * glow)
    screened = ImageChops.screen(image, bright)
    return Image.blend(image, screened, 0.85)


def _filter_grain(image, settings):
    """Resolution-aware organic grain via soft-light, mono or colour."""
    width, height = image.size
    sigma = max(4.0, float(settings.get("size", 24.0)))
    if settings.get("monochrome", True):
        noise = Image.effect_noise((width, height), sigma)
        noise_rgb = Image.merge("RGB", (noise, noise, noise))
    else:
        noise_rgb = Image.merge("RGB", tuple(
            Image.effect_noise((width, height), sigma) for _ in range(3)))
    return ImageChops.soft_light(image, noise_rgb)


def _filter_light_leak(image, settings):
    """A warm colour bloom from a corner, screened over the photo."""
    width, height = image.size
    colour = tuple(int(c) for c in settings.get("colour", [255, 120, 40]))[:3]
    cx = float(settings.get("x", 0.85)) * width
    cy = float(settings.get("y", 0.15)) * height
    reach = max(width, height) * float(settings.get("size", 0.6))
    glow = Image.new("L", (width, height), 0)
    ImageDraw.Draw(glow).ellipse([cx - reach, cy - reach, cx + reach, cy + reach], fill=255)
    glow = glow.filter(ImageFilter.GaussianBlur(reach * 0.4))
    leak = Image.composite(Image.new("RGB", (width, height), colour),
                           Image.new("RGB", (width, height), (0, 0, 0)), glow)
    return ImageChops.screen(image, leak)


def _filter_pastel(image, settings):
    """Muted, lifted, gently warm — a soft pastel treatment."""
    out = ImageEnhance.Color(image).enhance(float(settings.get("saturation", 0.7)))
    out = ImageEnhance.Contrast(out).enhance(0.9)
    lift = int(settings.get("lift", 28))
    out = out.point(lambda v: int(min(255, lift + v * (255 - lift) / 255.0)))
    wash = Image.new("RGB", image.size, (255, 240, 235))
    return Image.blend(out, ImageChops.multiply(out, wash), 0.28)


_FILTERS = {"orton": _filter_orton, "grain": _filter_grain,
            "light_leak": _filter_light_leak, "pastel": _filter_pastel}


def apply_filter(image, kind, settings=None):
    """Return the full-strength filtered RGB image. A filter layer's opacity is
    its Amount, so this always renders the effect at full and the compositor
    crossfades it over the photo."""
    func = _FILTERS.get(kind)
    if func is None:
        raise ValueError(f"Unknown filter: {kind}")
    return func(image.convert("RGB"), settings or {}).convert("RGB")


def apply_lewk_steps(image, steps, upto=None):
    """Run a LEWK's steps in order and return the result (RGB).

    A step is {"type": "adjust", "adjustments": {...}} or
    {"type": "filter", "filter": "orton", "settings": {...}}. ``upto`` limits how
    many steps run — the TEACH ME walkthrough uses it to show the effect building
    up one step at a time.
    """
    result = image.convert("RGB")
    limit = len(steps) if upto is None else max(0, min(int(upto), len(steps)))
    for step in steps[:limit]:
        if step.get("type") == "filter":
            result = apply_filter(result, step.get("filter", ""), step.get("settings"))
        else:
            result = apply_adjustments(result, step.get("adjustments", {}))
    return result


def blend_images(base, top, mode="normal", opacity=1.0):
    base = base.convert("RGBA")
    top = top.convert("RGBA")
    if top.size != base.size:
        top = ImageOps.contain(top, base.size, Image.Resampling.LANCZOS)
        positioned = Image.new("RGBA", base.size, (0, 0, 0, 0))
        positioned.alpha_composite(top, ((base.width - top.width) // 2, (base.height - top.height) // 2))
        top = positioned
    rgb_base, rgb_top = base.convert("RGB"), top.convert("RGB")
    functions = {
        "multiply": ImageChops.multiply, "screen": ImageChops.screen,
        "overlay": ImageChops.overlay, "soft_light": ImageChops.soft_light,
        "hard_light": ImageChops.hard_light, "darken": ImageChops.darker,
        "lighten": ImageChops.lighter, "difference": ImageChops.difference,
    }
    if mode == "color":
        blended = Image.merge("YCbCr", (rgb_base.convert("YCbCr").split()[0],
                                         *rgb_top.convert("YCbCr").split()[1:])).convert("RGB")
    elif mode == "luminosity":
        blended = Image.merge("YCbCr", (rgb_top.convert("YCbCr").split()[0],
                                         *rgb_base.convert("YCbCr").split()[1:])).convert("RGB")
    elif mode in functions:
        blended = functions[mode](rgb_base, rgb_top)
    else:
        blended = rgb_top
    alpha = top.getchannel("A").point(lambda value: int(value * max(0.0, min(1.0, opacity))))
    return Image.composite(blended.convert("RGBA"), base, alpha)


def apply_layer_styles(image, styles):
    result = image.convert("RGBA")
    alpha = result.getchannel("A")
    if styles.get("color_overlay"):
        colour = tuple(styles.get("overlay_color", [255, 255, 255]))
        overlay = Image.new("RGBA", result.size, colour + (255,))
        overlay.putalpha(alpha.point(lambda value: int(value * float(styles.get("overlay_opacity", .35)))))
        result = Image.alpha_composite(result, overlay)
    if styles.get("shadow"):
        shadow = Image.new("RGBA", result.size, (0, 0, 0, 0))
        shadow_alpha = alpha.filter(ImageFilter.GaussianBlur(styles.get("shadow_blur", 8)))
        shadow.putalpha(shadow_alpha)
        offset = int(styles.get("shadow_offset", 6))
        shifted = ImageChops.offset(shadow, offset, offset)
        result = Image.alpha_composite(shifted, result)
    if styles.get("inner_shadow"):
        softened = alpha.filter(ImageFilter.GaussianBlur(styles.get("inner_shadow_blur", 7)))
        inner_alpha = ImageChops.subtract(softened, alpha).point(lambda value: int(value * .65))
        inner = Image.new("RGBA", result.size, (0, 0, 0, 0))
        inner.putalpha(inner_alpha)
        result = Image.alpha_composite(result, inner)
    stroke = int(styles.get("stroke", 0))
    if stroke > 0:
        expanded = alpha.filter(ImageFilter.MaxFilter(stroke * 2 + 1))
        outline = Image.new("RGBA", result.size, tuple(styles.get("stroke_color", [255, 255, 255])) + (255,))
        outline.putalpha(ImageChops.subtract(expanded, alpha))
        result = Image.alpha_composite(outline, result)
    glow = int(styles.get("glow", 0))
    if glow > 0:
        glow_alpha = alpha.filter(ImageFilter.GaussianBlur(glow))
        glow_image = Image.new("RGBA", result.size, tuple(styles.get("glow_color", [255, 255, 255])) + (255,))
        glow_image.putalpha(glow_alpha)
        result = Image.alpha_composite(glow_image, result)
    return result


class EditorDocument:
    _font_reference_cache = {}
    def __init__(self, source_path):
        self.source_path = os.path.abspath(source_path)
        self.adjustments = copy.deepcopy(DEFAULT_ADJUSTMENTS)
        self.geometry = {"rotation": 0.0, "crop": None, "flip_x": False, "flip_y": False}
        self.layers = []
        self.retouched = []
        self.history = []
        self.history_index = -1
        self.project_path = None
        self.on_change = None
        self.record("Open image")
        self.saved_snapshot = self.snapshot()

    def notify_change(self):
        if self.on_change:
            self.on_change(self)

    def snapshot(self):
        return {"adjustments": copy.deepcopy(self.adjustments),
                "geometry": copy.deepcopy(self.geometry),
                "layers": copy.deepcopy(self.layers), "retouched": copy.deepcopy(self.retouched)}

    def restore(self, value):
        self.adjustments = copy.deepcopy(value["adjustments"])
        self.geometry = copy.deepcopy(value["geometry"])
        self.layers = copy.deepcopy(value["layers"])
        self.retouched = copy.deepcopy(value.get("retouched", []))

    def record(self, label):
        state = self.snapshot()
        self.history = self.history[:self.history_index + 1]
        self.history.append({"label": label, "state": state, "time": time.time()})
        self.history_index = len(self.history) - 1
        if len(self.history) > 100:
            self.history.pop(0)
            self.history_index -= 1
        self.notify_change()

    def undo(self):
        if self.history_index <= 0:
            return False
        self.history_index -= 1
        self.restore(self.history[self.history_index]["state"])
        self.notify_change()
        return True

    def redo(self):
        if self.history_index + 1 >= len(self.history):
            return False
        self.history_index += 1
        self.restore(self.history[self.history_index]["state"])
        self.notify_change()
        return True

    def is_dirty(self):
        return self.snapshot() != self.saved_snapshot

    def mark_saved(self):
        self.saved_snapshot = self.snapshot()

    def add_adjustment_layer(self, name="Adjustment"):
        self.layers.append({"id": _new_layer_id(), "name": name, "type": "adjustment",
                            "visible": True, "opacity": 1.0, "blend": "normal",
                            "adjustments": copy.deepcopy(DEFAULT_ADJUSTMENTS), "mask": "",
                            "mask_linked": True, "mask_transform": self.default_transform(),
                            "styles": {}})
        self.record("Add adjustment layer")
        return self.layers[-1]

    def add_image_layer(self, path, name=None):
        self.layers.append({"id": _new_layer_id(), "name": name or os.path.basename(path),
                            "type": "image", "path": os.path.abspath(path), "visible": True,
                            "opacity": 1.0, "blend": "normal",
                            "adjustments": copy.deepcopy(DEFAULT_ADJUSTMENTS),
                            "mask": "", "mask_linked": True,
                            "mask_transform": self.default_transform(), "styles": {},
                            "transform": self.default_transform()})
        self.record("Add image layer")
        return self.layers[-1]

    @staticmethod
    def default_transform():
        return {"x": .5, "y": .5, "scale_x": 1.0, "scale_y": 1.0,
                "rotation": 0.0, "flip_x": False, "flip_y": False}

    def add_text_layer(self, text="Text", name="Text", font_path="", font_size=72):
        self.layers.append({"id": _new_layer_id(), "name": name or "Text",
                            "type": "text", "text": str(text), "font_path": font_path or "",
                            "font_family": os.path.splitext(os.path.basename(font_path))[0]
                            if font_path else "Default", "font_size": int(font_size),
                            "fill": [255, 255, 255, 255], "align": "left",
                            "line_spacing": 4, "character_spacing": 0, "stroke_width": 0,
                            "stroke_fill": [0, 0, 0, 255], "visible": True,
                            "background": False, "background_fill": [0, 0, 0, 180],
                            "background_padding": 8, "text_box_width": 0,
                            "opacity": 1.0, "blend": "normal", "mask": "",
                            "adjustments": copy.deepcopy(DEFAULT_ADJUSTMENTS),
                            "mask_linked": True, "mask_transform": self.default_transform(),
                            "styles": {}, "transform": self.default_transform()})
        self.record("Add text layer")
        return self.layers[-1]

    @staticmethod
    def _image_layer_canvas(top, canvas_size, transform, linked_mask=None):
        """Transform an image layer and place it on a transparent document canvas."""
        scale_x = max(.01, min(20.0, float(transform.get("scale_x", 1.0))))
        scale_y = max(.01, min(20.0, float(transform.get("scale_y", 1.0))))
        if transform.get("flip_x"):
            top = ImageOps.mirror(top)
        if transform.get("flip_y"):
            top = ImageOps.flip(top)
        if linked_mask is not None:
            linked_mask = linked_mask.resize(top.size, Image.Resampling.LANCZOS)
            top.putalpha(ImageChops.multiply(top.getchannel("A"), linked_mask))
        size = (max(1, int(round(top.width * scale_x))),
                max(1, int(round(top.height * scale_y))))
        if size != top.size:
            top = top.resize(size, Image.Resampling.LANCZOS)
        rotation = float(transform.get("rotation", 0.0)) % 360
        if rotation:
            top = top.rotate(-rotation, expand=True, resample=Image.Resampling.BICUBIC)
        x = int(round(float(transform.get("x", .5)) * canvas_size[0] - top.width / 2))
        y = int(round(float(transform.get("y", .5)) * canvas_size[1] - top.height / 2))
        canvas = Image.new("RGBA", canvas_size, (0, 0, 0, 0))
        canvas.alpha_composite(top, (x, y))
        return canvas

    @staticmethod
    def _fit_layer_image(top, canvas_size, fit):
        """Fit an image/texture layer to the document canvas.

        cover  — fill the frame, cropping overflow (keeps aspect)
        contain— fit inside the frame, centred (keeps aspect)
        stretch— resize to the exact frame (ignores aspect)
        tile   — repeat the image across the frame
        """
        width, height = canvas_size
        top = top.convert("RGBA")
        if fit == "stretch":
            return top.resize((width, height), Image.Resampling.LANCZOS)
        if fit == "cover":
            return ImageOps.fit(top, (width, height), Image.Resampling.LANCZOS)
        if fit == "contain":
            fitted = ImageOps.contain(top, (width, height), Image.Resampling.LANCZOS)
            canvas = Image.new("RGBA", (width, height), (0, 0, 0, 0))
            canvas.alpha_composite(fitted, ((width - fitted.width) // 2,
                                            (height - fitted.height) // 2))
            return canvas
        if fit == "tile":
            canvas = Image.new("RGBA", (width, height), (0, 0, 0, 0))
            for y in range(0, height, max(1, top.height)):
                for x in range(0, width, max(1, top.width)):
                    canvas.alpha_composite(top, (x, y))
            return canvas
        return top

    @classmethod
    def _canvas_mask(cls, mask, canvas_size, transform):
        source = Image.new("RGBA", mask.size, (255, 255, 255, 255))
        source.putalpha(mask)
        return cls._image_layer_canvas(source, canvas_size, transform).getchannel("A")

    @staticmethod
    def _font_reference(layer):
        path = layer.get("font_path", "")
        if path and os.path.isfile(path):
            return path
        family = str(layer.get("font_family", "Default"))
        if family in {"", "Default"}:
            return ""
        key = "".join(character.lower() for character in family if character.isalnum())
        if key in EditorDocument._font_reference_cache:
            return EditorDocument._font_reference_cache[key]
        roots = []
        windows = os.environ.get("WINDIR")
        if windows:
            roots.append(os.path.join(windows, "Fonts"))
        roots.extend((os.path.expanduser("~/.fonts"), os.path.expanduser("~/.local/share/fonts"),
                      "/usr/share/fonts", "/usr/local/share/fonts", "/Library/Fonts",
                      "/System/Library/Fonts"))
        best = ""
        for root in roots:
            if not os.path.isdir(root):
                continue
            for directory, _folders, files in os.walk(root):
                for filename in files:
                    if os.path.splitext(filename)[1].lower() not in {".ttf", ".otf"}:
                        continue
                    stem = "".join(character.lower() for character in os.path.splitext(filename)[0]
                                   if character.isalnum())
                    if stem == key:
                        best = os.path.join(directory, filename); break
                    if not best and (key in stem or stem in key):
                        best = os.path.join(directory, filename)
                if best and "".join(character.lower() for character in os.path.splitext(
                        os.path.basename(best))[0] if character.isalnum()) == key:
                    break
            if best:
                break
        EditorDocument._font_reference_cache[key] = best
        return best

    @staticmethod
    def _text_layer_image(layer):
        size = max(1, min(2000, int(layer.get("font_size", 72))))
        reference = EditorDocument._font_reference(layer)
        try:
            font = ImageFont.truetype(reference, size) if reference else ImageFont.load_default(size=size)
        except (OSError, TypeError):
            font = ImageFont.load_default()
        text = str(layer.get("text", "Text")) or " "
        spacing = int(layer.get("line_spacing", 4))
        character_spacing = int(layer.get("character_spacing", 0))
        stroke = max(0, min(100, int(layer.get("stroke_width", 0))))
        probe = Image.new("L", (1, 1))
        draw = ImageDraw.Draw(probe)
        text_box_width = max(0, int(layer.get("text_box_width", 0)))
        source_lines = text.splitlines() or [" "]
        if text_box_width:
            average = max(1, draw.textlength("M", font=font) + character_spacing)
            columns = max(1, int(text_box_width / average))
            lines = [wrapped for source_line in source_lines
                     for wrapped in (textwrap.wrap(source_line, width=columns,
                                                   replace_whitespace=False,
                                                   drop_whitespace=False) or [" "])]
        else:
            lines = source_lines
        widths = []
        for line in lines:
            advances = [draw.textlength(character, font=font) for character in (line or " ")]
            widths.append(int(math.ceil(sum(advances) + character_spacing * max(0, len(advances) - 1))))
        line_box = draw.textbbox((0, 0), "Ag", font=font, stroke_width=stroke)
        line_height = max(1, line_box[3] - line_box[1])
        padding = max(0, int(layer.get("background_padding", 8))) if layer.get("background") else 0
        width = max(1, max(widths)); height = max(1, len(lines) * line_height + max(0, len(lines) - 1) * spacing)
        output = Image.new("RGBA", (width + (stroke + padding + 2) * 2,
                                    height + (stroke + padding + 2) * 2),
                           tuple(layer.get("background_fill", [0, 0, 0, 180]))
                           if layer.get("background") else (0, 0, 0, 0))
        painter = ImageDraw.Draw(output)
        top = stroke + padding + 2
        align = layer.get("align", "left")
        for line_index, line in enumerate(lines):
            line_width = widths[line_index]
            left = stroke + padding + 2
            if align == "center":
                left += (width - line_width) / 2
            elif align == "right":
                left += width - line_width
            y = top + line_index * (line_height + spacing)
            for character in (line or " "):
                painter.text((left, y), character, font=font,
                             fill=tuple(layer.get("fill", [255, 255, 255, 255])),
                             stroke_width=stroke,
                             stroke_fill=tuple(layer.get("stroke_fill", [0, 0, 0, 255])))
                left += painter.textlength(character, font=font) + character_spacing
        return output

    def render(self, max_size=None):
        with Image.open(self.source_path) as source:
            image = ImageOps.exif_transpose(source).convert("RGB")
        rotation = float(self.geometry.get("rotation", 0))
        if rotation:
            image = image.rotate(rotation, expand=True, resample=Image.Resampling.BICUBIC)
        if self.geometry.get("flip_x"):
            image = ImageOps.mirror(image)
        if self.geometry.get("flip_y"):
            image = ImageOps.flip(image)
        crop = self.geometry.get("crop")
        if crop:
            left, top, right, bottom = crop
            image = image.crop((int(left * image.width), int(top * image.height),
                                int(right * image.width), int(bottom * image.height)))
        if max_size:
            image.thumbnail(max_size, Image.Resampling.LANCZOS)
        image = apply_adjustments(image, self.adjustments).convert("RGBA")
        if self.retouched:
            for spot in self.retouched:
                x = int(float(spot.get("x", .5)) * image.width)
                y = int(float(spot.get("y", .5)) * image.height)
                radius = max(2, int(float(spot.get("radius", .02)) * min(image.size)))
                box = (max(0, x - radius), max(0, y - radius),
                       min(image.width, x + radius), min(image.height, y + radius))
                if box[2] > box[0] and box[3] > box[1]:
                    patch = image.crop(box)
                    if spot.get("type") == "red_eye":
                        red, green, blue, alpha = patch.split()
                        red = red.point(lambda value: int(value * .35))
                        corrected = Image.merge("RGBA", (red, green, blue, alpha))
                        patch = Image.blend(patch, corrected, .8)
                    else:
                        patch = patch.filter(ImageFilter.GaussianBlur(max(2, radius / 3)))
                    mask = Image.new("L", patch.size, 0)
                    ImageDraw.Draw(mask).ellipse((0, 0, patch.width - 1, patch.height - 1), fill=255)
                    mask = mask.filter(ImageFilter.GaussianBlur(max(1, radius / 5)))
                    image.paste(patch, box[:2], mask)
        for layer in self.layers:
            if not layer.get("visible", True):
                continue
            if layer.get("type") == "adjustment":
                adjusted = apply_adjustments(image.convert("RGB"), layer.get("adjustments", {})).convert("RGBA")
                top = adjusted
            elif layer.get("type") == "image":
                path = layer.get("path", "")
                if not os.path.isfile(path):
                    name = layer.get("name") or os.path.basename(path) or "unnamed layer"
                    raise FileNotFoundError(f'Image layer "{name}" is missing: {path}')
                with Image.open(path) as source_layer:
                    top = ImageOps.exif_transpose(source_layer).convert("RGBA")
                if max_size:
                    top.thumbnail(image.size, Image.Resampling.LANCZOS)
                alpha = top.getchannel("A")
                top = apply_adjustments(
                    top.convert("RGB"), layer.get("adjustments", {})).convert("RGBA")
                top.putalpha(alpha)
                fit = layer.get("fit", "original")
                if fit in ("cover", "contain", "stretch", "tile"):
                    top = self._fit_layer_image(top, image.size, fit)
            elif layer.get("type") == "text":
                top = self._text_layer_image(layer)
                alpha = top.getchannel("A")
                top = apply_adjustments(top.convert("RGB"), layer.get("adjustments", {})).convert("RGBA")
                top.putalpha(alpha)
            else:
                continue
            mask = (_mask_from_text(layer.get("mask", ""))
                    if layer.get("mask_enabled", True) else None)
            if layer.get("type") in {"image", "text"}:
                linked_mask = mask if mask is not None and layer.get("mask_linked", True) else None
                # A fit mode already sized the layer to the canvas; place it
                # centred at scale 1 rather than re-applying the free transform.
                fitted = layer.get("fit", "original") in ("cover", "contain", "stretch", "tile")
                transform = self.default_transform() if fitted else layer.get("transform", {})
                top = self._image_layer_canvas(top, image.size, transform, linked_mask)
                if mask is not None and not layer.get("mask_linked", True):
                    mask = self._canvas_mask(mask, image.size,
                                             layer.get("mask_transform", {}))
                    top.putalpha(ImageChops.multiply(top.getchannel("A"), mask))
            elif mask:
                if mask.size != image.size:
                    mask = mask.resize(image.size, Image.Resampling.LANCZOS)
                top.putalpha(ImageChops.multiply(top.getchannel("A"), mask))
            top = apply_layer_styles(top, layer.get("styles", {}))
            image = blend_images(image, top, layer.get("blend", "normal"), float(layer.get("opacity", 1.0)))
        return image.convert("RGB")

    def histogram(self, max_size=(512, 512)):
        image = self.render(max_size)
        red, green, blue = image.split()
        return {"red": red.histogram(), "green": green.histogram(), "blue": blue.histogram(),
                "luminance": ImageOps.grayscale(image).histogram()}

    def project_value(self, recovery=False):
        value = {"version": PROJECT_VERSION, "source_path": self.source_path,
                 "adjustments": self.adjustments, "geometry": self.geometry,
                 "layers": self.layers, "retouched": self.retouched}
        if recovery:
            value["recovery"] = True
            value["project_path"] = self.project_path
        return value

    def save_project(self, path):
        if photo_manager.same_file(path, self.source_path):
            raise ValueError("SNAP SLAPPER will not overwrite the original photograph with a project.")
        value = self.project_value()
        _write_project_archive(path, value)
        self.project_path = path
        self.mark_saved()

    def save_recovery(self, path):
        if photo_manager.same_file(path, self.source_path):
            raise ValueError("Recovery path resolves to the original photograph.")
        _write_project_archive(path, self.project_value(recovery=True))

    @classmethod
    def load_project(cls, path):
        if os.path.getsize(path) > MAX_PROJECT_BYTES:
            raise ValueError("SNAP SLAPPER project is too large to open safely")
        value = _read_project_document(path)
        if not isinstance(value, dict):
            raise ValueError("Invalid SNAP SLAPPER project: the root must be an object")
        if value.get("version") != PROJECT_VERSION:
            raise ValueError("Unsupported SNAP SLAPPER project version")
        source_path = value.get("source_path")
        if not isinstance(source_path, str) or not source_path.strip():
            raise ValueError("Invalid SNAP SLAPPER project: source_path is missing")
        if not os.path.isfile(source_path):
            raise FileNotFoundError(f"The project's original photograph is missing: {source_path}")
        if os.path.splitext(source_path)[1].lower() in photo_manager.RAW_EXTENSIONS:
            raise ValueError("This project references a RAW photograph. Open the original with "
                             "RawTherapee or darktable.")
        expected_types = {"adjustments": dict, "geometry": dict,
                          "layers": list, "retouched": list}
        for field, expected in expected_types.items():
            if field in value and not isinstance(value[field], expected):
                raise ValueError(f"Invalid SNAP SLAPPER project: {field} has the wrong type")
        layers = value.get("layers", [])
        retouched = value.get("retouched", [])
        if len(layers) > MAX_PROJECT_LAYERS:
            raise ValueError("Invalid SNAP SLAPPER project: too many layers")
        if len(retouched) > MAX_RETOUCH_POINTS:
            raise ValueError("Invalid SNAP SLAPPER project: too many retouch points")
        for index, layer in enumerate(layers):
            if not isinstance(layer, dict):
                raise ValueError(f"Invalid SNAP SLAPPER project: layer {index + 1} is not an object")
            mask = layer.get("mask", "")
            if not isinstance(mask, str) or len(mask) > MAX_ENCODED_MASK_BYTES:
                raise ValueError(f"Invalid SNAP SLAPPER project: layer {index + 1} mask is invalid")
            if layer.get("type") == "text":
                text = layer.get("text", "")
                if not isinstance(text, str) or len(text) > MAX_TEXT_LAYER_CHARS:
                    raise ValueError(f"Invalid SNAP SLAPPER project: layer {index + 1} text is invalid")
                if not isinstance(layer.get("font_path", ""), str):
                    raise ValueError(f"Invalid SNAP SLAPPER project: layer {index + 1} font is invalid")
        document = cls(source_path)
        document.adjustments = value.get("adjustments", copy.deepcopy(DEFAULT_ADJUSTMENTS))
        document.geometry = value.get("geometry", document.geometry)
        document.layers = layers
        document.retouched = retouched
        document.history = []
        document.history_index = -1
        if value.get("recovery"):
            project_path = value.get("project_path")
            document.project_path = project_path if isinstance(project_path, str) else None
            document.saved_snapshot = None
        else:
            document.project_path = path
        document.record("Open project")
        if not value.get("recovery"):
            document.mark_saved()
        return document

    def export(self, path, quality=95, copyright_text="", strip_gps=False):
        output = self.render()
        extension = os.path.splitext(path)[1].lower()
        options = {"quality": quality, "optimize": True} if extension in {".jpg", ".jpeg", ".webp"} else {}
        photo_manager.save_with_metadata(output, path, self.source_path,
                                         copyright_text, strip_gps=strip_gps, **options)

    def recipe(self):
        return {"version": PROJECT_VERSION, "adjustments": copy.deepcopy(self.adjustments),
                "layers": [copy.deepcopy(layer) for layer in self.layers if layer.get("type") == "adjustment"]}

    def stack_layers(self, layers):
        """Add adjustment layers ON TOP without touching the base or existing
        layers. This is how a LEWK applies — it must not flatten the
        photographer's existing edits. Each layer gets a fresh unique id.
        """
        added = []
        for layer in layers:
            if not isinstance(layer, dict) or layer.get("type") != "adjustment":
                raise ValueError("stack_layers only accepts adjustment layers")
            mask = layer.get("mask", "")
            if not isinstance(mask, str) or len(mask) > MAX_ENCODED_MASK_BYTES:
                raise ValueError("Invalid layer mask")
            clone = copy.deepcopy(layer)
            clone["id"] = _new_layer_id()
            self.layers.append(clone)
            added.append(clone)
        if len(self.layers) > MAX_PROJECT_LAYERS:
            raise ValueError("Too many layers")
        self.record("Apply LEWK")
        return added

    def apply_recipe(self, recipe):
        if not isinstance(recipe, dict):
            raise ValueError("Invalid SNAP SLAPPER recipe: the root must be an object")
        if recipe.get("version") != PROJECT_VERSION:
            raise ValueError("Unsupported recipe version")
        adjustments = recipe.get("adjustments", DEFAULT_ADJUSTMENTS)
        layers = recipe.get("layers", [])
        if not isinstance(adjustments, dict) or not isinstance(layers, list):
            raise ValueError("Invalid SNAP SLAPPER recipe contents")
        if len(layers) > MAX_PROJECT_LAYERS:
            raise ValueError("Invalid SNAP SLAPPER recipe: too many layers")
        for index, layer in enumerate(layers):
            if not isinstance(layer, dict) or layer.get("type") != "adjustment":
                raise ValueError(f"Invalid SNAP SLAPPER recipe: layer {index + 1} is invalid")
            mask = layer.get("mask", "")
            if not isinstance(mask, str) or len(mask) > MAX_ENCODED_MASK_BYTES:
                raise ValueError(f"Invalid SNAP SLAPPER recipe: layer {index + 1} mask is invalid")
        self.adjustments = copy.deepcopy(adjustments)
        self.layers.extend(copy.deepcopy(layers))
        self.record("Apply recipe")


def save_recipe(path, recipe):
    photo_manager.atomic_json(path, recipe)


def load_recipe(path):
    if os.path.getsize(path) > MAX_PROJECT_BYTES:
        raise ValueError("SNAP SLAPPER recipe is too large to open safely")
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle, parse_constant=photo_manager.reject_json_constant)


def batch_apply(paths, recipe, destination, suffix="_edited", quality=95,
                copyright_text="", strip_gps=False):
    os.makedirs(destination, exist_ok=True)
    outputs = []
    for source in paths:
        document = EditorDocument(source)
        document.apply_recipe(recipe)
        stem = os.path.splitext(os.path.basename(source))[0]
        target = os.path.join(destination, stem + suffix + ".jpg")
        number = 2
        while os.path.exists(target):
            target = os.path.join(destination, f"{stem}{suffix}_{number}.jpg")
            number += 1
        document.export(target, quality, copyright_text, strip_gps)
        outputs.append(target)
    return outputs

# ===== SNAPSMACK EOF =====
