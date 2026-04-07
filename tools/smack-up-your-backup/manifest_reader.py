"""
Smack Up Your Backup — manifest_reader.py
Parse manifest.json from a recovery kit .tar.gz or a backup .zip.
"""

import io
import json
import tarfile
import zipfile
from dataclasses import dataclass, field
from typing import Dict, List, Optional


@dataclass
class FileRecord:
    key:         str            # manifest key (restore path)
    size:        int
    checksum:    str            # "sha256:<hex>"
    restores_to: str            # relative path from site root
    bundled:     bool = False   # True only for database.sql


@dataclass
class Manifest:
    snapsmack_version: str = ""
    export_date:       str = ""
    site_name:         str = ""
    site_url:          str = ""
    active_skin:       str = ""
    files:             Dict[str, FileRecord] = field(default_factory=dict)
    directory_structure: List[str]           = field(default_factory=list)
    database_images:   List[Dict]            = field(default_factory=list)
    stats:             Dict                  = field(default_factory=dict)
    raw:               Dict                  = field(default_factory=dict)


def _parse_dict(data: dict) -> Manifest:
    m = Manifest()
    m.raw                 = data
    m.snapsmack_version   = data.get("snapsmack_version", "")
    m.export_date         = data.get("export_date", "")
    m.site_name           = data.get("site_name", "")
    m.site_url            = data.get("site_url", "")
    m.active_skin         = data.get("active_skin", "")
    m.directory_structure = data.get("directory_structure", [])
    m.database_images     = data.get("database_images", [])
    m.stats               = data.get("stats", {})

    for key, meta in data.get("files", {}).items():
        m.files[key] = FileRecord(
            key         = key,
            size        = int(meta.get("size", 0)),
            checksum    = meta.get("checksum", ""),
            restores_to = meta.get("restores_to", key),
            bundled     = bool(meta.get("bundled", False)),
        )
    return m


def from_tar(tar_path: str) -> Optional[Manifest]:
    """Extract and parse manifest.json from a recovery kit .tar.gz."""
    try:
        with tarfile.open(tar_path, "r:gz") as tf:
            for member in tf.getmembers():
                if member.name.endswith("manifest.json"):
                    f = tf.extractfile(member)
                    if f:
                        data = json.load(f)
                        return _parse_dict(data)
    except Exception as e:
        raise RuntimeError(f"Could not read manifest from tar: {e}") from e
    raise RuntimeError("manifest.json not found in recovery kit.")


def from_zip(zip_path: str) -> Optional[Manifest]:
    """
    Extract and parse manifest.json from a backup package .zip.
    The zip contains a .tar.gz; we look inside that for manifest.json.
    """
    try:
        with zipfile.ZipFile(zip_path, "r") as zf:
            # Find the embedded .tar.gz
            tar_names = [n for n in zf.namelist() if n.endswith(".tar.gz")]
            if tar_names:
                tar_bytes = zf.read(tar_names[0])
                with tarfile.open(fileobj=io.BytesIO(tar_bytes), mode="r:gz") as tf:
                    for member in tf.getmembers():
                        if member.name.endswith("manifest.json"):
                            f = tf.extractfile(member)
                            if f:
                                data = json.load(f)
                                return _parse_dict(data)
            # Fallback: manifest.json directly in the zip
            if "manifest.json" in zf.namelist():
                data = json.loads(zf.read("manifest.json"))
                return _parse_dict(data)
    except Exception as e:
        raise RuntimeError(f"Could not read manifest from zip: {e}") from e
    raise RuntimeError("manifest.json not found in backup package.")


def from_cloud_state(state: dict) -> Optional[Manifest]:
    """
    Build a lightweight Manifest from a cloud-state JSON.
    Useful for audit and restore browsing without a full recovery kit.
    """
    m = Manifest()
    m.site_name = state.get("blog_name", "")
    m.site_url  = state.get("site_url", "")
    m.stats     = {
        "total_files": state.get("total_files", 0),
        "total_bytes": state.get("total_bytes", 0),
    }
    for key, meta in state.get("files", {}).items():
        m.files[key] = FileRecord(
            key         = key,
            size        = int(meta.get("size", 0)),
            checksum    = meta.get("checksum", ""),
            restores_to = key,
        )
    return m
