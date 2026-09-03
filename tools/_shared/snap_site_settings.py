"""Typed shared per-site settings contract (SPEC v0.3).

Portable values are a read-only local mirror of the online hub. Machine-local
paths are stored separately and never transmitted. This module contains no UI
and is the one boundary all desktop tools consume.
"""

import json
import os
from datetime import datetime, timezone

import snap_home

SCHEMA = 1
PORTABLE_DEFAULTS = {
    "prompt": "",
    "max_width_landscape": 3840,
    "max_height_portrait": 2160,
    "jpeg_quality": 85,
    "image_resize_enabled": True,
    "export_sharpen": "auto",
}
LOCAL_DEFAULTS = {"handoff_dir": ""}
SHARPEN_VALUES = {"auto", "off", "low", "medium"}


def _bounded_int(value, minimum, maximum, field):
    try:
        value = int(value)
    except (TypeError, ValueError) as exc:
        raise ValueError(f"{field} must be an integer") from exc
    if not minimum <= value <= maximum:
        raise ValueError(f"{field} must be between {minimum} and {maximum}")
    return value


def validate_portable(values):
    """Return a complete, normalized portable settings dict."""
    raw = dict(values or {})
    out = dict(PORTABLE_DEFAULTS)
    unknown = set(raw) - set(out)
    if unknown:
        raise ValueError("unknown portable setting(s): " + ", ".join(sorted(unknown)))
    out.update(raw)
    out["prompt"] = str(out["prompt"] or "")
    out["max_width_landscape"] = _bounded_int(
        out["max_width_landscape"], 320, 16384, "max_width_landscape")
    out["max_height_portrait"] = _bounded_int(
        out["max_height_portrait"], 320, 16384, "max_height_portrait")
    out["jpeg_quality"] = _bounded_int(out["jpeg_quality"], 50, 100, "jpeg_quality")
    out["image_resize_enabled"] = bool(out["image_resize_enabled"])
    out["export_sharpen"] = str(out["export_sharpen"] or "auto").lower()
    if out["export_sharpen"] not in SHARPEN_VALUES:
        raise ValueError("export_sharpen must be auto, off, low, or medium")
    return out


def validate_local(values):
    raw = dict(values or {})
    unknown = set(raw) - set(LOCAL_DEFAULTS)
    if unknown:
        raise ValueError("unknown local setting(s): " + ", ".join(sorted(unknown)))
    path = str(raw.get("handoff_dir", "") or "").strip()
    return {"handoff_dir": os.path.abspath(path) if path else ""}


def _local_path():
    return snap_home.config_path("snap-hq", "site_settings.json")


def _read_json(path, default):
    try:
        with open(path, encoding="utf-8") as handle:
            data = json.load(handle)
        return data if isinstance(data, dict) else default
    except Exception:
        return default


def _atomic_write(path, data):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        json.dump(data, handle, indent=2, ensure_ascii=False)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(tmp, path)


def load_local(site_url):
    key = snap_home.site_key(site_url)
    store = _read_json(_local_path(), {})
    row = (store.get("sites") or {}).get(key, {})
    return validate_local(row.get("settings") or {})


def save_local(site_url, values):
    """Save machine-local settings only. This function performs no network I/O."""
    key = snap_home.site_key(site_url)
    store = _read_json(_local_path(), {})
    store["schema"] = SCHEMA
    sites = store.setdefault("sites", {})
    sites[key] = {
        "site_url": str(site_url).strip(),
        "settings": validate_local(values),
        "updated_at": datetime.now(timezone.utc).isoformat(),
    }
    _atomic_write(_local_path(), store)
    return sites[key]["settings"]


def handoff_paths(site_url, create=False):
    parent = load_local(site_url)["handoff_dir"]
    if not parent:
        return {"handoff_dir": "", "upload": "", "done": ""}
    upload = os.path.join(parent, "upload")
    done = os.path.join(parent, "done")
    if create:
        os.makedirs(upload, exist_ok=True)
        os.makedirs(done, exist_ok=True)
    return {"handoff_dir": parent, "upload": upload, "done": done}


def combined(site_url, portable=None, *, synced_at=None, offline=True):
    """The read model exposed to tools; ownership remains visibly separated."""
    return {
        "site_url": str(site_url).strip(),
        "portable": validate_portable(portable or {}),
        "local": load_local(site_url),
        "sync": {"synced_at": synced_at, "offline": bool(offline)},
    }

# ===== SNAPSMACK EOF =====
