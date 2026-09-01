"""Qt library parity state using the original SNAP SLAPPER file formats."""

import glob
import json
import os
import tempfile

import photo_manager


def _state_dir():
    try:
        import snap_home
        return os.path.dirname(snap_home.config_path("snap-slapper", "library_folders.json"))
    except Exception:
        root = os.path.join(tempfile.gettempdir(), "SnapSmack", "config", "snap-slapper")
        os.makedirs(root, exist_ok=True)
        return root


class LibraryState:
    def __init__(self):
        self.root = _state_dir()
        self.folders_path = os.path.join(self.root, "library_folders.json")
        self.metadata_path = os.path.join(self.root, "photo_metadata.json")
        self.albums_path = os.path.join(self.root, "albums.json")
        self.tools_path = os.path.join(self.root, "external_tools.json")
        self.trash_path = os.path.join(self.root, "trash_manifest.json")
        self.trash_root = os.path.join(self.root, "trash")
        self.metadata = photo_manager.load_versioned(self.metadata_path, "photos", {})

    @staticmethod
    def key(path):
        return os.path.normcase(os.path.abspath(path))

    def photo(self, path):
        value = self.metadata.get(self.key(path), {})
        if not isinstance(value, dict):
            value = {}
        try:
            rating = max(0, min(5, int(value.get("rating", 0))))
        except (TypeError, ValueError):
            rating = 0
        tags = value.get("tags", "")
        if isinstance(tags, list):
            tags = ", ".join(map(str, tags))
        return {"favorite": bool(value.get("favorite", False)),
                "rating": rating, "tags": str(tags or "")}

    def set_photo(self, path, *, favorite=False, rating=0, tags=""):
        value = {"favorite": bool(favorite), "rating": max(0, min(5, int(rating))),
                 "tags": str(tags or "").strip()}
        if value["favorite"] or value["rating"] or value["tags"]:
            self.metadata[self.key(path)] = value
        else:
            self.metadata.pop(self.key(path), None)
        self.save_metadata()

    def update_many(self, paths, update):
        for path in paths:
            value = self.photo(path)
            update(value)
            self.set_photo(path, **value)

    def save_metadata(self):
        photo_manager.save_versioned(self.metadata_path, "photos", self.metadata)

    def folders(self):
        rows = photo_manager.load_versioned(self.folders_path, "folders", [])
        return [os.path.abspath(path) for path in rows
                if isinstance(path, str) and os.path.isdir(path)]

    def save_folders(self, folders):
        clean = sorted({os.path.abspath(path) for path in folders if os.path.isdir(path)}, key=str.lower)
        photo_manager.save_versioned(self.folders_path, "folders", clean)

    def albums(self):
        value = photo_manager.load_versioned(self.albums_path, "albums", {})
        return {str(name): [str(path) for path in paths if isinstance(path, str)]
                for name, paths in value.items() if isinstance(paths, list)}

    def save_albums(self, albums):
        photo_manager.save_versioned(self.albums_path, "albums", albums)

    def add_album(self, name, paths):
        albums = self.albums()
        albums[str(name)] = list(dict.fromkeys(albums.get(str(name), []) + list(paths)))
        self.save_albums(albums)

    def external_tools(self):
        custom = photo_manager.load_versioned(self.tools_path, "custom", [])
        tools = []
        roots = [os.environ.get("ProgramFiles", r"C:\Program Files"),
                 os.environ.get("ProgramFiles(x86)", r"C:\Program Files (x86)"),
                 os.environ.get("LOCALAPPDATA", "")]
        patterns = [("Adobe Photoshop", "Adobe/Adobe Photoshop */Photoshop.exe"),
                    ("Affinity Photo", "Affinity/Photo */Photo.exe"),
                    ("GIMP", "GIMP */bin/gimp*.exe"),
                    ("darktable", "darktable/bin/darktable.exe"),
                    ("RawTherapee", "RawTherapee/*/rawtherapee.exe")]
        for name, pattern in patterns:
            matches = [match for root in roots if root
                       for match in glob.glob(os.path.join(root, *pattern.split("/")))]
            if matches:
                tools.append({"name": name, "path": sorted(matches)[-1]})
        tools.extend(item for item in custom if isinstance(item, dict)
                     and os.path.isfile(item.get("path", "")))
        return list({os.path.normcase(item["path"]): item for item in tools}.values())

    def add_external_tool(self, name, path):
        custom = photo_manager.load_versioned(self.tools_path, "custom", [])
        custom.append({"name": name, "path": path, "custom": True})
        photo_manager.save_versioned(self.tools_path, "custom", custom)

    def remap(self, sources, outputs, remove_old=False):
        mapping = dict(zip(sources, outputs))
        for source, target in mapping.items():
            old = self.key(source)
            if old in self.metadata:
                self.metadata[self.key(target)] = dict(self.metadata[old])
                if remove_old:
                    self.metadata.pop(old, None)
        albums = self.albums()
        for name, paths in albums.items():
            albums[name] = list(dict.fromkeys(mapping.get(path, path) for path in paths))
        self.save_metadata()
        self.save_albums(albums)

    def backup(self, destination):
        photo_manager.atomic_json(destination, {"version": 1, "metadata": self.metadata,
            "albums": self.albums(), "folders": self.folders()})

# ===== SNAPSMACK EOF =====
