"""Tests for SS-1492 and SS-1493:
- SS-1492: First Backspace at start of text block after an image selects (highlights)
  the image without losing any block; second Backspace deletes it; Ctrl+Z undo
  restores the image (returning it to the post and removing it from the tray).
- SS-1493:
  1. PAGE zoom (80%, 100%, 130%) scales the whole page as one unit while locking
     the column to the preview width (760 / 360) with identical line count and
     line break positions.
  2. Spacing after a placed image matches normal block margins (no 150% line-height
     huge gap below the image).
  3. Every text block uses the same body font family and size (no mixed sans/serif).
  4. "+ Add block" inserts directly after the focused block (above an image when
     cursor is in the paragraph above), and offers Paragraph before / Paragraph after
     when an image is selected.
"""

import os
import sys

from PIL import Image
from PySide6.QtCore import Qt
from PySide6.QtGui import QKeyEvent, QTextCursor
from PySide6.QtWidgets import QApplication

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

_SHARED = os.path.join(os.path.dirname(_ROOT), "_shared")
if _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)

from coldsnap_qt import theme
from coldsnap_qt.mode_take import TakeMode


def _app():
    return QApplication.instance() or QApplication([])


def _make_photo(path, size=(600, 400), color=(40, 110, 180)):
    im = Image.new("RGB", size, color)
    im.save(str(path), "JPEG")
    return str(path)


def _line_breaks(block):
    lay = block.layout()
    return [(lay.lineAt(i).textStart(), lay.lineAt(i).textLength()) for i in range(lay.lineCount())]


def test_ss1492_backspace_after_image_selects_then_deletes_and_undo_restores(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())

    p1 = _make_photo(tmp_path / "hero.jpg", size=(600, 400))
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
            {"type": "para", "text": "Opening paragraph above the photograph."},
            {"type": "image", "img_id": f"asset:{uid}", "asset_uuid": uid, "width_ratio": 1.0},
            {"type": "para", "text": "Closing paragraph after the photograph."},
        ])
        mode._changed()
        app.processEvents()

        assert len(canvas.to_blocks()) == 3
        assert "3 blocks" in mode.page_state_lbl.text()
        assert 0 in mode._used_photo_indices()

        # Place cursor at the start of the text block immediately after the image
        doc = canvas.document()
        b_open = doc.begin()
        b_img = b_open.next()
        b_close = b_img.next()
        cur = QTextCursor(b_close)
        cur.movePosition(QTextCursor.StartOfBlock)
        canvas.setTextCursor(cur)
        assert canvas.textCursor().atBlockStart()

        # First Backspace: must select/highlight the image, NOT delete the image or merge blocks
        ev1 = QKeyEvent(QKeyEvent.KeyPress, Qt.Key_Backspace, Qt.NoModifier)
        canvas.keyPressEvent(ev1)
        app.processEvents()

        blocks_after_first = canvas.to_blocks()
        assert len(blocks_after_first) == 3
        assert [b["type"] for b in blocks_after_first] == ["para", "image", "para"]
        assert canvas._selected_block_pos == b_img.position()
        assert "3 blocks" in mode.page_state_lbl.text()
        assert 0 in mode._used_photo_indices()

        # Second Backspace: deletes the selected image
        ev2 = QKeyEvent(QKeyEvent.KeyPress, Qt.Key_Backspace, Qt.NoModifier)
        canvas.keyPressEvent(ev2)
        mode._changed()
        app.processEvents()

        blocks_after_second = canvas.to_blocks()
        assert [b["type"] for b in blocks_after_second] == ["para", "para"]
        assert "2 blocks" in mode.page_state_lbl.text()
        # Removed photo returned to the unplaced tray
        assert 0 not in mode._used_photo_indices()
        assert "1 left" in mode.photos_sec.header.text()

        # Ctrl+Z (undo): restores the deleted image to the post and removes it from the tray
        canvas.undo()
        mode._changed()
        app.processEvents()

        blocks_after_undo = canvas.to_blocks()
        assert [b["type"] for b in blocks_after_undo] == ["para", "image", "para"]
        assert "3 blocks" in mode.page_state_lbl.text()
        assert 0 in mode._used_photo_indices()
        assert "0 left" in mode.photos_sec.header.text()
    finally:
        mode.close()
        mode.deleteLater()
        app.processEvents()


