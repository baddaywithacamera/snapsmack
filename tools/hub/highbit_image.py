"""Float32 image boundary for SNAP SLAPPER's high-bit compositor.

Pillow is deliberately absent from the storage and export path.  It is used
only by :meth:`FloatImage.display_proxy` to hand an 8-bit preview to Qt.
"""

from __future__ import annotations

from dataclasses import dataclass
from fractions import Fraction
import os
import subprocess
import sys
import tempfile

import numpy as np
import OpenImageIO as oiio
import tifffile

import subprocess_limits


READ_EXTENSIONS = {".bmp", ".gif", ".jpeg", ".jpg", ".png", ".tif", ".tiff", ".webp"}
MAX_INPUT_BYTES = 1024 * 1024 * 1024
MAX_IMAGE_PIXELS = 200_000_000
MAX_IMAGE_CHANNELS = 4
MAX_FLOAT_BYTES = 2 * 1024 * 1024 * 1024


@dataclass(frozen=True)
class FloatImage:
    """Linear image samples carried as H×W×C float32 arrays.

    Values are not clipped while editing.  This preserves highlight and shadow
    headroom between operations; clipping/quantisation happens exactly once at
    an integer export boundary.
    """

    pixels: np.ndarray
    channel_names: tuple[str, ...]
    source_format: str = "float"
    icc_profile: bytes = b""

    def __post_init__(self):
        pixels = np.asarray(self.pixels)
        if pixels.dtype != np.float32 or pixels.ndim != 3:
            raise TypeError("FloatImage pixels must be an H×W×C float32 array")
        if pixels.shape[2] not in (1, 2, 3, 4):
            raise ValueError("FloatImage supports one through four channels")
        if len(self.channel_names) != pixels.shape[2]:
            raise ValueError("channel_names must match the pixel channel count")

    @property
    def width(self):
        return int(self.pixels.shape[1])

    @property
    def height(self):
        return int(self.pixels.shape[0])

    @property
    def size(self):
        return self.width, self.height

    def resized(self, maximum):
        maximum = tuple(max(1, int(value)) for value in maximum)
        scale = min(maximum[0] / self.width, maximum[1] / self.height, 1.0)
        if scale >= 1.0:
            return self
        width = max(1, round(self.width * scale))
        height = max(1, round(self.height * scale))
        source = oiio.ImageBuf(np.ascontiguousarray(self.pixels))
        roi = oiio.ROI(0, width, 0, height, 0, 1, 0, self.pixels.shape[2])
        result = oiio.ImageBufAlgo.resize(source, roi=roi)
        _raise_oiio(result, "resize")
        return FloatImage(np.ascontiguousarray(result.get_pixels(oiio.FLOAT)),
                          self.channel_names, self.source_format, self.icc_profile)

    def resized_exact(self, size):
        width, height = (max(1, int(round(value))) for value in size)
        if (width, height) == self.size:
            return self
        source = oiio.ImageBuf(np.ascontiguousarray(self.pixels))
        roi = oiio.ROI(0, width, 0, height, 0, 1, 0, self.pixels.shape[2])
        result = oiio.ImageBufAlgo.resize(source, roi=roi)
        _raise_oiio(result, "resize")
        return FloatImage(np.ascontiguousarray(result.get_pixels(oiio.FLOAT)),
                          self.channel_names, self.source_format, self.icc_profile)

    def display_proxy(self, maximum=None):
        """Return a Pillow RGB/RGBA image solely for display in the UI."""
        from PIL import Image

        image = self.resized(maximum) if maximum else self
        values = np.uint8(np.rint(np.clip(image.pixels, 0.0, 1.0) * 255.0))
        channels = values.shape[2]
        if channels == 1:
            return Image.fromarray(values[:, :, 0], "L").convert("RGB")
        if channels == 2:
            grey = values[:, :, 0]
            rgba = np.dstack((grey, grey, grey, values[:, :, 1]))
            return Image.fromarray(rgba, "RGBA")
        return Image.fromarray(values, "RGB" if channels == 3 else "RGBA")

    def crop(self, box):
        left, top, right, bottom = (int(round(value)) for value in box)
        left = max(0, min(self.width, left)); right = max(left, min(self.width, right))
        top = max(0, min(self.height, top)); bottom = max(top, min(self.height, bottom))
        return FloatImage(np.ascontiguousarray(self.pixels[top:bottom, left:right]),
                          self.channel_names, self.source_format, self.icc_profile)

    def flipped(self, horizontal=False, vertical=False):
        values = self.pixels
        if horizontal:
            values = values[:, ::-1]
        if vertical:
            values = values[::-1]
        return FloatImage(np.ascontiguousarray(values), self.channel_names,
                          self.source_format, self.icc_profile)


def _raise_oiio(image, operation):
    error = image.geterror() if image.has_error else ""
    if error:
        raise OSError(f"OpenImageIO {operation} failed: {error}")


def _safe_input_spec(path):
    """Inspect an intended photo format and reject unsafe allocation sizes."""
    extension = os.path.splitext(path)[1].lower()
    if extension not in READ_EXTENSIONS:
        raise ValueError(f"Unsupported image format: {extension or '(none)'}")
    size = os.path.getsize(path)
    if size <= 0 or size > MAX_INPUT_BYTES:
        raise ValueError("Image file is empty or too large to open safely")
    image_input = oiio.ImageInput.open(path)
    if image_input is None:
        raise OSError(f"OpenImageIO could not inspect image: {path}")
    try:
        spec = image_input.spec()
        width, height, channels = int(spec.width), int(spec.height), int(spec.nchannels)
        if width <= 0 or height <= 0 or channels <= 0:
            raise ValueError("Image dimensions are invalid")
        pixels = width * height
        if pixels > MAX_IMAGE_PIXELS:
            raise ValueError("Image dimensions exceed the safe pixel limit")
        if channels > MAX_IMAGE_CHANNELS:
            raise ValueError("Image has too many channels to open safely")
        if pixels * channels * np.dtype(np.float32).itemsize > MAX_FLOAT_BYTES:
            raise ValueError("Image would exceed the safe working-memory limit")
        return spec
    finally:
        image_input.close()


def _read_direct(path, maximum=None):
    """Decode inside the dedicated worker after the independent safety preflight."""
    source = oiio.ImageBuf(path)
    _raise_oiio(source, f"open of {path}")
    spec = source.spec()
    if not spec.width or not spec.height or not spec.nchannels:
        raise OSError(f"OpenImageIO could not decode image: {path}")
    # Honour camera orientation before assigning stable working dimensions.
    oriented = oiio.ImageBufAlgo.reorient(source)
    _raise_oiio(oriented, "orientation")
    if maximum:
        bound_w, bound_h = (max(1, int(value)) for value in maximum)
        oriented_spec = oriented.spec()
        scale = min(bound_w / oriented_spec.width,
                    bound_h / oriented_spec.height, 1.0)
        if scale < 1.0:
            width = max(1, round(oriented_spec.width * scale))
            height = max(1, round(oriented_spec.height * scale))
            roi = oiio.ROI(0, width, 0, height, 0, 1, 0,
                           oriented_spec.nchannels)
            oriented = oiio.ImageBufAlgo.resize(oriented, roi=roi)
            _raise_oiio(oriented, "bounded preview resize")
    pixels = np.ascontiguousarray(oriented.get_pixels(oiio.FLOAT))
    if pixels.size == 0:
        raise OSError(f"OpenImageIO returned no pixels for: {path}")
    profile = spec.getattribute("ICCProfile")
    if profile is None:
        profile = b""
    elif isinstance(profile, np.ndarray):
        profile = profile.astype(np.uint8, copy=False).tobytes()
    if isinstance(profile, str):
        profile = profile.encode("latin-1")
    return FloatImage(pixels, tuple(oriented.spec().channelnames),
                      str(spec.format), bytes(profile))


def _worker_command(path, target, maximum=None):
    bounds = ([] if not maximum else
              [str(max(1, int(maximum[0]))), str(max(1, int(maximum[1])))])
    if getattr(sys, "frozen", False):
        return [sys.executable, "--highbit-decode-worker", path, target] + bounds
    launcher = os.path.join(os.path.dirname(__file__), "run_slapper_qt.py")
    return [sys.executable, launcher, "--highbit-decode-worker", path, target] + bounds


