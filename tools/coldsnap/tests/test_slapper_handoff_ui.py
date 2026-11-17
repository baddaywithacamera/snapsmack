"""Rendered checks for COLD TAKE's SNAP SLAPPER handoff states.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
from pathlib import Path
import sys
import tempfile

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
os.environ["SNAPSMACK_HOME"] = tempfile.mkdtemp(prefix="coldsnap-slapper-ui-")
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
sys.path.insert(0, str(ROOT.parent / "_shared"))

from PIL import Image  # noqa: E402
from PySide6.QtWidgets import QApplication  # noqa: E402

import sumna_offline as O  # noqa: E402


SESSION_ROOT = tempfile.mkdtemp(prefix="coldsnap-slapper-sessions-")
REAL_STORE = O.SessionStore


class TestStore(REAL_STORE):
    def __init__(self, root=SESSION_ROOT):
        super().__init__(root)


O.SessionStore = TestStore

from coldsnap_qt import theme  # noqa: E402
from coldsnap_qt.mode_take import TakeMode  # noqa: E402


def main():
    app = QApplication.instance() or QApplication([])
    app.setStyleSheet(theme.stylesheet())
    with tempfile.TemporaryDirectory(prefix="coldsnap-slapper-images-") as folder:
        source = Path(folder) / "farm.jpg"
        edited = Path(folder) / "farm_edit.jpg"
        Image.new("RGB", (800, 600), "#663322").save(source)
        Image.new("RGB", (800, 600), "#223366").save(edited)
        mode = TakeMode(lambda: {})
        mode._bucket = [O.DraftImage(
            local_path=str(edited), original_path=str(source), filename=edited.name,
            slapper_base_path=str(source), slapper_base_filename=source.name,
            slapper_edited_path=str(edited), slapper_use_edited=True,
            slapper_base_edit_state={"edit_flip_h": True})]
        mode._selected_photo_idx = 0
        mode.photos_sec.set_expanded(True)
        mode._refresh_bucket()
        mode.show()
        for width, height in ((1920, 1080), (1440, 900), (1024, 768)):
            mode.resize(width, height)
            app.processEvents()
            assert mode.slapper_btn.isVisible()
            assert mode.slapper_state_panel.isVisible()
            assert mode.slapper_use_edit.isChecked()
            assert mode.queue_btn.isVisible()
            assert mode.rail.send_btn.isVisible()
            assert mode.right_rail.geometry().right() <= mode.width()
        mode._choose_slapper_version(False)
        assert mode._bucket[0].local_path == str(source)
        assert mode._bucket[0].edit_flip_h is True
        mode._choose_slapper_version(True)
        assert mode._bucket[0].local_path == str(edited)
        assert mode._bucket[0].edit_flip_h is False
        for state in ("waiting", "returned", "not-installed", "launch-error"):
            mode._slapper_launch_error = "test launch failure"
            mode._set_slapper_state(state)
            app.processEvents()
            assert mode.slapper_state_panel.isVisible()
            assert mode.slapper_state_title.text()
        mode.close()
    print("PASS - handoff states rendered at 1920, 1440, and 1024 widths")


if __name__ == "__main__":
    main()


# ===== SNAPSMACK EOF =====
