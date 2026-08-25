"""Standalone SNAP SLAPPER photo manager entry point."""

import os
import sys
import tkinter as tk


BUILD_VERSION = "0.6.1-alpha"


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
    library = PhotoLibrary(root, snap_home.shared_library(), BUILD_VERSION, state_path=state_path)
    library.protocol("WM_DELETE_WINDOW", root.destroy)

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
