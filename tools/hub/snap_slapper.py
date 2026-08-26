"""Standalone SNAP SLAPPER photo manager entry point.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import os
import sys
import tkinter as tk
import json
import tempfile


BUILD_VERSION = "0.7.560"


def publish_backup_contract(state_path, library_root):
    """Tell SUYB exactly where SNAP SLAPPER's settings and photographs live."""
    state_dir = os.path.dirname(state_path)
    folders = []
    try:
        with open(state_path, "r", encoding="utf-8") as handle:
            value = json.load(handle)
        if isinstance(value, dict):
            folders = value.get("folders", [])
        else:
            folders = value if isinstance(value, list) else []
    except (OSError, ValueError, TypeError):
        pass
    saved_roots = []
    try:
        with open(os.path.join(state_dir, "export_settings.json"), "r", encoding="utf-8") as handle:
            export_value = json.load(handle)
        if isinstance(export_value, dict) and export_value.get("version") == 1:
            export_value = export_value.get("settings", {})
        for key in ("saved_images_dir", "projects_dir"):
            path = export_value.get(key, "") if isinstance(export_value, dict) else ""
            if isinstance(path, str) and os.path.isdir(path):
                saved_roots.append(os.path.abspath(path))
    except (OSError, ValueError, TypeError):
        pass
    contract = {
        "format": 1,
        "settings_dir": os.path.abspath(state_dir),
        "catalog_dir": os.path.abspath(library_root),
        "image_roots": [os.path.abspath(p) for p in folders
                        if isinstance(p, str) and os.path.isdir(p)],
        "saved_roots": saved_roots,
    }
    os.makedirs(state_dir, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix=".snap-contract-", suffix=".tmp",
                                             dir=state_dir, text=True)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            json.dump(contract, handle, indent=2, sort_keys=True)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, os.path.join(state_dir, "backup_contract.json"))
    except Exception:
        try:
            os.remove(temporary)
        except OSError:
            pass
        raise


def _add_shared_to_path():
    base = os.path.dirname(sys.executable) if getattr(sys, "frozen", False) \
        else os.path.dirname(os.path.abspath(__file__))
    for candidate in (os.path.join(base, "..", "_shared"), os.path.join(base, "_shared")):
        candidate = os.path.normpath(candidate)
        if os.path.isdir(candidate) and candidate not in sys.path:
            sys.path.insert(0, candidate)


_add_shared_to_path()
import snap_home
from photo_library import PhotoLibrary


def main():
    root = tk.Tk()
    root.withdraw()
    state_path = snap_home.config_path("snap-slapper", "library_folders.json")
    publish_backup_contract(state_path, snap_home.shared_library())
    library = PhotoLibrary(root, snap_home.shared_library(), BUILD_VERSION, state_path=state_path)

    def close_app():
        editor = getattr(library, "editor_window", None)
        if editor and editor.winfo_exists():
            editor.close_editor()
            if editor.winfo_exists():
                return
        root.destroy()

    library.protocol("WM_DELETE_WINDOW", close_app)

    qa_image = os.environ.get("SNAP_SLAPPER_QA_IMAGE", "")
    qa_marker = os.environ.get("SNAP_SLAPPER_QA_MARKER", "")
    if qa_image and qa_marker:
        def verify_image():
            try:
                row = {"path": qa_image, "title": "Packaged image check",
                       "description": "", "tags": []}
                library.rows = [row]
                library.filter_rows()
                library.select_photo(row)
                library.open_viewer(row)
                root.update_idletasks()
                with open(qa_marker, "w", encoding="utf-8") as handle:
                    handle.write("PASS")
            finally:
                root.after(100, root.destroy)
        root.after(300, verify_image)
    root.mainloop()


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
