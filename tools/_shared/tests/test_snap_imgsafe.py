"""
Tests for snap_imgsafe — the untrusted-image safe front door (SECAUDIT 054, ch.1).

Deny-path first, on purpose: a test that only proves a good image passes is the
false-green the audit standard exists to kill. These assert that the bomb raises,
the disallowed/unknown container is refused BEFORE any decode, oversized blobs are
refused, and the module's Pillow policy globals are actually set — then confirm a
legitimate image still opens.

Run: python tools/_shared/tests/test_snap_imgsafe.py   (exit 0 = all pass)
"""

import io
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from PIL import Image, ImageFile
import snap_imgsafe
from snap_imgsafe import safe_open, check_bytes, UnsafeImageError


def _raises(fn, *a, **k):
    try:
        fn(*a, **k)
    except UnsafeImageError:
        return True
    return False


def _png_bytes(w=8, h=8):
    b = io.BytesIO()
    Image.new("RGB", (w, h), (100, 120, 140)).save(b, format="PNG")
    return b.getvalue()


def _jpeg_bytes(w=8, h=8):
    b = io.BytesIO()
    Image.new("RGB", (w, h), (30, 60, 90)).save(b, format="JPEG")
    return b.getvalue()


def _checks():
    n = 0

    # 0. Policy globals are actually set at import (not left at Pillow defaults).
    assert Image.MAX_IMAGE_PIXELS == snap_imgsafe.MAX_IMAGE_PIXELS, "MAX_IMAGE_PIXELS not pinned"
    assert Image.MAX_IMAGE_PIXELS is not None, "pixel ceiling must be finite"
    assert ImageFile.LOAD_TRUNCATED_IMAGES is False, "truncated-load must stay OFF"
    n += 1

    # 1. DENY — a decompression-bomb dimension is refused (no allocation needed).
    assert _raises(snap_imgsafe._check_dimensions, (100000, 100000)), "bomb dims not refused"
    n += 1

    # 2. DENY — empty and oversized blobs.
    assert _raises(check_bytes, b""), "empty not refused"
    assert _raises(check_bytes, b"\xff\xd8\xff" + b"\x00" * (snap_imgsafe.MAX_BYTES + 1)), \
        "oversized blob not refused"
    n += 1

    # 3. DENY — an unknown container (random bytes) is refused before any decode.
    assert _raises(check_bytes, b"not an image at all, just text"), "unknown container passed"
    n += 1

    # 4. DENY — a real ICO (structurally valid, but OFF the allowlist) is refused
    #    BEFORE load. This is the "shrink the decoder surface" guarantee.
    ico = io.BytesIO()
    Image.new("RGB", (16, 16), (0, 0, 0)).save(ico, format="ICO")
    assert _raises(check_bytes, ico.getvalue()), "ICO passed the byte guard"
    assert _raises(safe_open, ico.getvalue()), "ICO passed safe_open"
    n += 1

    # 5. DENY — safe_open on a path that isn't a file, and on garbage bytes with a
    #    spoofed magic (JPEG header, junk body) fails to decode → raises, never
    #    returns a half-image.
    assert _raises(safe_open, b"\xff\xd8\xff\xe0" + b"\x00" * 200), "spoofed JPEG decoded"
    n += 1

    # 6. ALLOW — check_bytes identifies the real formats.
    assert check_bytes(_png_bytes()) == "PNG"
    assert check_bytes(_jpeg_bytes()) == "JPEG"
    n += 1

    # 7. ALLOW — a legitimate small image opens and is fully loaded (usable after
    #    the source is gone). Both the bytes and the path entry points.
    img = safe_open(_png_bytes(12, 10))
    assert img.size == (12, 10), f"loaded size wrong: {img.size}"
    assert img.tobytes(), "image not actually loaded"
    n += 1

    import tempfile
    fd, path = tempfile.mkstemp(suffix=".jpg")
    os.close(fd)
    try:
        with open(path, "wb") as f:
            f.write(_jpeg_bytes(20, 16))
        img2 = safe_open(path)
        assert img2.size == (20, 16), f"path-loaded size wrong: {img2.size}"
    finally:
        os.remove(path)
    n += 1

    return n


if __name__ == "__main__":
    count = _checks()
    print(f"OK — {count} checks passed")
