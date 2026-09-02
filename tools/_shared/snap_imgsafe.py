"""
snap_imgsafe.py — one safe front door for decoding UNTRUSTED image bytes.

SECAUDIT 054, chokepoint 1 (untrusted image ingress). The desktop suite decodes
bytes it did not choose — FOUND TEXTURES auto-downloads server-supplied bytes, a
`.slapper` project references arbitrary layer paths/URLs, GYSS thumbs come off the
wire — and nowhere in `tools/` was a pixel ceiling, a truncated-load policy, or a
format allowlist ever set (0 hits fleet-wide at audit time). That leaves an open
lane into Pillow AND Qt's separate decoder stack for a decompression bomb or a
crafted WebP/TIFF/ICO (the libwebp-zero-click class).

This module is the single chokepoint that closes it. Route every untrusted decode
through here:

    from snap_imgsafe import safe_open, check_bytes, UnsafeImageError

    img = safe_open(path)                 # Pillow path — returns a loaded RGB-safe Image
    fmt = check_bytes(downloaded_bytes)   # Qt path — validate BEFORE QImage.fromData()

Design choices, on purpose:
  * PIL-only dependency. `_shared` is imported by non-Qt tools too, so this module
    never imports Qt. The Qt-facing guard (`check_bytes`) is a dependency-free
    magic-byte + size check the caller runs before handing bytes to QImage; the
    caller still restricts the Qt decoder with `QImageReader.setAllowedFormats`.
  * Allowlist, not blocklist. We decode only the formats the suite actually uses
    (JPEG/PNG/WebP/GIF/TIFF/BMP). Everything else — ICO, and the long tail of
    obscure Pillow/Qt plugins an attacker would reach for — is refused before any
    decoder touches it. Shrinking the decoder surface is the point.
  * Fail loud, fail closed. A bad input raises UnsafeImageError; it never returns a
    half-decoded or truncated image. Callers treat a raise as "skip this file."

NOTE (SECAUDIT 054 §3.6): a decoder gate is a code-execution-carrying control. This
module existing with green unit tests is NOT "chokepoint 1 closed." The chokepoint
closes only when a live build shows the real ingress paths routing through here and
rejecting a crafted input. Until then it stays OPEN with live-route confirmation as
the named test.
"""

from __future__ import annotations

import io
import os

from PIL import Image, ImageFile


# ── Policy knobs (deliberate, not Pillow defaults) ───────────────────────────

# Hard pixel ceiling for a single decoded image. A 4K frame is ~8.3 MP; a big
# stitched panorama or medium-format original stays well under this, while the
# classic gigapixel decompression bomb (billions of px) is refused. Tunable, but
# it must stay FINITE — an unset ceiling is the hole this module closes.
MAX_IMAGE_PIXELS = 512 * 1024 * 1024          # 512 MP

# Refuse an untrusted blob larger than this before decoding it at all. Real photo
# files (even 4K/RAW-derived) are far smaller; a huge blob is either a bomb or a
# mistake. 128 MiB is generous headroom, not a working size.
MAX_BYTES = 128 * 1024 * 1024

# The only formats we decode. Pillow format names (Image.format values).
ALLOWED_FORMATS = frozenset({"JPEG", "PNG", "WEBP", "GIF", "TIFF", "BMP"})

# The Qt equivalents, lowercase, for the caller's QImageReader.setAllowedFormats.
# Kept here so the allowlist lives in ONE place for both decoder stacks.
ALLOWED_QT_FORMATS = ("jpeg", "jpg", "png", "webp", "gif", "tiff", "bmp")


class UnsafeImageError(Exception):
    """An untrusted image failed a safety check and was NOT decoded."""


# Make Pillow's own bomb guard a HARD error at our ceiling, and never silently
# accept a truncated (possibly malicious) stream. Set at import so any code that
# imports this module inherits the policy even before it calls safe_open.
Image.MAX_IMAGE_PIXELS = MAX_IMAGE_PIXELS
ImageFile.LOAD_TRUNCATED_IMAGES = False


# ── Byte-level guard (Qt path + a cheap first gate for the PIL path) ──────────

