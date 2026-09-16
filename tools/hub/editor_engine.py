"""Non-destructive SNAP SLAPPER editing document and Pillow renderer.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import base64
import copy
import hmac
import io
import json
import math
import os
import time
import tempfile
from pathlib import PurePosixPath
import textwrap
import threading
import zipfile
from collections import OrderedDict

from PIL import (Image, ImageChops, ImageDraw, ImageEnhance, ImageFilter,
                 ImageFont, ImageMath, ImageOps)
import numpy as np

import photo_manager
import slapper_filters
import slapper_provenance
import render_graph
from artifact_registry import ArtifactKind, ArtifactRegistry
import highbit_image as highbit

# SECAUDIT 054 chokepoint 1 (image ingress): importing snap_imgsafe pins
# Image.MAX_IMAGE_PIXELS process-wide (decompression-bomb cap) for EVERY
# Image.open in the editor, and _mask_from_text below routes .slapper-embedded
# mask blobs through its allowlisted safe_open. The import is best-effort only
# because the Tk main and Qt main each add _shared to sys.path before this
# module loads; a missing module FAILS CLOSED at the mask gate, never open.
try:
    import snap_imgsafe
except ImportError:  # _shared not on path — untrusted decodes will refuse below
    snap_imgsafe = None


# Fit/live previews repeatedly render the same source. Decoding a large TIFF
# for every slider tick costs far more than the tonal adjustment, so retain a
# few downsampled, orientation-correct source frames. Cached images are never
# handed out directly because Pillow operations are not uniformly immutable.
_PREVIEW_SOURCE_CACHE = OrderedDict()
_PREVIEW_SOURCE_CACHE_LOCK = threading.RLock()
_PREVIEW_SOURCE_CACHE_LIMIT = 4

# Float compositor equivalent. The Qt editor renders each slider frame from a
# fresh immutable document snapshot on worker threads; without this cache every
# frame re-ran the bounded OIIO decoder against the same 16-bit TIFF. That was
# the visible multi-second pause. Keep the largest recent proxy and derive
# smaller drag frames from RAM.
_FLOAT_SOURCE_CACHE = OrderedDict()
_FLOAT_SOURCE_CACHE_LOCK = threading.RLock()
_FLOAT_SOURCE_CACHE_LIMIT = 3
_MASK_IMAGE_CACHE = OrderedDict()
_MASK_IMAGE_CACHE_LOCK = threading.RLock()
_MASK_IMAGE_CACHE_LIMIT = 8
_MASK_IMAGE_CACHE_PIXELS = 256 * 1024 * 1024


def _read_float_source(path, max_size=None):
    absolute = os.path.abspath(path)
    try:
        signature = (absolute, os.path.getmtime(absolute), os.path.getsize(absolute))
    except OSError:
        return highbit.read(absolute, max_size)
    requested = tuple(map(int, max_size)) if max_size else None
    with _FLOAT_SOURCE_CACHE_LOCK:
        entry = _FLOAT_SOURCE_CACHE.get(signature)
        if entry is not None:
            cached, native = entry
            _FLOAT_SOURCE_CACHE.move_to_end(signature)
            if requested is not None:
                # A cached larger proxy is sufficient for this viewport. Never
                # upscale a small cache entry; decode once at the needed size.
                scale = min(requested[0] / cached.width,
                            requested[1] / cached.height, 1.0)
                if native or cached.width >= requested[0] or cached.height >= requested[1]:
                    return cached.resized(requested)
            elif native:
                return cached
        image = highbit.read(absolute, requested)
        native = requested is None or (image.width < requested[0] and
                                       image.height < requested[1])
        _FLOAT_SOURCE_CACHE[signature] = (image, native)
        _FLOAT_SOURCE_CACHE.move_to_end(signature)
        while len(_FLOAT_SOURCE_CACHE) > _FLOAT_SOURCE_CACHE_LIMIT:
            _FLOAT_SOURCE_CACHE.popitem(last=False)
        return image


def _open_source_image(path, max_size=None):
    """Decode *path*, using a reusable reduced source for preview renders."""
    if not max_size:
        with Image.open(path) as source:
            return ImageOps.exif_transpose(source).convert("RGB")

    requested = (max(1, int(max_size[0])), max(1, int(max_size[1])))
    try:
        stamp = os.stat(path).st_mtime_ns
    except OSError:
        stamp = 0
    cache_key = (os.path.normcase(os.path.abspath(path)), stamp)
    with _PREVIEW_SOURCE_CACHE_LOCK:
        cached = _PREVIEW_SOURCE_CACHE.get(cache_key)
        if cached is not None:
            cached_bound, cached_image = cached
            if (cached_bound[0] >= requested[0] and
                    cached_bound[1] >= requested[1]):
                _PREVIEW_SOURCE_CACHE.move_to_end(cache_key)
                image = cached_image.copy()
                image.thumbnail(requested, Image.Resampling.LANCZOS)
                return image

        with Image.open(path) as source:
            image = ImageOps.exif_transpose(source).convert("RGB")
        image.thumbnail(requested, Image.Resampling.LANCZOS)
        _PREVIEW_SOURCE_CACHE[cache_key] = (requested, image.copy())
        _PREVIEW_SOURCE_CACHE.move_to_end(cache_key)
        while len(_PREVIEW_SOURCE_CACHE) > _PREVIEW_SOURCE_CACHE_LIMIT:
            _PREVIEW_SOURCE_CACHE.popitem(last=False)
        return image


LEGACY_PROJECT_VERSION = 1
PROJECT_VERSION = 2
MAX_PROJECT_BYTES = 512 * 1024 * 1024
MAX_PROJECT_DOCUMENT_BYTES = 16 * 1024 * 1024
MAX_PROJECT_ENTRIES = 2048
MAX_PROJECT_ARCHIVE_BYTES = MAX_PROJECT_BYTES + MAX_PROJECT_DOCUMENT_BYTES + 1024 * 1024
MAX_PROJECT_COMPRESSION_RATIO = 200
MAX_PROJECT_LAYERS = 500
MAX_RETOUCH_POINTS = 100000
MAX_ENCODED_MASK_BYTES = 128 * 1024 * 1024
MAX_TEXT_LAYER_CHARS = 1_000_000
MAX_HISTORY_STEPS = 100
PROJECT_DOCUMENT_NAME = "project.json"
PROJECT_README_NAME = "README.txt"
PROJECT_MIMETYPE = "application/vnd.snapsmack.snap-slapper-project+zip"
PROJECT_ORIGINAL_NAME = "original/original"


class ExternalProjectSourceApprovalRequired(ValueError):
    """An imported project asked to read a path outside its own archive."""

    def __init__(self, source_path):
        self.source_path = os.path.abspath(os.fspath(source_path))
        super().__init__(
            "This project references a photograph outside the project file. "
            "SNAP SLAPPER will not read it without your confirmation.")
DEFAULT_ADJUSTMENTS = {
    "exposure": 0.0, "brightness": 0.0, "contrast": 0.0,
    "highlights": 0.0, "midtones": 0.0, "shadows": 0.0,
    "whites": 0.0, "blacks": 0.0,
    "temperature": 0.0, "tint": 0.0, "saturation": 0.0, "vibrance": 0.0,
    "clarity": 0.0, "texture": 0.0, "dehaze": 0.0, "sharpen": 0.0,
    # Smart-sharpen controls. amount == the sharpen slider above. Lens mode
    # edge-limits the sharpen (finer detail, no haloes, noise left alone);
    # gaussian is the classic unsharp mask. Defaults keep sharpen off (0).
    "sharpen_radius": 1.2, "sharpen_reduce_noise": 0.0, "sharpen_mode": "lens",
    "raw_noise_reduction": 0.0,
    "level_black": 0.0, "level_gamma": 1.0, "level_white": 255.0,
    "black_white": False, "vignette": 0.0, "grain": 0.0,
    # Vignette edge softness (50 == the classic look) and a darken-only grain
    # mode (False == the original soft-light grain). Defaults preserve every
    # existing LEWK and project unchanged.
    "vignette_size": 70.0, "vignette_feather": 50.0, "grain_darken": False,
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

# Controls performed in the RAW developer when a document has a RAW source.
RAW_DEVELOPMENT_KEYS = {
    "exposure", "brightness", "contrast", "highlights", "shadows",
    "temperature", "tint", "saturation", "raw_noise_reduction",
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


def _open_layer_image(path, target_size=None):
    """Open a raster layer or render an SVG sharply at the requested size."""
    if os.path.splitext(path)[1].lower() != ".svg":
        with Image.open(path) as source:
            return ImageOps.exif_transpose(source).convert("RGBA")
    try:
        from PySide6.QtCore import QSize
        from PySide6.QtGui import QImage, QPainter
        from PySide6.QtSvg import QSvgRenderer
    except ImportError as error:
        raise ValueError(
            "SVG watermarks require the SVG renderer included with SNAP SLAPPER") from error
    renderer = QSvgRenderer(path)
    if not renderer.isValid():
        raise ValueError(f"SVG watermark is invalid: {path}")
    natural = renderer.defaultSize()
    if target_size:
        width, height = max(1, int(target_size[0])), max(1, int(target_size[1]))
    elif natural.isValid() and not natural.isEmpty():
        width, height = natural.width(), natural.height()
    else:
        width, height = 1024, 1024
    canvas = QImage(QSize(width, height), QImage.Format_RGBA8888)
    canvas.fill(0)
    painter = QPainter(canvas)
    renderer.render(painter)
    painter.end()
    return Image.frombytes("RGBA", (width, height), bytes(canvas.bits()), "raw", "RGBA")

# Hue centres (degrees) for the black & white colour mix, in wheel order.
BW_BANDS = [("bw_red", 0.0), ("bw_orange", 30.0), ("bw_yellow", 60.0),
            ("bw_green", 120.0), ("bw_aqua", 180.0), ("bw_blue", 240.0),
            ("bw_purple", 270.0), ("bw_magenta", 300.0)]


def _clamp(value, low=0, high=255):
    return max(low, min(high, value))


def _smoothstep(edge0, edge1, value):
    """Smooth 0..1 transition used to isolate tonal adjustment bands."""
    if edge1 <= edge0:
        return 1.0 if value >= edge1 else 0.0
    amount = max(0.0, min(1.0, (value - edge0) / (edge1 - edge0)))
    return amount * amount * (3.0 - 2.0 * amount)


def _mask_to_text(mask):
    if mask is None:
        return ""
    stream = io.BytesIO()
    mask.convert("L").save(stream, "PNG")
    return base64.b64encode(stream.getvalue()).decode("ascii")


def _mask_from_text(value):
    # Masks ride inside .slapper project files — a shared/received project is
    # untrusted input. _mask_to_text() always writes PNG, so anything that is
    # not a PNG here is a tampered project: refuse it rather than hand the
    # bytes to whatever exotic Pillow decoder they were crafted for.
    # FAIL-CLOSED when the safety module is missing (broken install).
    if not value:
        return None
    if snap_imgsafe is None:
        raise ValueError(
            "Mask refused — snap_imgsafe (shared image safety module) is not "
            "available. Reinstall/repair SNAP SLAPPER; masks are never decoded "
            "unguarded.")
    # The encoded string is immutable and already belongs to the document, so
    # it is an exact collision-free cache key. Validation and full decode still
    # happen once; subsequent renders/layer selections receive an independent
    # copy and cannot mutate the cached image.
    with _MASK_IMAGE_CACHE_LOCK:
        cached = _MASK_IMAGE_CACHE.get(value)
        if cached is not None:
            _MASK_IMAGE_CACHE.move_to_end(value)
            return cached.copy()
        decoded = snap_imgsafe.safe_open(
            base64.b64decode(value), formats={"PNG"}).convert("L")
        pixels = decoded.width * decoded.height
        if pixels <= _MASK_IMAGE_CACHE_PIXELS:
            while _MASK_IMAGE_CACHE and (
                    len(_MASK_IMAGE_CACHE) >= _MASK_IMAGE_CACHE_LIMIT or
                    sum(image.width * image.height
                        for image in _MASK_IMAGE_CACHE.values()) + pixels >
                    _MASK_IMAGE_CACHE_PIXELS):
                _MASK_IMAGE_CACHE.popitem(last=False)
            _MASK_IMAGE_CACHE[value] = decoded.copy()
        return decoded


def _source_sha256(path):
    digest = __import__("hashlib").sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _project_preview_bytes(image):
    """Return portable flattened previews for browsers that cannot render a recipe."""
    composite = io.BytesIO()
    image.convert("RGB").save(composite, format="TIFF", compression="tiff_deflate")
    thumbnail_image = image.copy()
    thumbnail_image.thumbnail((512, 512), Image.Resampling.LANCZOS)
    thumbnail = io.BytesIO()
    thumbnail_image.convert("RGB").save(thumbnail, format="JPEG", quality=88,
                                         optimize=True)
    return composite.getvalue(), thumbnail.getvalue()


def _write_project_archive(path, value, source_path, *, embed_source=True,
                           preview=None):
    """Atomically publish the documented v2 ZIP64 project container."""
    target = os.path.abspath(path)
    directory = os.path.dirname(target)
    os.makedirs(directory, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix=".snap-project-", suffix=".tmp",
                                             dir=directory)
    os.close(descriptor)
    extension = os.path.splitext(source_path)[1].lower()
    original_name = PROJECT_ORIGINAL_NAME + extension
    value = copy.deepcopy(value)
    value["source_ingredient"] = {
        "archive_path": original_name if embed_source else "",
        "external": not embed_source,
        "original_filename": os.path.basename(source_path),
        "sha256": _source_sha256(source_path),
        "byte_count": os.path.getsize(source_path),
    }
    document = json.dumps(value, indent=2, sort_keys=True, allow_nan=False).encode("utf-8")
    readme = ("SNAP SLAPPER project archive (standard ZIP/ZIP64)\n\n"
              "Rename this file from .slapper to .zip to inspect it with any ZIP tool.\n"
              "project.json contains the versioned, human-readable editing document.\n"
              + ("original/original contains the untouched source photograph as a provenance ingredient.\n"
                 if embed_source else
                 "The immutable RAW original remains external and is referenced by typed artifact ID.\n"))
    if preview is None:
        with Image.open(source_path) as source_preview:
            preview = ImageOps.exif_transpose(source_preview).convert("RGB")
    composite, thumbnail = _project_preview_bytes(preview)
    entries = {
        PROJECT_DOCUMENT_NAME: document,
        PROJECT_README_NAME: readme.encode("utf-8"),
        "previews/composite.tif": composite,
        "previews/thumbnail.jpg": thumbnail,
        "metadata/original-exif.json": json.dumps(
            {"preserved_in_original": bool(embed_source)}, sort_keys=True).encode("utf-8"),
        "metadata/provenance.json": json.dumps(
            value["source_ingredient"], indent=2, sort_keys=True).encode("utf-8"),
        "metadata/dependencies.json": json.dumps(
            {"external_source": not embed_source}, sort_keys=True).encode("utf-8"),
        "schemas/project-schema.json": json.dumps(
            {"$schema": "https://json-schema.org/draft/2020-12/schema",
             "title": "SNAP SLAPPER project", "type": "object",
             "required": ["version", "source_path", "layers"],
             "properties": {"version": {"const": PROJECT_VERSION}}},
            indent=2, sort_keys=True).encode("utf-8"),
    }
    for index, layer in enumerate(value.get("layers", [])):
        stable_id = str(layer.get("id") or f"layer-{index + 1}")
        safe_id = "".join(character for character in stable_id
                          if character.isalnum() or character in "-_") or f"layer-{index + 1}"
        entries[f"layers/{safe_id}/layer.json"] = json.dumps(
            layer, indent=2, sort_keys=True, allow_nan=False).encode("utf-8")
    if embed_source:
        with open(source_path, "rb") as original:
            entries[original_name] = original.read()
    hashes = {name: __import__("hashlib").sha256(payload).hexdigest()
              for name, payload in entries.items()}
    entries["metadata/checksums.json"] = json.dumps(
        {"algorithm": "sha256", "entries": hashes},
        indent=2, sort_keys=True).encode("utf-8")
    entries["manifest.json"] = json.dumps({
        "format": "SNAP SLAPPER", "format_version": PROJECT_VERSION,
        "container": "standard ZIP/ZIP64", "project": PROJECT_DOCUMENT_NAME,
        "original": original_name if embed_source else None,
        "layer_order": [str(layer.get("id") or f"layer-{index + 1}")
                        for index, layer in enumerate(value.get("layers", []))],
    }, indent=2, sort_keys=True).encode("utf-8")
    try:
        with zipfile.ZipFile(temporary, "w", compression=zipfile.ZIP_DEFLATED,
                             allowZip64=True) as archive:
            archive.writestr("mimetype", PROJECT_MIMETYPE,
                             compress_type=zipfile.ZIP_STORED)
            for name, payload in entries.items():
                archive.writestr(name, payload)
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
                _validate_project_archive(archive)
                try:
                    info = archive.getinfo(PROJECT_DOCUMENT_NAME)
                except KeyError as exc:
                    raise ValueError("Invalid SNAP SLAPPER project: project.json is missing") from exc
                if info.file_size > MAX_PROJECT_DOCUMENT_BYTES:
                    raise ValueError("SNAP SLAPPER project document is too large to open safely")
                raw = archive.read(info)
            return json.loads(raw.decode("utf-8"),
                              parse_constant=photo_manager.reject_json_constant)
        except zipfile.BadZipFile as exc:
            raise ValueError("Invalid SNAP SLAPPER project archive") from exc
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle, parse_constant=photo_manager.reject_json_constant)


def _validate_project_archive(archive):
    """Reject hostile ZIP structure before reading any project member."""
    entries = archive.infolist()
    if len(entries) > MAX_PROJECT_ENTRIES:
        raise ValueError("SNAP SLAPPER project contains too many archive entries")
    if sum(info.file_size for info in entries) > MAX_PROJECT_ARCHIVE_BYTES:
        raise ValueError("SNAP SLAPPER project archive expands beyond the safe limit")
    for info in entries:
        name = info.filename.replace("\\", "/")
        parts = PurePosixPath(name).parts
        if (not name or name.startswith("/") or (len(name) > 1 and name[1] == ":") or
                ".." in parts):
            raise ValueError("SNAP SLAPPER project contains an unsafe archive path")
        unix_mode = (info.external_attr >> 16) & 0xFFFF
        if unix_mode and (unix_mode & 0o170000) == 0o120000:
            raise ValueError("SNAP SLAPPER project contains a symbolic link")
        if info.file_size and info.compress_size == 0:
            raise ValueError("SNAP SLAPPER project contains an invalid compressed member")
        if (info.compress_size and
                info.file_size / info.compress_size > MAX_PROJECT_COMPRESSION_RATIO):
            raise ValueError("SNAP SLAPPER project compression ratio is unsafe")


def _extract_embedded_source(project_path, ingredient):
    """Verify and extract a project's untouched source ingredient for editing."""
    member = ingredient.get("archive_path") if isinstance(ingredient, dict) else None
    expected_hash = ingredient.get("sha256") if isinstance(ingredient, dict) else None
    if not isinstance(member, str) or not member.startswith(PROJECT_ORIGINAL_NAME):
        return None
    suffix = os.path.splitext(member)[1]
    descriptor, extracted = tempfile.mkstemp(prefix="snap-slapper-original-", suffix=suffix)
    digest = __import__("hashlib").sha256()
    try:
        with os.fdopen(descriptor, "wb") as target, zipfile.ZipFile(project_path, "r") as archive:
            _validate_project_archive(archive)
            info = archive.getinfo(member)
            if info.file_size > MAX_PROJECT_BYTES:
                raise ValueError("Embedded original photograph is too large")
            with archive.open(info, "r") as source:
                while True:
                    chunk = source.read(1024 * 1024)
                    if not chunk:
                        break
                    digest.update(chunk)
                    target.write(chunk)
        if expected_hash and digest.hexdigest() != expected_hash:
            raise ValueError("Embedded original photograph failed its integrity check")
        return extracted
    except Exception:
        try:
            os.remove(extracted)
        except OSError:
            pass
        raise


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
    midtones = float(adjustments.get("midtones", 0)) / 100.0
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
        # Three deliberately separated tonal bands.  Highlights and shadows
        # have shoulders instead of leaking across the whole photograph;
        # midtones form a smooth bell centred on middle grey.  The 55-level
        # ceiling keeps even ±100 mappings monotonic and avoids tonal reversal.
        shadow_weight = 1.0 - _smoothstep(.15, .55, normalized)
        highlight_weight = _smoothstep(.55, .90, normalized)
        midtone_weight = 1.0 - _smoothstep(0.0, .32, abs(normalized - .5))
        value += shadows * 55.0 * shadow_weight
        value += midtones * 55.0 * midtone_weight
        value += highlights * 55.0 * highlight_weight
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


