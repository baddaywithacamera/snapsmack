"""Generate cached RAW thumbnails through a separately installed RawTherapee."""

import glob
import hashlib
import io
import hmac
import json
import math
import os
import re
import secrets
import shutil
import struct
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


LIBRARY_PREVIEW_TARGET = 118   # middle grey on the contact sheet


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
    # Middle grey, not 31%%. The old 80 target was set against RawTherapee's
    # deliberately flat render; on a camera preview it left a snow scene
    # reading dim on the contact sheet. Thumbnails only -- never the
    # photograph, the developed master or the export.
    if 0 < median < LIBRARY_PREVIEW_TARGET:
        gamma = (math.log(LIBRARY_PREVIEW_TARGET / 255.0)
                 / math.log(median / 255.0))
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
    # RawTherapee's Exposure black level works in raw units, at full depth
    # and before the compositor. The UI keeps the familiar -100..100 scale;
    # 100 maps to a deliberately conservative 4096 so the strongest setting
    # crushes convincingly without destroying the file.
    raw_black = round(max(-100.0, min(100.0,
                      float(adjustments.get('raw_black', 0.0)))) * 40.96)
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
    auto_levels = bool(adjustments.get("raw_auto_levels", False))
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
            f"Auto={'true' if auto_levels else 'false'}\n"
            "Clip=0.02\n"
            f"Black={raw_black}\n"
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


# A camera raw carries a finished JPEG preview -- it is what the camera shows on
# its own rear screen. Reading it costs milliseconds. Developing the raw through
# RawTherapee to produce the same picture costs about 1.4 seconds, and the
# filmstrip was doing exactly that, on two threads, to fill 160-pixel tiles: 61
# photographs took the better part of a minute.
#
# So: thumbnails come from the embedded preview, and the full development is
# kept for the editor view, where the photograph is actually being looked at and
# the raw processing is the point.
#
# The preview is the camera's own rendering, not a developed raw, so it carries
# the picture style baked in. At thumbnail size that is invisible, and it is
# arguably more faithful to what the photographer saw on the camera.

EMBEDDED_MIN_PIXELS = 240          # ignore the tiny 160x120 IFD thumbnail
# Raw files put their preview near the front: in Sean's CR2s the real one
# ends 0.45 MB in, inside an 11.4 MB file. Reading the whole file to find
# it meant 695 MB of reading for a 61-shot folder, which on OneDrive-backed
# storage was the entire cost -- the first version of this looked fast only
# because a benchmark had already warmed the OS cache. So: read a little,
# and only read more if that genuinely found nothing.
EMBEDDED_SCAN_STEPS = (2 * 1024 * 1024, 8 * 1024 * 1024, 48 * 1024 * 1024)