def read(path, maximum=None):
    """Read a bounded raster at source precision through an isolated worker."""
    path = os.path.abspath(os.fspath(path))
    _safe_input_spec(path)
    descriptor, target = tempfile.mkstemp(prefix="snap-highbit-decode-", suffix=".npz")
    os.close(descriptor)
    try:
        result = subprocess_limits.run(
            _worker_command(path, target, maximum), timeout=180,
            memory_bytes=MAX_FLOAT_BYTES,
            capture_output=True, creationflags=(subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0))
        if result.returncode:
            detail = (result.stderr or result.stdout or b"decoder failed")
            if isinstance(detail, bytes):
                detail = detail.decode("utf-8", "replace")
            raise OSError("Image decoder failed: " + detail.strip()[-1000:])
        with np.load(target, allow_pickle=False) as decoded:
            pixels = np.ascontiguousarray(decoded["pixels"], dtype=np.float32)
            channels = tuple(str(item) for item in decoded["channel_names"].tolist())
            source_format = str(decoded["source_format"].tolist()[0])
            profile = decoded["icc_profile"].astype(np.uint8, copy=False).tobytes()
        if pixels.ndim != 3 or pixels.shape[2] > MAX_IMAGE_CHANNELS:
            raise OSError("Image decoder returned an invalid pixel buffer")
        if pixels.nbytes > MAX_FLOAT_BYTES:
            raise OSError("Image decoder exceeded the safe working-memory limit")
        return FloatImage(pixels, channels, source_format, profile)
    finally:
        try:
            os.remove(target)
        except OSError:
            pass


def from_pillow(image):
    """Import an explicitly display/graphic asset; never use for photo ingress."""
    mode = "RGBA" if "A" in image.getbands() else "RGB"
    values = np.asarray(image.convert(mode), dtype=np.float32) / 255.0
    names = ("R", "G", "B", "A") if mode == "RGBA" else ("R", "G", "B")
    return FloatImage(np.ascontiguousarray(values), names, "uint8-graphic")


def constant(size, colour):
    colour = np.asarray(colour, dtype=np.float32)
    if np.max(colour, initial=0.0) > 1.0:
        colour = colour / 255.0
    values = np.broadcast_to(colour, (int(size[1]), int(size[0]), len(colour))).copy()
    names = ("R", "G", "B", "A")[:len(colour)]
    return FloatImage(values.astype(np.float32), names, "generated")


def resample_coordinates(image, source_x, source_y, *, transparent=True):
    """Bilinearly sample float pixels at output-to-source coordinate maps."""
    sx = np.asarray(source_x, dtype=np.float32)
    sy = np.asarray(source_y, dtype=np.float32)
    if sx.shape != sy.shape or sx.ndim != 2:
        raise ValueError("coordinate maps must be matching two-dimensional arrays")
    source = image.pixels
    alpha_added = transparent and source.shape[2] in (1, 3)
    if alpha_added:
        source = np.concatenate((source,
            np.ones((*source.shape[:2], 1), dtype=np.float32)), axis=2)
    height, width = source.shape[:2]
    valid = (sx >= 0.0) & (sx <= width - 1) & (sy >= 0.0) & (sy <= height - 1)
    x0 = np.floor(np.clip(sx, 0, width - 1)).astype(np.int32)
    y0 = np.floor(np.clip(sy, 0, height - 1)).astype(np.int32)
    x1 = np.minimum(x0 + 1, width - 1)
    y1 = np.minimum(y0 + 1, height - 1)
    wx = (np.clip(sx, 0, width - 1) - x0)[:, :, None]
    wy = (np.clip(sy, 0, height - 1) - y0)[:, :, None]
    top = source[y0, x0] * (1.0 - wx) + source[y0, x1] * wx
    bottom = source[y1, x0] * (1.0 - wx) + source[y1, x1] * wx
    result = top * (1.0 - wy) + bottom * wy
    if transparent:
        result *= valid[:, :, None]
    names = (("R", "G", "B", "A") if result.shape[2] == 4 else
             ("Y", "A") if result.shape[2] == 2 else image.channel_names)
    return FloatImage(np.ascontiguousarray(result, dtype=np.float32), names,
                      image.source_format, image.icc_profile)


def projective(image, coefficients, output_size=None):
    """Apply Pillow-compatible output-to-input projective coefficients."""
    width, height = output_size or image.size
    yy, xx = np.mgrid[0:height, 0:width].astype(np.float32)
    a, b, c, d, e, f, g, h = (np.float32(value) for value in coefficients)
    denominator = g * xx + h * yy + 1.0
    denominator = np.where(np.abs(denominator) < 1e-8, np.nan, denominator)
    return resample_coordinates(image, (a * xx + b * yy + c) / denominator,
                                (d * xx + e * yy + f) / denominator)


def radial_lens(image, geometry, auto_zoom=1.0):
    """Float equivalent of SNAP SLAPPER's radial/spherical lens mapping."""
    width, height = image.size
    yy, xx = np.mgrid[0:height, 0:width].astype(np.float32)
    half_w = max(1.0, (width - 1) / 2.0); half_h = max(1.0, (height - 1) / 2.0)
    centre_x = half_w + float(geometry.get("lens_center_x", 0.0)) / 100.0 * half_w
    centre_y = half_h + float(geometry.get("lens_center_y", 0.0)) / 100.0 * half_h
    nx = (xx - centre_x) / half_w; ny = (yy - centre_y) / half_h
    r2 = (nx * nx + ny * ny) / 2.0
    radial = float(geometry.get("lens_distortion", 0.0)) / 100.0
    spherical = float(geometry.get("lens_spherical", 0.0)) / 100.0
    factor = 1.0 + radial * .42 * r2 + spherical * .28 * r2 * r2
    manual_zoom = max(1.0, float(geometry.get("lens_scale", 100.0)) / 100.0)
    factor /= max(1.0, manual_zoom, float(auto_zoom))
    return resample_coordinates(image, centre_x + nx * factor * half_w,
                                centre_y + ny * factor * half_h)


