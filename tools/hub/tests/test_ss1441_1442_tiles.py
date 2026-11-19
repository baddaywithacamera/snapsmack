"""Tests for SS-1441 (transparent card labels) and SS-1442 (Migration Centre icons)."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import os
import sys
from pathlib import Path

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

HUB_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SHARED_DIR = os.path.abspath(os.path.join(HUB_DIR, "..", "_shared"))
for p in (HUB_DIR, SHARED_DIR):
    if p not in sys.path:
        sys.path.insert(0, p)

import main as core  # noqa: E402
import qt_main  # noqa: E402


def test_tile_labels_use_transparent_background():
    assert "QLabel { background:transparent; }" in qt_main.STYLE


def test_every_migration_roster_entry_has_an_icon():
    for name, _sub, _paths in qt_main.MIGRATION_ROSTER:
        assert name in core.TOOL_ICON_FILES, f"Missing TOOL_ICON_FILES entry for {name}"
        img = core._tool_image(name)
        assert img and os.path.isfile(img), f"Missing PNG icon for {name}: {img}"
        ico = os.path.join(HUB_DIR, "icons", core.TOOL_ICON_FILES[name])
        assert os.path.isfile(ico), f"Missing ICO icon for {name}: {ico}"


def test_packaged_hq_includes_non_simple_fed_up_icon():
    spec = Path(HUB_DIR, "hub.spec").read_text(encoding="utf-8")
    assert "os.path.join(_icon_dir, 'fed-up.png')" in spec

# ===== SNAPSMACK EOF =====
