"""Browser-powered dialog and inline mosaic regressions."""


# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical Python EOF marker below.

import os
import sys

from PIL import Image
import pytest
from PySide6.QtCore import Qt, QTimer
from PySide6.QtWidgets import QApplication, QDialog, QPushButton
from PySide6.QtTest import QTest

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

_SHARED = os.path.join(os.path.dirname(_ROOT), "_shared")
if _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)

from coldsnap_qt import theme
from coldsnap_qt.canvas import BiggieCanvas
from coldsnap_qt.mode_take import MosaicPreview, TakeMode
from coldsnap_qt.mosaic_clusters import break_cluster_weights, uniform_clusters


def _app():
    return QApplication.instance() or QApplication([])


def _make_photo(path, size, color=(40, 110, 180)):
    im = Image.new("RGB", size, color)
    im.save(str(path), "JPEG")
    return str(path)


def _wait_for(check):
    for _ in range(400):
        if check():
            return
        QTest.qWait(20)
    raise AssertionError("Timed out waiting for preview geometry")


def test_mosaic_browser_renders_shared_wall_modes(tmp_path):
    app = _app()
    sizes = [(900, 600), (600, 900), (800, 800), (1200, 800)]
    tiles = [(i, _make_photo(tmp_path / f"p{i}.jpg", shape)) for i, shape in enumerate(sizes)]
    preview = MosaicPreview(content_width=760)
    preview.show()
    try:
        for width in (760, 360):
            preview.set_content_width(width)
            for layout in ("asymmetric", "columns", "rows", "square"):
                preview.set_mosaic(tiles, layout)
                _wait_for(lambda: len(preview.computed_cells()["items"]) == len(tiles))
                result = preview.computed_cells()
                assert [item["id"] for item in result["items"]] == [1, 2, 3, 4]
                assert max(item["x"] + item["width"] for item in result["items"]) <= width + 1
                assert preview.height() >= result["height"] + 16
    finally:
        preview.close()


def test_tile_three_grows_without_a_different_photo_taking_its_place(tmp_path):
    _app()
    tiles = [(i, _make_photo(tmp_path / f"equal-{i}.jpg", (900, 600))) for i in range(4)]
    preview = MosaicPreview(content_width=760)
    preview.show()
    try:
        preview.set_mosaic(tiles, "asymmetric")
        _wait_for(lambda: len(preview.computed_cells()["items"]) == 4)
        before = preview.computed_cells()["items"]
        preview.set_mosaic(tiles, "asymmetric", weights={3: 1})
        _wait_for(lambda: len(preview.computed_cells()["items"]) == 4)
        after = preview.computed_cells()["items"]
        assert [item["id"] for item in after] == [1, 2, 3, 4]
        assert after[2]["width"] > before[2]["width"] * 1.5
        assert after[2]["width"] > after[0]["width"]
    finally:
        preview.close()


