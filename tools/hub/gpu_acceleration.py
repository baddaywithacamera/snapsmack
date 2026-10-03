"""Optional NVIDIA acceleration for SNAP SLAPPER's float compositor.

CuPy is deliberately optional.  A missing package, unsupported driver, small
image, or runtime CUDA error returns control to the existing NumPy path.
"""

from __future__ import annotations

import os
import sys
import threading

import numpy as np


def _register_cuda_libraries():
    """Put CuPy's CUDA DLLs and headers where it can find them.

    The CUDA runtime compiler and headers arrive as their own wheels, in
    site-packages/nvidia/, and nothing adds those to the DLL search path. Without
    this CuPy imports, reports the card, and then fails on the first real
    instruction, which is how an RTX 3050 sat unused while Preferences named it.
    In a packaged build PyInstaller puts the same folders beside the executable.
    """
    if os.name != "nt":
        return
    roots = []
    for base in (os.path.dirname(os.path.abspath(sys.executable)),
                 getattr(sys, "_MEIPASS", ""), ""):
        if base:
            roots.append(os.path.join(base, "nvidia"))
    for path in sys.path:
        if path:
            roots.append(os.path.join(path, "nvidia"))
    for root in roots:
        nvrtc = os.path.join(root, "cuda_nvrtc", "bin")
        if not os.path.isdir(nvrtc):
            continue
        try:
            os.add_dll_directory(nvrtc)
        except (OSError, AttributeError):
            pass
        # nvrtc loads its own builtins by bare name, which ignores
        # add_dll_directory, so it has to be on PATH as well.
        if nvrtc not in os.environ.get("PATH", "").split(os.pathsep):
            os.environ["PATH"] = nvrtc + os.pathsep + os.environ.get("PATH", "")
        runtime = os.path.join(root, "cuda_runtime")
        if os.path.isdir(os.path.join(runtime, "include")) and \
                not os.environ.get("CUDA_PATH"):
            # CuPy compiles kernels that #include <cuda_fp16.h>.
            os.environ["CUDA_PATH"] = runtime
        return


_register_cuda_libraries()


_MODE = "auto"
_CUPY = None
_PROBED = False
_ERROR = ""
_LOCK = threading.Lock()
MIN_GPU_PIXELS = 900_000


def configure(mode="auto"):
    """Select auto, gpu, or cpu without ever making rendering depend on CUDA."""
    global _MODE
    value = str(mode or "auto").lower()
    _MODE = value if value in {"auto", "gpu", "cpu"} else "auto"


def _cupy():
    global _CUPY, _PROBED, _ERROR
    if _MODE == "cpu":
        return None
    with _LOCK:
        if not _PROBED:
            _PROBED = True
            try:
                import cupy as cp
                if cp.cuda.runtime.getDeviceCount() < 1:
                    raise RuntimeError("no CUDA device was found")
                # Force driver/context validation now, not in the middle of a
                # render. A reduction is not enough: it runs a kernel CuPy ships
                # precompiled, so it succeeds on an install that is missing the
                # runtime compiler and cannot execute a single line of the
                # arithmetic below. Prove an elementwise kernel compiles and
                # runs, which is what resample and blend are made of, or the
                # Preferences dialog names a card it will never use.
                probe = (cp.zeros(4, dtype=cp.float32) + cp.float32(1.0)) * cp.float32(2.0)
                if float(probe.sum().get()) != 8.0:
                    raise RuntimeError("the GPU returned the wrong arithmetic result")
                _CUPY = cp
            except Exception as exc:  # noqa: BLE001 - fallback is the contract
                _ERROR = str(exc)
                _CUPY = None
    return _CUPY


def status():
    if _MODE == "cpu":
        return {"mode": _MODE, "available": False, "label": "CPU selected"}
    cp = _cupy()
    if cp is None:
        detail = _ERROR or "NVIDIA acceleration add-on is not installed"
        return {"mode": _MODE, "available": False, "label": f"CPU fallback — {detail}"}
    try:
        device = cp.cuda.Device()
        props = cp.cuda.runtime.getDeviceProperties(device.id)
        name = props.get("name", b"NVIDIA GPU")
        if isinstance(name, bytes):
            name = name.decode("utf-8", "replace")
        return {"mode": _MODE, "available": True, "label": str(name)}
    except Exception:  # pragma: no cover - context already validated above
        return {"mode": _MODE, "available": True, "label": "NVIDIA GPU"}


def array_module(reference=None):
    """CuPy when this array lives on the card, NumPy otherwise.

    Every helper in the adjustment chain dispatches through this, so one
    implementation serves both paths and the CPU result cannot drift from the
    GPU one by being written twice.
    """
    if reference is not None:
        module = type(reference).__module__
        if module.startswith("cupy"):
            return _CUPY if _CUPY is not None else np
        return np
    return _cupy() or np


def is_device_array(value):
    return type(value).__module__.startswith("cupy")


