"""SNAP SLAPPER control labels must not regress to the clipped layout."""

from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def test_editor_control_labels_have_room():
    widgets = (ROOT / "slapper_qt" / "widgets.py").read_text(encoding="utf-8")
    editor = (ROOT / "slapper_qt" / "editor_window.py").read_text(encoding="utf-8")

    assert "LABEL_WIDTH = 116" in widgets
    assert "name.setFixedWidth(self.LABEL_WIDTH)" in widgets
    assert "rail.setFixedWidth(320)" in editor
    # There was a fourth check here, on the standalone "Direction" label
    # (dlabel.setFixedWidth(SliderRow.LABEL_WIDTH)). That control was removed
    # from the editor in 53efe7b4, so the check was pinning a widget that no
    # longer exists. Every label the editor still draws goes through
    # SliderRow, which the two widgets.py checks above cover.
    assert "dlabel" not in editor, (
        "a label outside SliderRow is back; give it SliderRow.LABEL_WIDTH "
        "and check its width here")


# ===== SNAPSMACK EOF =====
