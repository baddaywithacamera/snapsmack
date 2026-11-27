"""Full-size published photos kept in the shared site library.

# SNAPSMACK_EOF_HEADER
"""

import hashlib
import os
from pathlib import Path
import re
import shutil
import tempfile
import time
from urllib.parse import urlsplit

from snap_home import contained_local_path, site_dir


CACHE_DIR = "published-photo-cache"
RETENTION_SECONDS = 14 * 24 * 60 * 60
_KEY = re.compile(r"[0-9a-f]{32}")
_IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp", ".gif", ".bmp", ".tif", ".tiff", ".avif"}


def _root(site):
    root = Path(contained_local_path(site_dir(site), CACHE_DIR))
    if root.is_symlink() or root.resolve() != root.parent.resolve() / CACHE_DIR:
        raise OSError("The published photo cache must be inside the shared site library.")
    return root


def prune(site, *, clear=False, now=None):
    """Remove unused entries from one site. Saved drafts retain their own links."""
    root = _root(site)
    cutoff = (time.time() if now is None else now) - RETENTION_SECONDS
    removed = 0
    if root.is_dir():
        for entry in root.iterdir():
            if not _KEY.fullmatch(entry.name) or entry.is_symlink() or not entry.is_dir():
                continue
            if not entry.resolve().is_relative_to(root.resolve()):
                continue
            try:
                if clear or entry.stat().st_mtime <= cutoff:
                    shutil.rmtree(entry)
                    removed += 1
            except FileNotFoundError:
                pass
    return removed


def get_full_size(site, image_id, url, fetch):
    """Return the shared original, refreshing its last-used time on a hit."""
    root = _root(site)
    key = hashlib.sha256(f"{image_id}\0{url}".encode("utf-8")).hexdigest()[:32]
    entry = Path(contained_local_path(str(root), key))
    if entry.is_symlink() or entry.resolve() != root.resolve() / key:
        raise OSError("The published photo cache entry is outside its site directory.")
    extension = Path(urlsplit(url).path).suffix.lower()
    if extension not in _IMAGE_EXTENSIONS:
        extension = ".img"
    target = Path(contained_local_path(str(entry), key + extension))
    if target.is_symlink():
        raise OSError("The published original must be an ordinary cached file.")
    if not target.is_file():
        payload = fetch()
        if payload is None:
            return None
        entry.mkdir(parents=True, exist_ok=True)
        with tempfile.NamedTemporaryFile(dir=entry, delete=False) as out:
            temporary = Path(out.name)
            try:
                out.write(payload)
                out.close()
                os.replace(temporary, target)
            finally:
                temporary.unlink(missing_ok=True)
    os.utime(entry, None)
    return target


def is_cached_original(source):
    if not source:
        return False
    source = Path(source)
    return source.parent.parent.name == CACHE_DIR and bool(_KEY.fullmatch(source.parent.name))


def link_original(source, destination):
    """Pin an original for a saved draft without copying shared bytes if possible."""
    source = Path(source)
    if not is_cached_original(source):
        return False
    destination = Path(destination)
    try:
        os.link(source, destination)
    except FileExistsError:
        if source.samefile(destination):
            return True
        return False
    except OSError:
        return False
    return True

# ===== SNAPSMACK EOF =====
