"""Safe, testable file and organizer operations for SNAP SLAPPER."""

import hashlib
import json
import os
import shutil
import time

from PIL import Image, ImageFilter, ImageOps, ImageStat


def unique_path(path):
    if not os.path.exists(path):
        return path
    stem, ext = os.path.splitext(path)
    number = 2
    while os.path.exists(f"{stem}_{number}{ext}"):
        number += 1
    return f"{stem}_{number}{ext}"


def atomic_json(path, value):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    temporary = path + ".tmp"
    with open(temporary, "w", encoding="utf-8") as handle:
        json.dump(value, handle, indent=2, sort_keys=True)
    os.replace(temporary, path)


def load_json(path, default):
    try:
        with open(path, "r", encoding="utf-8") as handle:
            value = json.load(handle)
        return value
    except (OSError, ValueError, TypeError):
        return default


def copy_files(paths, destination):
    os.makedirs(destination, exist_ok=True)
    outputs = []
    for source in paths:
        target = unique_path(os.path.join(destination, os.path.basename(source)))
        shutil.copy2(source, target)
        outputs.append(target)
    return outputs


def move_files(paths, destination):
    os.makedirs(destination, exist_ok=True)
    outputs = []
    for source in paths:
        target = unique_path(os.path.join(destination, os.path.basename(source)))
        outputs.append(shutil.move(source, target))
    return outputs


def trash_files(paths, trash_root, manifest_path):
    os.makedirs(trash_root, exist_ok=True)
    manifest = load_json(manifest_path, [])
    if not isinstance(manifest, list):
        manifest = []
    entries = []
    for source in paths:
        target = unique_path(os.path.join(trash_root, os.path.basename(source)))
        shutil.move(source, target)
        entry = {"original": source, "trashed": target, "time": int(time.time())}
        manifest.append(entry)
        entries.append(entry)
    atomic_json(manifest_path, manifest)
    return entries


def restore_last_trash(manifest_path):
    manifest = load_json(manifest_path, [])
    if not isinstance(manifest, list):
        manifest = []
    restored = []
    while manifest:
        entry = manifest.pop()
        source = entry.get("trashed", "")
        original = entry.get("original", "")
        if os.path.isfile(source) and original:
            os.makedirs(os.path.dirname(original), exist_ok=True)
            target = unique_path(original)
            shutil.move(source, target)
            restored.append(target)
            break
    atomic_json(manifest_path, manifest)
    return restored


def export_files(paths, destination, max_size=2048, quality=90, sharpen=False):
    os.makedirs(destination, exist_ok=True)
    outputs = []
    for source in paths:
        stem = os.path.splitext(os.path.basename(source))[0]
        target = unique_path(os.path.join(destination, stem + ".jpg"))
        with Image.open(source) as image:
            output = ImageOps.exif_transpose(image).convert("RGB")
            if max_size:
                output.thumbnail((max_size, max_size), Image.Resampling.LANCZOS)
            if sharpen:
                output = output.filter(ImageFilter.UnsharpMask(radius=1.2, percent=100, threshold=3))
            output.save(target, "JPEG", quality=quality, optimize=True)
        outputs.append(target)
    return outputs


def rotate_files(paths, degrees):
    for path in paths:
        with Image.open(path) as image:
            output = ImageOps.exif_transpose(image)
            output = output.rotate(degrees, expand=True)
            save_args = {"quality": 95, "optimize": True} if image.format in {"JPEG", "WEBP"} else {}
            output.save(path, **save_args)


def content_hash(path, chunk_size=1024 * 1024):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        while True:
            chunk = handle.read(chunk_size)
            if not chunk:
                return digest.hexdigest()
            digest.update(chunk)


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
