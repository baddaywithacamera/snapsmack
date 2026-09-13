"""SNAP SLAPPER control labels must not regress to the clipped layout."""

from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def test_editor_control_labels_have_room():
    widgets = (ROOT / "slapper_qt" / "widgets.py").read_text(encoding="utf-8")
    editor = (ROOT / "slapper_qt" / "editor_window.py").read_text(encoding="utf-8")

    assert "LABEL_WIDTH = 116" in widgets
    assert "name.setFixedWidth(self.LABEL_WIDTH)" in widgets
    assert "rail.setFixedWidth(320)" in editor
    assert "dlabel.setFixedWidth(SliderRow.LABEL_WIDTH)" in editor


# ===== SNAPSMACK EOF =====
