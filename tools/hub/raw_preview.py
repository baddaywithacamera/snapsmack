"""Generate cached RAW thumbnails through a separately installed RawTherapee."""

import glob
import hashlib
import hmac
import json
import math
import os
import secrets
import shutil
import subprocess

from PIL import Image, ImageOps

import snap_home
import snap_imgsafe
import snap_creds
import subprocess_limits


RAW_DECODER_MEMORY_BYTES = 4 * 1024 * 1024 * 1024
# A 45 MP camera can produce a 270 MB uncompressed 16-bit TIFF. These files
# are local RawTherapee outputs and still pass the format and 512 MP pixel
# checks; only the generic 128 MiB download-size limit is inappropriate here.
RAW_DEVELOPMENT_MAX_BYTES = 1024 * 1024 * 1024
CACHE_INTEGRITY_SECRET = "snap_slapper_raw_cache_hmac_v1"


def _integrity_key():
    encoded = snap_creds.get(CACHE_INTEGRITY_SECRET, "")
    if not encoded:
        encoded = secrets.token_hex(32)
        snap_creds.set(CACHE_INTEGRITY_SECRET, encoded)
    return bytes.fromhex(encoded)


def _file_digest(path):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _cache_signature(paths, key):
    value = hmac.new(key, digestmod=hashlib.sha256)
    for path in paths:
        value.update(os.path.basename(path).encode("utf-8"))
        value.update(b"\0")
        value.update(_file_digest(path).encode("ascii"))
        value.update(b"\0")
    return value.hexdigest()


def _integrity_path(target):
    return target + ".integrity.json"


def _write_cache_integrity(target, *companions):
    paths = (target,) + tuple(companions)
    key = _integrity_key()
    value = {"schema": 1, "files": [os.path.basename(path) for path in paths],
             "hmac_sha256": _cache_signature(paths, key)}
    integrity = _integrity_path(target)
    temporary = integrity + ".new"
    with open(temporary, "w", encoding="utf-8", newline="\n") as handle:
        json.dump(value, handle, sort_keys=True, separators=(",", ":"))
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temporary, integrity)


def _cache_is_authentic(target, *companions):
    paths = (target,) + tuple(companions)
    if not all(os.path.isfile(path) for path in paths):
        return False
    try:
        with open(_integrity_path(target), "r", encoding="utf-8") as handle:
            value = json.load(handle)
        expected_files = [os.path.basename(path) for path in paths]
        return (value.get("schema") == 1 and value.get("files") == expected_files and
                hmac.compare_digest(value.get("hmac_sha256", ""),
                                    _cache_signature(paths, _integrity_key())))
    except (OSError, ValueError, TypeError, json.JSONDecodeError):
        return False


def _install_roots():
    return [value for value in (
        os.environ.get("ProgramFiles", r"C:\Program Files"),
        os.environ.get("ProgramFiles(x86)", r"C:\Program Files (x86)"),
        os.path.join(os.environ.get("LOCALAPPDATA", ""), "Programs"),
    ) if value]


def find_rawtherapee(cli=True):
    """Find RawTherapee in known install roots before considering PATH."""
    name = "rawtherapee-cli.exe" if cli and os.name == "nt" else (
        "rawtherapee.exe" if os.name == "nt" else
        ("rawtherapee-cli" if cli else "rawtherapee"))
    patterns = []
    for root in _install_roots():
        patterns.extend((
            os.path.join(root, "RawTherapee", name),
            os.path.join(root, "RawTherapee", "*", name),
        ))
    matches = [path for pattern in patterns for path in glob.glob(pattern)
               if os.path.isfile(path)]
    if matches:
        return os.path.abspath(sorted(matches)[-1])
    found = shutil.which(name)
    return os.path.abspath(found) if found else ""


def _cache_path(path):
    details = os.stat(path)
    identity = "\n".join(("quality-thumb-v6-shadow-curve-1200", os.path.abspath(path),
                            str(details.st_size), str(details.st_mtime_ns)))
    digest = hashlib.sha256(identity.encode("utf-8")).hexdigest()
    folder = os.path.join(snap_home.config_dir("snap_slapper"), "raw_thumbnails")
    os.makedirs(folder, exist_ok=True)
    return os.path.join(folder, digest + ".jpg")


def _development_cache_path(path, adjustments):
    details = os.stat(path)
    identity = json.dumps({
        "path": os.path.abspath(path), "size": details.st_size,
        "mtime": details.st_mtime_ns, "adjustments": adjustments,
    }, sort_keys=True, separators=(",", ":"))
    digest = hashlib.sha256(identity.encode("utf-8")).hexdigest()
    folder = os.path.join(snap_home.config_dir("snap_slapper"), "raw_developments")
    os.makedirs(folder, exist_ok=True)
    return os.path.join(folder, digest + ".tif")


