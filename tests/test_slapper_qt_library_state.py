import os
import sys
import tempfile
import unittest
from unittest import mock

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
sys.path.insert(0, os.path.join(ROOT, "tools", "hub"))

from slapper_qt import library_state


class LibraryStateTests(unittest.TestCase):
    def test_reads_legacy_metadata_and_albums_without_conversion(self):
        with tempfile.TemporaryDirectory() as root, mock.patch.object(library_state, "_state_dir", return_value=root):
            state = library_state.LibraryState()
            photo = os.path.join(root, "one.jpg")
            state.set_photo(photo, favorite=True, rating=4, tags="car, red")
            state.add_album("Cars", [photo])
            reopened = library_state.LibraryState()
            self.assertEqual(reopened.photo(photo), {"favorite": True, "rating": 4, "tags": "car, red"})
            self.assertEqual(reopened.albums()["Cars"], [photo])

    def test_remap_preserves_metadata_and_album(self):
        with tempfile.TemporaryDirectory() as root, mock.patch.object(library_state, "_state_dir", return_value=root):
            state = library_state.LibraryState()
            old, new = os.path.join(root, "old.jpg"), os.path.join(root, "new.jpg")
            state.set_photo(old, rating=5)
            state.add_album("Best", [old])
            state.remap([old], [new], remove_old=True)
            self.assertEqual(state.photo(new)["rating"], 5)
            self.assertEqual(state.albums()["Best"], [new])


class LibraryWindowParityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
        from PySide6.QtWidgets import QApplication
        cls.app = QApplication.instance() or QApplication([])

    def test_library_restores_organizer_controls_and_multi_select(self):
        from PIL import Image
        from PySide6.QtWidgets import QAbstractItemView
        from slapper_qt import library_window, prefs

        with tempfile.TemporaryDirectory() as root, \
                mock.patch.object(library_state, "_state_dir", return_value=root), \
                mock.patch.object(prefs, "load", return_value={}), \
                mock.patch.object(prefs, "save"):
            photo = os.path.join(root, "photo.jpg")
            Image.new("RGB", (32, 24), "red").save(photo)
            window = library_window.LibraryWindow()
            window._pool.clear()
            window.load_folder(root)
            self.app.processEvents()

            self.assertEqual(window.list.selectionMode(), QAbstractItemView.ExtendedSelection)
            self.assertEqual([action.text() for action in window.menuBar().actions()],
                             ["Library", "Organize", "View"])
            labels = [action.text() for action in window.organize_menu.actions()]
            for required in ("Add selection to album…", "Export selection…",
                             "Find exact duplicates", "Move selection to SNAP SLAPPER Trash…"):
                self.assertIn(required, labels)
            self.assertIsNotNone(window.info_dock)
            window.close()

    def test_metadata_filter_uses_legacy_state(self):
        from PIL import Image
        from slapper_qt import library_window, prefs

        with tempfile.TemporaryDirectory() as root, \
                mock.patch.object(library_state, "_state_dir", return_value=root), \
                mock.patch.object(prefs, "load", return_value={}), \
                mock.patch.object(prefs, "save"):
            favorite = os.path.join(root, "favorite.jpg")
            ordinary = os.path.join(root, "ordinary.jpg")
            Image.new("RGB", (16, 16), "red").save(favorite)
            Image.new("RGB", (16, 16), "blue").save(ordinary)
            state = library_state.LibraryState()
            state.set_photo(favorite, favorite=True, rating=5, tags="keeper")
            window = library_window.LibraryWindow()
            window._pool.clear()
            window.load_folder(root)
            index = window.meta_filter.findData("favorite")
            window.meta_filter.setCurrentIndex(index)
            self.app.processEvents()
            self.assertFalse(window._items[favorite].isHidden())
            self.assertTrue(window._items[ordinary].isHidden())
            window.close()


if __name__ == "__main__":
    unittest.main()
