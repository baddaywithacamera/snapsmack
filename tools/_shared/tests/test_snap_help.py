"""Tests for shared offline Help dialog (tools/_shared/snap_help.py)."""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtWidgets import QApplication

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import snap_help


def _app():
    return QApplication.instance() or QApplication([])


def test_filter_topics_and_qt_help_dialog():
    topics = [
        ("Overview", "What this tool does offline."),
        ("AI / CLI", "Enable CLI in SNAP HQ Settings first."),
    ]
    assert len(snap_help.filter_topics(topics, "")) == 2
    assert snap_help.filter_topics(topics, "snap hq") == [topics[1]]

    _app()
    dlg = snap_help.HelpDialog(topics=topics, title="Test Tool — Help")
    assert dlg.windowTitle() == "Test Tool — Help"
    assert dlg.list.count() == 2
    assert "What this tool does offline." in dlg.body.toPlainText()

    dlg.search.setText("snap hq")
    assert dlg.list.count() == 1
    assert "Enable CLI in SNAP HQ Settings first." in dlg.body.toPlainText()

    dlg.search.setText("nonexistent-query")
    assert dlg.list.count() == 0
    assert "No help topics match" in dlg.body.toPlainText()
    dlg.close()


# ===== SNAPSMACK EOF =====
