"""
ft-batch-poster — exif_writer.py
Embeds copyright and metadata into a JPEG using piexif (pure Python).
No external binaries required — bundles cleanly with PyInstaller.

Fields written (EXIF IFD0):
  Copyright, Artist, ImageDescription, UserComment (keywords)
"""

import os
import re
import shutil
import tempfile

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


def _build_exif(title: str, tags: str, copyright_text: str) -> bytes:
    """Build a piexif-compatible EXIF bytes blob."""
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


def embed_inplace(path: str, title: str, tags: str, copyright_text: str = '') -> None:
    """
    Embed EXIF metadata directly into an existing JPEG.
    Raises RuntimeError on failure.
    """
    try:
        exif_bytes = _build_exif(title, tags, copyright_text)
        piexif.insert(exif_bytes, path)
    except Exception as e:
        raise RuntimeError(f"piexif error: {e}")


def embed(src_path: str, title: str, tags: str, copyright_text: str = '') -> str:
    """
    Copy src_path to a temp file, embed EXIF metadata, return the temp path.
    Caller is responsible for deleting the temp file after use.
    Raises RuntimeError on failure.
    """
    ext = os.path.splitext(src_path)[1] or '.jpg'
    tmp_fd, tmp_path = tempfile.mkstemp(prefix='ft_', suffix=ext)
    os.close(tmp_fd)
    shutil.copy2(src_path, tmp_path)
    try:
        embed_inplace(tmp_path, title, tags, copyright_text)
    except Exception:
        os.unlink(tmp_path)
        raise
    return tmp_path
