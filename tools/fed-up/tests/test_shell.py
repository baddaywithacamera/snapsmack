"""FED UP window smoke test: builds offscreen, three tabs, BACK UP arms only on a
real handle, RESTORE arms only after a PREVIEW, config never touches the user's
real config folder."""
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
import os
import sys
import tempfile
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
for p in (ROOT, os.path.join(ROOT, "..", "_shared"), os.path.join(ROOT, "..", "unzucker")):
    if p not in sys.path:
        sys.path.insert(0, p)
os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")


class ShellTest(unittest.TestCase):
    def setUp(self):
        from fed_up import config
        self.tmp = tempfile.mkdtemp(prefix="fedup-ui-")
        self._orig_path = config._path
        config._path = lambda: os.path.join(self.tmp, "config.json")

    def tearDown(self):
        from fed_up import config
        config._path = self._orig_path

    def test_window_builds(self):
        from PySide6.QtWidgets import QApplication, QTabWidget
        from fed_up import ui
        app = QApplication.instance() or QApplication([])
        window = ui.Window()
        tabs = window.findChild(QTabWidget)
        self.assertEqual([tabs.tabText(i) for i in range(tabs.count())], ["BACK UP", "RESTORE", "ALIAS"])
        backup = tabs.widget(0)
        self.assertFalse(backup.go.isEnabled(), "BACK UP stays off until a handle is typed")
        backup.handle.setCurrentText("@sean@pixelfed.social")
        self.assertTrue(backup.go.isEnabled())
        self.assertIn("pixelfed.social", backup.note.text())
        backup.handle.setCurrentText("nonsense")
        self.assertFalse(backup.go.isEnabled())
        self.assertIn("@name@instance", backup.note.text())
        restore = tabs.widget(1)
        self.assertFalse(restore.go.isEnabled(), "RESTORE stays off until PREVIEW has counted the posts")
        self.assertIn("0.1.0", window.windowTitle())
        # remembering an account writes config in the temp folder only
        backup.handle.setCurrentText("@leo@masto.test")
        backup._remember()
        self.assertTrue(os.path.isfile(os.path.join(self.tmp, "config.json")))
        from fed_up import config
        self.assertEqual(config.load()["accounts"][0], "leo@masto.test")


if __name__ == "__main__":
    unittest.main()
# ===== SNAPSMACK EOF =====
