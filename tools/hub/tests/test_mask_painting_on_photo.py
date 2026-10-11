"""Masking must be done ON the photograph, and must not lose what was painted.

These drive the real editor window offscreen because the failures they cover
were invisible to source-text checks: a mask painted on the photograph was
being replaced by an enlargement of the 238-pixel rail preview, painting gave
no feedback except the layer's own effect, and the brush size slider meant a
different size in each of the two places it applied.
"""

import os
import sys
import time

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "_shared"))

from PIL import Image
from PySide6.QtCore import Qt, QPoint
from PySide6.QtTest import QTest
from PySide6.QtWidgets import QApplication, QMessageBox

import editor_engine
from slapper_qt import editor_window


APP = QApplication.instance() or QApplication([])


def _wait_for(predicate, milliseconds=20000):
    deadline = time.monotonic() + milliseconds / 1000
    while not predicate() and time.monotonic() < deadline:
        QTest.qWait(10)
        APP.processEvents()
    assert predicate()


def _opened_window(tmp_path, size=(900, 600)):
    source = tmp_path / "photo.png"
    Image.new("RGB", size, (90, 110, 130)).save(source)
    window = editor_window.EditorWindow()
    assert window.open_path(source)
    _wait_for(lambda: window.doc is not None)
    _wait_for(lambda: window._last_rendered is not None)
    return window


def _with_mask_layer(window):
    window.layers_panel._add_adjustment()
    APP.processEvents()
    assert window._mask_layer() is not None
    window._activate_canvas_mask_tool("brush")
    APP.processEvents()
    return window._mask_layer()


def _stored_mask(layer):
    return editor_engine._mask_from_text(layer["mask"])


def _hidden_pixels(mask):
    return sum(1 for value in mask.getdata() if value < 128)


def test_choosing_the_brush_starts_painting_on_the_photograph(tmp_path):
    window = _opened_window(tmp_path)
    try:
        _with_mask_layer(window)
        # Choosing the brush is the request to paint. It used to leave a second
        # button unpressed, so every click on the photograph still panned it.
        assert window.paint_on_photo.isChecked()
        assert window.view._mask_paint_mode is True
        assert window.view._mask_brush_radius > 0
    finally:
        window.close()


def test_painting_shows_a_red_wash_and_a_brush_ring_on_the_photograph(tmp_path):
    window = _opened_window(tmp_path)
    try:
        _with_mask_layer(window)
        # Nothing hidden yet, so there is nothing to wash.
        assert window.view._mask_overlay is None
        window._paint_canvas_mask(0.5, 0.5, True)
        APP.processEvents()
        overlay = window.view._mask_overlay
        assert overlay is not None and not overlay.isNull()
        assert (overlay.width(), overlay.height()) == window._canvas_mask.size
        # Turning the wash off is allowed; losing it silently was the bug.
        window.show_mask_wash.setChecked(False)
        assert window.view._mask_overlay is None
    finally:
        window.close()


