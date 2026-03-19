"""
ft-batch-poster — exif_writer.py
Embeds copyright and metadata into a JPEG using a bundled ExifTool binary.

The original file is never touched. We work on a temp copy and return its path.
The caller is responsible for cleaning up the temp file after upload.
"""

import os
import platform
import re
import shutil
import subprocess
import sys
import tempfile

# Prevent a ghost console window when running as a PyInstaller --windowed exe
_SUBPROCESS_FLAGS = subprocess.CREATE_NO_WINDOW if platform.system() == 'Windows' else 0


COPYRIGHT = (
    "\u00a9 Sean McCormick / foundtextures.ca. "
    "Free for personal and commercial use. "
    "Cannot be resold as a standalone texture file. "
    "No attribution required. "
    "Permitted for use in AI training datasets."
)

ARTIST = "Sean McCormick"


def _exiftool_path() -> str:
    """
    Locate exiftool.exe.
    - When frozen by PyInstaller (--onefile), look next to the .exe first.
      exiftool.exe must NOT be bundled inside the archive — it self-extracts
      its own Perl DLLs and needs to live on real disk to do so.
    - In development, look next to this script.
    """
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))

    candidate = os.path.join(base, 'exiftool.exe')
    if os.path.isfile(candidate):
        return candidate

    # Fall back to PATH (useful in dev if exiftool is installed system-wide)
    found = shutil.which('exiftool') or shutil.which('exiftool.exe')
    if found:
        return found

    raise FileNotFoundError(
        "exiftool.exe not found. Place it next to ft-batch-poster.exe."
    )


def _tags_to_keywords(tags_str: str) -> str:
    """Convert '#rust #concrete #peeling' → 'rust, concrete, peeling'"""
    tokens = tags_str.split()
    keywords = [t.lstrip('#') for t in tokens if t.startswith('#')]
    # Filter out hex colour codes (e.g. #4b4144) — not useful as IPTC keywords
    keywords = [k for k in keywords if not re.fullmatch(r'[0-9a-fA-F]{6}', k)]
    return ', '.join(keywords)


def embed_inplace(path: str, title: str, tags: str, copyright_text: str = '') -> None:
    """
    Embed EXIF/IPTC metadata directly into an existing file.
    Use this for the web version we've already saved to disk.
    Raises RuntimeError if ExifTool fails.
    """
    copyright_str = copyright_text or COPYRIGHT
    keywords = _tags_to_keywords(tags)
    cmd = [
        _exiftool_path(),
        '-overwrite_original',
        # EXIF IFD0 — read by Windows Explorer Details tab
        f'-EXIF:Copyright={copyright_str}',
        f'-EXIF:Artist={ARTIST}',
        f'-EXIF:ImageDescription={title}',
        # IPTC — read by Photoshop, Lightroom, stock sites
        f'-IPTC:CopyrightNotice={copyright_str}',
        f'-IPTC:By-line={ARTIST}',
        f'-IPTC:ObjectName={title}',
        f'-IPTC:Keywords={keywords}',
        # XMP — read by everything else
        f'-XMP-dc:Rights={copyright_str}',
        f'-XMP-dc:Creator={ARTIST}',
        f'-XMP-dc:Title={title}',
        f'-XMP-dc:Subject={keywords}',
        '-codedcharacterset=UTF8',
        path,
    ]
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=30,
                                creationflags=_SUBPROCESS_FLAGS)
    except subprocess.TimeoutExpired:
        raise RuntimeError("ExifTool timed out")
    except FileNotFoundError as e:
        raise RuntimeError(str(e))
    if result.returncode != 0:
        raise RuntimeError(f"ExifTool error: {result.stderr.strip() or result.stdout.strip()}")


def embed(src_path: str, title: str, tags: str, copyright_text: str = '') -> str:
    """
    Copy src_path to a temp file, embed EXIF/IPTC metadata, and return the
    temp file path. Raises RuntimeError if ExifTool fails.
    """
    copyright_str = copyright_text or COPYRIGHT
    # Create a temp file with the same extension
    ext = os.path.splitext(src_path)[1] or '.jpg'
    tmp_fd, tmp_path = tempfile.mkstemp(prefix='ft_', suffix=ext)
    os.close(tmp_fd)

    shutil.copy2(src_path, tmp_path)

    keywords = _tags_to_keywords(tags)

    cmd = [
        _exiftool_path(),
        '-overwrite_original',
        # EXIF IFD0 — read by Windows Explorer Details tab
        f'-EXIF:Copyright={copyright_str}',
        f'-EXIF:Artist={ARTIST}',
        f'-EXIF:ImageDescription={title}',
        # IPTC — read by Photoshop, Lightroom, stock sites
        f'-IPTC:CopyrightNotice={copyright_str}',
        f'-IPTC:By-line={ARTIST}',
        f'-IPTC:ObjectName={title}',
        f'-IPTC:Keywords={keywords}',
        # XMP — read by everything else
        f'-XMP-dc:Rights={copyright_str}',
        f'-XMP-dc:Creator={ARTIST}',
        f'-XMP-dc:Title={title}',
        f'-XMP-dc:Subject={keywords}',
        '-codedcharacterset=UTF8',
        tmp_path,
    ]

    try:
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=30,
            creationflags=_SUBPROCESS_FLAGS,
        )
    except subprocess.TimeoutExpired:
        os.unlink(tmp_path)
        raise RuntimeError("ExifTool timed out")
    except FileNotFoundError as e:
        os.unlink(tmp_path)
        raise RuntimeError(str(e))

    if result.returncode != 0:
        os.unlink(tmp_path)
        raise RuntimeError(f"ExifTool error: {result.stderr.strip() or result.stdout.strip()}")

    return tmp_path
