"""COLD SNAP 0.7.53 regression tests:
- fetch_site_colors extracts both background and text colour from site CSS
- Reopened draft on WHITE site and BLACK site adopts site theme background & contrasting text
- Text-to-background WCAG contrast >= 4.5:1 on both white and black themes
- Zero #0d0e0e regions inside PageCard / BiggiePage
- Fallback to white/dark with 'site colours unavailable' note when site colours are missing
"""

import os
import sys

from PySide6.QtGui import QColor, QPalette, QPixmap
from PySide6.QtWidgets import QApplication

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

_SHARED = os.path.join(os.path.dirname(_ROOT), "_shared")
if _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)

import snap_library
import sumna_offline as O
from sumna_post import SumnaConnection
from coldsnap_qt import theme
from coldsnap_qt.canvas import compute_contrast_ratio
from coldsnap_qt.mode_take import TakeMode


def _app():
    return QApplication.instance() or QApplication([])


def test_fetch_site_colors_extracts_bg_and_fg(monkeypatch):
    conn = SumnaConnection("https://dark.example", "k")

    class Resp:
        def __init__(self, status_code, text):
            self.status_code = status_code
            self.text = text

    def fake_get(url, timeout=8):
        if url.endswith("/"):
            return Resp(200, '<style>body { background-color: #050505; color: #eaeaea; }</style>')
        return Resp(404, "")

    monkeypatch.setattr(conn.session, "get", fake_get)
    bg, fg = conn.fetch_site_colors()
    assert bg == "#050505"
    assert fg == "#eaeaea"
    assert conn.fetch_site_bg() == "#050505"


def _sample_surface_match(widget, expected_hex: str):
    pm = QPixmap(widget.size())
    pm.fill(QColor("#ff00ff"))
    widget.render(pm)
    img = pm.toImage()
    w, h = img.width(), img.height()
    assert w > 100 and h > 100
    exp = QColor(expected_hex)
    x = w // 2
    app_bg_hits = 0
    matched = 0
    total = 0
    unexpected = set()
    for y in range(12, h - 12, 6):
        c = img.pixelColor(x, y)
        total += 1
        if (c.red(), c.green(), c.blue()) == (0x0D, 0x0E, 0x0E) and expected_hex.lower() != "#0d0e0e":
            app_bg_hits += 1
        if abs(c.red() - exp.red()) <= 8 and abs(c.green() - exp.green()) <= 8 and abs(c.blue() - exp.blue()) <= 8:
            matched += 1
        else:
            unexpected.add(c.name())
    return app_bg_hits, matched, total, sorted(unexpected)


def test_take_mode_white_and_black_site_surfaces(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())

    white_url = "https://white-site.example"
    black_url = "https://black-site.example"
    snap_library.set_site_colors(white_url, "#ffffff", "#1a1a1a")
    snap_library.set_site_colors(black_url, "#000000", "#f0f2f0")

    current_url = [white_url]
    mode = TakeMode(lambda: {"url": current_url[0]})
    mode.body._set_biggie(True)
    mode.body.canvas.from_blocks([{"kind": "p", "text": "Hello from the essay."}])
    mode.resize(1000, 800)
    mode.show()
    app.processEvents()

    try:
        # 1. White site
        mode.apply_canvas_background(white_url)
        app.processEvents()
        app.processEvents()
        assert mode.site_colors_note.isHidden()
        hits, matched, total, unexp = _sample_surface_match(mode.body._biggie_page, "#ffffff")
        assert hits == 0
        assert matched >= total * 0.7, f"white matched {matched}/{total}, unexpected={unexp}"
        pal_w = mode.body.canvas._surface_palette
        assert compute_contrast_ratio(QColor(pal_w["bg"]), QColor(pal_w["fg"])) >= 4.5

        # 2. Black site
        current_url[0] = black_url
        mode.apply_canvas_background(black_url)
        app.processEvents()
        app.processEvents()
        assert mode.site_colors_note.isHidden()
        hits, matched, total, unexp = _sample_surface_match(mode.body._biggie_page, "#000000")
        assert hits == 0
        assert matched >= total * 0.7, f"black matched {matched}/{total}, unexpected={unexp}"
        pal_b = mode.body.canvas._surface_palette
        assert pal_b["bg"] == "#000000"
        assert compute_contrast_ratio(QColor(pal_b["bg"]), QColor(pal_b["fg"])) >= 4.5
        assert compute_contrast_ratio(QColor(pal_b["bg"]), QColor(pal_b["heading"])) >= 4.5
        assert mode.body.canvas.palette().color(QPalette.Text).name() == pal_b["fg"]
        # Reopened/existing paragraph text was recolored for black background
        blk_fg = mode.body.canvas.document().begin().charFormat().foreground().color()
        assert compute_contrast_ratio(QColor("#000000"), blk_fg) >= 4.5
    finally:
        mode.close()
        mode.deleteLater()
        app.processEvents()


def test_take_mode_unavailable_site_colors_fallback(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())

    mode = TakeMode(lambda: {"url": "https://missing-colors.example"})
    mode.body._set_biggie(True)
    mode.show()
    try:
        bg = mode.apply_canvas_background("https://missing-colors.example")
        app.processEvents()
        assert bg == "#ffffff"
        assert not mode.site_colors_note.isHidden()
        assert "site colours unavailable" in mode.site_colors_note.text().lower()
        pal = mode.body.canvas._surface_palette
        assert compute_contrast_ratio(QColor(pal["bg"]), QColor(pal["fg"])) >= 4.5
    finally:
        mode.close()
        mode.deleteLater()
        app.processEvents()

# ===== SNAPSMACK EOF =====