def test_ss1493_page_zoom_preserves_exact_line_breaks_at_80_100_130(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())

    mode = TakeMode(lambda: {"url": "https://example.com"})
    mode.resize(1600, 900)
    mode.show()
    app.processEvents()
    try:
        para = (
            "Most software lives in a browser because it suits the vendor, not you. "
            "SNAPSMACK keeps the website light and does the heavy lifting on your own "
            "computer so every photograph and essay stays under your roof."
        )
        canvas = mode.body.canvas
        canvas.from_blocks([{"type": "para", "text": para}])
        app.processEvents()

        breaks_by_zoom = {}
        card_widths = {}
        for pct in (80, 100, 130):
            mode._set_page_zoom(pct)
            app.processEvents()
            pm = canvas.grab()
            assert not pm.isNull()
            blk = canvas.document().begin()
            breaks_by_zoom[pct] = _line_breaks(blk)
            card_widths[pct] = mode.page_card.width()
            assert canvas.document().textWidth() == 760.0

        assert len(breaks_by_zoom[100]) >= 2
        assert breaks_by_zoom[80] == breaks_by_zoom[100] == breaks_by_zoom[130]
        assert card_widths[80] < card_widths[100] < card_widths[130]
    finally:
        mode.close()
        mode.deleteLater()
        app.processEvents()


def test_ss1493_no_huge_gap_after_image_and_consistent_fonts(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())

    p1 = _make_photo(tmp_path / "wide.jpg", size=(800, 400))
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
            {"type": "para", "text": "First paragraph before the image."},
            {"type": "image", "img_id": f"asset:{uid}", "asset_uuid": uid, "width_ratio": 1.0},
            {"type": "para", "text": "Second paragraph right below the image."},
        ])
        app.processEvents()

        doc = canvas.document()
        lay = doc.documentLayout()
        b0 = doc.begin()
        b1 = b0.next()
        b2 = b1.next()

        r1 = lay.blockBoundingRect(b1)
        r2 = lay.blockBoundingRect(b2)
        gap_after_image = r2.top() - (r1.top() + r1.height())
        # Normal block margin spacing (8px bottom + 8px top = 16px), never 200+ px
        assert 0 <= gap_after_image <= 28, f"Expected tight margin after image, got {gap_after_image}px"

        # Both paragraphs must use the exact same body font family and size
        f0 = b0.begin().fragment().charFormat().font()
        f2 = b2.begin().fragment().charFormat().font()
        assert f0.family() == f2.family() == canvas._body_font_family
        assert abs(f0.pointSizeF() - f2.pointSizeF()) < 1e-4
    finally:
        mode.close()
        mode.deleteLater()
        app.processEvents()


def test_ss1493_add_block_inserts_at_cursor_not_end(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())

    p1 = _make_photo(tmp_path / "photo.jpg", size=(600, 400))
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
            {"type": "para", "text": "Paragraph below the image."},
        ])
        app.processEvents()

        # Click/focus inside the first paragraph (ABOVE the image)
        b_above = canvas.document().begin()
        cur = QTextCursor(b_above)
        cur.movePosition(QTextCursor.EndOfBlock)
        canvas.setTextCursor(cur)

        # Trigger "+ Add block" -> "Paragraph" and type into the new block
        menu = mode._build_add_block_menu()
        para_action = next(a for a in menu.actions() if a.text() == "Paragraph")
        para_action.trigger()
        canvas.textCursor().insertText("Inserted directly after first paragraph.")
        app.processEvents()

        blocks = canvas.to_blocks()
        assert [b["type"] for b in blocks] == ["para", "para", "image", "para"]
        assert blocks[1]["text"] == "Inserted directly after first paragraph."

        # Select the image block and verify "Paragraph before" / "Paragraph after" are offered
        b_img = canvas.document().begin().next().next()
        canvas.select_block_at(b_img.position())
        img_menu = mode._build_add_block_menu()
        action_labels = [a.text() for a in img_menu.actions() if a.text()]
        assert "Paragraph before" in action_labels
        assert "Paragraph after" in action_labels
    finally:
        mode.close()
        mode.deleteLater()
        app.processEvents()

# ===== SNAPSMACK EOF =====
