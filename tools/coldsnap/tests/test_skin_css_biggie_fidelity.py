"""Tests for BIGGIE skin CSS parsing and approximate fallback banner."""

import os
import sys

from PySide6.QtWidgets import QApplication

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

_SHARED = os.path.join(os.path.dirname(_ROOT), "_shared")
if _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)

from coldsnap_qt import theme
from coldsnap_qt.mode_take import TakeMode
from coldsnap_qt.skin_css import parse_skin_css


def _app():
    return QApplication.instance() or QApplication([])


def test_parse_tilez_skin_css_extracts_font_line_height_and_margins():
    sample_css = """
    body { background: #fff; color: #282828; }
    .entry-content {
        color: #282828;
        font-family: Montserrat, 'Helvetica Neue', Helvetica, Arial, sans-serif;
        font-size: 20px;
        line-height: 1.72;
    }
    .entry-content > p { margin-bottom: 1.7em; }
    .entry-content > .snap-inline-frame {
        margin-top: 2.6em;
        margin-bottom: 2.6em;
    }
    """
    parsed = parse_skin_css(sample_css)
    assert parsed["loaded"] is True
    assert parsed["bg"] == "#fff"
    assert parsed["fg"] == "#282828"
    assert parsed["body_font"] == "Montserrat"
    assert parsed["body_px"] == 20.0
    assert parsed["body_pt"] == 15.0
    assert abs(parsed["line_height_pct"] - 172.0) < 1e-6
    assert abs(parsed["para_margin_bottom_px"] - 34.0) < 1e-6
    assert abs(parsed["image_margin_top_px"] - 52.0) < 1e-6
    assert abs(parsed["image_margin_bottom_px"] - 52.0) < 1e-6


def test_biggie_applies_skin_typography_and_shows_approximate_when_unloaded(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())
    mode = TakeMode(lambda: {"url": ""})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    try:
        mode.apply_canvas_background("")
        assert "approximate: skin styles not loaded" in mode.site_colors_note.text()
        assert mode.site_colors_note.isVisible() is True

        canvas = mode.body.canvas
        canvas.set_skin_typography(
            body_font="Montserrat",
            body_pt=15.0,
            line_height_pct=172.0,
            para_margin_bottom=34.0,
            image_margin_top=52.0,
            image_margin_bottom=52.0,
        )
        assert canvas._body_font_family == "Montserrat"
        assert canvas._body_font_pt == 15.0
        assert canvas._line_height_pct == 172.0
        assert canvas._image_margin_top == 52.0
        assert canvas._image_margin_bottom == 52.0
    finally:
        mode.close()
