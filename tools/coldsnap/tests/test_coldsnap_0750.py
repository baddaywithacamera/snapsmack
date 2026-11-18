"""Tests for COLD SNAP 0.7.50-test: _edit() load order, outlined placeholders, and slug= exact lookup."""

import os
import sys

from PIL import Image
from PySide6.QtCore import QRect, Qt
from PySide6.QtGui import QColor, QImage, QPainter
from PySide6.QtWidgets import QApplication, QWidget

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

from coldsnap_qt import biggie
from coldsnap_qt.canvas import BiggieCanvas, mosaic_cells
from coldsnap_qt.connect_panel import ConnectPanel
from coldsnap_qt.main_window import MainWindow
import sumna_offline as O
from sumna_post import SmacktalkPoster


def _app():
    return QApplication.instance() or QApplication([])


def test_reopen_saved_mosaic_draft_resolves_bucket_images(tmp_path, monkeypatch):
    app = _app()
    monkeypatch.setattr(ConnectPanel, "_start_mode_discovery", lambda self: None)
    monkeypatch.setattr(ConnectPanel, "_begin_async_load", lambda self: None)
    dummy_solo = QWidget()
    dummy_solo.refresh_taxonomy = lambda: None
    monkeypatch.setattr("coldsnap_qt.main_window.SoloMode", lambda _cfg: dummy_solo)
    monkeypatch.setattr("coldsnap_qt.main_window.StackMode", lambda _cfg: QWidget())
    monkeypatch.setattr("coldsnap_qt.main_window.StorageMode", lambda _cfg: QWidget())

    p1 = tmp_path / "car-01.jpg"
    p2 = tmp_path / "car-02.jpg"
    Image.new("RGB", (1200, 800), (200, 40, 40)).save(p1)
    Image.new("RGB", (1200, 800), (40, 200, 40)).save(p2)

    draft = O.Draft(
        draft_id="d-reopen",
        kind=O.KIND_SMACKTALK,
        mode="smacktalk",
        title="Start your engines",
        caption="Essay body\n\n[mosaic=1,2 layout=asymmetric]",
        body_blocks=biggie.blocks_to_json([
            {"type": "para", "text": "Essay body"},
            {"type": "mosaic", "order": ["u1", "u2"], "layout": "asymmetric"},
        ]),
        img_status="draft",
        images=[
            O.DraftImage(local_path=str(p1), filename="car-01.jpg", asset_uuid="u1", is_cover=True),
            O.DraftImage(local_path=str(p2), filename="car-02.jpg", asset_uuid="u2"),
        ],
    )

    win = MainWindow()
    win.take_mode._edit(draft)
    app.processEvents()

    cv = win.take_mode.body.canvas
    assert cv.bucket == [str(p1), str(p2)]
    assert cv.assets == {"u1": str(p1), "u2": str(p2)}
    paths, out = mosaic_cells(cv, {"order": ["u1", "u2"], "layout": "asymmetric"}, 760)
    assert paths == [str(p1), str(p2)]
    assert len(out["items"]) == 2
    win.close()


def test_placeholders_render_as_outlined_boxes_with_no_solid_fill_on_light_and_dark():
    _app()

    class _SpyPainter(QPainter):
        def __init__(self, img):
            super().__init__(img)
            self.drawn_texts = []
            self.filled_rects = []

        def fillRect(self, *args):
            self.filled_rects.append(args)
            return super().fillRect(*args)

        def drawText(self, *args):
            if args and isinstance(args[-1], str):
                self.drawn_texts.append(args[-1])
            return super().drawText(*args)

    for bg_hex, expect_outline, expect_text in (
        ("#ffffff", "#444444", "#222222"),
        ("#141714", "#9aa39b", "#e4e8e4"),
    ):
        cv = BiggieCanvas()
        cv.setStyleSheet(f"QTextEdit#BiggieCanvas {{ background: {bg_hex}; color: {expect_text}; border: none; }}")
        cv.set_assets({"u-miss": "/nonexistent/car-03.jpg"})
        assert cv._objects._placeholder_colors() == (expect_outline, expect_text)

        img = QImage(640, 220, QImage.Format_ARGB32)
        img.fill(QColor(bg_hex))
        p = _SpyPainter(img)
        cv._objects._draw_mosaic(p, QRect(0, 0, 640, 180), {"order": ["u-miss"], "layout": "asymmetric"})
        cv._objects._draw_image(p, QRect(0, 180, 640, 40), {"asset_uuid": "u-miss", "width_ratio": 1.0})
        p.end()

        assert p.filled_rects == [], f"Placeholder must not paint a solid fill on {bg_hex}"
        # Sample corner interior pixel: must still equal page background (no filled slab)
        assert img.pixelColor(6, 6).name().lower() == bg_hex
        assert any("MOSAIC" in t and "not in bucket" in t or "missing" in t for t in p.drawn_texts)
        assert any("IMAGE — file missing: car-03.jpg" in t for t in p.drawn_texts)


def test_slug_lookup_uses_exact_query_param(monkeypatch):
    monkeypatch.setattr("sumna_post._produce_library", lambda *a, **kw: None)

    class _Resp:
        status_code = 200

        def __init__(self, payload):
            self._payload = payload

        def json(self):
            return self._payload

        def raise_for_status(self):
            pass

    class _Sess:
        def __init__(self):
            self.headers = {}
            self.gets = []
            self.posts = []

        def get(self, url, params=None, timeout=30):
            self.gets.append((url, params))
            return _Resp({"posts": [{"id": 777, "slug": "old-post", "title": "Old Post"}], "exact": True})

        def post(self, url, json=None, files=None, data=None, timeout=60):
            self.posts.append((url, json))
            return _Resp({"post_id": int((json or {}).get("post_id") or 0), "slug": "old-post"})

    sess = _Sess()
    draft = O.Draft(
        draft_id="d-exact",
        kind=O.KIND_SMACKTALK,
        mode="smacktalk",
        title="Old Post",
        slug="old-post",
        caption="Updated body.",
        img_status="published",
        remote_post_id=0,
        images=[],
    )
    poster = SmacktalkPoster("https://example.test", "k" * 64, session=sess)
    res = poster.sync_smacktalk(draft, confirm_overwrite_fn=lambda _p: True)
    assert res.ok, res.message
    assert res.remote_post_id == 777
    assert sess.gets[0][1] == {"slug": "old-post"}
    assert sess.posts[0][1]["post_id"] == 777


# ===== SNAPSMACK EOF =====