def _visible_library_preview(image):
    """Lift a dark neutral RAW rendering for contact-sheet visibility only."""
    rgb = image.convert("RGB")
    histogram = ImageOps.grayscale(rgb).histogram()
    total = sum(histogram)

    def percentile(fraction):
        threshold = total * fraction
        running = 0
        for value, count in enumerate(histogram):
            running += count
            if running >= threshold:
                return value
        return 255

    median = percentile(0.50)
    # RawTherapee's neutral render is intentionally conservative and can make
    # correctly exposed camera files nearly black at thumbnail size. This does
    # not alter the RAW or developed master; it only makes the browser useful.
    # Lift shadows with a gamma curve instead of multiplying every pixel. This
    # makes a conservative RAW contact sheet readable while preserving small
    # bright subjects that a linear lift would blow out.
    if 0 < median < 80:
        gamma = math.log(80.0 / 255.0) / math.log(median / 255.0)
        gamma = max(0.55, min(1.0, gamma))
        table = [round(255.0 * ((value / 255.0) ** gamma))
                 for value in range(256)]
        rgb = rgb.point(table * 3)
    return rgb


def _pp3_text(adjustments):
    """Translate SNAP SLAPPER's base RAW controls into a RawTherapee profile."""
    exposure = max(-5.0, min(12.0, float(adjustments.get("exposure", 0.0))))
    brightness = round(max(-100, min(100, float(adjustments.get("brightness", 0.0)))))
    contrast = round(max(-100, min(100, float(adjustments.get("contrast", 0.0)))))
    saturation = round(max(-100, min(100, float(adjustments.get("saturation", 0.0)))))
    highlights = round(max(-100, min(100, float(adjustments.get("highlights", 0.0)))))
    shadows = round(max(-100, min(100, float(adjustments.get("shadows", 0.0)))))
    temperature = max(-100, min(100, float(adjustments.get("temperature", 0.0))))
    tint = max(-100, min(100, float(adjustments.get("tint", 0.0))))
    noise_reduction = round(max(
        0, min(100, float(adjustments.get("raw_noise_reduction", 0.0)))))
    rotation = max(-45.0, min(45.0, float(adjustments.get("raw_rotation", 0.0))))
    perspective_h = max(-100.0, min(
        100.0, float(adjustments.get("raw_perspective_horizontal", 0.0))))
    perspective_v = max(-100.0, min(
        100.0, float(adjustments.get("raw_perspective_vertical", 0.0))))
    # RawTherapee's Distortion Amount is -0.5..0.5; the UI presents the same
    # correction as an easier-to-read -50..50 scale.
    distortion = max(-0.5, min(
        0.5, float(adjustments.get("raw_lens_distortion", 0.0)) / 100.0))
    defish = max(0.0, min(100.0, float(adjustments.get("raw_defish", 0.0))))
    # RawTherapee's defish uses focal length as its strength control: 25 mm is
    # gentle and 0.5 mm is strongest.
    defish_focal = 25.0 - (24.5 * defish / 100.0)
    ca_red = max(-4.0, min(4.0, float(adjustments.get("raw_ca_red", 0.0))))
    ca_blue = max(-4.0, min(4.0, float(adjustments.get("raw_ca_blue", 0.0))))
    vignette = round(max(-100, min(
        100, float(adjustments.get("raw_vignette_correction", 0.0)))))
    lensfun = bool(adjustments.get("raw_lensfun", False))
    lensfun_distortion = bool(adjustments.get("raw_lensfun_distortion", True))
    lensfun_vignette = bool(adjustments.get("raw_lensfun_vignette", True))
    lensfun_ca = bool(adjustments.get("raw_lensfun_ca", True))
    # The UI values are deliberately relative.  6504 K / green 1.0 is
    # RawTherapee's neutral daylight centre; the ranges remain useful without
    # pretending the camera's as-shot multipliers are absolute UI values.
    kelvin = round(6504 * (2 ** (temperature / 100.0)))
    green = 2 ** (-tint / 200.0)
    return ("[Version]\nAppVersion=5.0\nVersion=352\n\n"
            "[Exposure]\nEnabled=true\n"
            f"Compensation={exposure:.4f}\nBrightness={brightness}\n"
            f"Contrast={contrast}\nSaturation={saturation}\n"
            f"HighlightCompr={max(0, -highlights)}\n"
            f"ShadowCompr={max(0, shadows)}\n\n"
            "[White Balance]\nEnabled=true\nSetting=Custom\n"
            f"Temperature={kelvin}\nGreen={green:.6f}\n\n"
            "[Directional Pyramid Denoising]\n"
            f"Enabled={'true' if noise_reduction else 'false'}\n"
            "Enhance=false\nMedian=true\n"
            f"Luma={noise_reduction}\nLdetail=60\n"
            "Method=Lab\nLMethod=SLI\nCMethod=AUT\nC2Method=AUTO\n"
            "SMethod=shal\nMedMethod=55\nRGBMethod=soft\nMethodMed=Lpab\n"
            "Redchro=0\nBluechro=0\nGamma=1.7\nPasses=1\n"
            "LCurve=0;\nCCCurve=0;\n\n"
            "[Common Properties for Transformations]\n"
            "Method=log\nAutoFill=true\n\n"
            f"[Rotation]\nDegree={rotation:.4f}\n\n"
            f"[Distortion]\nAmount={distortion:.6f}\n"
            f"Defish={'true' if defish else 'false'}\n"
            f"FocalLength={defish_focal:.4f}\n\n"
            f"[Perspective]\nHorizontal={perspective_h:.4f}\n"
            f"Vertical={perspective_v:.4f}\n\n"
            f"[CACorrection]\nRed={ca_red:.4f}\nBlue={ca_blue:.4f}\n\n"
            "[LensProfile]\n"
            f"LcMode={'lfauto' if lensfun else 'none'}\nLCPFile=\n"
            f"UseDistortion={'true' if lensfun_distortion else 'false'}\n"
            f"UseVignette={'true' if lensfun_vignette else 'false'}\n"
            f"UseCA={'true' if lensfun_ca else 'false'}\n\n"
            "[Vignetting Correction]\n"
            f"Amount={vignette}\nRadius=50\nStrength=1\nCenterX=0\nCenterY=0\n")