def test_the_rail_preview_cannot_replace_a_mask_painted_on_the_photo(tmp_path):
    window = _opened_window(tmp_path)
    try:
        layer = _with_mask_layer(window)
        window.brush_size.set_value(6)
        window._sync_brush_radius()
        window._set_paint_white(False)
        for x in (0.20, 0.25, 0.30, 0.35):
            window._paint_canvas_mask(x, 0.5, False)
        window._paint_canvas_mask(0.40, 0.5, True)
        APP.processEvents()
        painted = _hidden_pixels(_stored_mask(layer))
        assert painted > 0
        detail = window._canvas_mask.copy()

        # A stroke on the small preview: only its own change may carry over.
        window.mask_brush.set_paint_white(False)
        middle = QPoint(window.mask_brush.width() - 20,
                        window.mask_brush.height() - 20)
        QTest.mousePress(window.mask_brush, Qt.LeftButton, Qt.NoModifier, middle)
        QTest.mouseRelease(window.mask_brush, Qt.LeftButton, Qt.NoModifier, middle)
        APP.processEvents()

        merged = _stored_mask(layer)
        assert merged.size == detail.size
        # The strokes painted on the photograph survive, pixel for pixel, in
        # the region the preview stroke never touched.
        left = detail.crop((0, 0, detail.width // 2, detail.height))
        merged_left = merged.crop((0, 0, merged.width // 2, merged.height))
        assert _hidden_pixels(merged_left) >= _hidden_pixels(left)
        # And the preview's own corner stroke did land.
        assert _hidden_pixels(merged) > painted
    finally:
        window.close()


def test_swap_flips_the_painted_mask_instead_of_discarding_it(tmp_path):
    window = _opened_window(tmp_path)
    try:
        layer = _with_mask_layer(window)
        window._set_paint_white(False)
        window._paint_canvas_mask(0.5, 0.5, True)
        APP.processEvents()
        before = _stored_mask(layer)
        hidden = _hidden_pixels(before)
        assert hidden > 0

        window.mask_invert.setChecked(True)      # Swap hidden and revealed
        APP.processEvents()
        after = _stored_mask(layer)
        total = after.width * after.height
        # A flip, not a rebuild: what was hidden is now revealed and the count
        # is the complement. Rebuilding from the rail preview changed the
        # number by thousands of pixels and lost the strokes' shape.
        assert abs(_hidden_pixels(after) - (total - hidden)) < total * 0.02
    finally:
        window.close()


def test_leaving_paint_mode_and_returning_does_not_flip_the_mask(tmp_path):
    window = _opened_window(tmp_path)
    try:
        layer = _with_mask_layer(window)
        window._set_paint_white(False)
        window._paint_canvas_mask(0.5, 0.5, True)
        APP.processEvents()
        window.mask_invert.setChecked(True)
        APP.processEvents()
        flipped = _hidden_pixels(_stored_mask(layer))
        window.paint_on_photo.setChecked(False)
        window.paint_on_photo.setChecked(True)
        APP.processEvents()
        window._paint_canvas_mask(0.9, 0.9, True)
        APP.processEvents()
        # Swap used to be re-applied on every store as well as flipping the
        # mask once, so switching paint mode off and on inverted the mask.
        now = _hidden_pixels(_stored_mask(layer))
        assert abs(now - flipped) < flipped * 0.5
    finally:
        window.close()


def test_one_brush_size_applies_to_the_photo_and_the_preview(tmp_path):
    window = _opened_window(tmp_path)
    try:
        _with_mask_layer(window)
        window.brush_size.set_value(30)
        window._sync_brush_radius()
        fraction = window._brush_radius_fraction()
        assert abs(window.view._mask_brush_radius - fraction) < 1e-6
        short_edge = min(window.mask_brush.width(), window.mask_brush.height())
        # The preview used the slider as display pixels while the photograph
        # used it as a proportion, so 30 meant two very different brushes.
        assert window.mask_brush._radius == max(3, round(fraction * short_edge))
    finally:
        window.close()


def test_bucket_fill_reaches_the_photographs_mask_not_only_the_preview(tmp_path):
    window = _opened_window(tmp_path)
    try:
        layer = _with_mask_layer(window)
        window._bucket_fill(False)
        APP.processEvents()
        mask = _stored_mask(layer)
        assert _hidden_pixels(mask) == mask.width * mask.height
        window._bucket_fill(True)
        APP.processEvents()
        assert _hidden_pixels(_stored_mask(layer)) == 0
    finally:
        window.close()


def test_x_swaps_hide_and_reveal(tmp_path):
    window = _opened_window(tmp_path)
    try:
        _with_mask_layer(window)
        assert window.btn_hide.isChecked()
        window._mask_key("swap")
        assert window.btn_reveal.isChecked()
        assert window.mask_brush._paint_white is True
        window._mask_key("swap")
        assert window.btn_hide.isChecked()
        assert window.mask_brush._paint_white is False
    finally:
        window.close()


def test_masking_without_a_layer_offers_to_make_one(tmp_path, monkeypatch):
    window = _opened_window(tmp_path)
    try:
        asked = {}

        def fake_question(parent, title, text, *args, **kwargs):
            asked["title"] = title
            asked["text"] = text
            return QMessageBox.Yes

        monkeypatch.setattr(QMessageBox, "question", fake_question)
        assert window._mask_layer() is None          # the base photo is selected
        window._activate_canvas_mask_tool("brush")
        APP.processEvents()
        # A status line that scrolls away is not an answer to "why can't I mask".
        assert "layer" in asked["title"].lower()
        assert window._mask_layer() is not None
        assert window.paint_on_photo.isChecked()
    finally:
        window.close()


# ===== SNAPSMACK EOF =====