def _histogram_percentile(histogram, fraction):
    total = sum(histogram) or 1
    threshold = total * fraction
    running = 0
    for value, count in enumerate(histogram):
        running += count
        if running >= threshold:
            return value
    return 255


def auto_exposure_adjustments(image):
    """Choose exposure while reserving highlight headroom.

    Middle grey is gently brought toward 112, but the 99.5th percentile is
    never pushed above 245. Existing clipped pixels do not force the whole
    photograph darker because no exposure operation can reconstruct them.
    """
    lum = ImageOps.grayscale(image.convert("RGB"))
    histogram = lum.histogram()
    middle = max(1, _histogram_percentile(histogram, .50))
    high = max(1, _histogram_percentile(histogram, .995))
    desired = math.log2(112.0 / middle)
    headroom = 0.0 if high >= 252 else math.log2(245.0 / high)
    stops = max(-1.5, min(1.5, desired, headroom))
    # Match the editor's 0.05 EV control steps so document and UI stay exact.
    return {"exposure": round(round(stops / .05) * .05, 2)}


def auto_adjustments(image, percentile=0.005):
    """Compute a conservative, editable one-click enhancement.

    Unlike the old percentile levels stretch, this leaves the white point at
    255 and does not stack a global contrast boost on top. Bright photographs
    receive a selective highlight shoulder instead of clipped skies.
    """
    from PIL import ImageStat
    rgb = image.convert("RGB")
    result = auto_exposure_adjustments(rgb)
    lum = ImageOps.grayscale(rgb)
    histogram = lum.histogram()
    high = _histogram_percentile(histogram, 1.0 - percentile)
    if high > 235:
        result["highlights"] = float(-min(35, round((high - 235) * 1.5)))

    # Grey-world white balance: nudge temperature/tint toward neutral.
    mean_r, mean_g, mean_b = ImageStat.Stat(rgb).mean
    result["temperature"] = float(max(-40, min(40, round((mean_b - mean_r) * 0.5))))
    result["tint"] = float(max(-30, min(30, round(((mean_r + mean_b) / 2 - mean_g) * 0.4))))

    # A small saturation lift is safe; global contrast is deliberately absent.
    result["vibrance"] = 6.0
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
        # Elliptical normalized radius: the effect is mathematically zero
        # through the protected centre, then follows one continuous smoothstep
        # to full edge strength. This avoids the clipped Gaussian halo and the
        # unintended whole-frame dimming of the former oversized blur.
        yy, xx = np.ogrid[:height, :width]
        radius = np.sqrt(((xx - (width - 1) / 2) / max(1, width / 2)) ** 2 +
                         ((yy - (height - 1) / 2) / max(1, height / 2)) ** 2)
        size = max(0.0, min(200.0, float(settings.get("vignette_size", 70)))) / 100.0
        feather = max(0.0, min(200.0, float(
            settings.get("vignette_feather", 50)))) / 100.0
        midpoint = .55 + size * .40
        transition = .04 + feather * .42
        start = max(.08, midpoint - transition / 2)
        end = min(1.80, midpoint + transition / 2)
        weight = np.clip((radius - start) / max(.001, end - start), 0.0, 1.0)
        weight = weight * weight * (3.0 - 2.0 * weight)
        strength = abs(vignette) / 100.0
        edge = Image.new("RGB", output.size, (0, 0, 0) if vignette < 0 else (255, 255, 255))
        effect_mask = Image.fromarray(np.uint8(np.rint(weight * strength * 255)), "L")
        output = Image.composite(edge, output, effect_mask)

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