def straighten_curved_horizon(image, points, strength=1.0, edge_protection=0.6):
    """Warp a traced source horizon onto a horizontal target line.

    The guide is normalized to the image.  The inverse coordinate map makes
    every guide sample land at the guide's median height while smoothly
    anchoring the top and bottom edges.  All sampling stays float32.
    """
    if not points or len(points) < 2 or image.width < 2 or image.height < 2:
        return image
    clean = []
    for point in points:
        if not isinstance(point, (list, tuple)) or len(point) != 2:
            continue
        try:
            x = min(1.0, max(0.0, float(point[0])))
            y = min(1.0, max(0.0, float(point[1])))
        except (TypeError, ValueError):
            continue
        clean.append((x, y))
    if len(clean) < 2:
        return image
    clean.sort(key=lambda item: item[0])
    # Average repeated/backtracked x positions from a freehand stroke.
    bins = {}
    for x, y in clean:
        key = round(x * max(1, image.width - 1))
        bins.setdefault(key, []).append(y)
    xs = np.asarray(sorted(bins), dtype=np.float32)
    ys = np.asarray([sum(bins[x]) / len(bins[x]) for x in xs], dtype=np.float32)
    if len(xs) < 2:
        return image
    columns = np.arange(image.width, dtype=np.float32)
    guide = np.interp(columns, xs, ys) * (image.height - 1)
    # Remove hand tremor without erasing broad horizon curvature.
    radius = max(1, min(31, image.width // 160))
    if radius > 1:
        kernel = np.ones(radius * 2 + 1, dtype=np.float32) / (radius * 2 + 1)
        padded = np.pad(guide, (radius, radius), mode="edge")
        guide = np.convolve(padded, kernel, mode="valid")
    target = float(np.median(guide))
    displacement = (guide - target) * min(1.0, max(0.0, float(strength)))
    yy, xx = np.mgrid[0:image.height, 0:image.width].astype(np.float32)
    above_scale = max(1.0, target)
    below_scale = max(1.0, image.height - 1 - target)
    distance = np.where(yy <= target, (target - yy) / above_scale,
                        (yy - target) / below_scale)
    power = 1.0 + 4.0 * min(1.0, max(0.0, float(edge_protection)))
    influence = np.power(np.clip(1.0 - distance, 0.0, 1.0), power)
    source_y = yy + displacement[None, :] * influence
    return resample_coordinates(image, xx, source_y, transparent=False)


def rotate(image, degrees, expand=True, centre=None):
    degrees = float(degrees)
    if not degrees % 360.0:
        return image
    radians = np.deg2rad(degrees)
    source_cx = (image.width - 1) / 2.0
    source_cy = (image.height - 1) / 2.0
    if centre is not None:
        source_cx, source_cy = map(float, centre)
    cosine, sine = np.cos(radians), np.sin(radians)
    if expand and centre is None:
        width = max(1, int(np.ceil(abs(image.width * cosine) + abs(image.height * sine))))
        height = max(1, int(np.ceil(abs(image.width * sine) + abs(image.height * cosine))))
    else:
        width, height = image.size
    output_cx = (width - 1) / 2.0; output_cy = (height - 1) / 2.0
    yy, xx = np.mgrid[0:height, 0:width].astype(np.float32)
    dx, dy = xx - output_cx, yy - output_cy
    sx = cosine * dx + sine * dy + source_cx
    sy = -sine * dx + cosine * dy + source_cy
    return resample_coordinates(image, sx, sy)


def fit_layer(image, canvas_size, mode):
    width, height = map(int, canvas_size)
    if mode == "stretch":
        return image.resized_exact((width, height))
    if mode in {"cover", "contain"}:
        scale = (max(width / image.width, height / image.height) if mode == "cover" else
                 min(width / image.width, height / image.height))
        fitted = image.resized_exact((round(image.width * scale), round(image.height * scale)))
        if mode == "cover":
            left = max(0, (fitted.width - width) // 2)
            top = max(0, (fitted.height - height) // 2)
            return fitted.crop((left, top, left + width, top + height))
        return place_layer(fitted, (width, height), {"x": .5, "y": .5})
    if mode == "tile":
        source = image.pixels
        yy = np.arange(height)[:, None] % image.height
        xx = np.arange(width)[None, :] % image.width
        values = source[yy, xx]
        return FloatImage(np.ascontiguousarray(values), image.channel_names,
                          image.source_format, image.icc_profile)
    return image


def place_layer(image, canvas_size, transform, linked_mask=None):
    """Place/scale/rotate a layer directly onto a transparent float canvas."""
    width, height = map(int, canvas_size)
    scale_x = np.clip(float(transform.get("scale_x", 1.0)), .01, 20.0)
    scale_y = np.clip(float(transform.get("scale_y", 1.0)), .01, 20.0)
    if transform.get("flip_x"):
        scale_x *= -1.0
    if transform.get("flip_y"):
        scale_y *= -1.0
    angle = np.deg2rad(float(transform.get("rotation", 0.0)))
    cosine, sine = np.cos(angle), np.sin(angle)
    centre_x = float(transform.get("x", .5)) * width
    centre_y = float(transform.get("y", .5)) * height
    yy, xx = np.mgrid[0:height, 0:width].astype(np.float32)
    dx, dy = xx - centre_x, yy - centre_y
    local_x = (cosine * dx - sine * dy) / scale_x + (image.width - 1) / 2.0
    local_y = (sine * dx + cosine * dy) / scale_y + (image.height - 1) / 2.0
    corners = transform.get("warp_corners")
    if isinstance(corners, list) and len(corners) == 4:
        try:
            offsets = np.asarray(corners, dtype=np.float32).reshape(4, 2)
            offsets = np.clip(offsets, -.75, .75)
            u = local_x / max(1, image.width - 1)
            v = local_y / max(1, image.height - 1)
            # Bilinear free warp. Corner order is TL, TR, BR, BL and values
            # are fractions of the untransformed layer dimensions.
            wx = ((1-u)*(1-v)*offsets[0, 0] + u*(1-v)*offsets[1, 0] +
                  u*v*offsets[2, 0] + (1-u)*v*offsets[3, 0])
            wy = ((1-u)*(1-v)*offsets[0, 1] + u*(1-v)*offsets[1, 1] +
                  u*v*offsets[2, 1] + (1-u)*v*offsets[3, 1])
            local_x -= wx * image.width
            local_y -= wy * image.height
        except (TypeError, ValueError):
            pass
    placed = resample_coordinates(image, local_x, local_y, transparent=True)
    if linked_mask is not None:
        mask = np.asarray(linked_mask, dtype=np.float32)
        if mask.shape != (image.height, image.width):
            mask_image = FloatImage(mask[:, :, None], ("Y",)).resized_exact(image.size)
            mask = mask_image.pixels[:, :, 0]
        placed_mask = resample_coordinates(
            FloatImage(mask[:, :, None], ("Y",)), local_x, local_y,
            transparent=False).pixels[:, :, 0]
        values = placed.pixels.copy()
        if values.shape[2] == 4:
            values[:, :, 3] *= placed_mask
        return FloatImage(values, placed.channel_names, placed.source_format, placed.icc_profile)
    return placed


def write(image, path, *, integer_bits=16, metadata_source=None,
          copyright_text="", strip_gps=False, xmp=None):
    """Write a float image; integer exports quantise once at this boundary."""
    if not isinstance(image, FloatImage):
        raise TypeError("write expects a FloatImage")
    path = os.path.abspath(os.fspath(path))
    extension = os.path.splitext(path)[1].lower()
    if integer_bits == 16:
        output_type = oiio.TypeUInt16
    elif integer_bits == 8:
        output_type = oiio.TypeUInt8
    elif integer_bits == 32 and extension in {".exr", ".tif", ".tiff"}:
        output_type = oiio.TypeFloat
    else:
        raise ValueError("integer_bits must be 8, 16, or float32-compatible 32")
    source_spec = None
    if metadata_source:
        candidate = oiio.ImageBuf(os.path.abspath(os.fspath(metadata_source)))
        if not candidate.has_error and candidate.spec().width:
            source_spec = candidate.spec()
    if source_spec is not None and not strip_gps:
        spec = source_spec.copy()
    else:
        # Starting with a clean spec is important for privacy exports. Some
        # encoders preserve an empty GPS IFD pointer even after every visible
        # GPS field is erased from a copied JPEG spec.
        spec = oiio.ImageSpec(image.width, image.height,
                              image.pixels.shape[2], output_type)
        if source_spec is not None:
            portable = {
                "Copyright", "Exif:Copyright", "Artist", "ImageDescription",
                "Make", "Model", "DateTime", "Exif:DateTimeOriginal",
                "Exif:OffsetTimeOriginal", "Exif:OffsetTimeDigitized",
                "Exif:LensModel", "ExposureTime", "FNumber",
                "Exif:FocalLength", "Exif:Flash", "XResolution",
                "YResolution", "ResolutionUnit", "Software",
                "XML:com.adobe.xmp", "XMLPacket", "oiio:ColorSpace",
            }
            for attribute in source_spec.extra_attribs:
                if attribute.name in portable:
                    spec.attribute(attribute.name, attribute.type,
                                   source_spec.getattribute(attribute.name))
    spec.x = spec.y = spec.z = 0
    spec.width = spec.full_width = image.width
    spec.height = spec.full_height = image.height
    spec.depth = spec.full_depth = 1
    spec.nchannels = image.pixels.shape[2]
    spec.format = output_type
    spec.channelnames = image.channel_names
    spec.attribute("Orientation", 1)
    if image.icc_profile:
        spec.attribute("ICCProfile", oiio.TypeDesc(
            f"uint8[{len(image.icc_profile)}]"), image.icc_profile)
    if strip_gps:
        for attribute in list(spec.extra_attribs):
            if "gps" in attribute.name.lower():
                spec.erase_attribute(attribute.name)
    if copyright_text and not spec.get_string_attribute("Exif:Copyright", "").strip():
        spec.attribute("Exif:Copyright", str(copyright_text).strip())
    if xmp:
        if isinstance(xmp, bytes):
            xmp = xmp.decode("utf-8", errors="replace")
        if extension == ".png":
            # Added as a registered iTXt chunk after OIIO writes the pixels.
            # A generic OIIO attribute becomes tEXt and masks Pillow's XMP key.
            spec.erase_attribute("XML:com.adobe.xmp")
            spec.erase_attribute("XMLPacket")
        else:
            # TIFF tag 700 is a byte packet, not a NUL-terminated string.
            packet = str(xmp).encode("utf-8")
            spec.attribute("XMLPacket", oiio.TypeDesc(
                f"uint8[{len(packet)}]"), packet)
    directory = os.path.dirname(path)
    os.makedirs(directory, exist_ok=True)
    extension = os.path.splitext(path)[1]
    descriptor, temporary = tempfile.mkstemp(prefix=".snap-writing-", suffix=extension,
                                              dir=directory)
    os.close(descriptor)
    if extension.lower() in {".tif", ".tiff"}:
        try:
            if integer_bits == 16:
                pixels = np.rint(np.clip(image.pixels, 0.0, 1.0) * 65535.0).astype(np.uint16)
            elif integer_bits == 8:
                pixels = np.rint(np.clip(image.pixels, 0.0, 1.0) * 255.0).astype(np.uint8)
            else:
                pixels = image.pixels.astype(np.float32, copy=False)
            extra_tags = []
            if source_spec is not None:
                # Preserve the portable camera/author fields surfaced by OIIO.
                # These are ordinary TIFF fields and remain readable even when
                # the source was JPEG or a RawTherapee-developed TIFF.
                ascii_tags = {
                    "ImageDescription": 270, "Make": 271, "Model": 272,
                    "DateTime": 306, "Artist": 315,
                    "Exif:DateTimeOriginal": 36867,
                    "Exif:OffsetTimeOriginal": 36881,
                    "Exif:OffsetTimeDigitized": 36882,
                    "Exif:LensModel": 42036,
                }
                for attribute, tag in ascii_tags.items():
                    value = source_spec.get_string_attribute(attribute, "").strip()
                    if value:
                        extra_tags.append((tag, "s", len(value) + 1, value + "\0", False))
                rational_tags = {
                    "ExposureTime": 33434, "FNumber": 33437,
                    "Exif:FocalLength": 37386,
                }
                for attribute, tag in rational_tags.items():
                    value = source_spec.get_float_attribute(attribute, 0.0)
                    if value > 0:
                        ratio = Fraction(float(value)).limit_denominator(1_000_000)
                        extra_tags.append((tag, "2I", 1,
                                           (ratio.numerator, ratio.denominator), False))
                flash = source_spec.get_int_attribute("Exif:Flash", -1)
                if flash >= 0:
                    extra_tags.append((37385, "H", 1, flash, False))
            if image.icc_profile:
                extra_tags.append((34675, "B", len(image.icc_profile),
                                   image.icc_profile, False))
            if xmp:
                packet = (xmp if isinstance(xmp, bytes) else
                          str(xmp).encode("utf-8"))
                extra_tags.append((700, "B", len(packet), packet, False))
            source_copyright = ((source_spec.get_string_attribute("Exif:Copyright", "") or
                                 source_spec.get_string_attribute("Copyright", "")).strip()
                                if source_spec is not None else "")
            final_copyright = source_copyright or str(copyright_text).strip()
            if final_copyright:
                value = final_copyright + "\0"
                extra_tags.append((33432, "s", len(value), value, False))
            x_resolution = (source_spec.get_float_attribute("XResolution", 1.0)
                            if source_spec is not None else 1.0)
            y_resolution = (source_spec.get_float_attribute("YResolution", 1.0)
                            if source_spec is not None else 1.0)
            resolution_unit = (source_spec.get_string_attribute("ResolutionUnit", "").lower()
                               if source_spec is not None else "")
            software = (source_spec.get_string_attribute("Software", "").strip()
                        if source_spec is not None else "") or "SNAP SLAPPER"
            tifffile.imwrite(
                temporary, pixels, photometric="rgb", compression="deflate",
                metadata=None, extratags=extra_tags, software=software,
                resolution=(x_resolution, y_resolution),
                resolutionunit=("INCH" if resolution_unit in {"in", "inch"} else
                                "CENTIMETER" if resolution_unit in {"cm", "centimeter"}
                                else "NONE"))
            with open(temporary, "r+b") as handle:
                os.fsync(handle.fileno())
            os.replace(temporary, path)
            return
        except Exception:
            try:
                os.remove(temporary)
            except OSError:
                pass
            raise
    output = oiio.ImageOutput.create(temporary)
    if output is None or not output.open(temporary, spec):
        detail = output.geterror() if output is not None else oiio.geterror()
        try:
            os.remove(temporary)
        except OSError:
            pass
        raise OSError(f"OpenImageIO could not create {path}: {detail}")
    try:
        # OIIO performs the requested conversion.  Explicit clipping makes the
        # sole integer-quantisation boundary visible and testable.
        if not output.write_image(np.clip(image.pixels, 0.0, 1.0)):
            raise OSError(f"OpenImageIO could not write {path}: {output.geterror()}")
    finally:
        output.close()
    if extension.lower() == ".png" and xmp:
        # OIIO does not consistently emit the registered PNG XMP iTXt chunk.
        # Add it without decoding or requantising the 16-bit pixel payload.
        import struct
        import zlib
        packet = xmp if isinstance(xmp, bytes) else str(xmp).encode("utf-8")
        chunk_type = b"iTXt"
        chunk_data = b"XML:com.adobe.xmp\0\0\0\0\0" + packet
        chunk = (struct.pack(">I", len(chunk_data)) + chunk_type + chunk_data +
                 struct.pack(">I", zlib.crc32(chunk_type + chunk_data) & 0xffffffff))
        with open(temporary, "rb") as handle:
            png = handle.read()
        # Place ancillary metadata before IDAT so lazy PNG readers expose it
        # immediately on open (many do not scan beyond image data until load).
        marker = png.find(b"IDAT") - 4
        if marker < 8:
            raise OSError("OpenImageIO produced an invalid PNG")
        with open(temporary, "wb") as handle:
            handle.write(png[:marker] + chunk + png[marker:])
    try:
        with open(temporary, "r+b") as handle:
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    except Exception:
        try:
            os.remove(temporary)
        except OSError:
            pass
        raise


def _rgb(image):
    pixels = image.pixels
    if pixels.shape[2] == 1:
        return np.repeat(pixels, 3, axis=2), None
    if pixels.shape[2] == 2:
        return np.repeat(pixels[:, :, :1], 3, axis=2), pixels[:, :, 1:2]
    return pixels[:, :, :3], pixels[:, :, 3:4] if pixels.shape[2] == 4 else None


def _with_rgb(image, rgb, alpha=None):
    if alpha is None:
        return FloatImage(np.ascontiguousarray(rgb, dtype=np.float32),
                          ("R", "G", "B"), image.source_format, image.icc_profile)
    return FloatImage(np.ascontiguousarray(np.concatenate((rgb, alpha), axis=2),
                                           dtype=np.float32),
                      ("R", "G", "B", "A"), image.source_format, image.icc_profile)


def luminance(rgb):
    # Match the editor's established sRGB/Pillow visual contract. The working
    # values retain float headroom; this is only the luma weighting.
    return (rgb[:, :, 0] * np.float32(0.299) +
            rgb[:, :, 1] * np.float32(0.587) +
            rgb[:, :, 2] * np.float32(0.114))


def invert(image, luminance_only=False):
    """Invert RGB channels or luminance while preserving alpha.

    Luminance-only inversion adds the same delta to all three channels. That
    turns Y into 1-Y while leaving the two opponent-colour differences intact;
    applying it twice therefore restores the original float values exactly.
    """
    rgb, alpha = _rgb(image)
    if luminance_only:
        tone = luminance(rgb)
        result = rgb + (np.float32(1.0) - np.float32(2.0) * tone)[:, :, None]
    else:
        result = np.float32(1.0) - rgb
    return _with_rgb(image, result.astype(np.float32), alpha)


_HUE_BANDS = (("red", 0.0), ("orange", 30.0), ("yellow", 60.0),
              ("green", 120.0), ("aqua", 180.0), ("blue", 240.0),
              ("purple", 270.0), ("magenta", 300.0))


def _hue_saturation(rgb):
    values = np.clip(rgb, 0.0, None)
    maximum = np.max(values, axis=2)
    minimum = np.min(values, axis=2)
    delta = maximum - minimum
    saturation = np.zeros_like(maximum)
    np.divide(delta, maximum, out=saturation, where=maximum > 1e-8)
    hue = np.zeros_like(maximum)
    active = delta > 1e-8
    red = active & (maximum == values[:, :, 0])
    green = active & (maximum == values[:, :, 1])
    blue = active & (maximum == values[:, :, 2])
    hue[red] = 60.0 * np.mod((values[:, :, 1][red] - values[:, :, 2][red]) / delta[red], 6.0)
    hue[green] = 60.0 * (((values[:, :, 2][green] - values[:, :, 0][green]) / delta[green]) + 2.0)
    hue[blue] = 60.0 * (((values[:, :, 0][blue] - values[:, :, 1][blue]) / delta[blue]) + 4.0)
    return hue, saturation


def _band_values(settings, prefix, hue, scale):
    centres = np.asarray([degree for _name, degree in _HUE_BANDS], dtype=np.float32)
    sliders = np.asarray([float(settings.get(f"{prefix}_{name}", 0.0))
                          for name, _degree in _HUE_BANDS], dtype=np.float32)
    extended_x = np.concatenate((centres[-1:] - 360.0, centres, centres[:1] + 360.0))
    extended_y = np.concatenate((sliders[-1:], sliders, sliders[:1]))
    return 1.0 + np.interp(hue, extended_x, extended_y).astype(np.float32) / 100.0 * scale


def _smoothstep(edge0, edge1, value):
    amount = np.clip((value - edge0) / max(1e-6, edge1 - edge0), 0.0, 1.0)
    return amount * amount * (3.0 - 2.0 * amount)


def _remap_luminance(rgb, source_luma, target_luma):
    """Change luminance without independently bending the RGB channels.

    Exposure/tone tools operate on scene luminance and scale the colour vector,
    which keeps hue stable. Near black there is no colour vector to scale, so a
    neutral target is the only defined result.
    """
    source = np.asarray(source_luma, dtype=np.float32)
    target = np.asarray(target_luma, dtype=np.float32)
    active = source > np.float32(1e-6)
    ratio = np.ones_like(source, dtype=np.float32)
    np.divide(target, source, out=ratio, where=active)
    mapped = rgb * ratio[:, :, None]
    return np.where(active[:, :, None], mapped, target[:, :, None]).astype(np.float32)


def _wavelet_noise_reduction(rgb, luminance_amount=0.0, colour_amount=0.0):
    """Edge-preserving multiscale luminance and opponent-colour denoising.

    This is an independent à-trous-style implementation: fine-scale residuals
    are soft-thresholded from a robust MAD noise estimate, while colour noise
    receives a stronger edge-aware low-pass. Large residuals survive as real
    detail instead of being blurred with the noise.
    """
    lum_strength = np.clip(float(luminance_amount) / 100.0, 0.0, 1.0)
    colour_strength = np.clip(float(colour_amount) / 100.0, 0.0, 1.0)
    if not lum_strength and not colour_strength:
        return rgb

    original_luma = luminance(rgb).astype(np.float32)
    denoised_luma = original_luma
    if lum_strength:
        current = original_luma
        details = []
        for radius in (.65, 1.30, 2.60):
            plane = FloatImage(current[:, :, None].astype(np.float32), ("Y",))
            coarse = gaussian_blur(plane, radius).pixels[:, :, 0]
            details.append(current - coarse)
            current = coarse
        finest = details[0]
        centre = np.median(finest)
        sigma = float(np.median(np.abs(finest - centre)) / .67448975)
        # At 100, reject roughly five estimated sigmas on the finest scale;
        # coarser bands use lower thresholds so shapes and texture survive.
        threshold = sigma * (.35 + 4.65 * lum_strength)
        restored = current
        for detail, scale in zip(reversed(details), reversed((1.0, .62, .38))):
            limit = threshold * scale
            shrunk = np.sign(detail) * np.maximum(np.abs(detail) - limit, 0.0)
            restored = restored + shrunk.astype(np.float32)
        denoised_luma = (original_luma * (1.0 - lum_strength) +
                          restored * lum_strength).astype(np.float32)

    # Opponent colour is the RGB distance from luminance. Gaussian smoothing
    # is acceptable here only behind a luminance-edge gate; this prevents
    # colour bleeding across object boundaries.
    chroma = rgb - original_luma[:, :, None]
    if colour_strength:
        chroma_image = FloatImage(chroma.astype(np.float32), ("R", "G", "B"))
        soft_chroma, _ = _rgb(gaussian_blur(
            chroma_image, .75 + colour_strength * 2.75))
        gy, gx = np.gradient(original_luma)
        edge = np.sqrt(gx * gx + gy * gy)
        protection = np.clip(edge / (.012 + .045 * (1.0 - colour_strength)), 0.0, 1.0)
        mix = colour_strength * (1.0 - protection * .90)
        chroma = chroma * (1.0 - mix[:, :, None]) + soft_chroma * mix[:, :, None]

    return (denoised_luma[:, :, None] + chroma).astype(np.float32)


def _curve(values, points):
    """Piecewise-linear curve with endpoint extrapolation, never clipping headroom."""
    ordered = sorted((max(0.0, min(1.0, float(x) / 255.0)),
                      max(0.0, min(1.0, float(y) / 255.0))) for x, y in points)
    if not ordered or ordered[0][0] != 0.0:
        ordered.insert(0, (0.0, 0.0))
    if ordered[-1][0] != 1.0:
        ordered.append((1.0, 1.0))
    xs = np.asarray([item[0] for item in ordered], dtype=np.float32)
    ys = np.asarray([item[1] for item in ordered], dtype=np.float32)
    result = np.interp(values, xs, ys).astype(np.float32)
    low_slope = (ys[1] - ys[0]) / max(1e-6, xs[1] - xs[0])
    high_slope = (ys[-1] - ys[-2]) / max(1e-6, xs[-1] - xs[-2])
    result = np.where(values < xs[0], ys[0] + (values - xs[0]) * low_slope, result)
    result = np.where(values > xs[-1], ys[-1] + (values - xs[-1]) * high_slope, result)
    return result.astype(np.float32)


def _soft_light(base, blend):
    base_safe = np.clip(base, 0.0, None)
    dark = base - (1.0 - 2.0 * blend) * base * (1.0 - base)
    d = np.where(base_safe <= .25,
                 ((16.0 * base_safe - 12.0) * base_safe + 4.0) * base_safe,
                 np.sqrt(base_safe))
    light = base + (2.0 * blend - 1.0) * (d - base)
    return np.where(blend <= .5, dark, light)


def _oiio_result(image, operation, *args, **kwargs):
    source = oiio.ImageBuf(np.ascontiguousarray(image.pixels))
    result = operation(source, *args, **kwargs)
    _raise_oiio(result, getattr(operation, "__name__", "image operation"))
    return FloatImage(np.ascontiguousarray(result.get_pixels(oiio.FLOAT)),
                      image.channel_names, image.source_format, image.icc_profile)


def gaussian_blur(image, radius):
    radius = max(0.0, float(radius))
    if radius == 0.0:
        return image
    kernel = oiio.ImageBufAlgo.make_kernel("gaussian", radius * 2.0 + 1.0,
                                           radius * 2.0 + 1.0)
    source = oiio.ImageBuf(np.ascontiguousarray(image.pixels))
    result = oiio.ImageBufAlgo.convolve(source, kernel)
    _raise_oiio(result, "Gaussian blur")
    return FloatImage(np.ascontiguousarray(result.get_pixels(oiio.FLOAT)),
                      image.channel_names, image.source_format, image.icc_profile)


def unsharp(image, radius, amount, threshold=0.0):
    if float(amount) <= 0.0:
        return image
    return _oiio_result(image, oiio.ImageBufAlgo.unsharp_mask, "gaussian",
                        max(.1, float(radius)) * 2.0 + 1.0,
                        max(0.0, float(amount)), max(0.0, float(threshold)))


def blend(base, top, mode="normal", opacity=1.0, mask=None):
    """Composite float images without integer conversion or range clipping."""
    bottom, bottom_alpha = _rgb(base)
    upper, upper_alpha = _rgb(top)
    if bottom.shape != upper.shape:
        raise ValueError("Float blend inputs must have identical dimensions")
    if mode == "multiply":
        mixed = bottom * upper
    elif mode == "screen":
        mixed = 1.0 - (1.0 - bottom) * (1.0 - upper)
    elif mode == "overlay":
        mixed = np.where(bottom <= .5, 2.0 * bottom * upper,
                         1.0 - 2.0 * (1.0 - bottom) * (1.0 - upper))
    elif mode == "hard_light":
        mixed = np.where(upper <= .5, 2.0 * bottom * upper,
                         1.0 - 2.0 * (1.0 - bottom) * (1.0 - upper))
    elif mode == "soft_light":
        mixed = _soft_light(bottom, upper)
    elif mode == "darken":
        mixed = np.minimum(bottom, upper)
    elif mode == "lighten":
        mixed = np.maximum(bottom, upper)
    elif mode == "difference":
        mixed = np.abs(bottom - upper)
    elif mode == "color":
        mixed = upper + (luminance(bottom) - luminance(upper))[:, :, None]
    elif mode == "luminosity":
        mixed = bottom + (luminance(upper) - luminance(bottom))[:, :, None]
    else:
        mixed = upper
    amount = np.float32(max(0.0, min(1.0, float(opacity))))
    alpha = upper_alpha if upper_alpha is not None else np.ones((*upper.shape[:2], 1), np.float32)
    if mask is not None:
        alpha = alpha * np.asarray(mask, dtype=np.float32).reshape((*upper.shape[:2], 1))
    alpha = np.clip(alpha * amount, 0.0, 1.0)
    out_rgb = bottom * (1.0 - alpha) + mixed * alpha
    if bottom_alpha is None:
        out_alpha = None
    else:
        out_alpha = bottom_alpha + alpha * (1.0 - bottom_alpha)
    return _with_rgb(base, out_rgb, out_alpha)


def _effect_mix(original, effect, amount):
    amount = np.float32(np.clip(float(amount) / 100.0, 0.0, 1.0))
    return FloatImage(np.ascontiguousarray(
        original.pixels * (1.0 - amount) + effect.pixels * amount,
        dtype=np.float32), original.channel_names, original.source_format,
        original.icc_profile)


def _translated(image, dx, dy):
    yy, xx = np.mgrid[0:image.height, 0:image.width].astype(np.float32)
    return resample_coordinates(image, xx - np.float32(dx), yy - np.float32(dy),
                                transparent=False)


def apply_filter(image, kind, settings):
    """Run all filter-layer effects without leaving float32 working space."""
    settings = dict(settings or {})
    original = image
    rgb, alpha = _rgb(image)
    if kind == "gaussian_blur":
        effect = gaussian_blur(image, max(.1, float(settings.get("radius", 8))))
    elif kind == "motion_blur":
        length = np.clip(float(settings.get("length", 24)), 1.0, 200.0)
        angle = np.deg2rad(float(settings.get("angle", 0.0)))
        samples = max(3, min(31, int(length / 3) * 2 + 1))
        frames = [_rgb(_translated(image,
                  (index / (samples - 1) - .5) * length * np.cos(angle),
                  (index / (samples - 1) - .5) * length * np.sin(angle)))[0]
                  for index in range(samples)]
        effect = FloatImage(np.mean(frames, axis=0, dtype=np.float32),
                            image.channel_names, image.source_format, image.icc_profile)
    elif kind == "radial_blur":
        strength = np.clip(float(settings.get("strength", 18)), .1, 100.0)
        cx = image.width * np.clip(float(settings.get("center_x", 50)), 0, 100) / 100.0
        cy = image.height * np.clip(float(settings.get("center_y", 50)), 0, 100) / 100.0
        samples = max(5, min(25, int(strength / 5) * 2 + 5))
        frames = []
        for index in range(samples):
            offset = index / (samples - 1) - .5
            if settings.get("mode", "spin") == "zoom":
                scale = max(.5, 1.0 + offset * strength / 125.0)
                yy, xx = np.mgrid[0:image.height, 0:image.width].astype(np.float32)
                frame = resample_coordinates(image, cx + (xx - cx) / scale,
                                              cy + (yy - cy) / scale)
            else:
                frame = rotate(image, offset * strength, expand=False, centre=(cx, cy))
            frames.append(_rgb(frame)[0])
        effect = FloatImage(np.mean(frames, axis=0, dtype=np.float32),
                            image.channel_names, image.source_format, image.icc_profile)
    elif kind == "orton":
        radius = max(.1, float(settings.get("radius", 12)))
        glow = gaussian_blur(image, radius)
        glow_rgb, _ = _rgb(glow)
        glow_rgb *= 1.0 + float(settings.get("brightness", 12)) / 100.0
        glow_rgb = (glow_rgb - .5) * (1.0 + float(settings.get("contrast", 5)) / 100.0) + .5
        glow_luma = luminance(glow_rgb)[:, :, None]
        glow_rgb = glow_luma + (glow_rgb - glow_luma) * (
            1.0 + float(settings.get("saturation", 5)) / 100.0)
        screened = 1.0 - (1.0 - rgb) * (1.0 - glow_rgb)
        highlight = np.clip(luminance(rgb), 0.0, 1.0) * np.clip(
            float(settings.get("highlight_protection", 35)) / 100.0, 0.0, 1.0)
        screened = rgb * highlight[:, :, None] + screened * (1.0 - highlight[:, :, None])
        shadow = (1.0 - np.clip(luminance(rgb), 0.0, 1.0)) * np.clip(
            float(settings.get("shadow_protection", 15)) / 100.0, 0.0, 1.0)
        screened = rgb * shadow[:, :, None] + screened * (1.0 - shadow[:, :, None])
        effect = _with_rgb(image, screened.astype(np.float32), alpha)
    elif kind == "film_grain":
        rng = np.random.default_rng(int(settings.get("seed", 7319)))
        roughness = np.clip(float(settings.get("roughness", 55)), 1, 100) / 255.0
        mono = bool(settings.get("monochrome", True))
        shape = (*rgb.shape[:2], 1 if mono else 3)
        noise = .5 + rng.uniform(-roughness, roughness, shape).astype(np.float32)
        if mono:
            noise = np.repeat(noise, 3, axis=2)
        effected = _soft_light(rgb, noise)
        luma = np.clip(luminance(rgb), 0.0, 1.0)
        shadows = float(settings.get("shadows", 80)) / 100.0
        mids = float(settings.get("midtones", 100)) / 100.0
        highs = float(settings.get("highlights", 35)) / 100.0
        tonal = np.where(luma < .5, shadows + (mids - shadows) * luma * 2.0,
                         mids + (highs - mids) * (luma - .5) * 2.0)
        effect = _with_rgb(image, effected * tonal[:, :, None] +
                           rgb * (1.0 - tonal[:, :, None]), alpha)
    elif kind == "light_leak":
        height, width = rgb.shape[:2]
        yy, xx = np.mgrid[0:height, 0:width].astype(np.float32)
        edge = settings.get("edge", "left")
        along = (xx / max(1, width - 1) if edge == "left" else
                 1.0 - xx / max(1, width - 1) if edge == "right" else
                 yy / max(1, height - 1) if edge == "top" else
                 1.0 - yy / max(1, height - 1))
        across = (yy / max(1, height - 1) if edge in {"left", "right"} else
                  xx / max(1, width - 1))
        position = float(settings.get("position", 25)) / 100.0
        spread = max(.05, float(settings.get("spread", 55)) / 100.0)
        length = max(.05, float(settings.get("length", 70)) / 100.0)
        strength = np.maximum(0.0, 1.0 - along / length) * np.exp(
            -((across - position) ** 2) / max(.001, spread * spread / 3.0))
        first = np.asarray(settings.get("primary", [255, 82, 25])[:3], np.float32) / 255.0
        second = np.asarray(settings.get("secondary", [255, 210, 60])[:3], np.float32) / 255.0
        mix = np.clip(along / length, 0.0, 1.0)[:, :, None]
        leak = (first * (1.0 - mix) + second * mix) * strength[:, :, None]
        effect = _with_rgb(image, 1.0 - (1.0 - rgb) * (1.0 - leak), alpha)
    elif kind == "pastel":
        softened = gaussian_blur(image, max(0.0, float(settings.get("softness", 12)) / 20.0))
        out, _ = _rgb(softened)
        out = (out - .5) * (1.0 - max(0.0, float(settings.get("contrast_reduction", 22))) / 140.0) + .5
        grey = luminance(out)[:, :, None]
        out = grey + (out - grey) * max(0.0, 1.0 + float(settings.get("saturation", -8)) / 100.0)
        lift = np.clip(float(settings.get("lifted_blacks", 18)) / 255.0, 0.0, .32)
        out = lift + out * (1.0 - lift)
        tint = np.asarray(settings.get("tint", [255, 225, 235])[:3], np.float32) / 255.0
        tint_amount = np.clip(float(settings.get("tint_strength", 8)) / 100.0, 0.0, 1.0)
        out = out * (1.0 - tint_amount) + tint * tint_amount
        effect = _with_rgb(image, out.astype(np.float32), alpha)
    else:
        raise ValueError(f"Unsupported float filter type: {kind}")
    return _effect_mix(original, effect, settings.get("amount", 100))


def apply_styles(image, styles):
    """Float32 implementation of SNAP SLAPPER's layer-style stack."""
    if not styles:
        return image
    rgb, alpha = _rgb(image)
    if alpha is None:
        alpha = np.ones((*rgb.shape[:2], 1), dtype=np.float32)
    result = _with_rgb(image, rgb, alpha)
    if styles.get("color_overlay"):
        colour = np.asarray(styles.get("overlay_color", [255, 255, 255])[:3],
                            dtype=np.float32) / 255.0
        opacity = np.clip(float(styles.get("overlay_opacity", .35)), 0.0, 1.0)
        overlay_alpha = alpha * opacity
        rgb = rgb * (1.0 - overlay_alpha) + colour * overlay_alpha
        result = _with_rgb(image, rgb.astype(np.float32), alpha)
    if styles.get("shadow"):
        mask_image = FloatImage(alpha.astype(np.float32), ("Y",))
        shadow_alpha = gaussian_blur(mask_image, styles.get("shadow_blur", 8)).pixels
        offset = int(styles.get("shadow_offset", 6))
        shifted = _translated(FloatImage(shadow_alpha, ("Y",)), offset, offset).pixels
        shadow = FloatImage(np.concatenate((np.zeros((*rgb.shape[:2], 3), np.float32),
                                             shifted), axis=2), ("R", "G", "B", "A"))
        result = blend(shadow, result)
        rgb, alpha = _rgb(result)
    if styles.get("inner_shadow"):
        softened = gaussian_blur(FloatImage(alpha, ("Y",)),
                                  styles.get("inner_shadow_blur", 7)).pixels
        inner_alpha = np.maximum(0.0, softened - alpha) * .65
        inner = FloatImage(np.concatenate((np.zeros((*rgb.shape[:2], 3), np.float32),
                                           inner_alpha), axis=2), ("R", "G", "B", "A"))
        result = blend(result, inner)
        rgb, alpha = _rgb(result)
    stroke = max(0, int(styles.get("stroke", 0)))
    if stroke:
        source = oiio.ImageBuf(np.ascontiguousarray(alpha))
        expanded = oiio.ImageBufAlgo.dilate(source, stroke * 2 + 1, stroke * 2 + 1)
        _raise_oiio(expanded, "stroke dilation")
        outline_alpha = np.maximum(0.0, expanded.get_pixels(oiio.FLOAT) - alpha)
        colour = np.asarray(styles.get("stroke_color", [255, 255, 255])[:3],
                            dtype=np.float32) / 255.0
        outline = FloatImage(np.concatenate((np.broadcast_to(colour, rgb.shape).copy(),
                                              outline_alpha), axis=2),
                             ("R", "G", "B", "A"))
        result = blend(outline, result)
        rgb, alpha = _rgb(result)
    glow = max(0, int(styles.get("glow", 0)))
    if glow:
        glow_alpha = gaussian_blur(FloatImage(alpha, ("Y",)), glow).pixels
        colour = np.asarray(styles.get("glow_color", [255, 255, 255])[:3],
                            dtype=np.float32) / 255.0
        glow_image = FloatImage(np.concatenate((np.broadcast_to(colour, rgb.shape).copy(),
                                                 glow_alpha), axis=2),
                                ("R", "G", "B", "A"))
        result = blend(glow_image, result)
    return result


def apply_adjustments(image, adjustments, defaults=None):
    """Apply the photographic correction controls in float32 working space."""
    settings = dict(defaults or {})
    settings.update(adjustments or {})
    rgb, alpha = _rgb(image)
    rgb = rgb.copy()

    exposure = np.float32(2.0 ** float(settings.get("exposure", 0.0)))
    rgb *= exposure
    tone = luminance(rgb).astype(np.float32)
    target_tone = tone + np.float32(float(settings.get("brightness", 0.0)) * 1.28 / 255.0)
    rgb = _remap_luminance(rgb, tone, target_tone)
    tone = luminance(rgb).astype(np.float32)
    mask_tone = np.clip(tone, 0.0, 1.0)
    shadow_weight = (1.0 - _smoothstep(.10, .50, mask_tone)) ** 2
    highlight_weight = _smoothstep(.50, .90, mask_tone) ** 2
    midtone_weight = 1.0 - _smoothstep(0.0, .32, np.abs(mask_tone - .5))
    delta = (float(settings.get("shadows", 0.0)) / 100.0 * 50.0 / 255.0 * shadow_weight +
             float(settings.get("midtones", 0.0)) / 100.0 * 55.0 / 255.0 * midtone_weight +
             float(settings.get("highlights", 0.0)) / 100.0 * 50.0 / 255.0 * highlight_weight +
             float(settings.get("whites", 0.0)) / 100.0 * 45.0 / 255.0 *
             np.maximum(0.0, (mask_tone - .80) / .20) +
             float(settings.get("blacks", 0.0)) / 100.0 * 45.0 / 255.0 *
             np.maximum(0.0, (.20 - mask_tone) / .20))
    rgb = _remap_luminance(rgb, tone, tone + delta.astype(np.float32))
    contrast = 1.0 + float(settings.get("contrast", 0.0)) / 100.0
    tone = luminance(rgb).astype(np.float32)
    rgb = _remap_luminance(rgb, tone, (tone - .5) * np.float32(contrast) + .5)

    black = float(settings.get("level_black", 0.0)) / 255.0
    white = max(black + 1.0 / 255.0, float(settings.get("level_white", 255.0)) / 255.0)
    rgb = (rgb - black) / (white - black)
    gamma = max(.1, float(settings.get("level_gamma", 1.0)))
    positive = np.maximum(rgb, 0.0)
    rgb = np.where(rgb >= 0.0, np.power(positive, 1.0 / gamma), rgb).astype(np.float32)

    temperature = float(settings.get("temperature", 0.0)) / 100.0
    tint = float(settings.get("tint", 0.0)) / 100.0
    rgb *= np.asarray((1.0 + temperature * .22 + tint * .06,
                       1.0 - abs(tint) * .05,
                       1.0 - temperature * .22 + tint * .06), dtype=np.float32)

    grey = luminance(rgb)[:, :, None]
    saturation = max(0.0, 1.0 + float(settings.get("saturation", 0.0)) / 100.0)
    rgb = grey + (rgb - grey) * np.float32(saturation)
    vibrance = float(settings.get("vibrance", 0.0)) / 100.0
    if vibrance:
        chroma = np.max(rgb, axis=2) - np.min(rgb, axis=2)
        boost = 1.0 + vibrance * 1.4 * np.clip(1.0 - chroma, 0.0, 1.0)
        rgb = grey + (rgb - grey) * boost[:, :, None]

    hue, hue_saturation = _hue_saturation(rgb)
    if any(float(settings.get(f"col_sat_{name}", 0.0)) for name, _ in _HUE_BANDS):
        multiplier = np.maximum(0.0, _band_values(settings, "col_sat", hue, .9))
        grey = luminance(rgb)[:, :, None]
        rgb = grey + (rgb - grey) * multiplier[:, :, None]
    if any(float(settings.get(f"col_lum_{name}", 0.0)) for name, _ in _HUE_BANDS):
        multiplier = np.maximum(0.0, _band_values(settings, "col_lum", hue, .5))
        rgb *= multiplier[:, :, None]

    working = _with_rgb(image, rgb.astype(np.float32), alpha)
    clarity = float(settings.get("clarity", 0.0))
    if clarity:
        radius = max(2.0, min(working.size) / 180.0)
        blurred, _ = _rgb(gaussian_blur(working, radius))
        detail = rgb - blurred
        rgb = rgb + detail * np.float32(clarity / 35.0)
        working = _with_rgb(image, rgb.astype(np.float32), alpha)
    texture = float(settings.get("texture", 0.0))
    if texture > 0:
        working = unsharp(working, .65, texture * .018, 2.0 / 255.0)
        rgb, alpha = _rgb(working)
    elif texture < 0:
        softened, _ = _rgb(gaussian_blur(working, min(2.0, abs(texture) / 45.0)))
        amount = min(.75, abs(texture) / 100.0)
        rgb = rgb * (1.0 - amount) + softened * amount
        working = _with_rgb(image, rgb.astype(np.float32), alpha)

    dehaze = float(settings.get("dehaze", 0.0))
    if dehaze:
        rgb = (rgb - .5) * max(.2, 1.0 + dehaze / 130.0) + .5
        grey = luminance(rgb)[:, :, None]
        rgb = grey + (rgb - grey) * max(0.0, 1.0 + dehaze / 350.0)

    raw_denoise = float(settings.get("raw_noise_reduction", 0.0))
    luma_denoise = max(raw_denoise, float(settings.get("noise_luminance", 0.0)))
    colour_denoise = max(raw_denoise * .65, float(settings.get("noise_colour", 0.0)))
    rgb = _wavelet_noise_reduction(rgb, luma_denoise, colour_denoise)

    sharpen = float(settings.get("sharpen", 0.0))
    if sharpen > 0:
        working = _with_rgb(image, rgb.astype(np.float32), alpha)
        radius = max(.1, min(6.0, float(settings.get("sharpen_radius", 1.2))))
        reduce_noise = np.clip(float(settings.get("sharpen_reduce_noise", 0.0)) / 100.0,
                               0.0, 1.0)
        sharpened = unsharp(working, radius, min(5.0, sharpen * .025),
                            (3.0 + reduce_noise * 10.0) / 255.0)
        sharp_rgb, _ = _rgb(sharpened)
        gate = max(reduce_noise, .4) if settings.get("sharpen_mode", "lens") == "lens" else reduce_noise
        if gate:
            blurred, _ = _rgb(gaussian_blur(working, radius))
            detail = np.mean(np.abs(rgb - blurred), axis=2)
            floor = gate * 28.0 / 255.0
            mask = np.clip((detail - floor) * (6.0 + gate * 6.0), 0.0, 1.0)
            mask_image = FloatImage(mask[:, :, None].astype(np.float32), ("Y",))
            mask = gaussian_blur(mask_image, max(.4, radius * .5)).pixels[:, :, 0]
            rgb = sharp_rgb * mask[:, :, None] + rgb * (1.0 - mask[:, :, None])
        else:
            rgb = sharp_rgb

    rgb = _curve(rgb, settings.get("curve") or [[0, 0], [255, 255]])
    identity = [[0, 0], [255, 255]]
    for channel, key in enumerate(("curve_red", "curve_green", "curve_blue")):
        points = settings.get(key) or identity
        if points != identity:
            rgb[:, :, channel] = _curve(rgb[:, :, channel], points)

    if settings.get("black_white"):
        mono = luminance(rgb)
        hue, hue_saturation = _hue_saturation(rgb)
        if any(float(settings.get(f"bw_{name}", 0.0)) for name, _ in _HUE_BANDS):
            multiplier = _band_values(settings, "bw", hue, .7)
            mono *= 1.0 + (multiplier - 1.0) * hue_saturation
        rgb = np.repeat(mono[:, :, None], 3, axis=2)

    density = max(0.0, min(1.0, float(settings.get("photo_filter_density", 0.0)) / 100.0))
    if density:
        colour = np.asarray(settings.get("photo_filter_color", [236, 138, 0])[:3],
                            dtype=np.float32) / 255.0
        filtered = rgb * colour
        candidate = rgb * (1.0 - density) + filtered * density
        if settings.get("photo_filter_preserve_lum", True):
            candidate_luma = luminance(candidate)[:, :, None]
            candidate += luminance(rgb)[:, :, None] - candidate_luma
        rgb = candidate

    luma = np.clip(luminance(rgb), 0.0, 1.0)
    for prefix, default_colour, weight in (
            ("split_shadow", [60, 90, 150], (1.0 - luma)),
            ("split_midtone", [128, 128, 128], np.maximum(0.0, 1.0 - np.abs(luma - .5) * 2.0)),
            ("split_highlight", [255, 200, 120], luma)):
        amount = max(0.0, float(settings.get(prefix + "_amount", 0.0)) / 100.0) * .6
        if amount:
            colour = np.asarray(settings.get(prefix, default_colour)[:3], dtype=np.float32) / 255.0
            toned = _soft_light(rgb, colour.reshape((1, 1, 3)))
            mix = (weight * amount)[:, :, None]
            rgb = toned * mix + rgb * (1.0 - mix)

    glow_amount = max(0.0, float(settings.get("glow_amount", 0.0)) / 100.0)
    if glow_amount:
        height, width = rgb.shape[:2]
        yy, xx = np.ogrid[:height, :width]
        cx = float(settings.get("glow_x", 50.0)) / 100.0 * width
        cy = float(settings.get("glow_y", 40.0)) / 100.0 * height
        reach = max(1.0, max(width, height) * float(settings.get("glow_size", 45.0)) / 100.0)
        distance2 = ((xx - cx) ** 2 + (yy - cy) ** 2) / max(1.0, reach * reach)
        glow_mask = np.exp(-distance2 * 2.0).astype(np.float32) * glow_amount
        colour = np.asarray(settings.get("glow_colour", [255, 220, 170])[:3],
                            dtype=np.float32) / 255.0
        bloom = glow_mask[:, :, None] * colour.reshape((1, 1, 3))
        rgb = 1.0 - (1.0 - rgb) * (1.0 - bloom)

    vignette = float(settings.get("vignette", 0.0))
    if vignette:
        height, width = rgb.shape[:2]
        yy, xx = np.ogrid[:height, :width]
        radius = np.sqrt(((xx - (width - 1) / 2) / max(1, width / 2)) ** 2 +
                         ((yy - (height - 1) / 2) / max(1, height / 2)) ** 2)
        size = np.clip(float(settings.get("vignette_size", 70.0)) / 100.0, 0.0, 2.0)
        feather = np.clip(float(settings.get("vignette_feather", 50.0)) / 100.0,
                          0.0, 2.0)
        midpoint = .55 + size * .40
        transition = .04 + feather * .42
        weight = _smoothstep(max(.08, midpoint - transition / 2),
                             min(1.80, midpoint + transition / 2), radius)
        amount = (weight * min(1.0, abs(vignette) / 100.0))[:, :, None]
        edge = 0.0 if vignette < 0 else 1.0
        rgb = rgb * (1.0 - amount) + edge * amount

    grain = max(0.0, float(settings.get("grain", 0.0)))
    if grain:
        rng = np.random.default_rng(int(settings.get("grain_seed", 7319)))
        noise = rng.normal(0.0, grain / 100.0 * 32.0 / 255.0,
                           rgb.shape[:2]).astype(np.float32)
        if settings.get("grain_darken"):
            rgb *= (1.0 - np.maximum(0.0, -noise)[:, :, None])
        else:
            blend_noise = np.clip(.5 + noise, 0.0, 1.0)[:, :, None]
            rgb = _soft_light(rgb, blend_noise)

    return _with_rgb(image, rgb.astype(np.float32), alpha)


# ===== SNAPSMACK EOF =====