def test_two_by_two_detector_requires_four_adjacent_equal_boxes():
    cells = [
        {"id": i + 1, "x": (i % 2) * 154, "y": (i // 2) * 104,
         "width": 150, "height": 100}
        for i in range(4)
    ]
    assert [[cell["id"] for cell in group] for group in uniform_clusters(cells)] == [[1, 2, 3, 4]]
    assert uniform_clusters([dict(cell, width=230) if cell["id"] == 3 else cell for cell in cells]) == []

@pytest.mark.parametrize("count", [2, 3, 4, 5, 6])
def test_break_up_detects_equal_rows_and_contrasts_four_or_more_photos(count):
    cells = [
        {"id": i + 1, "x": i * 154, "y": 0, "width": 150, "height": 100}
        for i in range(count)
    ]
    clusters = uniform_clusters(cells)
    if count == 2:
        assert not clusters
    else:
        assert len(clusters) == 1
        identities = [cell["id"] for cell in clusters[0]]
        assert identities == list(range(1, count + 1))
        assert break_cluster_weights(identities, lambda _identity: 0) == (
            {1: 1} if count == 3 else {1: 1, count: -1})
        assert break_cluster_weights(identities, lambda _identity: 2) == {count: 1}


def test_inline_two_by_two_click_breaks_the_four_landscapes(tmp_path):
    _app()
    paths = [_make_photo(tmp_path / f"landscape-{i}.jpg", (900, 600)) for i in range(4)]
    canvas = BiggieCanvas()
    canvas.set_preview_width(240)
    canvas.resize(260, 650)
    canvas.set_bucket(paths)
    canvas.insert_mosaic([1, 2, 3, 4], "rows")
    canvas.show()
    try:
        _wait_for(lambda: any(buttons for _, buttons in canvas._inline_mosaic_hits.values()))
        pos, (tiles, buttons) = next(
            (pos, item) for pos, item in canvas._inline_mosaic_hits.items() if item[1])
        assert len({rect.x() for _, rect in tiles.values()}) == 2
        assert len({rect.y() for _, rect in tiles.values()}) == 2
        QTest.mouseClick(canvas.viewport(), Qt.LeftButton, pos=buttons[0][0].center())
        _wait_for(lambda: canvas._object_at(pos)[1].get("weights") == {"1": 1, "4": -1})
        assert canvas._object_at(pos)[1]["order"] == [1, 2, 3, 4]
    finally:
        canvas.close()


def test_inline_break_button_changes_mosaic_without_opening_dialog(tmp_path):
    _app()
    paths = [_make_photo(tmp_path / f"inline-{i}.jpg", (900, 600)) for i in range(4)]
    canvas = BiggieCanvas()
    canvas.set_preview_width(760)
    canvas.resize(800, 650)
    canvas.set_bucket(paths)
    canvas.insert_mosaic([1, 2, 3, 4], "columns")
    dialog_requests = []
    canvas.mosaicEditRequested.connect(lambda *args: dialog_requests.append(args))
    canvas.show()
    try:
        _wait_for(lambda: any(buttons for _, buttons in canvas._inline_mosaic_hits.values()))
        pos, (tiles, buttons) = next(
            (pos, item) for pos, item in canvas._inline_mosaic_hits.items() if item[1])
        assert len({rect.y() for _, rect in tiles.values()}) == 1
        assert next(ids for _rect, ids in buttons if len(ids) == 4) == [1, 2, 3, 4]
        QTest.mouseClick(canvas.viewport(), Qt.LeftButton, pos=buttons[0][0].center())
        _wait_for(lambda: canvas._object_at(pos)[1].get("weights") == {"1": 1, "4": -1})
        _wait_for(lambda: any(
            len(tiles) == 4 and not badges
            for tiles, badges in canvas._inline_mosaic_hits.values()))
        assert canvas._object_at(pos)[1]["order"] == [1, 2, 3, 4]
        assert dialog_requests == []
    finally:
        canvas.close()


def test_dialog_break_button_clears_four_photo_row(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    _app()
    mode = TakeMode(lambda: {"url": "https://example.com"})
    paths = [_make_photo(tmp_path / f"dialog-{i}.jpg", (900, 600)) for i in range(4)]
    mode._add_photo_paths(paths, insert=False)

    def exercise_dialog(dialog):
        dialog.show()
        preview = dialog.findChild(MosaicPreview)
        try:
            _wait_for(lambda: any(len(cluster) == 4 for cluster in preview._clusters))
            button = next(button for button in preview.findChildren(QPushButton)
                          if button.text() == "Break up" and button.isVisible())
            QTest.mouseClick(button, Qt.LeftButton)
            _wait_for(lambda: preview._weights == {"1": 1, "4": -1}
                      and len(preview.computed_cells()["items"]) == 4 and not preview._clusters)
        finally:
            dialog.reject()
        return QDialog.Rejected

    monkeypatch.setattr(QDialog, "exec", exercise_dialog)
    try:
        mode._mosaic_dialog([], "columns", preselected_order=[0, 1, 2, 3])
    finally:
        mode.close()


def test_mosaic_dialog_is_maximisable_and_uses_preview_width(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())
    mode = TakeMode(lambda: {"url": "https://example.com"})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    mode._clear()
    try:
        paths = [
            _make_photo(tmp_path / "a.jpg", (900, 600)),
            _make_photo(tmp_path / "b.jpg", (600, 900)),
            _make_photo(tmp_path / "c.jpg", (800, 800)),
        ]
        mode._add_photo_paths(paths, insert=False)
        mode._preview_width = 360

        seen = {}
        orig_exec = QDialog.exec

        def _capture_exec(dlg):
            seen["flags"] = dlg.windowFlags()
            seen["size_grip"] = dlg.isSizeGripEnabled()
            previews = dlg.findChildren(MosaicPreview)
            seen["preview_count"] = len(previews)
            seen["content_width"] = previews[0]._content_width if previews else None
            QTimer.singleShot(0, dlg.reject)
            return orig_exec(dlg)

        monkeypatch.setattr(QDialog, "exec", _capture_exec)
        mode._mosaic_dialog([], "asymmetric")

        assert seen["flags"] & Qt.WindowMaximizeButtonHint
        assert seen["size_grip"] is True
        assert seen["preview_count"] == 1
        assert seen["content_width"] == 360
    finally:
        mode.close()

# ===== SNAPSMACK EOF =====
