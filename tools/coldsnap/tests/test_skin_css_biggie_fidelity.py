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
    figcaption {
        color: #666;
        font-size: 14px;
        font-style: italic;
        padding-top: 12px;
    }
    """
    parsed = parse_skin_css(sample_css)
    assert parsed["loaded"] is True
    assert parsed["bg"] == "#fff"
    assert parsed["fg"] == "#282828"
    assert parsed["body_font"] == "Arial"  # sample_css has no @font-face for Montserrat
    assert parsed["body_px"] == 20.0
    assert parsed["body_pt"] == 15.0
    assert abs(parsed["line_height_pct"] - 172.0) < 1e-6
    assert abs(parsed["para_margin_bottom_px"] - 34.0) < 1e-6
    assert abs(parsed["image_margin_top_px"] - 52.0) < 1e-6
    assert abs(parsed["image_margin_bottom_px"] - 52.0) < 1e-6
    assert parsed["caption_color"] == "#666"
    assert parsed["caption_pt"] == 10.5
    assert parsed["caption_italic"] is True


def test_alfred_sybu_data_caption_controls_flow_into_biggie(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    import snap_library

    site = "https://alfred-captions.example"
    snap_library.sync_from_sybu_data(site, {
        "site_mode": "smacktalk",
        "skin": {
            "slug": "alfred",
            "name": "ALFRED",
            "version": "1.0.21",
            "options": {
                "post_content_width": "800",
                "caption_color": "#cc0000",
                "caption_size": "20",
                "caption_style": "normal",
                "caption_gap": "40",
                "caption_align": "center",
            },
        },
    })
    app = _app()
    app.setStyleSheet(theme.stylesheet())
    mode = TakeMode(lambda: {"url": site})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    try:
        mode.apply_canvas_background(site)
        f, col = mode.body.canvas.caption_font_and_color()
        assert col == "#cc0000"
        assert f.pointSizeF() == 15.0  # 20px * 0.75
        assert f.italic() is False
        assert mode.body.canvas._caption_style["align"] == "center"
        assert mode._preview_width == 800
    finally:
        mode.close()
    assert abs(parsed["caption_padding_top_px"] - 12.0) < 1e-6


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


def test_sybu_data_skin_block_is_authoritative_over_css_fallback(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    import snap_library
    from coldsnap_qt import skin_css

    site = "https://authoritative.example"
    snap_library.sync_from_sybu_data(site, {
        "site_mode": "smacktalk",
        "skin": {
            "slug": "writing-with-impact",
            "name": "Writing With Impact",
            "version": "1.0.0",
            "options": {
                "ink_color": "#112233",
                "body_font_family": "Palatino, serif",
                "post_content_width": 800,
            },
        },
    })
    skin_css._SITE_CACHE[site] = {
        "loaded": True,
        "bg": "#fafafa",
        "fg": "#999999",
        "body_font": "Arial",
        "body_pt": 14.0,
        "line_height_pct": 165.0,
        "para_margin_bottom_px": 24.0,
        "image_margin_top_px": 40.0,
        "image_margin_bottom_px": 40.0,
    }
    app = _app()
    app.setStyleSheet(theme.stylesheet())
    mode = TakeMode(lambda: {"url": site})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    try:
        bg = mode.apply_canvas_background(site)
        assert bg == "#fafafa"  # CSS fallback filled undeclared bg
        assert mode.body.canvas._body_font_family == "Palatino"  # sybu-data won over CSS Arial
        assert mode._preview_width == 800
        assert not mode.site_colors_note.isHidden()
        assert "css fallback" in mode.site_colors_note.text().lower()
    finally:
        skin_css._SITE_CACHE.pop(site, None)
        mode.close()


def test_tilez_stylesheet_picks_post_bg_fff_over_early_1d1d1d():
    css_path = os.path.join(os.path.dirname(_ROOT), "..", "skins", "tilez", "style.css")
    if not os.path.isfile(css_path):
        return
    with open(css_path, encoding="utf-8") as fh:
        parsed = parse_skin_css(fh.read())
    assert parsed["bg"].lower() in ("#fff", "#ffffff")
    assert parsed["body_font"] == "Arial"  # Montserrat has no @font-face in TILEZ, so visitor gets Arial
    assert 17.0 <= parsed["body_px"] <= 20.0
    assert 170.0 <= parsed["line_height_pct"] <= 172.0
    assert parsed["caption_color"] == "#666"
    assert parsed["caption_pt"] == 10.5
    assert parsed["caption_italic"] is True


def test_resolve_visitor_font_skips_unloaded_webfonts():
    from coldsnap_qt.skin_css import resolve_visitor_font
    stack = 'Montserrat, "Helvetica Neue", Helvetica, Arial, sans-serif'
    assert resolve_visitor_font(stack, loaded_webfonts=[]) == "Arial"
    assert resolve_visitor_font(stack, loaded_webfonts=["Montserrat"]) == "Montserrat"
    assert resolve_visitor_font('"Unknown House Face", serif', loaded_webfonts=[]) == "Georgia"


def test_alfred_sybu_data_caption_controls_flow_into_biggie(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    import snap_library

    site = "https://alfred-captions.example"
    snap_library.sync_from_sybu_data(site, {
        "site_mode": "smacktalk",
        "skin": {
            "slug": "alfred",
            "name": "ALFRED",
            "version": "1.0.21",
            "options": {
                "post_content_width": "800",
                "caption_color": "#cc0000",
                "caption_size": "20",
                "caption_style": "normal",
                "caption_gap": "40",
                "caption_align": "center",
            },
        },
    })
    app = _app()
    app.setStyleSheet(theme.stylesheet())
    mode = TakeMode(lambda: {"url": site})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    try:
        mode.apply_canvas_background(site)
        f, col = mode.body.canvas.caption_font_and_color()
        assert col == "#cc0000"
        assert f.pointSizeF() == 15.0  # 20px * 0.75
        assert f.italic() is False
        assert mode.body.canvas._caption_style["align"] == "center"
        assert mode._preview_width == 800
    finally:
        mode.close()
