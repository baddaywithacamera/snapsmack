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
from dataclasses import dataclass
from datetime import datetime
from typing import Optional, Tuple

from PIL import Image as PILImage
from PIL import ImageOps
from PIL.ExifTags import TAGS


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

def _generate_filename(date: Optional[datetime]) -> str:
    """
    Generate a unique filename from date + random hex suffix.
    Format: {YYYYMMDD_HHMMSS}_{rand6}.jpg
    """
    rand = secrets.token_hex(3)   # 6 hex chars
    if date:
        prefix = date.strftime('%Y%m%d_%H%M%S')
    else:
        prefix = datetime.utcnow().strftime('%Y%m%d_%H%M%S')
    return f"{prefix}_{rand}.jpg"


def prepare(
    source_path:  str,
    output_dir:   str,
    date:         Optional[datetime] = None,
    geo:          Optional[Tuple[float, float]] = None,
) -> PreparedImage:
    """
    Process one image: resize to web max + extract EXIF (thumbnails are made
    server-side after upload).

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

    # Open and normalise
    img = PILImage.open(source_path)
    # Apply EXIF orientation so portrait / rotated camera shots are not written
    # sideways (PIL does not auto-rotate on open). Must happen before any resize
    # or crop so width/height and the square thumbnail come out correct.
    img = ImageOps.exif_transpose(img)
    img = img.convert('RGB')  # normalise to RGB (handles CMYK, palette, etc.)

    orig_w, orig_h = img.size

    # ── Main image: resize to web max ────────────────────────────────────────
    web_img = img.copy()
    web_img.thumbnail((WEB_MAX_W, WEB_MAX_H), PILImage.LANCZOS)
    web_w, web_h = web_img.size

    orientation = 'landscape' if web_w >= web_h else 'portrait'
    if web_w == web_h:
        orientation = 'square'

    filename     = _generate_filename(date)
    main_path    = os.path.join(output_dir, filename)
    web_img.save(main_path, 'JPEG', quality=92, optimize=True)

    # Thumbnails (t_/a_) are generated server-side by the flkrfckr/upload
    # endpoint (core/thumb-generator.php), so none are produced here.

    return PreparedImage(
        main_path=main_path,
        filename=filename,
        width=web_w,
        height=web_h,
        orientation=orientation,
        img_exif=img_exif,
    )
# ===== SNAPSMACK EOF =====
