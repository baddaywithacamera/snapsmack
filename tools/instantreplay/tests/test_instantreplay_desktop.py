"""Desktop-side tests for INSTANT REPLAY (SS-1363)."""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtCore import Qt
from PySide6.QtGui import QKeyEvent
from PySide6.QtWidgets import QApplication

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from replay_core.providers import FakeBackupCatalog, FakeTestBlogTarget
from replay_qt.main_window import MainWindow


def _app():
    return QApplication.instance() or QApplication([])


def test_instant_replay_refuses_non_test_blog_and_blocks_paste():
    _app()
    win = MainWindow(catalog=FakeBackupCatalog(), target_provider=FakeTestBlogTarget())

    # Production target (index 1) must be refused on challenge request
    win.target_combo.setCurrentIndex(1)
    assert win.request_challenge() is None
    assert "REFUSED" in win.challenge_lbl.text()

    # Paste must be blocked on challenge input
    win.challenge_input.setText("abc")
    QApplication.clipboard().setText("PASTED")
    win.challenge_input.paste()
    assert win.challenge_input.text() == "abc"
    ev = QKeyEvent(QKeyEvent.KeyPress, Qt.Key_V, Qt.ControlModifier, "v")
    win.challenge_input.keyPressEvent(ev)
    assert win.challenge_input.text() == "abc"


def test_instant_replay_pass_and_fail_verification_reports():
    _app()
    # 1. PASS path
    win_ok = MainWindow(catalog=FakeBackupCatalog(), target_provider=FakeTestBlogTarget())
    win_ok.target_combo.setCurrentIndex(0)
    ch = win_ok.request_challenge()
    assert ch is not None
    win_ok.challenge_input.setText(ch.phrase)
    win_ok.totp_input.setText("123456")
    assert win_ok.execute_replay() == "PASS"
    assert "PASS — Restored" in win_ok.report_box.toPlainText()

    # 2. FAIL path (post count mismatch after restore)
    win_bad = MainWindow(
        catalog=FakeBackupCatalog(),
        target_provider=FakeTestBlogTarget(corrupt_after_restore=True),
    )
    win_bad.target_combo.setCurrentIndex(0)
    ch2 = win_bad.request_challenge()
    assert ch2 is not None
    win_bad.challenge_input.setText(ch2.phrase)
    win_bad.totp_input.setText("123456")
    assert win_bad.execute_replay() == "FAIL"
    assert "[FAIL] Posts count" in win_bad.report_box.toPlainText()


# ===== SNAPSMACK EOF =====
