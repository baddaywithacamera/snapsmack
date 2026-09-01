"""SLAP BACK: independent, offline SNAP SLAPPER project recovery."""

import argparse
import hashlib
import json
import os
import shutil
import sys
import zipfile

MIMETYPE = b"application/vnd.snapsmack.slapper+zip"
MAX_ENTRY = 8 * 1024 * 1024 * 1024
REQUIRED = {"mimetype", "manifest.json", "README.txt", "project.json",
            "metadata/checksums.json", "metadata/original-exif.json",
            "metadata/provenance.json", "metadata/dependencies.json",
            "schemas/project-schema.json", "previews/composite.tif",
            "previews/thumbnail.jpg"}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def inspect_project(path, verify=True):
    report = {"path": os.path.abspath(path), "valid": False, "errors": [], "warnings": []}
    try:
        with zipfile.ZipFile(path) as archive:
            infos = archive.infolist(); names = [item.filename for item in infos]
            if len(names) != len(set(names)):
                raise ValueError("duplicate ZIP entries")
            for info in infos:
                name = info.filename.replace("\\", "/")
                if (name.startswith("/") or name.startswith("../") or "/../" in name or
                        info.file_size > MAX_ENTRY):
                    raise ValueError(f"unsafe entry: {name}")
            missing = sorted(REQUIRED.difference(names))
            if missing:
                raise ValueError("missing required entries: " + ", ".join(missing))
            if archive.read("mimetype") != MIMETYPE:
                raise ValueError("wrong mimetype")
            manifest = json.loads(archive.read("manifest.json"))
            original = manifest["original"]; original_path = original["archive_path"]
            if original_path not in names:
                raise ValueError("embedded original is missing")
            if verify and digest(archive.read(original_path)) != original["sha256"]:
                raise ValueError("embedded original hash mismatch")
            checks = json.loads(archive.read("metadata/checksums.json"))["sha256"]
            if verify:
                for name, expected in checks.items():
                    if name not in names or digest(archive.read(name)) != expected:
                        raise ValueError(f"checksum mismatch: {name}")
            report.update({"valid": True, "format_version": manifest.get("format_version"),
                           "original": original, "layers": manifest.get("layer_order", []),
                           "composite": manifest.get("full_resolution_composite")})
    except Exception as error:
        report["errors"].append(str(error))
    return report


def collision_safe(path, overwrite=False):
    if overwrite or not os.path.exists(path):
        return path
    root, extension = os.path.splitext(path); number = 2
    while os.path.exists(f"{root} ({number}){extension}"):
        number += 1
    return f"{root} ({number}){extension}"


def extract_original(project, output, overwrite=False):
    report = inspect_project(project)
    if not report["valid"]:
        raise ValueError("; ".join(report["errors"]))
    os.makedirs(output, exist_ok=True)
    filename = os.path.basename(str(report["original"]["original_filename"])).replace("\x00", "")
    target = collision_safe(os.path.join(output, filename or "recovered-original"), overwrite)
    with zipfile.ZipFile(project) as archive, archive.open(report["original"]["archive_path"]) as source:
        with open(target, "wb") as destination:
            shutil.copyfileobj(source, destination, 1024 * 1024)
    with open(target, "rb") as recovered:
        if digest(recovered.read()) != report["original"]["sha256"]:
            os.remove(target)
            raise ValueError("recovered original failed its SHA-256 check")
    return target


def extract_all(project, output, overwrite=False):
    os.makedirs(output, exist_ok=True); recovered = []
    root = os.path.abspath(output)
    with zipfile.ZipFile(project) as archive:
        for info in archive.infolist():
            name = info.filename.replace("\\", "/")
            if name.endswith("/") or name.startswith("/") or ".." in name.split("/"):
                continue
            target = os.path.abspath(os.path.join(root, *name.split("/")))
            if os.path.commonpath((root, target)) != root:
                continue
            os.makedirs(os.path.dirname(target), exist_ok=True)
            target = collision_safe(target, overwrite)
            with archive.open(info) as source, open(target, "wb") as destination:
                shutil.copyfileobj(source, destination, 1024 * 1024)
            recovered.append(target)
    return recovered


def export_flat(project, output, overwrite=False):
    from PIL import Image
    report = inspect_project(project)
    if not report["valid"]:
        raise ValueError("; ".join(report["errors"]))
    target = collision_safe(output, overwrite)
    with zipfile.ZipFile(project) as archive, archive.open(report["composite"]) as source:
        with Image.open(source) as image:
            extension = os.path.splitext(target)[1].lower()
            if extension in {".jpg", ".jpeg"}:
                image.convert("RGB").save(target, quality=95)
            elif extension == ".png":
                image.save(target, "PNG")
            elif extension in {".tif", ".tiff"}:
                image.save(target, "TIFF", compression="tiff_deflate")
            else:
                raise ValueError("Flat export must be JPEG, PNG, or TIFF")
    return target


def main(argv=None):
    parser = argparse.ArgumentParser(prog="slap-back", description="Offline .slapper recovery")
    parser.add_argument("project"); parser.add_argument("--json", action="store_true")
    parser.add_argument("--extract-original", metavar="FOLDER")
    parser.add_argument("--extract-all", metavar="FOLDER")
    parser.add_argument("--export-flat", metavar="FILE")
    parser.add_argument("--overwrite", action="store_true")
    args = parser.parse_args(argv); report = inspect_project(args.project)
    if args.extract_original:
        report["recovered_original"] = extract_original(args.project, args.extract_original, args.overwrite)
    if args.extract_all:
        report["recovered_entries"] = extract_all(args.project, args.extract_all, args.overwrite)
    if args.export_flat:
        report["flattened_export"] = export_flat(args.project, args.export_flat, args.overwrite)
    if args.json:
        print(json.dumps(report, indent=2, sort_keys=True))
    else:
        print("VALID" if report["valid"] else "INVALID", report["path"])
        for error in report["errors"]:
            print("ERROR:", error)
        if report["valid"]:
            print("Original:", report["original"]["original_filename"])
            print("SHA-256:", report["original"]["sha256"])
            print("Layers:", len(report["layers"]))
    return 0 if report["valid"] else 2


if __name__ == "__main__":
    sys.exit(main())

# ===== SNAPSMACK EOF =====
