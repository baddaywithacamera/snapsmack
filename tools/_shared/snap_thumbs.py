"""
SNAPSMACK — snap_thumbs.py  (shared client-side thumbnail generator)

Canonical, build-once thumbnailer for the SnapSmack desktop tool family
(FLKR FCKR, Unzucker, Get Your Shit Sorted, Smack Some Shit Up, OH SNAP, …).
It is a faithful Python/Pillow port of core/thumb-generator.php so that thumbs
built on the CLIENT are dimensionally + naming-identical to what the server
would have produced — letting the upload endpoint save them and SKIP the
server-side GD pass entirely. The point is to move the thumbnail-generation
load OFF the shared host during big imports.

Output (mirrors the PHP helper exactly):
  t_<basename>.jpg  — sq_size x sq_size square centre-crop (focal + zoom)
  a_<basename>.jpg  — max asp_max px longest edge, aspect-preserving
Both JPEG quality 85, written into a /thumbs/ subdir beside the source file.

Parity notes (honest):
  - Dimensions, file naming, JPEG quality and the focal/zoom crop MATH are an
    exact port of the GD code.
  - Pixels are NOT byte-identical: Pillow's resampler (LANCZOS) differs from
    GD's imagecopyresampled, and libjpeg quantisation differs slightly. The
    result is visually equivalent (in practice a touch sharper) and a valid
    drop-in. The server accepts the files and skips its own generation.
  - Like GD's imagecreatefromjpeg, this does NOT apply EXIF orientation before
    cropping — it works on the raw stored pixels, so the thumb matches exactly
    what the server path produces today. (Flickr originals are already upright.)
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import os
from typing import Optional

from PIL import Image as PILImage


DEFAULT_SQ_SIZE = 400
DEFAULT_MED_MAX = 600
DEFAULT_ASP_MAX = 900


def _scaled_box(w: int, h: int, max_edge: int) -> tuple[int, int]:
    """Never upscale past source dimensions; matches core/thumb-generator.php."""
    if w <= max_edge and h <= max_edge:
        return w, h
    if w >= h:
        return max_edge, max(1, int(round(h * (max_edge / float(w)))))
    return max(1, int(round(w * (max_edge / float(h))))), max_edge


def generate_thumbs(
    src_path: str,
    *,
    sq_size: int = DEFAULT_SQ_SIZE,
    med_max: int = DEFAULT_MED_MAX,
    asp_max: int = DEFAULT_ASP_MAX,
    focus_x: int = 50,
    focus_y: int = 50,
    zoom: int = 100,
    thumb_dir: Optional[str] = None,
    quality: int = 85,
) -> Optional[dict]:
    """
    Generate square (t_), medium (m_), and aspect (a_) thumbnails for src_path.

    Args:
        src_path:  absolute path to a JPEG/PNG/WebP source image.
        sq_size:   square thumbnail dimension (default 400 — SNAPSMACK_THUMB_SQUARE).
        med_max:   medium thumbnail longest-edge limit (default 600 — SNAPSMACK_THUMB_MEDIUM_LONG).
        asp_max:   aspect thumbnail longest-edge limit (default 900 — SNAPSMACK_THUMB_ASPECT_LONG).
        focus_x:   square-crop focal point X, 0-100 (% across the spare axis). 50 = centre.
        focus_y:   square-crop focal point Y, 0-100. 50 = centre.
        zoom:      100-300. Crop window = min(w,h)/(zoom/100). 200 = 2x tighter.
        thumb_dir: where to write the three files. Default: <src_dir>/thumbs.
        quality:   JPEG quality (default 85 — matches the server helper).

    Returns:
        {'sq_path', 'med_path', 'asp_path', 'width', 'height'} with ABSOLUTE local paths,
        or None on failure (unreadable / unsupported source).
    """
    if not os.path.isfile(src_path):
        return None

    try:
        with PILImage.open(src_path) as im:
            im.load()
            # Match GD: operate on raw stored pixels (no EXIF transpose).
            src = im.convert('RGB')
    except Exception:
        return None

    w, h = src.size
    if w < 1 or h < 1:
        return None

    base = os.path.basename(src_path)
    if thumb_dir is None:
        thumb_dir = os.path.join(os.path.dirname(src_path), 'thumbs')
    os.makedirs(thumb_dir, exist_ok=True)

    # ── Square thumbnail (t_) — focal-point + zoom crop ──────────────────────
    z = max(100, min(300, int(zoom)))
    crop_dim = int(round(min(w, h) / (z / 100.0)))
    if crop_dim < 1:
        crop_dim = 1
    fx = max(0, min(100, int(focus_x)))
    fy = max(0, min(100, int(focus_y)))
    crop_x = int(round((w - crop_dim) * (fx / 100.0)))
    crop_y = int(round((h - crop_dim) * (fy / 100.0)))
    crop_x = max(0, min(w - crop_dim, crop_x))
    crop_y = max(0, min(h - crop_dim, crop_y))

    sq_path = os.path.join(thumb_dir, 't_' + base)
    sq = src.crop((crop_x, crop_y, crop_x + crop_dim, crop_y + crop_dim))
    sq = sq.resize((sq_size, sq_size), PILImage.LANCZOS)
    sq.save(sq_path, 'JPEG', quality=quality)

    # ── Medium thumbnail (m_) ────────────────────────────────────────────────
    med_w, med_h = _scaled_box(w, h, med_max)
    med_path = os.path.join(thumb_dir, 'm_' + base)
    med = src if (med_w, med_h) == (w, h) else src.resize((med_w, med_h), PILImage.LANCZOS)
    med.save(med_path, 'JPEG', quality=quality)

    # ── Aspect thumbnail (a_) ────────────────────────────────────────────────
    asp_w, asp_h = _scaled_box(w, h, asp_max)
    asp_path = os.path.join(thumb_dir, 'a_' + base)
    asp = src if (asp_w, asp_h) == (w, h) else src.resize((asp_w, asp_h), PILImage.LANCZOS)
    asp.save(asp_path, 'JPEG', quality=quality)

    return {
        'sq_path': sq_path,
        'med_path': med_path,
        'asp_path': asp_path,
        'width': w,
        'height': h,
    }
# ===== SNAPSMACK EOF =====