def _deconv_gauss_taps(sigma):
    """1-D separable Gaussian taps [(offset, weight), …] for a lens/soft blur."""
    ri = max(1, int(math.ceil(sigma * 2.5)))
    ws = [math.exp(-(i * i) / (2.0 * sigma * sigma)) for i in range(-ri, ri + 1)]
    tot = sum(ws) or 1.0
    return [(i - ri, w / tot) for i, w in enumerate(ws)]


def _deconv_motion_taps(length, angle_deg):
    """2-D taps [(dx, dy, weight), …] for a straight motion blur of `length` px."""
    n = max(1, min(64, int(round(length))))
    a = math.radians(angle_deg)
    counts = {}
    for i in range(n):
        t = i - (n - 1) / 2.0
        key = (int(round(math.cos(a) * t)), int(round(-math.sin(a) * t)))
        counts[key] = counts.get(key, 0) + 1
    tot = sum(counts.values()) or 1
    return [(dx, dy, c / tot) for (dx, dy), c in counts.items()]


def _deconv_conv2d(img_f, taps):
    """Convolve an 'F' image with arbitrary (dx,dy,w) taps by shift-accumulate."""
    acc = None
    for dx, dy, w in taps:
        term = _image_math_eval("a*%r" % float(w), a=ImageChops.offset(img_f, dx, dy))
        acc = term if acc is None else _image_math_eval("a+b", a=acc, b=term)
    return acc


def _deconv_conv_sep(img_f, taps1d):
    """Convolve an 'F' image with a separable 1-D kernel (H pass then V pass)."""
    def run(im, horiz):
        acc = None
        for off, w in taps1d:
            shifted = ImageChops.offset(im, off if horiz else 0, 0 if horiz else off)
            term = _image_math_eval("a*%r" % float(w), a=shifted)
            acc = term if acc is None else _image_math_eval("a+b", a=acc, b=term)
        return acc
    return run(run(img_f, True), False)


def deconvolve(image, kind="lens", radius=2.0, length=12.0, angle=0.0, iterations=12):
    """Richardson–Lucy deconvolution in PURE PIL — no third-party maths library.
    Recovers a lens/soft blur (Gaussian PSF, run separably) or a straight motion
    blur (PSF = a line at `angle` degrees, `length` px long).

    HEAVY by nature: a few seconds for a web-sized frame, ~10 s for a large one,
    because each of many iterations shift-accumulates the whole image. It is a
    deliberate apply-and-wait operation and is intentionally NOT wired into the
    live adjustment path (apply_adjustments) — that would stall the editor.

    kind: 'lens' | 'motion'. radius: lens blur sigma. length/angle: motion PSF.
    Returns a new RGB image the size of the input."""
    iterations = max(1, min(40, int(iterations)))
    rgb = image.convert("RGB")
    if kind == "motion":
        offs = _deconv_motion_taps(length, angle)
        flip = [(-dx, -dy, w) for dx, dy, w in offs]
        forward = lambda f: _deconv_conv2d(f, offs)
        adjoint = lambda f: _deconv_conv2d(f, flip)
    else:
        taps = _deconv_gauss_taps(max(0.4, min(8.0, radius)))   # symmetric: adjoint == forward
        forward = adjoint = lambda f: _deconv_conv_sep(f, taps)
    out_bands = []
    for band in rgb.split():
        observed = band.convert("F")
        estimate = observed
        for _ in range(iterations):
            reblur = forward(estimate)
            ratio = _image_math_eval("o/(b+1.0)", o=observed, b=reblur)
            estimate = _image_math_eval("e*c", e=estimate, c=adjoint(ratio))
        out_bands.append(estimate.convert("L"))     # convert clamps to 0–255
    return Image.merge("RGB", out_bands)


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


