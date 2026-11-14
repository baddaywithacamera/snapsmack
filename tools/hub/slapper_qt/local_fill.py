"""Bridge between frozen SNAP SLAPPER and its optional local Diffusers tool."""

import json
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
RUNNER_STEPS = 30          # must match STEPS in local_ai/local_fill_runner.py

# The diffusion model inpaints at 512. That is the model's limit, not a
# setting. Named so the tile chooser can ask what this provider can see
# instead of repeating the number.
WORK_RESOLUTION = 512

# How much neighbouring 1:1 windows share. Two windows invent their own
# content independently, so butting them together leaves a visible ridge;
# the shared band is what there is to blend across.
OVERLAP = 96

Cancelled = subprocess_limits.Cancelled


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


def _fill_one_window(tile, tile_mask, prompt, on_progress=None,
                     should_cancel=None):
    """Generate inside ``tile_mask`` and return a patch at the TILE's own size.

    The diffusion model works at 512 pixels; that is the model's limit, not a
    choice. What this avoids is the second loss: the patch is delivered at the
    resolution of the photograph rather than of the screen preview, so it is
    composited once instead of being generated small, shrunk to a preview and
    then stretched back up at export.

    ``on_progress(stage, done, total)`` is called as the child reports: once
    with ("loading", 0, 0) while the model loads, then per diffusion step with
    ("generating", n, 30). ``total`` is 0 when the step count is unknown, which
    is the honest answer for the load and for an older diffusers build -- a bar
    that invents a percentage for an unmeasured stage is worse than no bar.

    ``should_cancel()`` is polled while the child runs; returning true kills it
    and raises Cancelled. The person gets their application back.
    """
    if not installed():
        raise RuntimeError("Local Generative Fill is not installed yet.")
    if not tile_mask.getbbox():
        raise ValueError("Paint an area to fill first.")
    tile = tile.convert("RGB")
    tile_mask = tile_mask.convert("L").resize(tile.size, Image.Resampling.LANCZOS)
    scale = min(WORK_RESOLUTION / tile.width, WORK_RESOLUTION / tile.height)
    work_size = (max(64, round(tile.width * scale)), max(64, round(tile.height * scale)))
    work_image = tile.resize(work_size, Image.Resampling.LANCZOS)
    work_mask = tile_mask.resize(work_size, Image.Resampling.NEAREST)
    with tempfile.TemporaryDirectory(prefix="snap-local-fill-") as temp:
        image_path = os.path.join(temp, "image.png")
        mask_path = os.path.join(temp, "mask.png")
        output_path = os.path.join(temp, "result.png")
        work_image.save(image_path); work_mask.save(mask_path)
        run = subprocess_limits.stream(
            [_python(), _runner(), "--image", image_path, "--mask", mask_path,
             "--output", output_path, "--prompt", prompt or ""],
            timeout=1800,
            memory_bytes=MAX_RUNNER_MEMORY_BYTES,
            on_line=_progress_reader(on_progress),
            should_cancel=should_cancel,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        if run.returncode != 0 or not os.path.isfile(output_path):
            message = (run.stderr or run.stdout or "Local model did not return an image.").strip()
            raise RuntimeError(message[-2000:])
        if os.path.getsize(output_path) > MAX_RESULT_BYTES:
            raise RuntimeError("Local Generative Fill returned an oversized image.")
        with Image.open(output_path) as source:
            if (source.format != "PNG"
                    or source.width * source.height > WORK_RESOLUTION ** 2):
                raise RuntimeError("Local Generative Fill returned an unsafe image.")
            source.load()
            # NEVER UPSCALED. The model answers at WORK_RESOLUTION; this window
            # is at most that size, so this resize only ever shrinks (or does
            # nothing). Sean's words: "i don't want the patch upscaled at all."
            # Growing a 512 answer to fill a 2600-pixel region is what made
            # fills look soft, and the caller guarantees the window is bounded.
            patch = source.convert("RGB")
            if patch.size != tile.size:
                if tile.width > WORK_RESOLUTION or tile.height > WORK_RESOLUTION:
                    raise RuntimeError(
                        "internal: a %dx%d window reached the model, which "
                        "would upscale its answer" % tile.size)
                patch = patch.resize(tile.size, Image.Resampling.LANCZOS)
            return patch, MODEL


def plan_windows(size, mask_bbox, work=WORK_RESOLUTION, overlap=OVERLAP):
    """The 1:1 windows that cover ``mask_bbox`` inside an image of ``size``.

    Each window is at most ``work`` pixels on a side, so the model's answer is
    never stretched. Windows overlap by ``overlap`` pixels, which is what lets
    neighbouring answers be blended instead of butted together with a seam.

    A window is pushed inward at the frame edge rather than shrunk, so the
    model always gets its full canvas of real photograph to look at.
    """
    width, height = size
    left, top, right, bottom = mask_bbox
    step = max(1, work - overlap)
    boxes = []
    y = top
    while True:
        x = left
        while True:
            x0 = max(0, min(x, width - work)) if width > work else 0
            y0 = max(0, min(y, height - work)) if height > work else 0
            x1 = min(width, x0 + work)
            y1 = min(height, y0 + work)
            box = (x0, y0, x1, y1)
            if box not in boxes:
                boxes.append(box)
            if x + work >= right:
                break
            x += step
        if y + work >= bottom:
            break
        y += step
    return boxes


def _feather(size, overlap):
    """A raised-cosine weight that falls to zero at a window's edges.

    Averaging overlapping answers with a flat weight leaves a visible ridge
    where two windows meet, because each one invented its own content. The
    falloff makes the handover gradual.

    NumPy, not nested lists. The first version of this built the weights and
    accumulated the composite in Python loops; on the 2600-pixel region from
    Sean's own photograph that is 6.8 million iterations per pass, twice, and
    it turned a fix for slow-looking output into slow output.
    """
    import numpy as np
    width, height = size
    ramp = int(max(1, min(overlap, width // 2, height // 2)))

    def axis(n):
        out = np.ones(n, dtype=np.float32)
        i = np.arange(ramp, dtype=np.float32)
        w = 0.5 - 0.5 * np.cos(np.pi * (i + 0.5) / ramp)
        out[:ramp] = w
        out[n - ramp:] = w[::-1]
        return out

    return np.outer(axis(height), axis(width)).astype(np.float32)


def fill_tile(tile, tile_mask, prompt, on_progress=None, should_cancel=None):
    """Rebuild everything ``tile_mask`` covers, at the photograph's own scale.

    WHY THIS IS NOT ONE MODEL CALL. The model works at WORK_RESOLUTION. The
    old path squeezed the whole region into that canvas and then stretched the
    answer back out: a 528-pixel selection inside a 2600-pixel region reached
    the model as 104 pixels and came back magnified 5.1 times. Measured on
    Sean's own 3456x5184 photograph, and it is why fills looked soft. His
    instruction: "upscaling looks like shit. it has to be quality or it has to
    be taken out."

    So the region is covered by overlapping windows of at most
    WORK_RESOLUTION REAL pixels. Every window is inpainted at 1:1, and the
    surrounding photograph inside that window is the model's context -- at full
    detail, not a thumbnail of a wider view. Overlaps are blended with a
    raised-cosine weight so neighbouring answers hand over gradually.

    The cost is honest and it is time: a region needing four windows is four
    model runs. The progress reported counts every window, so a long job looks
    long instead of looking stuck.
    """
    if not installed():
        raise RuntimeError("Local Generative Fill is not installed yet.")
    tile = tile.convert("RGB")
    tile_mask = tile_mask.convert("L").resize(tile.size, Image.Resampling.LANCZOS)
    bbox = tile_mask.getbbox()
    if not bbox:
        raise ValueError("Paint an area to fill first.")

    windows = plan_windows(tile.size, bbox)
    if len(windows) == 1 and windows[0] == (0, 0, tile.width, tile.height):
        return _fill_one_window(tile, tile_mask, prompt, on_progress, should_cancel)

    total = len(windows)

    def window_progress(index):
        def report(stage, done, steps):
            if on_progress is None:
                return
            # Steps are reported across the whole job, not per window, or the
            # bar would restart from zero several times and read as a hang.
            if stage == "generating" and steps > 0:
                on_progress(stage, index * steps + done, total * steps)
            else:
                on_progress(stage, done, steps)
        return report

    # Accumulate in floats: overlapping windows are averaged by weight, and
    # rounding each one into 8-bit first would band the overlap.
    import numpy as np
    width, height = tile.size
    acc = np.zeros((height, width, 3), dtype=np.float32)
    wsum = np.zeros((height, width), dtype=np.float32)

    used_model = MODEL
    for index, (x0, y0, x1, y1) in enumerate(windows):
        if should_cancel is not None and should_cancel():
            raise Cancelled("Stopped before the model finished.")
        window = tile.crop((x0, y0, x1, y1))
        window_mask = tile_mask.crop((x0, y0, x1, y1))
        if not window_mask.getbbox():
            continue            # nothing painted here; do not spend a model run
        patch, used_model = _fill_one_window(
            window, window_mask, prompt, window_progress(index), should_cancel)
        weights = _feather(patch.size, OVERLAP)
        values = np.asarray(patch, dtype=np.float32)
        acc[y0:y0 + patch.height, x0:x0 + patch.width] += values * weights[..., None]
        wsum[y0:y0 + patch.height, x0:x0 + patch.width] += weights

    # Only painted pixels are replaced. Everything outside the mask is the
    # photograph, untouched, which is the promise the dialog makes in words.
    base = np.asarray(tile, dtype=np.float32)
    covered = wsum > 0.0
    blended = np.where(covered[..., None], acc / np.maximum(wsum, 1e-6)[..., None], base)
    painted = (np.asarray(tile_mask) > 0) & covered
    merged = np.where(painted[..., None], blended, base)
    result = Image.fromarray(np.clip(merged, 0, 255).astype(np.uint8), "RGB")
    return result, used_model


def _progress_reader(on_progress):
    """Turn the runner's NDJSON lines into on_progress calls.

    The runner prints one line per diffusion step. Anything that is not a
    progress line -- a warning from torch, a blank line, the final result
    object -- is ignored rather than guessed at, so a chatty dependency cannot
    make the bar jump.
    """
    if on_progress is None:
        return None

    def read(line):
        try:
            record = json.loads(line)
        except (TypeError, ValueError):
            return
        if not isinstance(record, dict):
            return
        stage = record.get("stage")
        if stage not in ("loading", "generating", "saving"):
            return
        on_progress(stage, int(record.get("step", 0) or 0),
                    int(record.get("steps", 0) or 0))

    return read


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
