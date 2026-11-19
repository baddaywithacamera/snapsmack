"""Tests for inert DRIVE BY HQ tile: normal OPEN appearance, icon present, click is no-op."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import os
import sys

import pytest

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtWidgets import QApplication

HUB_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SHARED_DIR = os.path.abspath(os.path.join(HUB_DIR, "..", "_shared"))
for p in (HUB_DIR, SHARED_DIR):
    if p not in sys.path:
        sys.path.insert(0, p)

import main as core  # noqa: E402
import qt_main  # noqa: E402


@pytest.fixture(autouse=True)
def _sandbox_home(tmp_path, monkeypatch):
    sandbox = tmp_path / "snapsmack_home"
    sandbox.mkdir(parents=True, exist_ok=True)
    monkeypatch.setenv("SNAPSMACK_HOME", str(sandbox))


def test_driveby_tile_looks_normal_and_click_is_noop(monkeypatch):
    app = QApplication.instance() or QApplication([])
    called = []
    monkeypatch.setattr(core, "_launch", lambda *a, **kw: (called.append((a, kw)), (True, ""))[1])

    entry = next(r for r in core.ROSTER if r[0] == "DRIVE BY")
    assert entry[2] == []
    card = qt_main.ToolCard(*entry)
    assert card.inert is True
    assert card.launch.text() == "OPEN"
    assert card.launch.isEnabled() is True
    assert "not installed" not in card.sub.text()
    assert card.icon_path and os.path.isfile(card.icon_path)

    card.launch.click()
    app.processEvents()
    assert called == []

# ===== SNAPSMACK EOF =====
