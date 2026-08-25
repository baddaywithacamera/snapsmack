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

from PIL import Image, ImageChops, ImageDraw, ImageEnhance, ImageFilter, ImageOps

import photo_manager


PROJECT_VERSION = 1
DEFAULT_ADJUSTMENTS = {
    "exposure": 0.0, "brightness": 0.0, "contrast": 0.0,
    "highlights": 0.0, "shadows": 0.0, "whites": 0.0, "blacks": 0.0,
    "temperature": 0.0, "tint": 0.0, "saturation": 0.0, "vibrance": 0.0,
    "clarity": 0.0, "texture": 0.0, "dehaze": 0.0, "sharpen": 0.0,
    "level_black": 0.0, "level_gamma": 1.0, "level_white": 255.0,
    "black_white": False, "vignette": 0.0, "grain": 0.0,
    "curve": [[0, 0], [255, 255]],
}


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
    sharpen = float(settings["sharpen"])
    if sharpen > 0:
        output = output.filter(ImageFilter.UnsharpMask(radius=1.2, percent=int(sharpen * 2.5), threshold=3))

    curve = settings.get("curve") or [[0, 0], [255, 255]]
    output = output.point(_curve_lut(curve) * 3)
    if settings.get("black_white"):
        mono = ImageOps.grayscale(output)
        output = Image.merge("RGB", (mono, mono, mono))

    vignette = float(settings["vignette"])
    if vignette:
        width, height = output.size
        mask = Image.new("L", (width, height), 0)
        draw = ImageDraw.Draw(mask)
        inset_x, inset_y = int(width * .08), int(height * .08)
        draw.ellipse((inset_x, inset_y, width - inset_x, height - inset_y), fill=255)
        mask = mask.filter(ImageFilter.GaussianBlur(radius=max(width, height) * .16))
        strength = abs(vignette) / 100.0
        edge = Image.new("RGB", output.size, (0, 0, 0) if vignette < 0 else (255, 255, 255))
        blend_mask = mask.point(lambda value: int(255 - (255 - value) * strength))
        output = Image.composite(output, edge, blend_mask)

    grain = float(settings["grain"])
    if grain > 0:
        amount = grain / 100.0 * 32
        noise = Image.effect_noise(output.size, amount)
        noise_rgb = Image.merge("RGB", (noise, noise, noise))
        output = ImageChops.soft_light(output, noise_rgb)
    return output


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
    def __init__(self, source_path):
        self.source_path = os.path.abspath(source_path)
        self.adjustments = copy.deepcopy(DEFAULT_ADJUSTMENTS)
        self.geometry = {"rotation": 0.0, "crop": None, "flip_x": False, "flip_y": False}
        self.layers = []
        self.retouched = []
        self.history = []
        self.history_index = -1
        self.project_path = None
        self.record("Open image")

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

    def undo(self):
        if self.history_index <= 0:
            return False
        self.history_index -= 1
        self.restore(self.history[self.history_index]["state"])
        return True

    def redo(self):
        if self.history_index + 1 >= len(self.history):
            return False
        self.history_index += 1
        self.restore(self.history[self.history_index]["state"])
        return True

    def add_adjustment_layer(self, name="Adjustment"):
        self.layers.append({"id": str(time.time_ns()), "name": name, "type": "adjustment",
                            "visible": True, "opacity": 1.0, "blend": "normal",
                            "adjustments": copy.deepcopy(DEFAULT_ADJUSTMENTS), "mask": "", "styles": {}})
        self.record("Add adjustment layer")
        return self.layers[-1]

    def add_image_layer(self, path, name=None):
        self.layers.append({"id": str(time.time_ns()), "name": name or os.path.basename(path),
                            "type": "image", "path": os.path.abspath(path), "visible": True,
                            "opacity": 1.0, "blend": "normal", "mask": "", "styles": {}})
        self.record("Add image layer")
        return self.layers[-1]

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
            else:
                path = layer.get("path", "")
                if not os.path.isfile(path):
                    continue
                with Image.open(path) as source_layer:
                    top = ImageOps.exif_transpose(source_layer).convert("RGBA")
                if max_size:
                    top.thumbnail(image.size, Image.Resampling.LANCZOS)
            top = apply_layer_styles(top, layer.get("styles", {}))
            mask = _mask_from_text(layer.get("mask", ""))
            if mask:
                if mask.size != image.size:
                    mask = mask.resize(image.size, Image.Resampling.LANCZOS)
                top.putalpha(ImageChops.multiply(top.getchannel("A"), mask))
            image = blend_images(image, top, layer.get("blend", "normal"), float(layer.get("opacity", 1.0)))
        return image.convert("RGB")

    def histogram(self, max_size=(512, 512)):
        image = self.render(max_size)
        red, green, blue = image.split()
        return {"red": red.histogram(), "green": green.histogram(), "blue": blue.histogram(),
                "luminance": ImageOps.grayscale(image).histogram()}

    def save_project(self, path):
        value = {"version": PROJECT_VERSION, "source_path": self.source_path,
                 "adjustments": self.adjustments, "geometry": self.geometry,
                 "layers": self.layers, "retouched": self.retouched}
        os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
        temporary = path + ".tmp"
        with open(temporary, "w", encoding="utf-8") as handle:
            json.dump(value, handle, indent=2)
        os.replace(temporary, path)
        self.project_path = path

    @classmethod
    def load_project(cls, path):
        with open(path, "r", encoding="utf-8") as handle:
            value = json.load(handle)
        if value.get("version") != PROJECT_VERSION:
            raise ValueError("Unsupported SNAP SLAPPER project version")
        document = cls(value["source_path"])
        document.adjustments = value.get("adjustments", copy.deepcopy(DEFAULT_ADJUSTMENTS))
        document.geometry = value.get("geometry", document.geometry)
        document.layers = value.get("layers", [])
        document.retouched = value.get("retouched", [])
        document.history = []
        document.history_index = -1
        document.project_path = path
        document.record("Open project")
        return document

    def export(self, path, quality=95, copyright_text=""):
        output = self.render()
        extension = os.path.splitext(path)[1].lower()
        options = {"quality": quality, "optimize": True} if extension in {".jpg", ".jpeg", ".webp"} else {}
        photo_manager.save_with_metadata(output, path, self.source_path,
                                         copyright_text, **options)

    def recipe(self):
        return {"version": PROJECT_VERSION, "adjustments": copy.deepcopy(self.adjustments),
                "layers": [copy.deepcopy(layer) for layer in self.layers if layer.get("type") == "adjustment"]}

    def apply_recipe(self, recipe):
        if recipe.get("version") != PROJECT_VERSION:
            raise ValueError("Unsupported recipe version")
        self.adjustments = copy.deepcopy(recipe.get("adjustments", DEFAULT_ADJUSTMENTS))
        self.layers.extend(copy.deepcopy(recipe.get("layers", [])))
        self.record("Apply recipe")


def save_recipe(path, recipe):
    temporary = path + ".tmp"
    with open(temporary, "w", encoding="utf-8") as handle:
        json.dump(recipe, handle, indent=2)
    os.replace(temporary, path)


def load_recipe(path):
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)


def batch_apply(paths, recipe, destination, suffix="_edited", quality=95,
                copyright_text=""):
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
        document.export(target, quality, copyright_text)
        outputs.append(target)
    return outputs

# ===== SNAPSMACK EOF =====
