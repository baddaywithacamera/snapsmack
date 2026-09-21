"""SMACKPRESS Qt shell: builds offscreen; a WordPress post pulled through the
real SourcePane lands in COLD SNAP's editor with local pictures; after the
batch says it was posted, MARK MIGRATED can find the URL."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))
sys.path.insert(0, str(HERE))

import smackpress_qt  # noqa: E402,F401  (puts coldsnap + _shared on sys.path)
from test_wp_source import POST, PNG  # noqa: E402


def fake_fetch(url, timeout=60):
    return PNG


class ShellTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        from PySide6.QtWidgets import QApplication
        cls.app = QApplication.instance() or QApplication([])
        cls.tmp = tempfile.TemporaryDirectory()
        os.environ["SNAPSMACK_HOME"] = cls.tmp.name
        import sumna_offline as O
        cls._root_patch = mock.patch.object(O, "SESSIONS_ROOT", os.path.join(cls.tmp.name, "sessions"))
        cls._root_patch.start()
        from smackpress import config as sp_config
        cls._cfg_patch = mock.patch.object(sp_config, "_app_dir", lambda: Path(cls.tmp.name))
        cls._cfg_patch.start()

    @classmethod
    def tearDownClass(cls):
        cls._root_patch.stop(); cls._cfg_patch.stop(); cls.tmp.cleanup()

    def _window(self):
        from smackpress_qt.main_window import MainWindow
        import sumna_offline as O
        w = MainWindow()
        # SessionStore's default root is bound at import time; point this window's
        # rail at the temp store so no test batch ever lands in a real sob_sessions.
        w.take.rail.store = O.SessionStore(os.path.join(self.tmp.name, "sessions"))
        w.take.rail.session = None
        w.connect_panel.config["url"] = "https://pixhellated.ca"
        w.connect_panel.config["smackpress_key"] = "k" * 64
        return w

    def test_window_builds_with_coldsnap_editor_inside(self):
        w = self._window()
        import config as cold_config
        from coldsnap_qt.mode_take import TakeMode
        from coldsnap_qt.connect_panel import ConnectPanel
        self.assertTrue(hasattr(cold_config, "load"),
                        "COLD SNAP's config module must win the frozen import name")
        self.assertIsInstance(w.take, TakeMode)
        self.assertIsInstance(w.connect_panel, ConnectPanel)
        self.assertFalse(w.source.pull_btn.isEnabled(), "nothing selected → PULL is off")

    def test_pull_across_lands_in_the_editor_with_local_pictures(self):
        from PySide6.QtWidgets import QListWidgetItem
        from PySide6.QtCore import Qt
        from smackpress import wp_client, wp_source
        w = self._window()
        item = QListWidgetItem("x"); item.setData(Qt.UserRole, POST)
        w.source.list.addItem(item); w.source.list.setCurrentItem(item)
        self.assertTrue(w.source.pull_btn.isEnabled())

        import threading
        done = threading.Event()
        orig = wp_source.draft_from_wp
        def patched(full, workdir, **kw):
            try:
                return orig(full, workdir, fetch=fake_fetch, **kw)
            finally:
                done.set()
        with mock.patch.object(wp_client, "get_post", lambda wp_id: POST), \
                mock.patch.object(wp_source, "draft_from_wp", patched):
            w.source._pull()
            self.assertTrue(done.wait(10))
            for _ in range(50):
                self.app.processEvents()

        # it is now the draft being edited in COLD SNAP's editor
        self.assertEqual(w.take.title_edit.text(), "Rust & Chrome")
        self.assertEqual(len(w.take._bucket), 4)
        self.assertTrue(all(os.path.isfile(im.local_path) for im in w.take._bucket))
        self.assertIn("[img:bucket:", w.take.body.toPlainText())
        # and it is saved in the import batch
        batch = w._batch()
        self.assertEqual(batch.name, "WordPress import")
        self.assertEqual([d.title for d in batch.list_drafts()], ["Rust & Chrome"])
        self.assertTrue(w.source.migrated_btn.isEnabled())

        # before SEND: nothing to record
        self.assertIsNone(w._posted_lookup(w.take._editing_id))
        # after SEND (simulate what COLD SNAP's rail writes back)
        d = batch.load_draft(w.take._editing_id)
        d.remote_post_id = 777
        batch.save_draft(d)
        self.assertEqual(w._posted_lookup(d.draft_id), (777, "https://pixhellated.ca/post/rust-and-chrome"))


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
