"""Local-only SnapSmack blog-copy preparation contract.

This module never performs network I/O. It creates a collision-safe derivative
and an auditable sidecar manifest in a Hub profile's workstation staging folder.
"""

from datetime import datetime, timezone
import json
import os

from PIL import Image

import photo_manager
import slapper_provenance
import snap_site_settings


CONTRACT_VERSION = 1


def suggested_stem(document):
    """Human filename suggestion, without project or image extensions."""
    remembered = str(getattr(document, "output_title", "") or "").strip()
    if remembered:
        return remembered
    naming_path = (getattr(document, "project_path", "") or
                   getattr(document, "recorded_source_path", "") or
                   document.source_path)
    stem = os.path.splitext(os.path.basename(naming_path))[0]
    # A project may have been named from an image as ``title.tif.slapper``.
    # Do not offer the user a misleading ``title.tif.jpg`` blog filename.
    nested, extension = os.path.splitext(stem)
    if extension.lower() in {".jpg", ".jpeg", ".png", ".tif", ".tiff",
                             ".webp", ".bmp", ".raw", ".dng", ".cr2",
                             ".cr3", ".nef", ".orf", ".arw", ".rw2"}:
        stem = nested
    return stem or "blog-copy"


def safe_filename_stem(value):
    """Keep a human title while excluding Windows path/control characters."""
    stem = "".join("_" if char in '<>:"/\\|?*' or ord(char) < 32 else char
                   for char in str(value or "")).strip().rstrip(". ")
    if not stem:
        raise ValueError("Enter a filename for the blog copy.")
    return stem


def profile_policy(profile):
    extras = dict(profile.get("extras") or {})
    capabilities = dict(extras.get("capabilities") or {})
    portable = dict(profile.get("portable") or {})
    # The machine-local workflow belongs to SNAP HQ's shared site settings.
    # SYBU already consumes this contract; SNAP SLAPPER must not look only for
    # obsolete profile extras and incorrectly report the same blog unconfigured.
    handoff = snap_site_settings.handoff_paths(
        profile.get("site_url", ""), create=False)
    staging = (handoff.get("upload") or
               extras.get("local_uploads_dir") or
               extras.get("slapper_staging_dir") or "").strip()
    # Current blog/HUB profiles use the server's actual setting names.  Keep
    # support for the proposed capability aliases, but never ignore the live
    # portable mirror and silently substitute 2048/90.
    width = int(capabilities.get("max_image_width") or
                extras.get("max_image_width") or
                portable.get("max_width_landscape") or
                portable.get("max_long_edge") or 2048)
    height = int(capabilities.get("max_image_height") or
                 extras.get("max_image_height") or
                 portable.get("max_height_portrait") or
                 portable.get("max_long_edge") or 2048)
    quality = int(capabilities.get("preferred_quality") or
                  extras.get("preferred_quality") or
                  portable.get("jpeg_quality") or 90)
    extension = str(capabilities.get("preferred_extension") or
                    extras.get("preferred_extension") or ".jpg").lower()
    if extension not in {".jpg", ".jpeg", ".png", ".webp"}:
        raise ValueError(f"The blog profile requests an unsupported format: {extension}")
    contract = int(capabilities.get("contract_version") or
                   extras.get("contract_version") or CONTRACT_VERSION)
    if contract != CONTRACT_VERSION:
        raise ValueError(
            f"CMS/tool version mismatch: profile contract {contract}, "
            f"SNAP SLAPPER supports {CONTRACT_VERSION}.")
    return {
        "contract_version": contract,
        "staging_dir": os.path.abspath(staging) if staging else "",
        "completed_dir": os.path.abspath(handoff.get("completed", ""))
        if handoff.get("completed") else "",
        "max_width": max(1, min(30000, width)),
        "max_height": max(1, min(30000, height)),
        "quality": max(40, min(100, quality)),
        "extension": extension,
        "strip_gps": bool(capabilities.get("strip_gps", extras.get("strip_gps", False))),
        "colour_space": str(capabilities.get("colour_space") or "sRGB"),
    }


def describe(profile):
    policy = profile_policy(profile)
    return (
        f"Blog: {profile.get('name') or profile.get('site_url')}\n"
        f"Upload folder: {policy['staging_dir'] or '(not configured in SNAP HQ)'}\n"
        f"Completed folder: {policy['completed_dir'] or '(not configured in SNAP HQ)'}\n"
        f"Copy: fits inside {policy['max_width']} × {policy['max_height']} px, "
        f"aspect ratio kept (no crop), "
        f"{policy['extension'].lstrip('.').upper()}, quality {policy['quality']}\n"
        f"Colour: {policy['colour_space']}\n"
        f"Metadata: preserve embedded metadata; GPS "
        f"{'removed' if policy['strip_gps'] else 'preserved'}")


def prepare(document, profile, copyright_text="", destination_override="",
            filename_stem=""):
    policy = profile_policy(profile)
    destination = os.path.abspath(destination_override) if destination_override else policy["staging_dir"]
    if not destination:
        raise ValueError("This blog has no local uploads folder. Configure it in THE HUB first.")
    if not os.path.isdir(destination):
        raise ValueError(f"The local uploads folder is unavailable: {destination}")
    if not os.access(destination, os.W_OK):
        raise ValueError(f"The local uploads folder is read-only: {destination}")
    # Render to the actual publishing ceiling. Rendering the full RAW master
    # and only then shrinking it needlessly consumes enough memory/CPU to
    # starve Qt and make Windows report the editor as unresponsive.
    image = document.render((policy["max_width"], policy["max_height"]))
    image.thumbnail((policy["max_width"], policy["max_height"]), Image.Resampling.LANCZOS)
    stem = safe_filename_stem(filename_stem) if filename_stem else \
        safe_filename_stem(suggested_stem(document) + "_blog")
    target = photo_manager.unique_path(
        os.path.join(destination, stem + policy["extension"]))
    temporary = target + ".preparing"
    save_options = {}
    image_format = {".jpg": "JPEG", ".jpeg": "JPEG", ".png": "PNG",
                    ".webp": "WEBP"}[policy["extension"]]
    if image_format in {"JPEG", "WEBP"}:
        save_options.update(quality=policy["quality"], optimize=True)
    try:
        provenance_records = slapper_provenance.export_operations(
            document.layers, image.size)
        photo_manager.save_with_metadata(
            image, temporary, document.source_path, copyright_text,
            strip_gps=policy["strip_gps"], format=image_format,
            provenance_records=provenance_records, **save_options)
        os.replace(temporary, target)
    finally:
        if os.path.exists(temporary):
            try:
                os.remove(temporary)
            except OSError:
                pass
    manifest = {
        "schema": CONTRACT_VERSION,
        "status": "prepared",
        "prepared_at": datetime.now(timezone.utc).isoformat(),
        "site_name": profile.get("name", ""),
        "site_url": profile.get("site_url", ""),
        "source_name": os.path.basename(document.source_path),
        "source_sha256": photo_manager.content_hash(document.source_path),
        "derivative_name": os.path.basename(target),
        "derivative_sha256": photo_manager.content_hash(target),
        "dimensions": list(image.size),
        "format": image_format,
        "quality": policy["quality"],
        "colour_space": policy["colour_space"],
        "gps_removed": policy["strip_gps"],
    }
    manifest_path = target + ".snapstage.json"
    photo_manager.atomic_json(manifest_path, manifest)
    return target, manifest_path, manifest


# ===== SNAPSMACK EOF =====
