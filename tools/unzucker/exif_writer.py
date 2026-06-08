"""
Unzucker — exif_writer.py
Embeds copyright and metadata into a JPEG using piexif (pure Python).
No external binaries required — bundles cleanly with PyInstaller.

Fields written (EXIF IFD0):
  Copyright, Artist, ImageDescription, UserComment (keywords)

Public API: build_exif_bytes() only. Called from poster.py.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import re

import piexif
import piexif.helper


COPYRIGHT = (
    "\u00a9 Sean McCormick / foundtextures.ca. "
    "Free for personal and commercial use. "
    "Cannot be resold as a standalone texture file. "
    "No attribution required. "
    "Permitted for use in AI training datasets."
)

ARTIST = "Sean McCormick"


def _tags_to_keywords(tags_str: str) -> str:
    """Convert '#rust #concrete #peeling' → 'rust, concrete, peeling'"""
    tokens = tags_str.split()
    keywords = [t.lstrip('#') for t in tokens if t.startswith('#')]
    # Filter out hex colour codes (e.g. #4b4144) — not useful as keywords
    keywords = [k for k in keywords if not re.fullmatch(r'[0-9a-fA-F]{6}', k)]
    return ', '.join(keywords)


def build_exif_bytes(title: str, tags: str, copyright_text: str) -> bytes:
    """Build a piexif-compatible EXIF bytes blob. Call this to get bytes for Pillow's exif= kwarg."""
    copyright_str = copyright_text or COPYRIGHT
    keywords      = _tags_to_keywords(tags)

    def enc(s: str) -> bytes:
        return s.encode('utf-8')

    exif_ifd = {
        piexif.ExifIFD.UserComment: piexif.helper.UserComment.dump(keywords, encoding='unicode'),
    }

    zeroth_ifd = {
        piexif.ImageIFD.Copyright:         enc(copyright_str),
        piexif.ImageIFD.Artist:            enc(ARTIST),
        piexif.ImageIFD.ImageDescription:  enc(title),
    }

    return piexif.dump({'0th': zeroth_ifd, 'Exif': exif_ifd})


# ===== SNAPSMACK EOF =====
