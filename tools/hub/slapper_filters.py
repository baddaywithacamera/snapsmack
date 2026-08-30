"""Non-destructive photographic filter processors used by filter layers."""

import math
import random

from PIL import Image, ImageChops, ImageEnhance, ImageFilter, ImageOps


FILTER_DEFAULTS = {
    "orton": {"amount": 35, "radius": 12, "brightness": 12,
               "contrast": 5, "saturation": 5, "highlight_protection": 35,
               "shadow_protection": 15},
    "film_grain": {"amount": 24, "size": 1.4, "roughness": 55,
                   "softness": 20, "monochrome": True,
                   "color_variation": 15, "shadows": 80,
                   "midtones": 100, "highlights": 35, "seed": 7319},
    "light_leak": {"amount": 35, "position": 25, "edge": "left",
                   "rotation": 0, "spread": 55, "length": 70,
                   "softness": 70, "primary": [255, 82, 25],
                   "secondary": [255, 210, 60], "warmth": 20,
                   "bloom": 20, "seed": 4103},
    "pastel": {"amount": 45, "softness": 12, "lifted_blacks": 18,
               "highlight_rolloff": 25, "contrast_reduction": 22,
               "saturation": -8, "vibrance": 12, "warmth": 4,
               "fade": 12, "tint": [255, 225, 235], "tint_strength": 8},
}

FILTER_NAMES = {
    "orton": "Orton Effect", "film_grain": "Film Grain",
    "light_leak": "Light Leak", "pastel": "Pastel Effect",
}


def defaults(kind):
    import copy
    if kind not in FILTER_DEFAULTS:
        raise ValueError(f"Unsupported filter type: {kind}")
    return copy.deepcopy(FILTER_DEFAULTS[kind])


def _blend(original, effect, amount):
    return Image.blend(original.convert("RGB"), effect.convert("RGB"),
                       max(0.0, min(1.0, float(amount) / 100.0)))


def _orton(image, settings):
    radius = max(.1, float(settings.get("radius", 12)))
    glow = image.filter(ImageFilter.GaussianBlur(radius))
    glow = ImageEnhance.Brightness(glow).enhance(
        1 + float(settings.get("brightness", 12)) / 100)
    glow = ImageEnhance.Contrast(glow).enhance(
        1 + float(settings.get("contrast", 5)) / 100)
    glow = ImageEnhance.Color(glow).enhance(
        1 + float(settings.get("saturation", 5)) / 100)
    screen = ImageChops.screen(image, glow)
    protection = max(0, min(100, float(settings.get("highlight_protection", 35))))
    if protection:
        luma = ImageOps.grayscale(image)
        mask = luma.point(lambda value: int(value * protection / 100))
        screen = Image.composite(image, screen, mask)
    return screen


def _grain(image, settings):
    width, height = image.size
    size = max(1, int(round(float(settings.get("size", 1.4)))))
    nw, nh = max(1, width // size), max(1, height // size)
    rng = random.Random(int(settings.get("seed", 7319)))
    roughness = max(1, min(100, int(settings.get("roughness", 55))))
    mono = bool(settings.get("monochrome", True))
    if mono:
        values = [max(0, min(255, 128 + rng.randint(-roughness, roughness)))
                  for _ in range(nw * nh)]
        noise = Image.new("L", (nw, nh)); noise.putdata(values)
        noise = Image.merge("RGB", (noise, noise, noise))
    else:
        variation = max(1, int(settings.get("color_variation", 15)))
        values = [tuple(max(0, min(255, 128 + rng.randint(-roughness, roughness)
                                                   + rng.randint(-variation, variation)))
                        for _ in range(3)) for _ in range(nw * nh)]
        noise = Image.new("RGB", (nw, nh)); noise.putdata(values)
    noise = noise.resize((width, height), Image.Resampling.BILINEAR)
    softness = float(settings.get("softness", 20)) / 40
    if softness > 0:
        noise = noise.filter(ImageFilter.GaussianBlur(softness))
    return ImageChops.overlay(image, noise)


def _light_leak(image, settings):
    width, height = image.size
    small_w, small_h = 256, max(1, int(256 * height / max(1, width)))
    edge = settings.get("edge", "left")
    spread = max(.05, float(settings.get("spread", 55)) / 100)
    length = max(.05, float(settings.get("length", 70)) / 100)
    first = settings.get("primary", [255, 82, 25])
    second = settings.get("secondary", [255, 210, 60])
    overlay = Image.new("RGB", (small_w, small_h), (0, 0, 0))
    pixels = overlay.load()
    for y in range(small_h):
        for x in range(small_w):
            nx, ny = x / max(1, small_w - 1), y / max(1, small_h - 1)
            distance = nx if edge == "left" else 1 - nx if edge == "right" \
                else ny if edge == "top" else 1 - ny
            across = abs((ny if edge in {"left", "right"} else nx) -
                         float(settings.get("position", 25)) / 100)
            strength = max(0.0, 1 - distance / length) * \
                math.exp(-(across * across) / max(.001, spread * spread / 3))
            mix = min(1.0, distance / max(.01, length))
            pixels[x, y] = tuple(int((first[i] * (1 - mix) + second[i] * mix) * strength)
                                 for i in range(3))
    overlay = overlay.resize((width, height), Image.Resampling.BICUBIC)
    return ImageChops.screen(image, overlay)


def _pastel(image, settings):
    result = image.filter(ImageFilter.GaussianBlur(
        max(0, float(settings.get("softness", 12)) / 20)))
    contrast = 1 - max(0, float(settings.get("contrast_reduction", 22))) / 140
    result = ImageEnhance.Contrast(result).enhance(contrast)
    result = ImageEnhance.Color(result).enhance(
        max(0, 1 + float(settings.get("saturation", -8)) / 100))
    lift = max(0, min(80, int(settings.get("lifted_blacks", 18))))
    result = result.point(lambda value: int(lift + value * (255 - lift) / 255))
    tint = Image.new("RGB", result.size, tuple(settings.get("tint", [255, 225, 235])))
    result = Image.blend(result, tint,
                         max(0, min(1, float(settings.get("tint_strength", 8)) / 100)))
    return result


def apply_filter(image, kind, settings=None):
    settings = {**defaults(kind), **(settings or {})}
    original = image.convert("RGB")
    if kind == "orton":
        effect = _orton(original, settings)
    elif kind == "film_grain":
        effect = _grain(original, settings)
    elif kind == "light_leak":
        effect = _light_leak(original, settings)
    elif kind == "pastel":
        effect = _pastel(original, settings)
    else:
        raise ValueError(f"Unsupported filter type: {kind}")
    return _blend(original, effect, settings.get("amount", 100))


# ===== SNAPSMACK EOF =====
