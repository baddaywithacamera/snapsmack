"""Typed shared per-site settings contract (SPEC v0.4).

Portable values are a read-only local mirror of the online hub. Machine-local
paths are stored separately and never transmitted. This module contains no UI
and is the one boundary all desktop tools consume.

Image size (SPEC image-sizing-4k, §9b; Sean's ruling 2026-09-01):
  ``max_long_edge`` is the CANONICAL, semantic source of truth for the stored
  size of a photo — the longest edge is capped at this value in EITHER
  orientation (a square ``max_long_edge`` x ``max_long_edge`` box), never
  enlarged. The fleet standard is 3840.

  The orientation pair ``max_width_landscape`` / ``max_height_portrait`` is kept
  DERIVED (always symmetric ``long`` x ``long``) so a reader that has not upgraded
  still gets a sane cap that agrees with the canonical box. A legacy store carrying
  only the pair migrates to ``max_long_edge = max(landscape, portrait)`` — the
  LARGER edge, so a portrait's cap is promoted, never shrunk (Sean's ruling:
  portraits are not second-class). Normalization is idempotent: validating an
  already-validated dict returns it unchanged. The pair is retired only once every
  client reads ``max_long_edge`` (SPEC §9f: migrate, don't delete in one release).

Provenance: this file is Codex's uncommitted _shared work (schema 1) ported onto
dev alongside the sizing engine (snap_sizing / snap_sharpen) and extended to
schema 2 with the canonical size field. RECONCILE-WITH-CODEX before any merge that
also carries his copy.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import json
import os
from datetime import datetime, timezone

import snap_home

SCHEMA = 2  # was 1; added max_long_edge canonical size field
DEFAULT_MAX_LONG_EDGE = 3840  # fleet standard (Sean, 2026-09-01): symmetric 3840
PORTABLE_DEFAULTS = {
    "prompt": "",
    "max_long_edge": DEFAULT_MAX_LONG_EDGE,
    "max_width_landscape": 3840,
    "max_height_portrait": 2160,
    "jpeg_quality": 85,
    "image_resize_enabled": True,
    "export_sharpen": "auto",
}
LOCAL_DEFAULTS = {"handoff_dir": ""}
SHARPEN_VALUES = {"auto", "off", "low", "medium"}
SIZE_MIN, SIZE_MAX = 320, 16384


def _bounded_int(value, minimum, maximum, field):
    try:
        value = int(value)
    except (TypeError, ValueError) as exc:
        raise ValueError(f"{field} must be an integer") from exc
    if not minimum <= value <= maximum:
        raise ValueError(f"{field} must be between {minimum} and {maximum}")
    return value


def validate_portable(values):
    """Return a complete, normalized portable settings dict.

    Size normalization (SPEC image-sizing-4k §9b) — idempotent:
      * Canonical ``max_long_edge`` present -> it is authoritative.
      * Only the legacy pair present (schema-1 store) -> migrate up:
        ``max_long_edge = max(landscape, portrait)`` (promote, never shrink).
      In both cases the legacy pair is then written symmetric (long, long) so an
      un-upgraded reader caps to the same box the canonical value describes.
    """
    raw = dict(values or {})
    out = dict(PORTABLE_DEFAULTS)
    unknown = set(raw) - set(out)
    if unknown:
        raise ValueError("unknown portable setting(s): " + ", ".join(sorted(unknown)))
    out.update(raw)
    out["prompt"] = str(out["prompt"] or "")

    # --- size: canonical max_long_edge is the source of truth; pair is derived ---
    if raw.get("max_long_edge") not in (None, ""):
        long_edge = _bounded_int(out["max_long_edge"], SIZE_MIN, SIZE_MAX, "max_long_edge")
    else:
        # Legacy (schema-1) store: derive the canonical from the larger stored edge.
        landscape = _bounded_int(
            out["max_width_landscape"], SIZE_MIN, SIZE_MAX, "max_width_landscape")
        portrait = _bounded_int(
            out["max_height_portrait"], SIZE_MIN, SIZE_MAX, "max_height_portrait")
        long_edge = max(landscape, portrait)
    out["max_long_edge"] = long_edge
    out["max_width_landscape"] = long_edge
    out["max_height_portrait"] = long_edge

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