# Magic-byte signatures for the allowed formats. (offset, bytes, format-name).
# Enough to identify the container without invoking any decoder.
def _sniff_format(head: bytes) -> str | None:
    if head[:3] == b"\xff\xd8\xff":
        return "JPEG"
    if head[:8] == b"\x89PNG\r\n\x1a\n":
        return "PNG"
    if head[:6] in (b"GIF87a", b"GIF89a"):
        return "GIF"
    if head[:2] in (b"II", b"MM") and head[2:4] in (b"\x2a\x00", b"\x00\x2a"):
        return "TIFF"
    if head[:2] == b"BM":
        return "BMP"
    if head[:4] == b"RIFF" and head[8:12] == b"WEBP":
        return "WEBP"
    return None


def check_bytes(data: bytes) -> str:
    """Validate an untrusted image blob WITHOUT decoding it.

    Returns the detected format name (one of ALLOWED_FORMATS) or raises
    UnsafeImageError. Run this before QImage.fromData / QImageReader on any bytes
    that came off the wire — it enforces the size cap and confines the format to
    the allowlist so an obscure/crafted container never reaches a Qt decoder plugin.
    """
    if not data:
        raise UnsafeImageError("empty image data")
    if len(data) > MAX_BYTES:
        raise UnsafeImageError(
            f"image is {len(data)} bytes, over the {MAX_BYTES}-byte ceiling")
    fmt = _sniff_format(bytes(data[:16]))
    if fmt is None:
        raise UnsafeImageError("unrecognised image container (not on the allowlist)")
    if fmt not in ALLOWED_FORMATS:
        raise UnsafeImageError(f"format {fmt!r} is not on the allowlist")
    return fmt


# ── Pillow path ──────────────────────────────────────────────────────────────

def _check_dimensions(size) -> None:
    w, h = size
    if w <= 0 or h <= 0:
        raise UnsafeImageError(f"non-positive dimensions {size}")
    if w * h > MAX_IMAGE_PIXELS:
        raise UnsafeImageError(
            f"image is {w}x{h} = {w * h} px, over the {MAX_IMAGE_PIXELS}-px ceiling")


def safe_open(source, *, formats=None) -> Image.Image:
    """Open + fully load an untrusted image safely, or raise UnsafeImageError.

    `source` is a filesystem path or a bytes/bytes-like object. The image is
    verified against the format allowlist and the pixel ceiling BEFORE it is
    loaded, then loaded in one pass. The returned Image is fully in memory (safe
    to use after the source is closed). Any decoder error is reported as
    UnsafeImageError so callers have one exception to skip on.
    """
    allowed = ALLOWED_FORMATS if formats is None else frozenset(formats)

    if isinstance(source, (bytes, bytearray, memoryview)):
        raw = bytes(source)
        # Byte guard first: size cap + container sniff, no decoder involved.
        check_bytes(raw)
        opener = lambda: Image.open(io.BytesIO(raw))
    else:
        path = os.fspath(source)
        if not os.path.isfile(path):
            raise UnsafeImageError(f"not a file: {path!r}")
        if os.path.getsize(path) > MAX_BYTES:
            raise UnsafeImageError(f"file over the {MAX_BYTES}-byte ceiling: {path!r}")
        opener = lambda: Image.open(path)

    # Pass 1: verify structure + read the header (format, size) without decoding
    # pixels. verify() consumes the object, so we re-open for the real load.
    try:
        probe = opener()
        fmt = probe.format
        size = probe.size
        probe.verify()
    except UnsafeImageError:
        raise
    except Exception as e:
        raise UnsafeImageError(f"image failed structural verification: {e}") from e

    if fmt not in allowed:
        raise UnsafeImageError(f"format {fmt!r} is not on the allowlist")
    _check_dimensions(size)

    # Pass 2: the real decode, now that format + dimensions are vouched for.
    try:
        img = opener()
        _check_dimensions(img.size)   # header can lie; re-check the decoded truth
        img.load()
    except UnsafeImageError:
        raise
    except Exception as e:
        raise UnsafeImageError(f"image failed to decode: {e}") from e
    return img


# ===== SNAPSMACK EOF =====
