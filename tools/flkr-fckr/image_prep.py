"""
FLKR FCKR — image_prep.py
Image processing pipeline: resize, thumbnail generation, EXIF extraction.

Extracted and adapted from tools/unzucker/poster.py's prepare_image() helper.
Same resize logic, same thumbnail naming convention. Flickr-specific additions:
  - Reads EXIF from source file via Pillow
  - Merges Flickr geo (lat/lon) into img_exif JSON blob
  - Preserves original format detection
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
import os
import secrets
import shutil
import sys
from dataclasses import dataclass
from datetime import datetime
from typing import Optional, Tuple

from PIL import Image as PILImage
from PIL import ImageOps
from PIL.ExifTags import TAGS

# Shared, build-once client thumbnailer (tools/_shared/snap_thumbs.py). In dev
# the module lives one dir up in _shared/; in the frozen exe PyInstaller bundles
# it (build.bat adds --paths ..\_shared --hidden-import snap_thumbs), so the
# bare `import snap_thumbs` resolves there. The sys.path bootstrap only fires in
# dev (the _shared dir does not exist inside the bundle).
_SHARED_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '_shared')
if os.path.isdir(_SHARED_DIR) and _SHARED_DIR not in sys.path:
    sys.path.insert(0, _SHARED_DIR)
import snap_thumbs


# ---------------------------------------------------------------------------
# Constants — match Unzucker
# ---------------------------------------------------------------------------

WEB_MAX_W   = 1900
WEB_MAX_H   = 1425


# ---------------------------------------------------------------------------
# Output
# ---------------------------------------------------------------------------

@dataclass
class PreparedImage:
    main_path:     str          # resized web image
    filename:      str          # basename of main_path (server will use this)
    width:         int
    height:        int
    orientation:   str          # 'landscape', 'portrait', 'square'
    img_exif:      str          # JSON string for snap_images.img_exif
    thumb_square_path: str = ''  # local t_ thumb (client-built); '' = let server gen
    thumb_aspect_path: str = ''  # local a_ thumb (client-built); '' = let server gen


# ---------------------------------------------------------------------------
# EXIF extraction
# ---------------------------------------------------------------------------

_EXIF_KEYS_OF_INTEREST = {
    'Make', 'Model', 'LensModel', 'FNumber', 'ExposureTime',
    'ISOSpeedRatings', 'FocalLength', 'Flash', 'DateTimeOriginal',
}


def _read_exif(image_path: str) -> dict:
    """Read EXIF from a JPEG/TIFF file. Returns empty dict on failure."""
    try:
        img = PILImage.open(image_path)
        raw = img._getexif()
        if not raw:
            return {}
        result = {}
        for tag_id, value in raw.items():
            tag = TAGS.get(tag_id, str(tag_id))
            if tag in _EXIF_KEYS_OF_INTEREST:
                # Convert tuples (e.g. FNumber = (28, 10)) to float
                if isinstance(value, tuple) and len(value) == 2 and value[1] != 0:
                    result[tag] = round(value[0] / value[1], 2)
                else:
                    result[tag] = str(value)
        return result
    except Exception:
        return {}


def _build_exif_json(image_path: str,
                     geo: Optional[Tuple[float, float]] = None) -> str:
    """
    Build the img_exif JSON string for snap_images.
    Merges file EXIF with Flickr geo data.
    Returns '' if nothing to store.
    """
    data = _read_exif(image_path)
    if geo:
        data['latitude']  = geo[0]
        data['longitude'] = geo[1]
    if not data:
        return ''
    return json.dumps(data)


# ---------------------------------------------------------------------------
# Image processing
# ---------------------------------------------------------------------------

def _generate_filename(date: Optional[datetime], ext: str = 'jpg') -> str:
    """
    Generate a unique filename from date + random hex suffix.
    Format: {YYYYMMDD_HHMMSS}_{rand6}.{ext}
    """
    rand = secrets.token_hex(3)   # 6 hex chars
    if date:
        prefix = date.strftime('%Y%m%d_%H%M%S')
    else:
        prefix = datetime.utcnow().strftime('%Y%m%d_%H%M%S')
    return f"{prefix}_{rand}.{ext}"


def prepare(
    source_path:  str,
    output_dir:   str,
    date:         Optional[datetime] = None,
    geo:          Optional[Tuple[float, float]] = None,
) -> PreparedImage:
    """
    Prepare one image for upload: preserve the full original (byte-for-byte for
    JPEG/PNG/WebP; full-res high-quality JPEG for exotic formats) + extract EXIF
    + build t_/a_ thumbnails locally (shipped with the upload so the server can
    skip its GD pass).

    Args:
        source_path: absolute path to the source Flickr image
        output_dir:  directory to write the three output files into
        date:        photo date (for filename generation)
        geo:         (lat, lon) from Flickr metadata — merged into EXIF JSON

    Returns:
        PreparedImage with paths and metadata.

    Raises:
        OSError / PIL exceptions on read/write failure.
    """
    os.makedirs(output_dir, exist_ok=True)

    # Build EXIF JSON before opening image (reads separately to avoid mode issues)
    img_exif = _build_exif_json(source_path, geo)

    # ── Full original, untouched ─────────────────────────────────────────────
    # JPEG / PNG / WebP sources are copied BYTE-FOR-BYTE — no resize, no
    # re-encode — so a Flickr original is preserved exactly (no "92% of an
    # already-compressed JPEG" quality loss, and no downscale of full-res work).
    # Exotic formats (tiff/gif/bmp/heic/…) are converted ONCE to high-quality
    # JPEG at full resolution — still never downscaled.
    src_ext     = os.path.splitext(source_path)[1].lower().lstrip('.')
    passthrough = {'jpg': 'jpg', 'jpeg': 'jpg', 'png': 'png', 'webp': 'webp'}

    if src_ext in passthrough:
        out_ext   = passthrough[src_ext]
        filename  = _generate_filename(date, out_ext)
        main_path = os.path.join(output_dir, filename)
        shutil.copy2(source_path, main_path)          # exact original bytes
        # Stored dimensions honour the EXIF orientation tag the file still
        # carries, so width/height match what the browser and the server
        # thumbnailer display.
        with PILImage.open(source_path) as probe:
            web_w, web_h = ImageOps.exif_transpose(probe).size
    else:
        filename  = _generate_filename(date, 'jpg')
        main_path = os.path.join(output_dir, filename)
        img = ImageOps.exif_transpose(PILImage.open(source_path)).convert('RGB')
        web_w, web_h = img.size                        # full resolution — no resize
        img.save(main_path, 'JPEG', quality=95, optimize=True)

    orientation = 'square' if web_w == web_h else ('landscape' if web_w > web_h else 'portrait')

    # ── Thumbnails (t_/a_) built CLIENT-SIDE ─────────────────────────────────
    # Faithful port of core/thumb-generator.php (tools/_shared/snap_thumbs.py).
    # Built here and shipped in the upload so the server can skip its GD pass —
    # this is what moves the thumbnail load off the shared host during imports.
    # If generation fails for any reason, we leave the paths empty and the
    # upload endpoint falls back to server-side generation (no lost thumbs).
    thumb_sq_path = ''
    thumb_as_path = ''
    try:
        thumbs = snap_thumbs.generate_thumbs(
            main_path,
            thumb_dir=os.path.join(output_dir, 'thumbs'),
        )
        if thumbs:
            thumb_sq_path = thumbs['sq_path']
            thumb_as_path = thumbs['asp_path']
    except Exception:
        thumb_sq_path = ''
        thumb_as_path = ''

    return PreparedImage(
        main_path=main_path,
        filename=filename,
        width=web_w,
        height=web_h,
        orientation=orientation,
        img_exif=img_exif,
        thumb_square_path=thumb_sq_path,
        thumb_aspect_path=thumb_as_path,
    )
# ===== SNAPSMACK EOF =====
