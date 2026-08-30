"""Persistent preferences for the Qt SNAP SLAPPER.

A small JSON file (under C:\\snapsmack\\config_files via snap_home, or a home
fallback) holding export defaults and the Found Textures site hint. Loading is
forgiving — a missing or corrupt file just yields the defaults.
"""

import json
import os

DEFAULTS = {
    "export_quality": 95,          # JPEG/WebP quality
    "copyright_text": "",          # added on export only if the source has none
    "add_copyright_if_missing": True,
    "strip_gps": False,            # remove GPS from exported copies (never the original)
    "texture_site_hint": "foundtextures",
    "mode": "advanced",            # "normal" (Picasa-simple) or "advanced"
    "filmstrip_visible": True,     # show the folder filmstrip under the canvas
    "editor_maximized": False,     # reopen editor windows in their last maximized state
    "library_folders_visible": True,   # show the library's left folder tree
    "library_include_subfolders": False,  # recurse below selected library folder
    "library_sort": "name",        # library grid sort: name / date_new / date_old
    "library_folder_font_size": 11, # readable folder-tree text
    "library_splitter_sizes": [260, 920],  # remembered folder/grid widths
    "panomerge_xpano_path": "", # separately installed XPANO executable
}


def _path():
    try:
        import snap_home
        return snap_home.config_path("snap_slapper", "slapper_qt.json")
    except Exception:  # noqa: BLE001
        base = os.path.join(os.path.expanduser("~"), "SnapSmack", "config")
        os.makedirs(base, exist_ok=True)
        return os.path.join(base, "slapper_qt.json")


def load():
    values = dict(DEFAULTS)
    try:
        with open(_path(), "r", encoding="utf-8") as handle:
            stored = json.load(handle)
        if isinstance(stored, dict):
            for key in DEFAULTS:
                if key in stored:
                    values[key] = stored[key]
    except Exception:  # noqa: BLE001 — missing/corrupt file -> defaults
        pass
    return values


def save(values):
    merged = {key: values.get(key, DEFAULTS[key]) for key in DEFAULTS}
    try:
        path = _path()
        os.makedirs(os.path.dirname(path), exist_ok=True)
        tmp = path + ".tmp"
        with open(tmp, "w", encoding="utf-8") as handle:
            json.dump(merged, handle, indent=2)
        os.replace(tmp, path)
    except Exception:  # noqa: BLE001 — never let a settings write crash the app
        pass
    return merged

# ===== SNAPSMACK EOF =====