def _perspective_destination(geometry, width, height):
    """Return the destination quadrilateral in pixels, ordered TL/TR/BR/BL."""
    corners = geometry.get("perspective_corners") or (
        [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]])
    if not isinstance(corners, list) or len(corners) != 4:
        corners = [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]]
    points = []
    for index, fallback in enumerate(((0, 0), (1, 0), (1, 1), (0, 1))):
        value = corners[index]
        if not isinstance(value, (list, tuple)) or len(value) != 2:
            value = fallback
        points.append([max(-0.5, min(1.5, float(value[0]))),
                       max(-0.5, min(1.5, float(value[1])))])

    # Sliders are deliberately restrained: their full range moves an edge by
    # one quarter of the frame. They combine with, rather than overwrite, the
    # four freely positioned corners.
    vertical = max(-100.0, min(100.0, float(
        geometry.get("perspective_vertical", 0.0)))) * 0.0025
    horizontal = max(-100.0, min(100.0, float(
        geometry.get("perspective_horizontal", 0.0)))) * 0.0025
    if vertical >= 0:
        points[0][0] += vertical; points[1][0] -= vertical
    else:
        points[3][0] -= vertical; points[2][0] += vertical
    if horizontal >= 0:
        points[0][1] += horizontal; points[3][1] -= horizontal
    else:
        points[1][1] -= horizontal; points[2][1] += horizontal
    return [(x * (width - 1), y * (height - 1)) for x, y in points]


def _perspective_coefficients(destination, width, height):
    """Solve Pillow's output-to-input projective mapping coefficients."""
    crosses = []
    for index in range(4):
        a = destination[index]
        b = destination[(index + 1) % 4]
        c = destination[(index + 2) % 4]
        crosses.append((b[0] - a[0]) * (c[1] - b[1]) -
                       (b[1] - a[1]) * (c[0] - b[0]))
    if not (all(value > 1e-6 for value in crosses) or
            all(value < -1e-6 for value in crosses)):
        raise ValueError("Perspective corners cannot cross or collapse")
    source = [(0.0, 0.0), (width - 1.0, 0.0),
              (width - 1.0, height - 1.0), (0.0, height - 1.0)]
    matrix = []
    values = []
    for (x, y), (u, v) in zip(destination, source):
        matrix.extend(([x, y, 1, 0, 0, 0, -u * x, -u * y],
                       [0, 0, 0, x, y, 1, -v * x, -v * y]))
        values.extend((u, v))
    try:
        return tuple(np.linalg.solve(np.asarray(matrix, dtype=float),
                                     np.asarray(values, dtype=float)))
    except np.linalg.LinAlgError as error:
        raise ValueError("Perspective corners cannot cross or collapse") from error


def apply_perspective(image, geometry):
    """Apply a straight-line-preserving projective transform to ``image``."""
    neutral = (not float(geometry.get("perspective_vertical", 0.0)) and
               not float(geometry.get("perspective_horizontal", 0.0)) and
               geometry.get("perspective_corners", [[0, 0], [1, 0], [1, 1], [0, 1]]) ==
               [[0, 0], [1, 0], [1, 1], [0, 1]])
    if neutral:
        return image
    rgba = image.convert("RGBA")
    destination = _perspective_destination(geometry, rgba.width, rgba.height)
    coefficients = _perspective_coefficients(destination, rgba.width, rgba.height)
    transformed = rgba.transform(
        rgba.size, Image.Transform.PERSPECTIVE, coefficients,
        Image.Resampling.BICUBIC, fillcolor=(0, 0, 0, 0))
    if geometry.get("perspective_edges", "auto_crop") == "auto_crop":
        # Largest conservative axis-aligned rectangle implied by the four
        # straight edges. Unlike an alpha bounding box, this removes the empty
        # triangular wedges left by a keystone correction.
        left = math.ceil(max(destination[0][0], destination[3][0], 0))
        right = math.floor(min(destination[1][0], destination[2][0], rgba.width - 1))
        top = math.ceil(max(destination[0][1], destination[1][1], 0))
        bottom = math.floor(min(destination[2][1], destination[3][1], rgba.height - 1))
        if right - left >= 2 and bottom - top >= 2:
            transformed = transformed.crop((left, top, right + 1, bottom + 1))
    return transformed


def _lens_source_point(x, y, width, height, geometry, auto_zoom=1.0):
    """Map an output point back into the source for radial lens correction."""
    half_w = max(1.0, (width - 1) / 2.0)
    half_h = max(1.0, (height - 1) / 2.0)
    centre_x = half_w + float(geometry.get("lens_center_x", 0.0)) / 100.0 * half_w
    centre_y = half_h + float(geometry.get("lens_center_y", 0.0)) / 100.0 * half_h
    nx = (x - centre_x) / half_w
    ny = (y - centre_y) / half_h
    r2 = (nx * nx + ny * ny) / 2.0
    radial = float(geometry.get("lens_distortion", 0.0)) / 100.0
    spherical = float(geometry.get("lens_spherical", 0.0)) / 100.0
    factor = 1.0 + radial * .42 * r2 + spherical * .28 * r2 * r2
    manual_zoom = max(1.0, float(geometry.get("lens_scale", 100.0)) / 100.0)
    factor /= max(1.0, manual_zoom, auto_zoom)
    return centre_x + nx * factor * half_w, centre_y + ny * factor * half_h


def apply_lens_distortion(image, geometry):
    """Apply barrel/pincushion and spherical correction using a fine mesh."""
    radial = float(geometry.get("lens_distortion", 0.0))
    spherical = float(geometry.get("lens_spherical", 0.0))
    centre_x = float(geometry.get("lens_center_x", 0.0))
    centre_y = float(geometry.get("lens_center_y", 0.0))
    scale = float(geometry.get("lens_scale", 100.0))
    if not any((radial, spherical, centre_x, centre_y, scale - 100.0)):
        return image
    rgba = image.convert("RGBA")
    width, height = rgba.size
    edge_mode = geometry.get("lens_edges", "auto_crop")
    auto_zoom = 1.0
    if edge_mode == "auto_fill":
        # Sample the boundary and zoom just enough that no transparent wedge
        # survives. Manual Scale can add further framing if desired.
        boundary = []
        for step in range(65):
            t = step / 64.0
            boundary.extend(((t * (width - 1), 0), (t * (width - 1), height - 1),
                             (0, t * (height - 1)), (width - 1, t * (height - 1))))
        mapped = [_lens_source_point(x, y, width, height, geometry) for x, y in boundary]
        cx = (width - 1) / 2.0; cy = (height - 1) / 2.0
        auto_zoom = max(1.0, max(abs(x - cx) / max(cx, 1) for x, _ in mapped),
                        max(abs(y - cy) / max(cy, 1) for _, y in mapped))

    divisions = 32
    xs = [round(i * width / divisions) for i in range(divisions + 1)]
    ys = [round(i * height / divisions) for i in range(divisions + 1)]
    mesh = []
    for row in range(divisions):
        for column in range(divisions):
            left, right = xs[column], xs[column + 1]
            top, bottom = ys[row], ys[row + 1]
            # Pillow mesh quad order: upper-left, lower-left, lower-right, upper-right.
            points = [(left, top), (left, bottom), (right, bottom), (right, top)]
            quad = tuple(value for point in points for value in
                         _lens_source_point(*point, width, height, geometry,
                                            auto_zoom=auto_zoom))
            mesh.append(((left, top, right, bottom), quad))
    transformed = rgba.transform(rgba.size, Image.Transform.MESH, mesh,
                                 Image.Resampling.BICUBIC,
                                 fillcolor=(0, 0, 0, 0))
    if edge_mode == "auto_crop":
        rectangle = _largest_opaque_rectangle(transformed.getchannel("A"))
        if rectangle and rectangle[2] > rectangle[0] and rectangle[3] > rectangle[1]:
            transformed = transformed.crop(rectangle)
    return transformed


def _largest_opaque_rectangle(alpha, sample_size=320):
    """Find a conservative largest rectangular area without warped-edge gaps."""
    width, height = alpha.size
    scale = min(1.0, sample_size / max(width, height))
    small_w = max(1, round(width * scale))
    small_h = max(1, round(height * scale))
    small = alpha.resize((small_w, small_h), Image.Resampling.BILINEAR)
    pixels = small.load()
    heights = [0] * small_w
    best_area = 0
    best = None
    for y in range(small_h):
        for x in range(small_w):
            heights[x] = heights[x] + 1 if pixels[x, y] >= 250 else 0
        stack = []
        for x in range(small_w + 1):
            current = heights[x] if x < small_w else 0
            start = x
            while stack and stack[-1][1] > current:
                left, bar_height = stack.pop()
                area = bar_height * (x - left)
                if area > best_area:
                    best_area = area
                    best = (left, y - bar_height + 1, x, y + 1)
                start = left
            if not stack or stack[-1][1] < current:
                stack.append((start, current))
    if not best or best_area < 4:
        return None
    left, top, right, bottom = best
    # Round inward so no unsampled translucent boundary is retained.
    margin = 2
    return (min(width - 1, math.ceil((left + margin) * width / small_w)),
            min(height - 1, math.ceil((top + margin) * height / small_h)),
            max(1, math.floor((right - margin) * width / small_w)),
            max(1, math.floor((bottom - margin) * height / small_h)))


