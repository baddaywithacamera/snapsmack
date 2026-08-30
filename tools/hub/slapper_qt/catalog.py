"""Persistent SNAP SLAPPER catalogue shared with the earlier desktop library."""

import os
import tempfile

import photo_manager


class Catalog:
    """Compatibility layer over the existing versioned JSON catalogue files."""

    def __init__(self, directory=None):
        if directory is None:
            try:
                import snap_home
                directory = snap_home.config_dir("snap-slapper")
            except Exception:  # noqa: BLE001
                root = (os.environ.get("SNAPSMACK_HOME") or r"C:\snapsmack")
                directory = os.path.join(
                    root, "config_files", "snap-slapper")
        try:
            os.makedirs(directory, exist_ok=True)
        except OSError:
            if os.environ.get("QT_QPA_PLATFORM") != "offscreen":
                raise
            directory = tempfile.mkdtemp(prefix="slapper_catalog_test_")
        self.directory = directory
        self.metadata_path = os.path.join(directory, "photo_metadata.json")
        self.albums_path = os.path.join(directory, "albums.json")
        self.folders_path = os.path.join(directory, "library_folders.json")
        self.trash_path = os.path.join(directory, "trash_manifest.json")
        self.trash_root = os.path.join(directory, "trash")
        self.metadata = photo_manager.load_versioned(
            self.metadata_path, "photos", {})
        self.albums = photo_manager.load_versioned(
            self.albums_path, "albums", {})

    @staticmethod
    def key(path):
        return os.path.normcase(os.path.abspath(path))

    def details(self, path):
        value = self.metadata.get(self.key(path), {})
        if not isinstance(value, dict):
            value = {}
        try:
            rating = max(0, min(5, int(value.get("rating", 0))))
        except (TypeError, ValueError):
            rating = 0
        tags = value.get("tags", "")
        if isinstance(tags, list):
            tags = ", ".join(str(tag) for tag in tags)
        return {
            "favorite": bool(value.get("favorite", False)),
            "rating": rating,
            "tags": str(tags or ""),
        }

    def set_details(self, paths, favorite=None, rating=None, add_tags=None,
                    replace_tags=None):
        for path in paths:
            key = self.key(path)
            value = self.details(path)
            if favorite is not None:
                value["favorite"] = bool(favorite)
            if rating is not None:
                value["rating"] = max(0, min(5, int(rating)))
            if replace_tags is not None:
                value["tags"] = str(replace_tags).strip()
            if add_tags:
                existing = [tag.strip() for tag in value["tags"].split(",")
                            if tag.strip()]
                additions = [tag.strip() for tag in str(add_tags).split(",")
                             if tag.strip()]
                seen = set()
                value["tags"] = ", ".join(
                    tag for tag in existing + additions
                    if not (tag.lower() in seen or seen.add(tag.lower())))
            if value["favorite"] or value["rating"] or value["tags"]:
                self.metadata[key] = value
            else:
                self.metadata.pop(key, None)
        photo_manager.save_versioned(self.metadata_path, "photos", self.metadata)

    def move_path(self, source, target):
        old, new = self.key(source), self.key(target)
        if old in self.metadata:
            self.metadata[new] = self.metadata.pop(old)
            photo_manager.save_versioned(
                self.metadata_path, "photos", self.metadata)
        changed = False
        for name, paths in self.albums.items():
            replacement = [target if self.key(path) == old else path for path in paths]
            if replacement != paths:
                self.albums[name] = replacement
                changed = True
        if changed:
            self.save_albums()

    def copy_path(self, source, target):
        value = self.details(source)
        self.set_details(
            [target], favorite=value["favorite"], rating=value["rating"],
            replace_tags=value["tags"])

    def add_to_album(self, name, paths):
        clean = str(name).strip()
        if not clean:
            raise ValueError("Album name is empty")
        current = list(self.albums.get(clean, []))
        known = {self.key(path) for path in current}
        for path in paths:
            key = self.key(path)
            if key not in known:
                current.append(path)
                known.add(key)
        self.albums[clean] = current
        self.save_albums()

    def save_albums(self):
        photo_manager.save_versioned(self.albums_path, "albums", self.albums)


# ===== SNAPSMACK EOF =====
