"""Generate cached RAW thumbnails through a separately installed RawTherapee."""

import glob
import hashlib
import os
import shutil
import subprocess

from PIL import Image

import snap_home
import snap_imgsafe


def _install_roots():
    return [value for value in (
        os.environ.get("ProgramFiles", r"C:\Program Files"),
        os.environ.get("ProgramFiles(x86)", r"C:\Program Files (x86)"),
        os.path.join(os.environ.get("LOCALAPPDATA", ""), "Programs"),
    ) if value]


def find_rawtherapee(cli=True):
    """Find versioned or PATH-installed RawTherapee executables."""
    name = "rawtherapee-cli.exe" if cli and os.name == "nt" else (
        "rawtherapee.exe" if os.name == "nt" else
        ("rawtherapee-cli" if cli else "rawtherapee"))
    found = shutil.which(name)
    if found:
        return os.path.abspath(found)
    patterns = []
    for root in _install_roots():
        patterns.extend((
            os.path.join(root, "RawTherapee", name),
            os.path.join(root, "RawTherapee", "*", name),
        ))
    matches = [path for pattern in patterns for path in glob.glob(pattern)
               if os.path.isfile(path)]
    return os.path.abspath(sorted(matches)[-1]) if matches else ""


def _cache_path(path):
    details = os.stat(path)
    identity = "\n".join((os.path.abspath(path), str(details.st_size),
                            str(details.st_mtime_ns)))
    digest = hashlib.sha256(identity.encode("utf-8")).hexdigest()
    folder = os.path.join(snap_home.config_dir("snap_slapper"), "raw_thumbnails")
    os.makedirs(folder, exist_ok=True)
    return os.path.join(folder, digest + ".jpg")


def render(path, timeout=180):
    """Return a loaded, validated preview developed by RawTherapee's fast pipeline."""
    cli = find_rawtherapee(cli=True)
    if not cli:
        raise RuntimeError("RawTherapee is not installed or could not be found")
    path = os.path.abspath(os.fspath(path))
    target = _cache_path(path)
    if os.path.isfile(target):
        return snap_imgsafe.safe_open(target, formats={"JPEG"})
    temporary = target + ".new.jpg"
    flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
    try:
        result = subprocess.run(
            [cli, "-q", "-f", "-j85", "-js2", "-Y", "-o", temporary,
             "-c", path], shell=False, capture_output=True, text=True,
            timeout=timeout, creationflags=flags)
        if result.returncode or not os.path.isfile(temporary):
            detail = (result.stderr or result.stdout or "RawTherapee returned no preview").strip()
            raise RuntimeError(detail)
        image = snap_imgsafe.safe_open(temporary, formats={"JPEG"})
        exif = image.info.get("exif", b"")
        image.thumbnail((512, 512), Image.Resampling.LANCZOS)
        image.convert("RGB").save(temporary, "JPEG", quality=86, optimize=True,
                                  exif=exif)
        os.replace(temporary, target)
        return snap_imgsafe.safe_open(target, formats={"JPEG"})
    finally:
        if os.path.isfile(temporary):
            try:
                os.remove(temporary)
            except OSError:
                pass


# ===== SNAPSMACK EOF =====
