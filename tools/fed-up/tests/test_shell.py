"""FED UP shell smoke test: the window builds offscreen, three tabs, nothing enabled that would do nothing."""
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
import os
import sys
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
for p in (ROOT, os.path.join(ROOT, "..", "_shared")):
    if p not in sys.path:
        sys.path.insert(0, p)
os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")


class ShellTest(unittest.TestCase):
    def test_window_builds(self):
        from PySide6.QtWidgets import QApplication, QTabWidget
        from fed_up import ui
        app = QApplication.instance() or QApplication([])
        window = ui.Window()
        tabs = window.findChild(QTabWidget)
        self.assertEqual([tabs.tabText(i) for i in range(tabs.count())], ["BACK UP", "RESTORE", "ALIAS"])
        backup = tabs.widget(0)
        self.assertFalse(backup.go.isEnabled(), "BACK UP must stay disabled until function 1 exists")
        backup.handle.setText("@sean@pixelfed.social")
        self.assertIn("pixelfed.social", backup.note.text())
        backup.handle.setText("nonsense")
        self.assertIn("@name@instance", backup.note.text())
        self.assertIn("0.0.1", window.windowTitle())


if __name__ == "__main__":
    unittest.main()
# ===== SNAPSMACK EOF =====
