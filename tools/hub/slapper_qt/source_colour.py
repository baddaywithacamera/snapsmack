"""Truthful source depth/profile inspection without converting image pixels."""

import io

from PIL import Image, ImageCms


def _profile_name(blob):
    if not blob:
        return ""
    try:
        profile = ImageCms.ImageCmsProfile(io.BytesIO(blob))
        return ImageCms.getProfileDescription(profile).strip()
    except Exception:  # malformed profiles are reported, never trusted
        return "Embedded ICC (unreadable description)"


def inspect_source(path):
    with Image.open(path) as image:
        tags = getattr(image, "tag_v2", {})
        bits = tags.get(258) if hasattr(tags, "get") else None
        if isinstance(bits, (tuple, list)):
            depth = max(int(value) for value in bits)
        elif bits:
            depth = int(bits)
        elif image.mode.startswith("I;16"):
            depth = 16
        elif image.mode in {"I", "F"}:
            depth = 32
        else:
            depth = 8
        sample = tags.get(339) if hasattr(tags, "get") else None
        sample_format = "float" if sample == 3 or image.mode == "F" else "integer"
        model = ("Grayscale" if image.mode in {"1", "L", "LA", "I", "F"} or
                 image.mode.startswith("I;16") else "RGB")
        icc = image.info.get("icc_profile") or b""
        profile = _profile_name(icc)
        return {
            "format": image.format or "Unknown",
            "model": model,
            "mode": image.mode,
            "width": image.width,
            "height": image.height,
            "bits_per_channel": depth,
            "sample_format": sample_format,
            "icc_profile": icc,
            "profile_name": profile,
            "profile_source": "embedded" if icc else "assumed",
        }


def workspace_label(info):
    profile = (f"{info['profile_name']} (embedded ICC)" if info.get("icc_profile") else
               "Unprofiled (assumed sRGB)")
    depth = f"{info['bits_per_channel']}-bit/channel"
    if info.get("sample_format") == "float":
        depth += " float"
    return f"{info['model']} · {depth} · {profile} · Working: 8-bit sRGB (legacy engine)"


# ===== SNAPSMACK EOF =====
