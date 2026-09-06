"""
SECAUDIT 054 chokepoint 1 WIRING tests — snap_imgsafe was a module with zero
callers; these prove the three untrusted decode paths actually route through it
and FAIL CLOSED (the audit bar: a hostile file is refused at the front door,
never "module exists + its own units are green").

Paths under test:
  1. found_textures.download()      — network bytes → disk cache for the editor
  2. editor_engine._mask_from_text  — .slapper-embedded mask blobs (PNG-only)
  3. slapper_qt/textures_dialog.py  — thumb bytes → Qt decode (static check;
     importing the Qt dialog needs a GUI package context)

Run: python tools/hub/tests/test_imgsafe_wiring.py   (exit 0 = all pass)
"""

import base64
import io
import os
import sys

_HUB = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, _HUB)
sys.path.insert(0, os.path.join(os.path.dirname(_HUB), "_shared"))

from PIL import Image

import snap_imgsafe
import editor_engine
import found_textures

FAILED = 0


def check(ok, message):
    global FAILED
    print(("PASS " if ok else "FAIL ") + message)
    if not ok:
        FAILED += 1


def _png_bytes():
    buf = io.BytesIO()
    Image.new("L", (4, 4)).save(buf, "PNG")
    return buf.getvalue()


def _jpeg_bytes():
    buf = io.BytesIO()
    Image.new("RGB", (4, 4)).save(buf, "JPEG")
    return buf.getvalue()


# ── 1. found_textures.download refuses junk bytes, caches nothing ────────────
_texture = {"id": "wiretest", "full_url": "https://example.test/x.jpg"}

_orig_fetch = found_textures.fetch_bytes
_orig_cache = found_textures.cache_dir
import tempfile
_tmpdir = tempfile.mkdtemp(prefix="imgsafe-wire-")
found_textures.cache_dir = lambda: _tmpdir

found_textures.fetch_bytes = lambda *a, **k: b"<html>this is not an image</html>"
try:
    found_textures.download(dict(_texture), api_key=None)
    check(False, "download() must refuse non-image bytes")
except snap_imgsafe.UnsafeImageError:
    check(True, "download() refuses non-image bytes (UnsafeImageError)")
except Exception as e:  # noqa: BLE001
    check(False, f"download() raised the wrong error for junk: {type(e).__name__}")
check(not any(n.startswith("ft_wiretest") for n in os.listdir(_tmpdir)),
      "refused bytes were never written to the texture cache")

found_textures.fetch_bytes = lambda *a, **k: _jpeg_bytes()
try:
    path = found_textures.download(dict(_texture), api_key=None)
    check(os.path.isfile(path), "a real JPEG still downloads and caches")
except Exception as e:  # noqa: BLE001
    check(False, f"legit JPEG was refused: {e}")

# FAIL-CLOSED: with the module gone, download refuses rather than caching blind.
found_textures.fetch_bytes = lambda *a, **k: _jpeg_bytes()
_saved_mod = found_textures.snap_imgsafe
found_textures.snap_imgsafe = None
try:
    found_textures.download({"id": "wiretest2", "full_url": "https://example.test/y.jpg"}, api_key=None)
    check(False, "download() must FAIL CLOSED when snap_imgsafe is unavailable")
except RuntimeError:
    check(True, "download() fails closed without snap_imgsafe")
finally:
    found_textures.snap_imgsafe = _saved_mod
    found_textures.fetch_bytes = _orig_fetch
    found_textures.cache_dir = _orig_cache

# ── 2. editor_engine._mask_from_text: PNG-only, fail-closed ─────────────────
png_b64 = base64.b64encode(_png_bytes()).decode("ascii")
mask = editor_engine._mask_from_text(png_b64)
check(mask is not None and mask.mode == "L", "a PNG mask still decodes to L")

jpg_b64 = base64.b64encode(_jpeg_bytes()).decode("ascii")
try:
    editor_engine._mask_from_text(jpg_b64)
    check(False, "a non-PNG mask must be refused (masks are PNG by construction)")
except snap_imgsafe.UnsafeImageError:
    check(True, "a non-PNG mask blob is refused")

_saved_engine_mod = editor_engine.snap_imgsafe
editor_engine.snap_imgsafe = None
try:
    editor_engine._mask_from_text(png_b64)
    check(False, "_mask_from_text must FAIL CLOSED when snap_imgsafe is unavailable")
except ValueError:
    check(True, "_mask_from_text fails closed without snap_imgsafe")
finally:
    editor_engine.snap_imgsafe = _saved_engine_mod

check(editor_engine._mask_from_text("") is None, "empty mask value still returns None")

# ── 3. the Tk editor has NO remaining inline mask decodes ────────────────────
with open(os.path.join(_HUB, "editor_ui.py"), encoding="utf-8") as fh:
    _ui_src = fh.read()
check("Image.open(io.BytesIO(base64.b64decode(" not in _ui_src,
      "editor_ui.py routes every mask decode through editor_engine._mask_from_text")

# ── 4. Qt thumb path (static — importing the dialog needs a GUI context) ────
with open(os.path.join(_HUB, "slapper_qt", "textures_dialog.py"), encoding="utf-8") as fh:
    _dlg_src = fh.read()
check("snap_imgsafe.check_bytes(data)" in _dlg_src,
      "thumb bytes are checked before Qt decodes")
check("QImage.fromData(data, fmt)" in _dlg_src,
      "Qt is handed the DETECTED format so only that decoder runs")
check("thumb refused — snap_imgsafe unavailable" in _dlg_src,
      "thumbs fail closed when the safety module is missing")

# ── 5. the global bomb cap is actually pinned by the engine import ──────────
check(Image.MAX_IMAGE_PIXELS == snap_imgsafe.MAX_IMAGE_PIXELS,
      "Image.MAX_IMAGE_PIXELS is pinned process-wide via snap_imgsafe import")

print("ALL PASS" if FAILED == 0 else f"{FAILED} FAILURE(S)")
sys.exit(0 if FAILED == 0 else 1)

# ===== SNAPSMACK EOF =====
