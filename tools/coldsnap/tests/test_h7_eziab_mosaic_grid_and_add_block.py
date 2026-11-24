"""Regression tests for H7_ezIab (SS-1495 / SS-1493 follow-up):
1. True-aspect thumbnails with P / L / SQ badges in both the tray and the mosaic picker grid
   (filenames in tooltip; orientation + ratio on the tile).
2. Mosaic dialog opens with the tray selection pre-ticked, in tray order.
3. Mosaic dialog LIVE PREVIEW and Layout dropdown work, and Suggest arrangement uses orientation.
4. + Add block inserts at the cursor (above an image) with a real mouse click on the button/menu.
"""

import os
import sys

from PIL import Image
from PySide6.QtCore import Qt, QPoint, QTimer
from PySide6.QtGui import QTextCursor
from PySide6.QtTest import QTest
from PySide6.QtWidgets import QApplication, QDialog

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

_SHARED = os.path.join(os.path.dirname(_ROOT), "_shared")
if _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)

from coldsnap_qt import theme
from coldsnap_qt.mode_take import TakeMode
from coldsnap_qt.photo_tray import PhotoThumbTile, photo_orientation_badge


def _app():
    return QApplication.instance() or QApplication([])


def _make_photo(path, size, color=(50, 110, 170)):
    im = Image.new("RGB", size, color)
    im.save(str(path), "JPEG")
    return str(path)


def test_tray_orientation_badges_and_true_aspect_tiles(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())

    p_port = _make_photo(tmp_path / "portrait.jpg", (400, 800))
    p_land = _make_photo(tmp_path / "landscape.jpg", (800, 400))
    p_sq = _make_photo(tmp_path / "square.jpg", (500, 500))

    assert photo_orientation_badge(400, 800)[0] == "P"
    assert photo_orientation_badge(800, 400)[0] == "L"
    assert photo_orientation_badge(500, 500)[0] == "SQ"

    mode = TakeMode(lambda: {"url": "https://example.com"})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    mode._clear()
    try:
        mode._add_photo_paths([p_port, p_land, p_sq], insert=False)
        app.processEvents()

        tiles = [
            mode.bucket_col.itemAt(i).widget()
            for i in range(mode.bucket_col.count())
            if isinstance(mode.bucket_col.itemAt(i).widget(), PhotoThumbTile)
        ]
        assert len(tiles) == 3
        assert [t._orientation for t in tiles] == ["P", "L", "SQ"]
        # True aspect ratio preserved on pixmaps (portrait taller than wide, landscape wider than tall)
        assert tiles[0]._pixmap.height() > tiles[0]._pixmap.width()
        assert tiles[1]._pixmap.width() > tiles[1]._pixmap.height()
        assert tiles[2]._pixmap.width() == tiles[2]._pixmap.height()
    finally:
        mode.close()
        mode.deleteLater()
        app.processEvents()