def to_device(array):
    """Move a frame onto the card, or return it unchanged if it cannot go."""
    cp = _cupy()
    if cp is None:
        return array
    try:
        return cp.asarray(array)
    except Exception:  # noqa: BLE001 - the CPU path is always valid
        return array


def to_host(array):
    """Bring a frame back, whichever side it is on."""
    if not is_device_array(array):
        return array
    cp = _cupy()
    if cp is None:
        return array
    return np.ascontiguousarray(cp.asnumpy(array))


def chain_is_worth_offloading(pixel_count):
    """Below this the transfer costs more than the maths saves."""
    return _MODE != "cpu" and pixel_count >= MIN_GPU_PIXELS and _cupy() is not None


def _eligible(shape):
    return (_MODE != "cpu" and
            int(shape[0]) * int(shape[1]) >= MIN_GPU_PIXELS and
            _cupy() is not None)


def resample(source, source_x, source_y, *, transparent=True):
    """Return a CPU float32 result, or None to request the NumPy implementation."""
    if not _eligible(source_x.shape):
        return None
    cp = _cupy()
    try:
        pixels = cp.asarray(source, dtype=cp.float32)
        sx = cp.asarray(source_x, dtype=cp.float32)
        sy = cp.asarray(source_y, dtype=cp.float32)
        alpha_added = transparent and pixels.shape[2] in (1, 3)
        if alpha_added:
            pixels = cp.concatenate((pixels, cp.ones((*pixels.shape[:2], 1), cp.float32)), axis=2)
        height, width = pixels.shape[:2]
        valid = (sx >= 0) & (sx <= width - 1) & (sy >= 0) & (sy <= height - 1)
        clipped_x = cp.clip(sx, 0, width - 1)
        clipped_y = cp.clip(sy, 0, height - 1)
        x0 = cp.floor(clipped_x).astype(cp.int32); y0 = cp.floor(clipped_y).astype(cp.int32)
        x1 = cp.minimum(x0 + 1, width - 1); y1 = cp.minimum(y0 + 1, height - 1)
        wx = (clipped_x - x0)[:, :, None]; wy = (clipped_y - y0)[:, :, None]
        top = pixels[y0, x0] * (1 - wx) + pixels[y0, x1] * wx
        bottom = pixels[y1, x0] * (1 - wx) + pixels[y1, x1] * wx
        result = top * (1 - wy) + bottom * wy
        if transparent:
            result *= valid[:, :, None]
        return np.ascontiguousarray(cp.asnumpy(result), dtype=np.float32)
    except Exception:  # noqa: BLE001 - a render must survive CUDA loss/OOM
        return None


def blend(bottom, upper, upper_alpha, mode, opacity, mask):
    """GPU blend result as (rgb, alpha), or None for the NumPy path."""
    if not _eligible(bottom.shape):
        return None
    cp = _cupy()
    try:
        low = cp.asarray(bottom, dtype=cp.float32)
        high = cp.asarray(upper, dtype=cp.float32)
        if mode == "multiply": mixed = low * high
        elif mode == "screen": mixed = 1 - (1 - low) * (1 - high)
        elif mode == "overlay": mixed = cp.where(low <= .5, 2 * low * high, 1 - 2 * (1 - low) * (1 - high))
        elif mode == "hard_light": mixed = cp.where(high <= .5, 2 * low * high, 1 - 2 * (1 - low) * (1 - high))
        elif mode == "soft_light":
            safe = cp.clip(low, 0, None)
            d = cp.where(safe <= .25, ((16 * safe - 12) * safe + 4) * safe, cp.sqrt(safe))
            mixed = cp.where(high <= .5, low - (1 - 2 * high) * low * (1 - low), low + (2 * high - 1) * (d - low))
        elif mode == "darken": mixed = cp.minimum(low, high)
        elif mode == "lighten": mixed = cp.maximum(low, high)
        elif mode == "difference": mixed = cp.abs(low - high)
        elif mode in {"color", "luminosity"}:
            low_luma = low[:, :, 0] * .299 + low[:, :, 1] * .587 + low[:, :, 2] * .114
            high_luma = high[:, :, 0] * .299 + high[:, :, 1] * .587 + high[:, :, 2] * .114
            mixed = high + (low_luma - high_luma)[:, :, None] if mode == "color" else low + (high_luma - low_luma)[:, :, None]
        else: mixed = high
        alpha = cp.asarray(upper_alpha, dtype=cp.float32) if upper_alpha is not None else cp.ones((*high.shape[:2], 1), cp.float32)
        if mask is not None:
            alpha *= cp.asarray(mask, dtype=cp.float32).reshape((*high.shape[:2], 1))
        alpha = cp.clip(alpha * float(opacity), 0, 1)
        out = low * (1 - alpha) + mixed * alpha
        return np.ascontiguousarray(cp.asnumpy(out), dtype=np.float32), np.ascontiguousarray(cp.asnumpy(alpha), dtype=np.float32)
    except Exception:  # noqa: BLE001
        return None
