"""
COLD SNAP — sumna_resize.py

Destination-aware export derivatives. Before upload, COLD SNAP sizes a photo to the
TARGET site's long-edge policy (default 3840 — see tools/_shared/snap_sizing.py) and
mild-sharpens it only if it actually shrank, so what you upload is what gets stored
and the sharpen lands on the final pixels. Guardrails (per
SPEC-image-sizing-4k-coldsnap-gyss.md §9d/§10):

  * The photographer's ORIGINAL is never modified — the derivative is a separate
    cached file. If a photo is already within policy (or sizing is off), the
    original path is returned as-is and uploaded untouched (no re-encode).
  * EXIF + ICC are carried onto the derivative (the suite's metadata invariant).
    Orientation is NOT baked into pixels — the original EXIF (incl. any orientation
    tag) is preserved verbatim, and the long-edge cap is orientation-agnostic
    (max(w, h)), so a rotated capture still caps and displays correctly.
  * Derivatives are cached by (content-hash, long-edge, quality, sharpen preset) so
    an offline retry re-uses the same file instead of re-rendering. Writes are
    atomic (temp + os.replace).

The policy value itself is supplied by the caller (which knows the destination site),
never guessed here — keeping this module a pure, testable transform.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import hashlib
import os
import sys

from PIL import Image

# Shared sizing/sharpen — bundled flat next to this module on the frozen exe, one
# dir up under _shared/ in the dev tree. Mirrors sumna_offline's import shim.
try:
    import snap_sizing
    import snap_sharpen
except ImportError:  # pragma: no cover - dev-tree import shim
    _shared = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), '_shared')
    if _shared not in sys.path:
        sys.path.insert(0, _shared)
    import snap_sizing
    import snap_sharpen


def _cache_root() -> str:
    """Per-user cache dir for export derivatives — next to the exe when frozen,
    else beside this module. Kept out of the session store so it can be cleared
    freely without touching drafts."""
    if getattr(sys, "frozen", False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    d = os.path.join(base, "export_cache")
    os.makedirs(d, exist_ok=True)
    return d


def _file_hash(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def _cache_key(src_hash: str, max_long_edge: int, quality: int,
               sharpen: bool, preset: str) -> str:
    tag = f"{src_hash[:16]}_{int(max_long_edge)}_q{int(quality)}_{preset if sharpen else 'nosharp'}"
    return tag + ".jpg"


def export_path(local_path: str, max_long_edge: int, *,
                jpeg_quality: int = 85, sharpen: bool = True,
                sharpen_preset: str = snap_sharpen.DEFAULT_PRESET,
                cache_dir: str = None) -> str:
    """Return a path to the upload-ready image for `local_path` under the target
    site's policy.

    If the image is already within `max_long_edge` (or `max_long_edge` <= 0), the
    ORIGINAL path is returned unchanged — upload it as-is. Otherwise a sized +
    (optionally) sharpened JPEG derivative is produced, cached, and its path
    returned. The original file is never written to.

    Never raises for a sizing problem — on any failure it falls back to the
    original path so a post is never blocked by the resize step."""
    try:
        if not local_path or not os.path.isfile(local_path):
            return local_path
        if not max_long_edge or max_long_edge <= 0:
            return local_path

        with Image.open(local_path) as probe:
            w, h = probe.size
        if max(w, h) <= max_long_edge:
            return local_path  # within policy → upload original untouched

        cache_dir = cache_dir or _cache_root()
        os.makedirs(cache_dir, exist_ok=True)
        key = _cache_key(_file_hash(local_path), max_long_edge, jpeg_quality,
                         sharpen, sharpen_preset)
        out_path = os.path.join(cache_dir, key)
        if os.path.isfile(out_path):
            return out_path  # cached from an earlier attempt

        with Image.open(local_path) as im:
            im.load()
            exif = im.info.get("exif")
            icc = im.info.get("icc_profile")
            rgb = im.convert("RGB") if im.mode not in ("RGB", "L") else im
            sized, downsized = snap_sizing.size_for_export(
                rgb, max_long_edge,
                sharpen_on_downsize=sharpen, sharpen_preset=sharpen_preset)
            if not downsized:
                return local_path  # nothing to do (shouldn't happen after the guard)

            save_kwargs = {"quality": int(jpeg_quality), "optimize": True}
            if exif:
                save_kwargs["exif"] = exif
            if icc:
                save_kwargs["icc_profile"] = icc
            tmp = out_path + ".tmp"
            sized.save(tmp, "JPEG", **save_kwargs)
        os.replace(tmp, out_path)  # atomic
        return out_path
    except Exception:
        # A resize failure must never block a post — send the original.
        try:
            if 'tmp' in locals() and os.path.isfile(tmp):
                os.remove(tmp)
        except Exception:
            pass
        return local_path
# ===== SNAPSMACK EOF =====
