"""Tests for SS-1450: HQ tray Quit action and installer tray-HQ detection."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import os
import sys
from types import SimpleNamespace

import pytest

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtWidgets import QApplication

HUB_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SHARED_DIR = os.path.abspath(os.path.join(HUB_DIR, "..", "_shared"))
for p in (HUB_DIR, SHARED_DIR):
    if p not in sys.path:
        sys.path.insert(0, p)

import qt_main  # noqa: E402
import snap_hq_process  # noqa: E402


@pytest.fixture(autouse=True)
def _sandbox_home(tmp_path, monkeypatch):
    sandbox = tmp_path / "snapsmack_home"
    sandbox.mkdir(parents=True, exist_ok=True)
    monkeypatch.setenv("SNAPSMACK_HOME", str(sandbox))


def test_hq_tray_menu_has_obvious_quit_action():
    app = QApplication.instance() or QApplication([])
    win = qt_main.Window()
    texts = [act.text() for act in win.tray_menu.actions() if act.text()]
    assert "Show SNAP HQ" in texts
    assert "Quit SNAP HQ" in texts
    win.close()
    app.processEvents()


def test_installer_detects_running_hq_and_offers_to_quit():
    calls = []
    state = {"running": True}

    def fake_runner(cmd, **_kwargs):
        calls.append(cmd)
        if cmd[0] == "tasklist":
            out = '"SNAP HQ.exe","4321","Console","1","42,000 K"\n' if state["running"] else "INFO: No tasks are running.\n"
            return SimpleNamespace(returncode=0, stdout=out)
        if cmd[0] == "taskkill":
            state["running"] = False
            return SimpleNamespace(returncode=0, stdout="SUCCESS")
        return SimpleNamespace(returncode=0, stdout="")

    # User declines -> returns False, no taskkill
    assert snap_hq_process.ensure_hq_closed(prompt_fn=lambda _msg: "n", runner=fake_runner) is False
    assert not any(c[0] == "taskkill" for c in calls)

    # User accepts -> quits SNAP HQ.exe and returns True
    assert snap_hq_process.ensure_hq_closed(prompt_fn=lambda _msg: "y", runner=fake_runner) is True
    assert any(c[0] == "taskkill" for c in calls)

# ===== SNAPSMACK EOF =====
