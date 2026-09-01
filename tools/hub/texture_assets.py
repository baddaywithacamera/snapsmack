"""Shared, reference-only texture asset library for SNAP SLAPPER."""

import copy
import hashlib
import json
import os
import re
import tempfile
import sys

try:
    import snap_home
except ImportError:
    shared = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "_shared"))
    if shared not in sys.path:
        sys.path.insert(0, shared)
    import snap_home


def root_dir():
    path = os.path.join(snap_home.shared_library(), "assets", "textures")
    os.makedirs(path, exist_ok=True)
    return path


def files_dir():
    path = os.path.join(root_dir(), "files")
    os.makedirs(path, exist_ok=True)
    return path


def index_path():
    return os.path.join(root_dir(), "index.json")


def _read():
    try:
        with open(index_path(), "r", encoding="utf-8") as handle:
            value = json.load(handle)
        return value if isinstance(value, dict) else {}
    except (FileNotFoundError, ValueError, OSError):
        return {}


def _write(value):
    directory = root_dir()
    fd, temporary = tempfile.mkstemp(prefix="textures-", suffix=".json", dir=directory)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(value, handle, indent=2, ensure_ascii=False, sort_keys=True)
        os.replace(temporary, index_path())
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def key_for(provenance):
    texture_id = provenance.get("texture_id") or provenance.get("id")
    source_site = (provenance.get("source_site") or "").lower()
    if texture_id is not None and "foundtextures" in source_site:
        return f"foundtextures:{texture_id}"
    seed = "|".join(str(provenance.get(field) or "") for field in
                    ("source_page_url", "source_url", "title"))
    return "external:" + hashlib.sha256(seed.encode("utf-8")).hexdigest()[:24]


def reference(provenance):
    first_party = "foundtextures" in (provenance.get("source_site") or "").lower()
    return {
        "key": key_for(provenance),
        "name": provenance.get("title") or "Texture",
        "origin": "first-party" if first_party else "third-party",
        "source_url": provenance.get("source_page_url") or provenance.get("source_url") or "",
        "license_status": provenance.get("rights_status") or provenance.get("licence") or "unknown",
        "restore_url": (provenance.get("highres_download_url") or "") if first_party else "",
    }


def register(provenance, path):
    absolute = os.path.abspath(path)
    if not os.path.isfile(absolute):
        raise FileNotFoundError(absolute)
    ref = reference(provenance)
    records = _read()
    records[ref["key"]] = {**copy.deepcopy(ref), "path": absolute}
    _write(records)
    return ref


def resolve(ref):
    if not isinstance(ref, dict) or not ref.get("key"):
        return ""
    record = _read().get(ref["key"], {})
    path = record.get("path", "")
    return path if os.path.isfile(path) else ""


def safe_filename(ref, url=""):
    tail = os.path.basename((url or "").split("?", 1)[0]) or "texture.jpg"
    tail = re.sub(r"[^A-Za-z0-9._-]", "-", tail).strip(".-") or "texture.jpg"
    prefix = re.sub(r"[^A-Za-z0-9._-]", "-", ref.get("key", "texture"))
    return os.path.join(files_dir(), f"{prefix}-{tail}")

# ===== SNAPSMACK EOF =====
