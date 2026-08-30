"""Local-only SnapSmack blog-copy preparation contract.

This module never performs network I/O. It creates a collision-safe derivative
and an auditable sidecar manifest in a Hub profile's workstation staging folder.
"""

from datetime import datetime, timezone
import json
import os

from PIL import Image

import photo_manager


CONTRACT_VERSION = 1


def profile_policy(profile):
    extras = dict(profile.get("extras") or {})
    capabilities = dict(extras.get("capabilities") or {})
    staging = (extras.get("local_uploads_dir") or
               extras.get("slapper_staging_dir") or "").strip()
    width = int(capabilities.get("max_image_width") or extras.get("max_image_width") or 2048)
    height = int(capabilities.get("max_image_height") or extras.get("max_image_height") or 2048)
    quality = int(capabilities.get("preferred_quality") or extras.get("preferred_quality") or 90)
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
        f"Destination: {policy['staging_dir'] or '(not configured in THE HUB)'}\n"
        f"Copy: {policy['max_width']} × {policy['max_height']} maximum, "
        f"{policy['extension'].lstrip('.').upper()}, quality {policy['quality']}\n"
        f"Colour: {policy['colour_space']}\n"
        f"Metadata: preserve embedded metadata; GPS "
        f"{'removed' if policy['strip_gps'] else 'preserved'}")


def prepare(document, profile, copyright_text=""):
    policy = profile_policy(profile)
    destination = policy["staging_dir"]
    if not destination:
        raise ValueError("This blog has no local uploads folder. Configure it in THE HUB first.")
    if not os.path.isdir(destination):
        raise ValueError(f"The local uploads folder is unavailable: {destination}")
    if not os.access(destination, os.W_OK):
        raise ValueError(f"The local uploads folder is read-only: {destination}")
    image = document.render()
    image.thumbnail((policy["max_width"], policy["max_height"]), Image.Resampling.LANCZOS)
    stem = os.path.splitext(os.path.basename(document.source_path))[0]
    target = photo_manager.unique_path(
        os.path.join(destination, stem + "_blog" + policy["extension"]))
    temporary = target + ".preparing"
    save_options = {}
    image_format = {".jpg": "JPEG", ".jpeg": "JPEG", ".png": "PNG",
                    ".webp": "WEBP"}[policy["extension"]]
    if image_format in {"JPEG", "WEBP"}:
        save_options.update(quality=policy["quality"], optimize=True)
    try:
        photo_manager.save_with_metadata(
            image, temporary, document.source_path, copyright_text,
            strip_gps=policy["strip_gps"], format=image_format, **save_options)
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