def _embedded_jpeg_candidates(path):
    """Every plausible JPEG stored inside a raw file, largest first.

    Raw formats are TIFF containers and each vendor hides its previews behind
    different tags, so rather than teach this four dialects it scans for JPEG
    start/end markers. Sensor data contains those byte pairs by coincidence, so
    a candidate is only offered if its header actually parses as a JPEG with a
    real frame header -- the first version of this took the largest run and
    came back with 10.5 MB of noise.
    """
    try:
        size = os.path.getsize(path)
    except OSError:
        return []

    for limit in EMBEDDED_SCAN_STEPS:
        want = min(size, limit)
        try:
            with open(path, 'rb') as handle:
                data = handle.read(want)
        except OSError:
            return []

        found = []
        start = data.find(b'\xff\xd8\xff')
        while start != -1:
            end = data.find(b'\xff\xd9', start + 3)
            if end == -1:
                break
            blob = data[start:end + 2]
            # A real JPEG carries a start-of-frame marker. Noise almost never
            # does, and the first version of this returned 10.5 MB of sensor
            # data because it trusted the longest run.
            if len(blob) > 2048 and re.search(b'\xff[\xc0\xc1\xc2]', blob[:len(blob) // 2]):
                found.append(blob)
            start = data.find(b'\xff\xd8\xff', end + 2)

        if found:
            found.sort(key=len, reverse=True)
            return found
        if want >= size:
            break           # the whole file has been looked at
    return []


# Orientation 1-8 -> the PIL transposes that straighten it, in order.
_ORIENTATION_STEPS = {
    1: (),
    2: (Image.Transpose.FLIP_LEFT_RIGHT,),
    3: (Image.Transpose.ROTATE_180,),
    4: (Image.Transpose.FLIP_TOP_BOTTOM,),
    5: (Image.Transpose.FLIP_LEFT_RIGHT, Image.Transpose.ROTATE_90),
    6: (Image.Transpose.ROTATE_270,),
    7: (Image.Transpose.FLIP_LEFT_RIGHT, Image.Transpose.ROTATE_270),
    8: (Image.Transpose.ROTATE_90,),
}


def raw_orientation(path):
    """The Orientation tag from the raw's own TIFF header, or 1.

    The embedded preview JPEG usually carries no EXIF of its own, so rotating
    it by its own tags does nothing and a portrait frame comes back landscape.
    The camera records the orientation once, in the raw's IFD0, which is what
    RawTherapee reads -- so read the same place.
    """
    try:
        with open(path, 'rb') as handle:
            head = handle.read(65536)
    except OSError:
        return 1
    if len(head) < 16:
        return 1
    if head[:2] == b'II':
        order = '<'
    elif head[:2] == b'MM':
        order = '>'
    else:
        return 1
    try:
        offset = struct.unpack_from(order + 'I', head, 4)[0]
        if offset + 2 > len(head):
            return 1
        count = struct.unpack_from(order + 'H', head, offset)[0]
        for index in range(min(count, 512)):
            entry = offset + 2 + index * 12
            if entry + 12 > len(head):
                break
            tag, kind = struct.unpack_from(order + 'HH', head, entry)
            if tag == 0x0112 and kind == 3:      # Orientation, SHORT
                value = struct.unpack_from(order + 'H', head, entry + 8)[0]
                return value if 1 <= value <= 8 else 1
    except struct.error:
        return 1
    return 1


def embedded_preview(path, max_size):
    """A thumbnail from the raw's own embedded JPEG, or None if there isn't one.

    ``max_size`` is the longest edge wanted. The decoder is asked for a
    reduced-scale image rather than decoding full size and shrinking, which is
    most of the saving.
    """
    for blob in _embedded_jpeg_candidates(path):
        try:
            # snap_imgsafe is the decode boundary; nothing bypasses it.
            probe = snap_imgsafe.safe_open(blob, formats={"JPEG"})
        except Exception:  # noqa: BLE001 - try the next candidate
            continue
        if min(probe.size) < EMBEDDED_MIN_PIXELS:
            continue        # the little IFD thumbnail, not the real preview
        try:
            with Image.open(io.BytesIO(blob)) as handle:
                handle.draft('RGB', (max_size, max_size))
                image = handle.convert('RGB')
        except Exception:  # noqa: BLE001 - the verified copy is still good
            image = probe.convert('RGB')
        image = ImageOps.exif_transpose(image)
        # The preview JPEG normally has no EXIF, so the line above is a no-op
        # and a portrait frame would come back landscape. The camera wrote the
        # orientation into the raw's own TIFF header; apply that.
        for step in _ORIENTATION_STEPS.get(raw_orientation(path), ()):
            image = image.transpose(step)
        # Same contact-sheet lift the RawTherapee path has always applied. A
        # camera preview of a correctly exposed snow scene still reads dark at
        # thumbnail size, and skipping this is why the grid went dim the moment
        # thumbnails started coming from the embedded preview.
        image = _visible_library_preview(image)
        image.thumbnail((max_size, max_size), Image.Resampling.LANCZOS)
        return image
    return None


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