def test_mosaic_dialog_pre_ticks_tray_selection_renders_grid_preview_and_layouts(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())

    p0 = _make_photo(tmp_path / "p0_land.jpg", (800, 400))
    p1 = _make_photo(tmp_path / "p1_port.jpg", (400, 800))
    p2 = _make_photo(tmp_path / "p2_land.jpg", (800, 400))
    p3 = _make_photo(tmp_path / "p3_sq.jpg", (500, 500))

    mode = TakeMode(lambda: {"url": "https://example.com"})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    mode._clear()
    try:
        mode._add_photo_paths([p0, p1, p2, p3], insert=False)
        # Tick 2, 0, 1 in tray (tray order [0, 1, 2] or selection order [2, 0, 1])
        mode.select_tray_photos([2, 0, 1])
        app.processEvents()

        captured = {}

        orig_exec = QDialog.exec

        def fake_exec(dlg):
            captured["checked"] = dlg._mosaic_checked_indices()
            captured["count_text"] = dlg._mosaic_selected_count_lbl.text()
            captured["layout_count"] = dlg._mosaic_preset.count()
            captured["layout_data"] = dlg._mosaic_preset.currentData()
            captured["preview_tiles"] = list(dlg._mosaic_preview._tiles)
            captured["tile_oris"] = {bi: t._orientation for bi, t in dlg._mosaic_tiles.items()}
            captured["tile_tooltips"] = {bi: t.toolTip() for bi, t in dlg._mosaic_tiles.items()}
            # Paint preview to verify it is non-empty
            pm = dlg._mosaic_preview.grab()
            captured["preview_ok"] = not pm.isNull()
            # Test orientation-aware Suggest arrangement
            dlg._mosaic_suggest_btn.click()
            captured["suggested_layout"] = dlg._mosaic_preset.currentData()
            return QDialog.Rejected

        monkeypatch.setattr(QDialog, "exec", fake_exec)
        mode._canvas_mosaic([], "")

        assert captured["checked"] == [2, 0, 1]
        assert "3 of 4 included" in captured["count_text"]
        assert captured["layout_count"] == 4
        assert captured["layout_data"] in ("one-left", "one-right", "three-across", "one-top")
        assert len(captured["preview_tiles"]) == 3
        assert captured["preview_ok"]
        assert captured["tile_oris"] == {0: "L", 1: "P", 2: "L", 3: "SQ"}
        assert "p1_port.jpg" in captured["tile_tooltips"][1]
        # 2 landscape + 1 portrait -> Suggest picks one-top
        assert captured["suggested_layout"] == "one-top"
    finally:
        mode.close()
        mode.deleteLater()
        app.processEvents()


def test_add_block_real_mouse_click_inserts_above_image(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())

    p1 = _make_photo(tmp_path / "photo.jpg", (600, 400))
    mode = TakeMode(lambda: {"url": "https://example.com"})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    mode._clear()
    try:
        mode._add_photo_paths([p1], insert=False)
        uid = mode._bucket[0].asset_uuid
        canvas = mode.body.canvas
        canvas.from_blocks([
            {"type": "para", "text": "Paragraph above the image."},
            {"type": "image", "img_id": f"asset:{uid}", "asset_uuid": uid, "width_ratio": 1.0},
            {"type": "para", "text": ""},
        ])
        app.processEvents()

        canvas.verticalScrollBar().setValue(0)
        app.processEvents()

        # Real mouse click in the paragraph ABOVE the image
        doc = canvas.document()
        b0 = doc.begin()
        r0 = doc.documentLayout().blockBoundingRect(b0)
        scale = float(getattr(canvas, "_zoom_scale", 1.0) or 1.0)
        QTest.mouseClick(
            canvas.viewport(),
            Qt.LeftButton,
            Qt.NoModifier,
            QPoint(int(round((r0.left() + 30) * scale)), int(round(r0.center().y() * scale))),
        )
        app.processEvents()
        assert canvas.textCursor().block().blockNumber() == 0

        # Real mouse click on + Add block -> click Paragraph in popup QMenu
        def pick_paragraph():
            popup = app.activePopupWidget()
            if popup is not None:
                act = next(a for a in popup.actions() if a.text() == "Paragraph")
                rect = popup.actionGeometry(act)
                QTest.mouseClick(popup, Qt.LeftButton, Qt.NoModifier, rect.center())

        QTimer.singleShot(40, pick_paragraph)
        QTest.mouseClick(mode.add_block_btn, Qt.LeftButton)
        app.processEvents()

        assert canvas.textCursor().block().blockNumber() == 1
        canvas.textCursor().insertText("Inserted above the image.")
        app.processEvents()

        blocks = canvas.to_blocks()
        assert [b["type"] for b in blocks] == ["para", "para", "image"]
        assert blocks[1]["text"] == "Inserted above the image."
    finally:
        mode.close()
        mode.deleteLater()
        app.processEvents()

# ===== SNAPSMACK EOF =====
