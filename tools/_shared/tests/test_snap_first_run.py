"""Tests for SS-1452 shared first-run dialog and SNAPSMACK_HOME persistence."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import os
import sys

import pytest

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtWidgets import QApplication

SHARED_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if SHARED_DIR not in sys.path:
    sys.path.insert(0, SHARED_DIR)

import snap_first_run  # noqa: E402
import snap_home  # noqa: E402


@pytest.fixture(autouse=True)
def _sandbox_home(tmp_path, monkeypatch):
    sandbox = tmp_path / "snapsmack_home"
    sandbox.mkdir(parents=True, exist_ok=True)
    monkeypatch.setenv("SNAPSMACK_HOME", str(sandbox))


def test_first_run_dismissal_persists_under_snapsmack_home():
    QApplication.instance() or QApplication([])
    assert snap_first_run.should_show("suyb") is True

    dlg = snap_first_run.FirstRunDialog(
        "SMACK UP YOUR BACKUP",
        ["Backs up SNAPSMACK sites."],
        ["Does not back up photo libraries."],
        tool_key="suyb",
    )
    dlg.dont_show_check.setChecked(True)
    dlg.accept()

    assert snap_first_run.should_show("suyb") is False
    expected = os.path.join(snap_home.config_dir("suyb"), "first_run.json")
    assert os.path.isfile(expected)

    # Unchecking re-enables first-run display
    dlg2 = snap_first_run.FirstRunDialog(
        "SMACK UP YOUR BACKUP", ["A"], ["B"], tool_key="suyb",
    )
    assert dlg2.dont_show_check.isChecked() is True
    dlg2.dont_show_check.setChecked(False)
    dlg2.accept()
    assert snap_first_run.should_show("suyb") is True

# ===== SNAPSMACK EOF =====
