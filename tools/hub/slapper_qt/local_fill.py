"""Bridge between frozen SNAP SLAPPER and its optional local Diffusers tool."""

import os
import shutil
import subprocess
import sys
import tempfile

from PIL import Image

import snap_home

MODEL = "stable-diffusion-v1-5/stable-diffusion-inpainting"


def install_root():
    return os.path.join(snap_home.home(), "local_ai", "generative_fill")


def _python():
    return os.path.join(install_root(), "venv", "Scripts", "python.exe")


def _runner():
    return os.path.join(install_root(), "local_fill_runner.py")


def installed():
    return all(os.path.isfile(path) for path in (
        _python(), _runner(), os.path.join(install_root(), "installed.txt")))


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


def fill(photo, mask, prompt):
    if not installed():
        raise RuntimeError("Local Generative Fill is not installed yet.")
    bounds = mask.getbbox()
    if not bounds:
        raise ValueError("Paint an area to fill first.")
    left, top, right, bottom = bounds
    margin = max(96, int(max(right - left, bottom - top) * 0.75))
    box = (max(0, left - margin), max(0, top - margin),
           min(photo.width, right + margin), min(photo.height, bottom + margin))
    crop = photo.crop(box).convert("RGB")
    crop_mask = mask.crop(box).convert("L")
    original_size = crop.size
    scale = min(512 / crop.width, 512 / crop.height)
    work_size = (max(64, round(crop.width * scale)), max(64, round(crop.height * scale)))
    crop = crop.resize(work_size, Image.Resampling.LANCZOS)
    crop_mask = crop_mask.resize(work_size, Image.Resampling.NEAREST)
    with tempfile.TemporaryDirectory(prefix="snap-local-fill-") as temp:
        image_path = os.path.join(temp, "image.png")
        mask_path = os.path.join(temp, "mask.png")
        output_path = os.path.join(temp, "result.png")
        crop.save(image_path); crop_mask.save(mask_path)
        run = subprocess.run(
            [_python(), _runner(), "--image", image_path, "--mask", mask_path,
             "--output", output_path, "--prompt", prompt or ""],
            capture_output=True, text=True, timeout=1800,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        if run.returncode != 0 or not os.path.isfile(output_path):
            message = (run.stderr or run.stdout or "Local model did not return an image.").strip()
            raise RuntimeError(message[-2000:])
        generated = Image.open(output_path).convert("RGB").resize(
            original_size, Image.Resampling.LANCZOS)
        result = photo.copy().convert("RGB")
        local_mask = mask.crop(box).convert("L")
        result.paste(generated, box[:2], local_mask)
        return result, MODEL


# ===== SNAPSMACK EOF =====
