"""
snap_sizing.py — canonical image sizing for the SnapSmack desktop suite.

ONE rule, one place: fit an image inside a maximum LONG EDGE (the fleet standard is
3840 — "4K", applied to the long edge in EITHER orientation, so a portrait keeps the
same pixel budget as a landscape), and NEVER enlarge. This is the single source of
truth every desktop tool should call before upload, so COLD SNAP, THE HUB and any
future tool size identically instead of each guessing.

`size_for_export()` is the entry point: it resizes once and, only when it actually
downsized, applies the versioned mild sharpen (snap_sharpen) — enforcing the
"sharpen exactly once, only after a real downsize" rule at the module boundary so a
caller can't get it wrong.

Deterministic and headless-testable. Pure Pillow. Does NOT touch EXIF/ICC — the
caller is responsible for carrying metadata onto the saved derivative (the suite's
EXIF-preservation invariant), and for never overwriting the photographer's original.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from PIL import Image

import snap_sharpen


# Fleet standard long edge ("4K"). Sean, 2026-09-01: 3840 on BOTH axes — an image
# fits inside 3840x3840, so the long edge is capped at 3840 in either orientation
# and portraits are not second-class. A per-site value overrides this default.
DEFAULT_MAX_LONG_EDGE = 3840


def fit_long_edge(img, max_long_edge: int = DEFAULT_MAX_LONG_EDGE, *, resample=Image.LANCZOS):
    """Downscale `img` so its LONG edge <= max_long_edge, preserving aspect and
    orientation. NEVER enlarges. Returns (image, did_downsize).

    If the image is already within the limit (or the limit is <= 0), returns the
    ORIGINAL image object unchanged and did_downsize=False — so the caller both
    avoids a needless re-encode and knows not to sharpen."""
    w, h = img.size
    long_edge = max(w, h)
    if max_long_edge is None or max_long_edge <= 0 or long_edge <= max_long_edge:
        return img, False
    scale = float(max_long_edge) / float(long_edge)
    new_w = max(1, int(round(w * scale)))
    new_h = max(1, int(round(h * scale)))
    return img.resize((new_w, new_h), resample), True


def size_for_export(img, max_long_edge: int = DEFAULT_MAX_LONG_EDGE, *,
                    sharpen_on_downsize: bool = True,
                    sharpen_preset: str = snap_sharpen.DEFAULT_PRESET,
                    resample=Image.LANCZOS):
    """The one call a tool makes before upload. Resize once to the long-edge limit,
    then apply the mild sharpen ONLY if an actual downsize happened.

    Returns (image, did_downsize). When did_downsize is False the returned image is
    the untouched original (no resize, no sharpen). This is where the
    "sharpen exactly once, only after a real downsize" rule is enforced, so no
    caller can double-sharpen or sharpen an unresized image."""
    out, downsized = fit_long_edge(img, max_long_edge, resample=resample)
    if downsized and sharpen_on_downsize:
        out = snap_sharpen.sharpen(out, sharpen_preset)
    return out, downsized
# ===== SNAPSMACK EOF =====
