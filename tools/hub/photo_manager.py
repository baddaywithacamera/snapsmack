"""Safe, testable file and organizer operations for SNAP SLAPPER.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import hashlib
import errno
import json
import os
import shutil
import tempfile
import time

from PIL import Image, ImageFilter, ImageOps, ImageStat, PngImagePlugin

EXIF_COPYRIGHT = 33432
EXIF_GPS_INFO = 34853
RAW_EXTENSIONS = {".dng", ".nef", ".cr2", ".cr3", ".arw", ".orf", ".rw2", ".raf"}


def reject_json_constant(token):
    raise ValueError(f"Invalid JSON number: {token}")


def same_file(left, right):
    """Compare paths safely, including aliases to an existing file."""
    try:
        return os.path.samefile(left, right)
    except OSError:
        return os.path.normcase(os.path.realpath(left)) == \
            os.path.normcase(os.path.realpath(right))


def fsync_file(path):
    with open(path, "r+b") as handle:
        os.fsync(handle.fileno())


def save_with_metadata(output, target, source_path, copyright_text="", strip_gps=False,
                       **options):
    """Atomically save a derivative while retaining source metadata."""
    if same_file(target, source_path):
        raise ValueError("SNAP SLAPPER will not overwrite an original photograph.")
    with Image.open(source_path) as source:
        source_format = source.format
        xmp = source.info.get("xmp")
        oriented = ImageOps.exif_transpose(source)
        exif = oriented.getexif()
        if strip_gps and EXIF_GPS_INFO in exif:
            del exif[EXIF_GPS_INFO]
        if copyright_text and not str(exif.get(EXIF_COPYRIGHT, "")).strip():
            exif[EXIF_COPYRIGHT] = copyright_text.strip()
        if exif:
            options["exif"] = exif.tobytes()
        icc = source.info.get("icc_profile")
        if icc:
            options["icc_profile"] = icc
        dpi = source.info.get("dpi")
        if dpi:
            options["dpi"] = dpi
    image_format = options.pop("format", None)
    if not image_format:
        image_format = Image.registered_extensions().get(os.path.splitext(target)[1].lower())
    if not image_format:
        image_format = source_format
    if xmp:
        if image_format in {"JPEG", "WEBP"}:
            options["xmp"] = xmp
        elif image_format == "PNG" and "pnginfo" not in options:
            pnginfo = PngImagePlugin.PngInfo()
            pnginfo.add_itxt("XML:com.adobe.xmp", xmp.decode("utf-8", errors="replace"))
            options["pnginfo"] = pnginfo
    target_dir = os.path.dirname(os.path.abspath(target))
    os.makedirs(target_dir, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix=".snap-writing-", suffix=".tmp",
                                             dir=target_dir)
    os.close(descriptor)
    try:
        output.save(temporary, format=image_format, **options)
        fsync_file(temporary)
        os.replace(temporary, target)
    except Exception:
        try:
            os.remove(temporary)
        except OSError:
            pass
        raise


def unique_path(path):
    if not os.path.exists(path):
        return path
    stem, ext = os.path.splitext(path)
    number = 2
    while os.path.exists(f"{stem}_{number}{ext}"):
        number += 1
    return f"{stem}_{number}{ext}"


def atomic_json(path, value):
    target = os.path.abspath(path)
    target_dir = os.path.dirname(target)
    os.makedirs(target_dir, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix=".snap-json-", suffix=".tmp",
                                             dir=target_dir, text=True)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            json.dump(value, handle, indent=2, sort_keys=True, allow_nan=False)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, target)
    except Exception:
        try:
            os.close(descriptor)
        except OSError:
            pass
        try:
            os.remove(temporary)
        except OSError:
            pass
        raise


def load_json(path, default):
    try:
        with open(path, "r", encoding="utf-8") as handle:
            value = json.load(handle, parse_constant=reject_json_constant)
        return value
    except (OSError, ValueError, TypeError):
        return default


def load_versioned(path, key, default):
    """Read a version-1 state envelope while accepting the legacy bare value."""
    value = load_json(path, default)
    expected_type = type(default)
    if isinstance(value, dict):
        if "version" in value:
            if value.get("version") != 1:
                return default
            payload = value.get(key)
            return payload if isinstance(payload, expected_type) else default
        if key in value and isinstance(value.get(key), expected_type):
            return value[key]
    return value if isinstance(value, expected_type) else default


def save_versioned(path, key, value):
    atomic_json(path, {"version": 1, key: value})


def atomic_copy(source, target, prefix=".snap-copy-"):
    """Copy to a sibling temporary file, then publish the complete copy atomically."""
    if same_file(source, target):
        raise ValueError("Source and destination refer to the same photograph.")
    target_dir = os.path.dirname(os.path.abspath(target))
    os.makedirs(target_dir, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix=prefix, suffix=".tmp", dir=target_dir)
    os.close(descriptor)
    try:
        shutil.copy2(source, temporary)
        fsync_file(temporary)
        os.replace(temporary, target)
    except Exception:
        try:
            os.remove(temporary)
        except OSError:
            pass
        raise
    return target


def atomic_move(source, target, prefix=".snap-move-"):
    """Move atomically on one volume; safely copy-then-remove across volumes."""
    if same_file(source, target):
        raise ValueError("Source and destination refer to the same photograph.")
    try:
        os.replace(source, target)
        return target
    except OSError as error:
        if error.errno != errno.EXDEV and getattr(error, "winerror", None) != 17:
            raise
    atomic_copy(source, target, prefix=prefix)
    if os.path.getsize(source) != os.path.getsize(target) or \
            content_hash(source) != content_hash(target):
        try:
            os.remove(target)
        except OSError:
            pass
        raise OSError("Cross-volume move verification failed; the source was kept.")
    try:
        os.remove(source)
    except Exception:
        try:
            os.remove(target)
        except OSError:
            pass
        raise
    return target


def copy_files(paths, destination):
    os.makedirs(destination, exist_ok=True)
    outputs = []
    for source in paths:
        target = unique_path(os.path.join(destination, os.path.basename(source)))
        outputs.append(atomic_copy(source, target))
    return outputs


def copy_for_external_edit(source):
    """Create an atomic sibling working copy for a third-party editor."""
    stem, extension = os.path.splitext(source)
    target = unique_path(stem + "_edit" + extension)
    return atomic_copy(source, target, prefix=".snap-edit-")


def move_files(paths, destination):
    os.makedirs(destination, exist_ok=True)
    outputs = []
    for source in paths:
        target = unique_path(os.path.join(destination, os.path.basename(source)))
        outputs.append(atomic_move(source, target))
    return outputs


def trash_files(paths, trash_root, manifest_path):
    os.makedirs(trash_root, exist_ok=True)
    manifest = load_versioned(manifest_path, "entries", [])
    entries = []
    try:
        for source in paths:
            target = unique_path(os.path.join(trash_root, os.path.basename(source)))
            atomic_move(source, target, prefix=".snap-trash-")
            entry = {"original": source, "trashed": target, "time": int(time.time())}
            manifest.append(entry)
            entries.append(entry)
        save_versioned(manifest_path, "entries", manifest)
        return entries
    except Exception as error:
        rollback_failures = []
        for entry in reversed(entries):
            try:
                if os.path.exists(entry["original"]):
                    raise FileExistsError(entry["original"])
                atomic_move(entry["trashed"], entry["original"], prefix=".snap-rollback-")
            except Exception as rollback_error:
                rollback_failures.append(str(rollback_error))
        if rollback_failures:
            raise OSError("Trash operation failed and some photographs could not be "
                          "returned: " + "; ".join(rollback_failures)) from error
        raise


def restore_last_trash(manifest_path):
    manifest = load_versioned(manifest_path, "entries", [])
    restored = []
    restored_entry = None
    while manifest:
        entry = manifest.pop()
        source = entry.get("trashed", "")
        original = entry.get("original", "")
        if os.path.isfile(source) and original:
            os.makedirs(os.path.dirname(original), exist_ok=True)
            target = unique_path(original)
            atomic_move(source, target, prefix=".snap-restore-")
            restored.append(target)
            restored_entry = entry
            break
    try:
        save_versioned(manifest_path, "entries", manifest)
    except Exception as error:
        if restored and restored_entry:
            try:
                atomic_move(restored[0], restored_entry["trashed"], prefix=".snap-restore-rollback-")
            except Exception as rollback_error:
                raise OSError("Restore manifest failed and the photograph could not be returned "
                              "to Trash: " + str(rollback_error)) from error
        raise
    return restored


def export_files(paths, destination, max_size=2048, quality=90, sharpen=False,
                 copyright_text="", strip_gps=False):
    os.makedirs(destination, exist_ok=True)
    outputs = []
    for source in paths:
        if os.path.splitext(source)[1].lower() in RAW_EXTENSIONS:
            raise ValueError("SNAP SLAPPER does not process RAW photographs. Open this file "
                             "with RawTherapee or darktable.")
        stem = os.path.splitext(os.path.basename(source))[0]
        target = unique_path(os.path.join(destination, stem + ".jpg"))
        with Image.open(source) as image:
            output = ImageOps.exif_transpose(image).convert("RGB")
            if max_size:
                output.thumbnail((max_size, max_size), Image.Resampling.LANCZOS)
            if sharpen:
                output = output.filter(ImageFilter.UnsharpMask(radius=1.2, percent=100, threshold=3))
            save_with_metadata(output, target, source, copyright_text,
                               strip_gps=strip_gps,
                               format="JPEG", quality=quality, optimize=True)
        outputs.append(target)
    return outputs


def rotate_files(paths, degrees):
    """Create rotated derivatives without ever rewriting the source photographs."""
    numeric_degrees = float(degrees)
    if not numeric_degrees.is_integer():
        raise ValueError("Rotation must be 90, 180, or 270 degrees.")
    normalized = int(numeric_degrees) % 360
    if normalized not in {90, 180, 270}:
        raise ValueError("Rotation must be 90, 180, or 270 degrees.")
    labels = {90: "left", 180: "180", 270: "right"}
    outputs = []
    for path in paths:
        if os.path.splitext(path)[1].lower() in RAW_EXTENSIONS:
            raise ValueError("SNAP SLAPPER does not rotate RAW photographs. Open this file "
                             "with RawTherapee or darktable.")
        with Image.open(path) as image:
            output = ImageOps.exif_transpose(image)
            output = output.rotate(degrees, expand=True)
            image_format = image.format
        save_args = {"quality": 95, "optimize": True} if image_format in {"JPEG", "WEBP"} else {}
        stem, extension = os.path.splitext(path)
        target = unique_path(f"{stem}_rotated_{labels[normalized]}{extension}")
        save_with_metadata(output, target, path, format=image_format, **save_args)
        outputs.append(target)
    return outputs


def content_hash(path, chunk_size=1024 * 1024):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        while True:
            chunk = handle.read(chunk_size)
            if not chunk:
                return digest.hexdigest()
            digest.update(chunk)


def recovery_path(recovery_dir, source_path):
    """Return a stable, non-identifying recovery filename for a source path."""
    identity = os.path.normcase(os.path.realpath(source_path)).encode("utf-8")
    return os.path.join(recovery_dir, hashlib.sha256(identity).hexdigest() + ".slapper-recovery")


def duplicate_groups(paths):
    by_size = {}
    for path in paths:
        try:
            by_size.setdefault(os.path.getsize(path), []).append(path)
        except OSError:
            pass
    groups = []
    for same_size in by_size.values():
        if len(same_size) < 2:
            continue
        by_hash = {}
        for path in same_size:
            by_hash.setdefault(content_hash(path), []).append(path)
        groups.extend(group for group in by_hash.values() if len(group) > 1)
    return groups


def quality_flags(paths, blur_threshold=18.0, dark_threshold=28.0):
    results = []
    for path in paths:
        try:
            with Image.open(path) as image:
                sample = ImageOps.grayscale(ImageOps.exif_transpose(image))
                sample.thumbnail((512, 512), Image.Resampling.BILINEAR)
                edges = sample.filter(ImageFilter.FIND_EDGES)
                blur_score = ImageStat.Stat(edges).var[0]
                brightness = ImageStat.Stat(sample).mean[0]
            reasons = []
            if blur_score < blur_threshold:
                reasons.append("possibly blurry")
            if brightness < dark_threshold:
                reasons.append("very dark")
            if reasons:
                results.append({"path": path, "reasons": reasons,
                                "sharpness": round(blur_score, 1), "brightness": round(brightness, 1)})
        except Exception:
            continue
    return results

# ===== SNAPSMACK EOF =====
