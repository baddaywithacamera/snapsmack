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


def test_all_ten_tools_have_muse_topics_and_ui_wiring():
    expected = [
        "sybu", "shots-fired", "oh-snap", "smack-your-mouth", "cronometer",
        "instantreplay", "keysplease", "blogger-flogger", "ghosted", "fed-up",
    ]
    for key in expected:
        topics = snap_help.TOOL_HELP_TOPICS[key]
        assert len(topics) >= 4
        for t_title, t_body in topics:
            assert "—" not in t_title and "—" not in t_body

    html_out = snap_help.render_web_help_html(snap_help.TOOL_HELP_TOPICS["oh-snap"], title="OH SNAP — Help")
    assert "snap-help-btn" in html_out and "F1" in html_out

    tools_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
    wired_files = [
        "sybu/sybu_qt.py",
        "instantreplay/replay_qt/main_window.py",
        "keysplease/keysplease_qt/main_window.py",
        "shots-fired/main.py",
        "smack-your-mouth/main.py",
        "cronometer/cronometer.py",
        "blogger-flogger/blogger_flogger/ui.py",
        "ghosted/ghosted/ui.py",
        "fed-up/fed_up/ui.py",
        "oh-snap/src/scripts/help.js",
        "oh-snap/src/index.html",
    ]
    for rel in wired_files:
        full = os.path.join(tools_dir, rel)
        assert os.path.isfile(full), f"Missing {rel}"
        text = open(full, encoding="utf-8").read()
        assert ("snap_help" in text) or ("help.js" in text) or ("ohsnap-help-btn" in text)


# ===== SNAPSMACK EOF =====
