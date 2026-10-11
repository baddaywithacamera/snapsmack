"""Bridge between frozen SNAP SLAPPER and its optional local Diffusers tool."""

import os
import hashlib
import hmac
import shutil
import subprocess
import sys
import tempfile

from PIL import Image

import snap_home
import subprocess_limits

MODEL = "stable-diffusion-v1-5/stable-diffusion-inpainting"
MAX_RESULT_BYTES = 32 * 1024 * 1024
MAX_RUNNER_MEMORY_BYTES = 24 * 1024 * 1024 * 1024


def install_root():
    return os.path.join(snap_home.home(), "local_ai", "generative_fill")


def _python():
    return os.path.join(install_root(), "venv", "Scripts", "python.exe")


def _runner():
    return os.path.join(install_root(), "local_fill_runner.py")


def installed():
    required = (_python(), _runner(), os.path.join(install_root(), "installed.txt"))
    if not all(os.path.isfile(path) for path in required):
        return False
    source = _resource("local_fill_runner.py")
    if not source:
        return False
    return hmac.compare_digest(_sha256(_runner()), _sha256(source))


def _sha256(path):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _resource(name):
    base = getattr(sys, "_MEIPASS", os.path.dirname(os.path.dirname(__file__)))
    candidates = (os.path.join(base, "local_ai", name),
                  os.path.join(os.path.dirname(os.path.dirname(__file__)), "local_ai", name))
    return next((path for path in candidates if os.path.isfile(path)), "")


def start_installer():
    installer = _resource("install_local_fill.py")
    runner = _resource("local_fill_runner.py")
    if not installer or not runner:
        raise RuntimeError("The Local Generative Fill installer files are missing.")
    launcher = shutil.which("py")
    if not launcher:
        raise RuntimeError("Python 3.10 or 3.11 must be installed before Local Generative Fill.")
    flags = getattr(subprocess, "CREATE_NEW_CONSOLE", 0)
    return subprocess.Popen(
        [launcher, "-3.10", installer, install_root(), runner],
        creationflags=flags)


def fill_tile(tile, tile_mask, prompt):
    """Generate inside ``tile_mask`` and return a patch at the TILE's own size.

    The diffusion model works at 512 pixels; that is the model's limit, not a
    choice. What this avoids is the second loss: the patch is delivered at the
    resolution of the photograph rather than of the screen preview, so it is
    composited once instead of being generated small, shrunk to a preview and
    then stretched back up at export.
    """
    if not installed():
        raise RuntimeError("Local Generative Fill is not installed yet.")
    if not tile_mask.getbbox():
        raise ValueError("Paint an area to fill first.")
    tile = tile.convert("RGB")
    tile_mask = tile_mask.convert("L").resize(tile.size, Image.Resampling.LANCZOS)
    scale = min(512 / tile.width, 512 / tile.height)
    work_size = (max(64, round(tile.width * scale)), max(64, round(tile.height * scale)))
    work_image = tile.resize(work_size, Image.Resampling.LANCZOS)
    work_mask = tile_mask.resize(work_size, Image.Resampling.NEAREST)
    with tempfile.TemporaryDirectory(prefix="snap-local-fill-") as temp:
        image_path = os.path.join(temp, "image.png")
        mask_path = os.path.join(temp, "mask.png")
        output_path = os.path.join(temp, "result.png")
        work_image.save(image_path); work_mask.save(mask_path)
        run = subprocess_limits.run(
            [_python(), _runner(), "--image", image_path, "--mask", mask_path,
             "--output", output_path, "--prompt", prompt or ""],
            capture_output=True, text=True, timeout=1800,
            memory_bytes=MAX_RUNNER_MEMORY_BYTES,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        if run.returncode != 0 or not os.path.isfile(output_path):
            message = (run.stderr or run.stdout or "Local model did not return an image.").strip()
            raise RuntimeError(message[-2000:])
        if os.path.getsize(output_path) > MAX_RESULT_BYTES:
            raise RuntimeError("Local Generative Fill returned an oversized image.")
        with Image.open(output_path) as source:
            if source.format != "PNG" or source.width * source.height > 512 * 512:
                raise RuntimeError("Local Generative Fill returned an unsafe image.")
            source.load()
            return source.convert("RGB").resize(tile.size, Image.Resampling.LANCZOS), MODEL


def fill(photo, mask, prompt):
    """Whole-frame convenience wrapper: choose a bounded tile, fill, composite."""
    bounds = mask.getbbox()
    if not bounds:
        raise ValueError("Paint an area to fill first.")
    left, top, right, bottom = bounds
    margin = max(96, int(max(right - left, bottom - top) * 0.75))
    box = (max(0, left - margin), max(0, top - margin),
           min(photo.width, right + margin), min(photo.height, bottom + margin))
    patch, model = fill_tile(photo.crop(box), mask.crop(box), prompt)
    result = photo.copy().convert("RGB")
    result.paste(patch, box[:2], mask.crop(box).convert("L"))
    return result, model


# ===== SNAPSMACK EOF =====