class EditorDocument:
    _font_reference_cache = {}
    def __init__(self, source_path):
        self.source_path = os.path.abspath(source_path)
        self.revision = 0
        # Rendering may use a verified source extracted from a portable project,
        # but folder browsing and recovery identity must remain attached to the
        # photograph the user actually opened.
        self.recorded_source_path = self.source_path
        self.browse_source_path = self.source_path
        self.original_filename = os.path.basename(self.source_path)
        self.artifacts = ArtifactRegistry()
        self.original_artifact_id = self.artifacts.assign(
            ArtifactKind.ORIGINAL_RASTER, self.source_path)
        self.edit_source_artifact_id = self.original_artifact_id
        self.adjustments = copy.deepcopy(DEFAULT_ADJUSTMENTS)
        self.geometry = {
            "rotation": 0.0, "crop": None, "flip_x": False, "flip_y": False,
            "perspective_vertical": 0.0, "perspective_horizontal": 0.0,
            "perspective_corners": [[0.0, 0.0], [1.0, 0.0],
                                    [1.0, 1.0], [0.0, 1.0]],
            "perspective_edges": "auto_crop",
            "lens_distortion": 0.0, "lens_spherical": 0.0,
            "lens_center_x": 0.0, "lens_center_y": 0.0,
            "lens_scale": 100.0, "lens_edges": "auto_crop",
            "horizon_curve": [], "horizon_strength": 100.0,
            "horizon_edge_protection": 60.0,
        }
        self.layers = []
        self.retouched = []
        self.saved_selections = {}
        self.history = []
        self.history_index = -1
        self.project_path = None
        self.on_change = None
        # The Qt shell supplies this callback.  It is deliberately optional so
        # the editing engine remains usable by tests and non-interactive tools.
        self.history_limit_handler = None
        self.record("Open image")
        self.saved_snapshot = self.snapshot()

    def attach_raw_source(self, original_path, developed_path, profile_path,
                          producer_build="RawTherapee"):
        """Attach an explicit RAW→profile→master artifact chain."""
        original_path = os.path.abspath(original_path)
        developed_path = os.path.abspath(developed_path)
        profile_path = os.path.abspath(profile_path)
        registry = ArtifactRegistry()
        raw_id = registry.assign(ArtifactKind.ORIGINAL_RAW, original_path)
        profile_id = registry.assign(ArtifactKind.RAW_PROFILE, profile_path,
                                     source_refs=(raw_id,))
        master_id = registry.derive(
            ArtifactKind.DEVELOPED_MASTER, developed_path,
            source_refs=(raw_id, profile_id), bit_depth="uint16",
            producer_build=str(producer_build))
        self.artifacts = registry
        self.original_artifact_id = raw_id
        self.raw_profile_artifact_id = profile_id
        self.edit_source_artifact_id = master_id
        self.raw_source_path = original_path
        self.source_path = developed_path
        self._raw_developed_adjustments = {
            key: copy.deepcopy(DEFAULT_ADJUSTMENTS[key])
            for key in RAW_DEVELOPMENT_KEYS
        }
        self.recorded_source_path = original_path
        self.browse_source_path = original_path
        self.original_filename = os.path.basename(original_path)

    def _use_raw_development(self, artifacts):
        """Advance the typed profile/master chain when RAW controls change."""
        original_path = os.path.abspath(artifacts["original"])
        current_original = None
        try:
            candidate = self.artifacts.get(self.original_artifact_id)
            if candidate.kind == ArtifactKind.ORIGINAL_RAW:
                current_original = candidate
        except (KeyError, TypeError, AttributeError):
            pass
        if current_original is None:
            self.attach_raw_source(original_path, artifacts["master"],
                                   artifacts["profile"], artifacts["producer"])
            return
        self.artifacts.relocate(current_original.id, original_path)
        profile_hash = _source_sha256(artifacts["profile"])
        profile_id = self.artifacts.derive(
            ArtifactKind.RAW_PROFILE, artifacts["profile"],
            source_refs=(current_original.id,), producer_build=profile_hash)
        master_id = self.artifacts.derive(
            ArtifactKind.DEVELOPED_MASTER, artifacts["master"],
            source_refs=(current_original.id, profile_id), bit_depth="uint16",
            producer_build=str(artifacts["producer"]))
        self.raw_profile_artifact_id = profile_id
        self.edit_source_artifact_id = master_id
        self.source_path = os.path.abspath(artifacts["master"])

    def notify_change(self):
        self.revision += 1
        if self.on_change:
            self.on_change(self)

    def snapshot(self):
        return {"adjustments": copy.deepcopy(self.adjustments),
                "geometry": copy.deepcopy(self.geometry),
                "layers": copy.deepcopy(self.layers), "retouched": copy.deepcopy(self.retouched),
                "saved_selections": copy.deepcopy(self.saved_selections)}

    def restore(self, value):
        self.adjustments = copy.deepcopy(value["adjustments"])
        self.geometry = copy.deepcopy(value["geometry"])
        self.layers = copy.deepcopy(value["layers"])
        self.retouched = copy.deepcopy(value.get("retouched", []))
        self.saved_selections = copy.deepcopy(value.get("saved_selections", {}))

    def record(self, label):
        state = self.snapshot()
        self.history = self.history[:self.history_index + 1]
        if len(self.history) >= MAX_HISTORY_STEPS:
            # Mutating commands call record immediately after changing the
            # document.  Temporarily put the document back at the last accepted
            # state so the checkpoint contains the first 100 steps, not the
            # as-yet-unaccepted 101st edit.
            previous = copy.deepcopy(self.history[self.history_index]["state"])
            self.restore(previous)
            accepted = False
            if self.history_limit_handler:
                accepted = bool(self.history_limit_handler(self))
            if not accepted:
                self.notify_change()
                return False
            self.history = [{"label": "Saved checkpoint", "state": previous,
                             "time": time.time()}]
            self.history_index = 0
            self.restore(state)
        self.history.append({"label": label, "state": state, "time": time.time()})
        self.history_index = len(self.history) - 1
        self.notify_change()
        return True

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

    def add_filter_layer(self, kind, name=None):
        settings = slapper_filters.defaults(kind)
        self.layers.append({
            "id": _new_layer_id(), "name": name or slapper_filters.FILTER_NAMES[kind],
            "type": "filter", "filter_type": kind, "filter_version": 1,
            "settings": settings, "visible": True, "opacity": 1.0,
            "blend": "normal", "mask": "", "mask_enabled": True,
            "styles": {},
        })
        self.record(f"Add {self.layers[-1]['name']} filter")
        return self.layers[-1]

    def add_image_layer(self, path, name=None, *, record=True):
        self.layers.append({"id": _new_layer_id(), "name": name or os.path.basename(path),
                            "type": "image", "path": os.path.abspath(path), "visible": True,
                            "opacity": 1.0, "blend": "normal",
                            "adjustments": copy.deepcopy(DEFAULT_ADJUSTMENTS),
                            "mask": "", "mask_linked": True,
                            "mask_transform": self.default_transform(), "styles": {},
                            "transform": self.default_transform()})
        if record:
            self.record("Add image layer")
        return self.layers[-1]

    def add_paint_layer(self, name="Blank layer"):
        """Add a transparent canvas layer that can be filled and masked."""
        self.layers.append({
            "id": _new_layer_id(), "name": name, "type": "paint",
            "fill": [0, 0, 0, 0], "visible": True, "opacity": 1.0,
            "blend": "normal", "adjustments": copy.deepcopy(DEFAULT_ADJUSTMENTS),
            "mask": "", "mask_enabled": True, "mask_linked": True,
            "mask_transform": self.default_transform(), "styles": {},
        })
        self.record("Add blank layer")
        return self.layers[-1]

    @staticmethod
    def default_transform():
        return {"x": .5, "y": .5, "scale_x": 1.0, "scale_y": 1.0,
                "rotation": 0.0, "flip_x": False, "flip_y": False,
                "warp_corners": [[0.0, 0.0], [0.0, 0.0],
                                 [0.0, 0.0], [0.0, 0.0]]}

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
        return self.render_float(max_size).display_proxy()

    def _graph_source_identity(self):
        raw_source = getattr(self, "raw_source_path", "")
        raw_settings = ({key: self.adjustments.get(key, DEFAULT_ADJUSTMENTS[key])
                         for key in RAW_DEVELOPMENT_KEYS} if raw_source else None)
        return {"source": render_graph.file_identity(self.source_path),
                "raw_source": render_graph.file_identity(raw_source) if raw_source else None,
                "raw_settings": raw_settings}

    def _graph_base_identity(self):
        return {"adjustments": self.adjustments, "geometry": self.geometry,
                "retouched": self.retouched}

    @staticmethod
    def _graph_layer_identity(layer):
        identity = copy.deepcopy(layer)
        path = identity.get("path")
        if path:
            identity["path_identity"] = render_graph.file_identity(path)
        return identity

    def _graph_decode_source(self, max_size):
        raw_source = getattr(self, "raw_source_path", "")
        if not raw_source:
            self._graph_base_adjustments = copy.deepcopy(self.adjustments)
            return highbit.read(self.source_path, max_size)
        import raw_preview
        raw_settings = {key: self.adjustments.get(key, DEFAULT_ADJUSTMENTS[key])
                        for key in RAW_DEVELOPMENT_KEYS}
        artifacts = raw_preview.development_artifacts(raw_source, raw_settings)
        self._use_raw_development(artifacts)
        self._raw_developed_adjustments = copy.deepcopy(raw_settings)
        base_adjustments = copy.deepcopy(self.adjustments)
        for key in RAW_DEVELOPMENT_KEYS:
            base_adjustments[key] = DEFAULT_ADJUSTMENTS[key]
        self._graph_base_adjustments = base_adjustments
        return highbit.read(artifacts["master"], max_size)

    def _graph_stage_document(self, image):
        stage = copy.copy(self)
        stage._graph_input_image = image
        stage.raw_source_path = ""
        stage.on_change = None
        return stage

    def _graph_render_base(self, source):
        stage = self._graph_stage_document(source)
        stage.adjustments = copy.deepcopy(self.adjustments)
        if getattr(self, "raw_source_path", ""):
            for key in RAW_DEVELOPMENT_KEYS:
                stage.adjustments[key] = DEFAULT_ADJUSTMENTS[key]
        stage.layers = []
        return stage._render_float_reference()

    def _graph_render_layer(self, composite, layer, _position):
        stage = self._graph_stage_document(composite)
        stage._graph_skip_base = True
        stage.adjustments = copy.deepcopy(DEFAULT_ADJUSTMENTS)
        stage.geometry = {
            "rotation": 0.0, "crop": None, "flip_x": False, "flip_y": False,
            "perspective_vertical": 0.0, "perspective_horizontal": 0.0,
            "perspective_corners": [[0.0, 0.0], [1.0, 0.0],
                                    [1.0, 1.0], [0.0, 1.0]],
            "perspective_edges": "transparent", "lens_distortion": 0.0,
            "lens_spherical": 0.0, "lens_center_x": 0.0, "lens_center_y": 0.0,
            "lens_scale": 100.0, "lens_edges": "transparent",
            "horizon_curve": [], "horizon_strength": 100.0,
            "horizon_edge_protection": 60.0,
        }
        stage.retouched = []
        stage.layers = [copy.deepcopy(layer)]
        return stage._render_float_reference()

    def render_float(self, max_size=None, *, request=None, is_current=None):
        """Evaluate the authoritative incremental graph in float32."""
        return render_graph.DEFAULT_GRAPH.render(
            self, max_size, request=request, is_current=is_current).image

    def _float_geometry(self, image):
        geometry = self.geometry
        if any(float(geometry.get(key, 0.0)) for key in
               ("lens_distortion", "lens_spherical", "lens_center_x", "lens_center_y")) or \
                float(geometry.get("lens_scale", 100.0)) != 100.0:
            image = highbit.radial_lens(image, geometry)
            if geometry.get("lens_edges", "auto_crop") == "auto_crop" and image.pixels.shape[2] == 4:
                alpha = Image.fromarray(np.uint8(np.clip(image.pixels[:, :, 3], 0, 1) * 255), "L")
                rectangle = _largest_opaque_rectangle(alpha)
                if rectangle:
                    image = image.crop(rectangle)
        neutral_perspective = (
            not float(geometry.get("perspective_vertical", 0.0)) and
            not float(geometry.get("perspective_horizontal", 0.0)) and
            geometry.get("perspective_corners", [[0, 0], [1, 0], [1, 1], [0, 1]]) ==
            [[0, 0], [1, 0], [1, 1], [0, 1]])
        if not neutral_perspective:
            destination = _perspective_destination(geometry, image.width, image.height)
            image = highbit.projective(
                image, _perspective_coefficients(destination, image.width, image.height))
            if geometry.get("perspective_edges", "auto_crop") == "auto_crop":
                left = math.ceil(max(destination[0][0], destination[3][0], 0))
                right = math.floor(min(destination[1][0], destination[2][0], image.width - 1))
                top = math.ceil(max(destination[0][1], destination[1][1], 0))
                bottom = math.floor(min(destination[2][1], destination[3][1], image.height - 1))
                if right - left >= 2 and bottom - top >= 2:
                    image = image.crop((left, top, right + 1, bottom + 1))
        horizon = geometry.get("horizon_curve") or []
        if len(horizon) >= 2 and float(geometry.get("horizon_strength", 100.0)):
            image = highbit.straighten_curved_horizon(
                image, horizon,
                float(geometry.get("horizon_strength", 100.0)) / 100.0,
                float(geometry.get("horizon_edge_protection", 60.0)) / 100.0)
        rotation = float(geometry.get("rotation", 0.0))
        if rotation:
            image = highbit.rotate(image, rotation)
        image = image.flipped(bool(geometry.get("flip_x")), bool(geometry.get("flip_y")))
        crop = geometry.get("crop")
        if crop:
            left, top, right, bottom = crop
            image = image.crop((left * image.width, top * image.height,
                                right * image.width, bottom * image.height))
        return image

    @staticmethod
    def _float_mask(mask, size):
        if mask is None:
            return None
        values = np.asarray(mask.convert("L"), dtype=np.float32) / 255.0
        if (mask.width, mask.height) != size:
            values = highbit.FloatImage(values[:, :, None], ("Y",)).resized_exact(size).pixels[:, :, 0]
        return values

    def _render_float_reference(self, max_size=None):
        """Render the complete document with float32 as the sole colour source."""
        # Preview work starts at preview resolution. Previously the source TIFF
        # and all geometry were processed at full resolution, then discarded by
        # thumbnail() at the end of the render.
        base_adjustments = self.adjustments
        graph_input = getattr(self, "_graph_input_image", None)
        raw_source = getattr(self, "raw_source_path", "")
        if graph_input is not None:
            image = graph_input
        elif raw_source:
            import raw_preview
            raw_settings = {key: self.adjustments.get(key, DEFAULT_ADJUSTMENTS[key])
                            for key in RAW_DEVELOPMENT_KEYS}
            artifacts = raw_preview.development_artifacts(raw_source, raw_settings)
            self._use_raw_development(artifacts)
            self._raw_developed_adjustments = copy.deepcopy(raw_settings)
            image = _read_float_source(artifacts["master"], max_size)
            # RawTherapee has already applied these controls. The remaining
            # SNAP SLAPPER-only controls continue through the layer engine.
            base_adjustments = copy.deepcopy(self.adjustments)
            for key in RAW_DEVELOPMENT_KEYS:
                base_adjustments[key] = DEFAULT_ADJUSTMENTS[key]
        else:
            image = _read_float_source(self.source_path, max_size)
        if not getattr(self, "_graph_skip_base", False):
            image = self._float_geometry(image)
            image = highbit.apply_adjustments(image, base_adjustments, DEFAULT_ADJUSTMENTS)
        if self.retouched and not getattr(self, "_graph_skip_base", False):
            for spot in self.retouched:
                x = int(float(spot.get("x", .5)) * image.width)
                y = int(float(spot.get("y", .5)) * image.height)
                radius = max(2, int(float(spot.get("radius", .02)) * min(image.size)))
                box = (max(0, x - radius), max(0, y - radius),
                       min(image.width, x + radius), min(image.height, y + radius))
                if box[2] > box[0] and box[3] > box[1]:
                    if spot.get("type") in {"clone", "patch"}:
                        source_x = int(float(spot.get("source_x", spot.get("x", .5))) * image.width)
                        source_y = int(float(spot.get("source_y", spot.get("y", .5))) * image.height)
                        source_box = (source_x - (x - box[0]), source_y - (y - box[1]),
                                      source_x + (box[2] - x), source_y + (box[3] - y))
                        sx0, sy0, sx1, sy1 = source_box
                        sx0 = max(0, min(image.width - (box[2] - box[0]), sx0))
                        sy0 = max(0, min(image.height - (box[3] - box[1]), sy0))
                        patch = image.crop((sx0, sy0, sx0 + (box[2] - box[0]),
                                            sy0 + (box[3] - box[1])))
                        if spot.get("type") == "patch":
                            # Patch keeps source texture but gently matches the
                            # destination's mean colour/luminance.
                            target_values = image.pixels[box[1]:box[3], box[0]:box[2]]
                            values = patch.pixels.copy()
                            channels = min(3, values.shape[2], target_values.shape[2])
                            delta = (target_values[:, :, :channels].mean(axis=(0, 1)) -
                                     values[:, :, :channels].mean(axis=(0, 1)))
                            values[:, :, :channels] += delta[None, None, :] * .75
                            patch = highbit.FloatImage(values, patch.channel_names,
                                                       patch.source_format, patch.icc_profile)
                    else:
                        patch = image.crop(box)
                    if spot.get("type") == "red_eye":
                        values = patch.pixels.copy()
                        values[:, :, 0] *= .48
                        patch = highbit.FloatImage(values, patch.channel_names,
                                                   patch.source_format, patch.icc_profile)
                    elif spot.get("type") not in {"clone", "patch"}:
                        patch = highbit.gaussian_blur(patch, max(2, radius / 3))
                    yy, xx = np.mgrid[0:patch.height, 0:patch.width]
                    px = (patch.width - 1) / 2; py = (patch.height - 1) / 2
                    mask = np.clip(1.0 - np.sqrt(((xx - px) / max(1, px)) ** 2 +
                                                 ((yy - py) / max(1, py)) ** 2), 0, 1)
                    values = image.pixels.copy()
                    target = values[box[1]:box[3], box[0]:box[2]]
                    channels = min(target.shape[2], patch.pixels.shape[2])
                    target[:, :, :channels] = (patch.pixels[:, :, :channels] * mask[:, :, None] +
                                                target[:, :, :channels] * (1.0 - mask[:, :, None]))
                    image = highbit.FloatImage(values, image.channel_names,
                                               image.source_format, image.icc_profile)
        for layer_number, layer in enumerate(self.layers, 1):
            if not layer.get("visible", True):
                continue
            if layer.get("type") == "adjustment":
                top = highbit.apply_adjustments(image, layer.get("adjustments", {}),
                                                DEFAULT_ADJUSTMENTS)
            elif layer.get("type") == "filter":
                if layer.get("filter_version", 1) != 1:
                    raise ValueError(
                        f"Unsupported filter version: {layer.get('filter_version')}")
                top = highbit.apply_filter(image, layer.get("filter_type", ""),
                                           layer.get("settings", {}))
            elif layer.get("type") == "image":
                path = layer.get("path", "")
                if layer.get("asset_ref"):
                    try:
                        import texture_assets
                        path = texture_assets.resolve(layer["asset_ref"]) or path
                        if path:
                            layer["path"] = path
                    except Exception:  # noqa: BLE001
                        pass
                if not os.path.isfile(path):
                    name = layer.get("name") or os.path.basename(path) or "unnamed layer"
                    if layer.get("asset_ref"):
                        ref = layer["asset_ref"]
                        source = ref.get("source_url") or "source unknown"
                        raise FileNotFoundError(
                            f'Texture "{name}" is missing from layer {layer_number}. '
                            f'Origin: {ref.get("origin", "unknown")}. Source: {source}')
                    raise FileNotFoundError(
                        f'Image layer "{name}" is missing from layer {layer_number}: {path}')
                fit = layer.get("fit", "original")
                svg_target = image.size if (
                    os.path.splitext(path)[1].lower() == ".svg" and
                    fit in ("cover", "contain", "stretch")) else None
                top = (highbit.from_pillow(_open_layer_image(path, svg_target))
                       if os.path.splitext(path)[1].lower() == ".svg" else
                       highbit.read(path))
                top = highbit.apply_adjustments(top, layer.get("adjustments", {}),
                                                DEFAULT_ADJUSTMENTS)
                if fit in ("cover", "contain", "stretch", "tile"):
                    top = highbit.fit_layer(top, image.size, fit)
            elif layer.get("type") == "paint":
                fill = list(layer.get("fill", [0, 0, 0, 0]))
                fill = (fill + [0, 0, 0, 0])[:4]
                top = highbit.constant(image.size, fill)
                top = highbit.apply_adjustments(top, layer.get("adjustments", {}),
                                                DEFAULT_ADJUSTMENTS)
            elif layer.get("type") == "text":
                top = highbit.from_pillow(self._text_layer_image(layer))
                top = highbit.apply_adjustments(top, layer.get("adjustments", {}),
                                                DEFAULT_ADJUSTMENTS)
            else:
                continue
            mask = (_mask_from_text(layer.get("mask", ""))
                    if layer.get("mask_enabled", True) else None)
            if layer.get("type") in {"image", "text"}:
                linked_mask = (self._float_mask(mask, top.size) if
                               mask is not None and layer.get("mask_linked", True) else None)
                # A fit mode already sized the layer to the canvas; place it
                # centred at scale 1 rather than re-applying the free transform.
                fitted = layer.get("fit", "original") in ("cover", "contain", "stretch", "tile")
                transform = self.default_transform() if fitted else layer.get("transform", {})
                top = highbit.place_layer(top, image.size, transform, linked_mask)
                if mask is not None and not layer.get("mask_linked", True):
                    mask_layer = highbit.place_layer(
                        highbit.FloatImage(self._float_mask(mask, mask.size)[:, :, None], ("Y",)),
                        image.size, layer.get("mask_transform", {}))
                    values = top.pixels.copy()
                    values[:, :, 3] *= mask_layer.pixels[:, :, -1]
                    top = highbit.FloatImage(values, top.channel_names)
            elif mask:
                layer_mask = self._float_mask(mask, image.size)
                values = top.pixels.copy()
                if values.shape[2] == 4:
                    values[:, :, 3] *= layer_mask
                    top = highbit.FloatImage(values, top.channel_names)
                else:
                    top = highbit.FloatImage(np.concatenate((values, layer_mask[:, :, None]), axis=2),
                                             ("R", "G", "B", "A"))
            top = highbit.apply_styles(top, layer.get("styles", {}))
            image = highbit.blend(image, top, layer.get("blend", "normal"),
                                  float(layer.get("opacity", 1.0)))
        return image

    def histogram(self, max_size=(512, 512)):
        values = np.clip(self.render_float(max_size).pixels[:, :, :3], 0.0, 1.0)
        return {"red": np.histogram(values[:, :, 0], 256, (0, 1))[0].tolist(),
                "green": np.histogram(values[:, :, 1], 256, (0, 1))[0].tolist(),
                "blue": np.histogram(values[:, :, 2], 256, (0, 1))[0].tolist(),
                "luminance": np.histogram(highbit.luminance(values), 256, (0, 1))[0].tolist()}

    def project_value(self, recovery=False):
        value = {"version": PROJECT_VERSION,
                 "source_path": self.recorded_source_path,
                 "artifacts": self.artifacts.value(),
                 "original_artifact_id": self.original_artifact_id,
                 "edit_source_artifact_id": self.edit_source_artifact_id,
                 "adjustments": self.adjustments, "geometry": self.geometry,
                 "layers": self.layers, "retouched": self.retouched,
                 "saved_selections": self.saved_selections,
                 "history": self.history[-MAX_HISTORY_STEPS:],
                 "history_index": min(self.history_index, MAX_HISTORY_STEPS - 1)}
        if recovery:
            value["recovery"] = True
            value["project_path"] = self.project_path
        return value

    def save_project(self, path):
        archive_source = getattr(self, "raw_source_path", self.source_path)
        if photo_manager.same_file(path, archive_source):
            raise ValueError("SNAP SLAPPER will not overwrite the original photograph with a project.")
        value = self.project_value()
        preview_document = self
        if getattr(self, "raw_source_path", ""):
            preview_document = copy.deepcopy(self)
            preview_document.raw_source_path = ""
        _write_project_archive(path, value, archive_source,
                               embed_source=not bool(getattr(self, "raw_source_path", "")),
                               preview=preview_document.render((1600, 1600)))
        self.project_path = path
        self.mark_saved()

    def save_recovery(self, path):
        archive_source = getattr(self, "raw_source_path", self.source_path)
        if photo_manager.same_file(path, archive_source):
            raise ValueError("Recovery path resolves to the original photograph.")
        preview_document = self
        if getattr(self, "raw_source_path", ""):
            preview_document = copy.deepcopy(self)
            preview_document.raw_source_path = ""
        _write_project_archive(path, self.project_value(recovery=True), archive_source,
                               embed_source=not bool(getattr(self, "raw_source_path", "")),
                               preview=preview_document.render((1600, 1600)))

    @classmethod
    def load_project(cls, path, *, trust_external_source=False):
        if os.path.getsize(path) > MAX_PROJECT_BYTES:
            raise ValueError("SNAP SLAPPER project is too large to open safely")
        value = _read_project_document(path)
        if not isinstance(value, dict):
            raise ValueError("Invalid SNAP SLAPPER project: the root must be an object")
        if value.get("version") not in {LEGACY_PROJECT_VERSION, PROJECT_VERSION}:
            raise ValueError("Unsupported SNAP SLAPPER project version")
        legacy_project = value.get("version") == LEGACY_PROJECT_VERSION
        expected_types = {"adjustments": dict, "geometry": dict,
                          "layers": list, "retouched": list,
                          "saved_selections": dict}
        for field, expected in expected_types.items():
            if field in value and not isinstance(value[field], expected):
                raise ValueError(f"Invalid SNAP SLAPPER project: {field} has the wrong type")
        recorded_source_path = value.get("source_path")
        if not isinstance(recorded_source_path, str) or not recorded_source_path.strip():
            raise ValueError("Invalid SNAP SLAPPER project: source_path is missing")
        source_path = recorded_source_path
        embedded_source = None
        if zipfile.is_zipfile(path) and isinstance(value.get("source_ingredient"), dict):
            embedded_source = _extract_embedded_source(path, value["source_ingredient"])
        if embedded_source:
            source_path = embedded_source
        else:
            if not legacy_project and not trust_external_source:
                raise ExternalProjectSourceApprovalRequired(source_path)
            if not os.path.isfile(source_path):
                raise FileNotFoundError(
                    f"The project's original photograph is missing: {source_path}")
            ingredient = value.get("source_ingredient")
            expected_hash = ingredient.get("sha256") if isinstance(ingredient, dict) else ""
            if expected_hash:
                if not isinstance(expected_hash, str) or len(expected_hash) != 64:
                    raise ValueError("Invalid SNAP SLAPPER project: source hash is invalid")
                if not hmac.compare_digest(_source_sha256(source_path), expected_hash.lower()):
                    raise ValueError("The project's external photograph failed its integrity check")
        raw_source_path = None
        if os.path.splitext(source_path)[1].lower() in photo_manager.RAW_EXTENSIONS:
            import raw_preview
            raw_source_path = source_path
            source_path = raw_preview.develop(raw_source_path,
                                              value.get("adjustments", {}))
        layers = value.get("layers", [])
        retouched = value.get("retouched", [])
        saved_selections = value.get("saved_selections", {})
        history = value.get("history", [])
        if len(layers) > MAX_PROJECT_LAYERS:
            raise ValueError("Invalid SNAP SLAPPER project: too many layers")
        if len(retouched) > MAX_RETOUCH_POINTS:
            raise ValueError("Invalid SNAP SLAPPER project: too many retouch points")
        if len(saved_selections) > 100:
            raise ValueError("Invalid SNAP SLAPPER project: too many saved selections")
        for name, encoded in saved_selections.items():
            if (not isinstance(name, str) or not name.strip() or len(name) > 100 or
                    not isinstance(encoded, str) or len(encoded) > MAX_ENCODED_MASK_BYTES):
                raise ValueError("Invalid SNAP SLAPPER project: saved selection is invalid")
        if not isinstance(history, list) or len(history) > MAX_HISTORY_STEPS:
            raise ValueError("Invalid SNAP SLAPPER project: editing history is invalid")
        for entry in history:
            if not isinstance(entry, dict) or not isinstance(entry.get("label"), str):
                raise ValueError("Invalid SNAP SLAPPER project: editing history entry is invalid")
            state = entry.get("state")
            if not isinstance(state, dict):
                raise ValueError("Invalid SNAP SLAPPER project: editing history state is invalid")
            for field, expected in expected_types.items():
                if field == "saved_selections" and field not in state:
                    continue
                if not isinstance(state.get(field), expected):
                    raise ValueError(
                        f"Invalid SNAP SLAPPER project: history {field} has the wrong type")
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
            if layer.get("type") == "filter":
                if layer.get("filter_type") not in slapper_filters.FILTER_DEFAULTS:
                    raise ValueError(
                        f"Invalid SNAP SLAPPER project: layer {index + 1} filter is unsupported")
                if layer.get("filter_version", 1) != 1:
                    raise ValueError(
                        f"Invalid SNAP SLAPPER project: layer {index + 1} filter version is unsupported")
        document = cls(source_path)
        if raw_source_path:
            artifacts_value = value.get("artifacts")
            if artifacts_value:
                document.artifacts = ArtifactRegistry.from_value(artifacts_value)
                document.original_artifact_id = value["original_artifact_id"]
                document.edit_source_artifact_id = value["edit_source_artifact_id"]
                document.artifacts.relocate(document.original_artifact_id,
                                            raw_source_path)
                profile = next((record for record in document.artifacts.records
                                if record.kind == ArtifactKind.RAW_PROFILE), None)
                if profile:
                    document.raw_profile_artifact_id = profile.id
                    document.artifacts.relocate(profile.id,
                                                os.path.splitext(source_path)[0] + ".pp3")
                document.artifacts.relocate(document.edit_source_artifact_id,
                                            source_path)
                document.source_path = source_path
                document.raw_source_path = raw_source_path
                document.original_filename = os.path.basename(recorded_source_path)
            else:
                artifacts = raw_preview.development_artifacts(
                    raw_source_path, value.get("adjustments", {}))
                document.attach_raw_source(raw_source_path, artifacts["master"],
                                           artifacts["profile"], artifacts["producer"])
        document.recorded_source_path = os.path.abspath(recorded_source_path)
        ingredient = value.get("source_ingredient")
        original_name = (ingredient.get("original_filename")
                         if isinstance(ingredient, dict) else None)
        if isinstance(original_name, str) and original_name.strip():
            document.original_filename = os.path.basename(original_name)
        if embedded_source:
            expected_hash = ingredient.get("sha256") if isinstance(ingredient, dict) else None
            candidate = document.recorded_source_path
            document.browse_source_path = None
            if os.path.isfile(candidate) and expected_hash:
                try:
                    if _source_sha256(candidate) == expected_hash:
                        document.browse_source_path = candidate
                except OSError:
                    pass
        document.adjustments = value.get("adjustments", copy.deepcopy(DEFAULT_ADJUSTMENTS))
        document.geometry = value.get("geometry", document.geometry)
        document.layers = layers
        document.retouched = retouched
        document.saved_selections = copy.deepcopy(saved_selections)
        document.history = copy.deepcopy(history)
        stored_index = value.get("history_index", len(history) - 1)
        document.history_index = (max(0, min(int(stored_index), len(history) - 1))
                                  if history else -1)
        if value.get("recovery"):
            project_path = value.get("project_path")
            document.project_path = project_path if isinstance(project_path, str) else None
            document.saved_snapshot = None
        else:
            document.project_path = path
        if not document.history:
            document.record("Open project")
        if not value.get("recovery"):
            document.mark_saved()
        return document

    def export(self, path, quality=95, copyright_text="", strip_gps=False):
        output = self.render_float()
        extension = os.path.splitext(path)[1].lower()
        records = slapper_provenance.export_operations(self.layers, output.size)
        if extension in {".tif", ".tiff", ".png"}:
            source_xmp = None
            try:
                with Image.open(self.source_path) as source:
                    source_xmp = source.info.get("xmp")
            except (OSError, ValueError):
                pass
            xmp = (slapper_provenance.embed_xmp(source_xmp, records)
                   if records else source_xmp)
            highbit.write(output, path, integer_bits=16, metadata_source=self.source_path,
                          copyright_text=copyright_text, strip_gps=strip_gps, xmp=xmp)
        else:
            display = output.display_proxy()
            if display.mode == "RGBA":
                background = Image.new("RGB", display.size, "black")
                background.paste(display, mask=display.getchannel("A"))
                display = background
            options = ({"quality": quality, "optimize": True}
                       if extension in {".jpg", ".jpeg", ".webp"} else {})
            photo_manager.save_with_metadata(
                display, path, self.source_path, copyright_text, strip_gps=strip_gps,
                provenance_records=records, **options)

    def recipe(self):
        geometry = {key: copy.deepcopy(value) for key, value in self.geometry.items()
                    if key != "crop"}
        layers = []
        for layer in self.layers:
            if layer.get("type") in {"adjustment", "filter"}:
                layers.append(copy.deepcopy(layer))
            elif layer.get("type") == "image" and layer.get("asset_ref"):
                clone = copy.deepcopy(layer)
                clone.pop("path", None)  # recipes/LEWKS carry references, never texture bytes/paths
                layers.append(clone)
        return {"version": PROJECT_VERSION, "adjustments": copy.deepcopy(self.adjustments),
                "geometry": geometry, "layers": layers}

    def stack_layers(self, layers, history_label="Apply LEWK"):
        """Add LEWK layers ON TOP without touching the base or existing
        layers. This is how a LEWK applies — it must not flatten the
        photographer's existing edits. Each layer gets a fresh unique id.
        """
        added = []
        for layer in layers:
            if (not isinstance(layer, dict) or
                    layer.get("type") not in {"adjustment", "filter", "image"}):
                raise ValueError("stack_layers only accepts safe LEWK layers")
            if layer.get("type") == "image" and not layer.get("asset_ref"):
                raise ValueError("LEWK texture layers require an asset reference")
            mask = layer.get("mask", "")
            if not isinstance(mask, str) or len(mask) > MAX_ENCODED_MASK_BYTES:
                raise ValueError("Invalid layer mask")
            clone = copy.deepcopy(layer)
            clone["id"] = _new_layer_id()
            self.layers.append(clone)
            added.append(clone)
        if len(self.layers) > MAX_PROJECT_LAYERS:
            raise ValueError("Too many layers")
        self.record(history_label)
        return added

    def apply_recipe(self, recipe):
        if not isinstance(recipe, dict):
            raise ValueError("Invalid SNAP SLAPPER recipe: the root must be an object")
        if recipe.get("version") != PROJECT_VERSION:
            raise ValueError("Unsupported recipe version")
        adjustments = recipe.get("adjustments", DEFAULT_ADJUSTMENTS)
        geometry = recipe.get("geometry", {})
        layers = recipe.get("layers", [])
        if (not isinstance(adjustments, dict) or not isinstance(geometry, dict) or
                not isinstance(layers, list)):
            raise ValueError("Invalid SNAP SLAPPER recipe contents")
        if len(layers) > MAX_PROJECT_LAYERS:
            raise ValueError("Invalid SNAP SLAPPER recipe: too many layers")
        for index, layer in enumerate(layers):
            if not isinstance(layer, dict) or layer.get("type") not in {"adjustment", "filter", "image"}:
                raise ValueError(f"Invalid SNAP SLAPPER recipe: layer {index + 1} is invalid")
            if layer.get("type") == "image" and not layer.get("asset_ref"):
                raise ValueError(
                    f"Invalid SNAP SLAPPER recipe: texture layer {index + 1} has no asset reference")
            if layer.get("type") == "filter" and (
                    layer.get("filter_type") not in slapper_filters.FILTER_DEFAULTS or
                    layer.get("filter_version", 1) != 1):
                raise ValueError(
                    f"Invalid SNAP SLAPPER recipe: layer {index + 1} filter is unsupported")
            mask = layer.get("mask", "")
            if not isinstance(mask, str) or len(mask) > MAX_ENCODED_MASK_BYTES:
                raise ValueError(f"Invalid SNAP SLAPPER recipe: layer {index + 1} mask is invalid")
        self.adjustments = copy.deepcopy(adjustments)
        for key in ("rotation", "flip_x", "flip_y", "perspective_vertical",
                    "perspective_horizontal", "perspective_corners",
                    "perspective_edges", "lens_distortion", "lens_spherical",
                    "lens_center_x", "lens_center_y", "lens_scale", "lens_edges",
                    "horizon_curve", "horizon_strength", "horizon_edge_protection"):
            if key in geometry:
                self.geometry[key] = copy.deepcopy(geometry[key])
        clones = copy.deepcopy(layers)
        for layer in clones:
            layer["id"] = _new_layer_id()
        self.layers.extend(clones)
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