def develop(path, adjustments=None, timeout=300):
    """Develop an untouched RAW to a validated 16-bit TIFF via RawTherapee."""
    cli = find_rawtherapee(cli=True)
    if not cli:
        raise RuntimeError("RawTherapee is required to edit RAW photographs")
    path = os.path.abspath(os.fspath(path))
    adjustments = dict(adjustments or {})
    target = _development_cache_path(path, adjustments)
    profile = os.path.splitext(target)[0] + ".pp3"
    if _cache_is_authentic(target, profile):
        snap_imgsafe.safe_open(target, formats={"TIFF"},
                               max_bytes=RAW_DEVELOPMENT_MAX_BYTES)
        return target
    temporary = target + ".new.tif"
    flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
    complete = False
    try:
        with open(profile, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(_pp3_text(adjustments))
        result = subprocess_limits.run(
            [cli, "-q", "-p", profile, "-t", "-b16", "-Y", "-o", temporary,
             "-c", path], capture_output=True, text=True, timeout=timeout,
            memory_bytes=RAW_DECODER_MEMORY_BYTES, creationflags=flags)
        if result.returncode or not os.path.isfile(temporary):
            detail = (result.stderr or result.stdout or
                      "RawTherapee returned no developed image").strip()
            raise RuntimeError(detail)
        # safe_open performs a structural verification and a complete second
        # decode before the derivative becomes visible to the editor.
        snap_imgsafe.safe_open(temporary, formats={"TIFF"},
                               max_bytes=RAW_DEVELOPMENT_MAX_BYTES)
        os.replace(temporary, target)
        _write_cache_integrity(target, profile)
        complete = True
        return target
    finally:
        # The profile is a first-class provenance artifact. Keep it beside the
        # developed master after success; only failed/incomplete work is swept.
        leftovers = (temporary,) if complete else (temporary, profile)
        for leftover in leftovers:
            if os.path.isfile(leftover):
                try:
                    os.remove(leftover)
                except OSError:
                    pass


def development_artifacts(path, adjustments=None, timeout=300):
    """Return explicit typed-path inputs/outputs; no consumer derives a .pp3."""
    master = develop(path, adjustments, timeout=timeout)
    profile = os.path.splitext(master)[0] + ".pp3"
    if not os.path.isfile(profile):
        raise RuntimeError("RawTherapee development profile was not preserved")
    return {"original": os.path.abspath(os.fspath(path)),
            "profile": profile, "master": master,
            "producer": os.path.abspath(find_rawtherapee(cli=True))}


def render(path, timeout=180):
    """Return a quality cached preview developed by RawTherapee."""
    cli = find_rawtherapee(cli=True)
    if not cli:
        raise RuntimeError("RawTherapee is not installed or could not be found")
    path = os.path.abspath(os.fspath(path))
    target = _cache_path(path)
    if _cache_is_authentic(target):
        return snap_imgsafe.safe_open(target, formats={"JPEG"})
    temporary = target + ".new.jpg"
    flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
    try:
        result = subprocess_limits.run(
            [cli, "-q", "-j90", "-js3", "-Y", "-o", temporary,
             "-c", path], capture_output=True, text=True, timeout=timeout,
            memory_bytes=RAW_DECODER_MEMORY_BYTES, creationflags=flags)
        if result.returncode or not os.path.isfile(temporary):
            detail = (result.stderr or result.stdout or "RawTherapee returned no preview").strip()
            raise RuntimeError(detail)
        image = snap_imgsafe.safe_open(temporary, formats={"JPEG"})
        exif = image.info.get("exif", b"")
        image.thumbnail((1200, 1200), Image.Resampling.LANCZOS)
        image = _visible_library_preview(image)
        image.save(temporary, "JPEG", quality=90, optimize=True, exif=exif)
        os.replace(temporary, target)
        _write_cache_integrity(target)
        return snap_imgsafe.safe_open(target, formats={"JPEG"})
    finally:
        if os.path.isfile(temporary):
            try:
                os.remove(temporary)
            except OSError:
                pass


# ===== SNAPSMACK EOF =====
