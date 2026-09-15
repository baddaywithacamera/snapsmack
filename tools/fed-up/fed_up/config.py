"""FED UP — settings. One JSON under the suite's shared config folder
(C:\\snapsmack\\config_files\\fed-up\\config.json via snap_home). Holds the
accounts you back up, the archive root, the mirror folder and the B2 bucket
name. Secrets (the B2 application key) live in the shared credential store,
never here.
"""
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
from __future__ import annotations

import json
import os

DEFAULTS = {
    "archive_root": "",       # empty = <home>/fed-up/archives
    "accounts": [],           # ["name@host", ...]
    "mirror_folder": "",
    "b2_bucket": "",
    "want_media": True,
    "want_graph": True,
    "want_replies": True,
    "last_account": "",
    "restore_site": "",
    "restore_category": "",
    "restore_album": "",
    "restore_copyright": "",
}


def _path() -> str:
    try:
        import snap_home
        return snap_home.config_path("fed-up", "config.json")
    except Exception:
        base = os.path.join(os.environ.get("LOCALAPPDATA") or os.path.expanduser("~"), "snapsmack", "fed-up")
        os.makedirs(base, exist_ok=True)
        return os.path.join(base, "config.json")


def default_archive_root() -> str:
    try:
        import snap_home
        return os.path.join(snap_home.home(), "fed-up", "archives")
    except Exception:
        return os.path.join(os.path.expanduser("~"), "snapsmack", "fed-up", "archives")


def load() -> dict:
    cfg = dict(DEFAULTS)
    try:
        with open(_path(), encoding="utf-8") as fh:
            data = json.load(fh)
        if isinstance(data, dict):
            cfg.update({k: v for k, v in data.items() if k in DEFAULTS})
    except (OSError, ValueError):
        pass
    if not cfg["archive_root"]:
        cfg["archive_root"] = default_archive_root()
    return cfg


def save(cfg: dict) -> None:
    p = _path()
    os.makedirs(os.path.dirname(p), exist_ok=True)
    tmp = p + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump({k: cfg.get(k, DEFAULTS[k]) for k in DEFAULTS}, fh, indent=2, sort_keys=True)
    os.replace(tmp, p)

# ===== SNAPSMACK EOF =====
