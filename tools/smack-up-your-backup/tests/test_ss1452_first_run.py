"""Tests for SS-1452 SUYB first-run screen, photo-library disclaimer, and Help reopen."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import os
import sys

import pytest

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtWidgets import QApplication

SUYB_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SHARED_DIR = os.path.abspath(os.path.join(SUYB_DIR, "..", "_shared"))
for p in (SUYB_DIR, SHARED_DIR):
    if p not in sys.path:
        sys.path.insert(0, p)

import snap_first_run  # noqa: E402
import suyb_qt  # noqa: E402


@pytest.fixture(autouse=True)
def _sandbox_home(tmp_path, monkeypatch):
    sandbox = tmp_path / "snapsmack_home"
    sandbox.mkdir(parents=True, exist_ok=True)
    monkeypatch.setenv("SNAPSMACK_HOME", str(sandbox))


def test_suyb_first_run_text_disclaims_photo_libraries():
    joined = " ".join(suyb_qt.SUYB_DOESNT).lower()
    assert "photo library" in joined
    assert "original" in joined
    assert "separately" in joined
    assert "snapsmack site" in " ".join(suyb_qt.SUYB_DOES).lower()


def test_suyb_first_run_shows_once_when_dismissed(monkeypatch):
    QApplication.instance() or QApplication([])
    shown = []
    monkeypatch.setattr(
        snap_first_run, "FirstRunDialog",
        lambda *a, **kw: type("StubDlg", (), {
            "exec": lambda self: (shown.append(a[0]), snap_first_run.set_dismissed(kw["tool_key"], True), 1)[2],
        })(),
    )
    monkeypatch.setattr(suyb_qt, "SetupWizard", lambda _p: type("StubWiz", (), {"exec": lambda self: 0})())

    win = suyb_qt.SuybWindow()
    win._first_run()
    assert shown == ["SMACK UP YOUR BACKUP"]
    win._first_run()
    assert shown == ["SMACK UP YOUR BACKUP"]
    win.close()

# ===== SNAPSMACK EOF =====
