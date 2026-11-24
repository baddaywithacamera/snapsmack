"""SS-1550: BIGGIE object Move earlier/later preserves and reorders blocks.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys

from PySide6.QtGui import QTextCursor
from PySide6.QtWidgets import QApplication

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

from coldsnap_qt.canvas import BiggieCanvas  # noqa: E402


def _app():
    return QApplication.instance() or QApplication([])


def _canvas():
    _app()
    canvas = BiggieCanvas()
    canvas.from_blocks([
        {"type": "para", "text": "Before"},
        {"type": "mosaic", "order": [2, 1, 3], "layout": "one-left"},
        {"type": "para", "text": "After"},
    ])
    return canvas


def _mosaic_position(canvas):
    block = canvas.document().begin()
    while block.isValid():
        hit = canvas._object_at(block.position())
        if hit and hit[0] == "mosaic":
            return hit[2]
        block = block.next()
    raise AssertionError("mosaic object was not found")


def test_move_earlier_reorders_without_merging_or_losing_blocks():
    canvas = _canvas()
    pos = _mosaic_position(canvas)

    canvas._move_object(pos, -1)

    assert canvas.to_blocks() == [
        {"type": "mosaic", "order": [2, 1, 3], "layout": "one-left"},
        {"type": "para", "text": "Before"},
        {"type": "para", "text": "After"},
    ]
    canvas.close()


def test_move_later_reorders_without_merging_or_losing_blocks():
    canvas = _canvas()
    pos = _mosaic_position(canvas)

    canvas._move_object(pos, 1)

    assert canvas.to_blocks() == [
        {"type": "para", "text": "Before"},
        {"type": "para", "text": "After"},
        {"type": "mosaic", "order": [2, 1, 3], "layout": "one-left"},
    ]
    canvas.close()


def test_shared_move_control_also_preserves_inline_images():
    _app()
    canvas = BiggieCanvas()
    canvas.from_blocks([
        {"type": "para", "text": "Before"},
        {"type": "image", "img_id": "42", "size": "full", "align": "center"},
        {"type": "para", "text": "After"},
    ])
    block = canvas.document().begin()
    while block.isValid():
        hit = canvas._object_at(block.position())
        if hit and hit[0] == "image":
            canvas._move_object(hit[2], 1)
            break
        block = block.next()

    assert canvas.to_blocks() == [
        {"type": "para", "text": "Before"},
        {"type": "para", "text": "After"},
        {"type": "image", "img_id": "42", "size": "full", "align": "center"},
    ]
    canvas.close()


def test_move_is_one_undoable_edit_and_boundaries_are_disabled():
    canvas = _canvas()
    original = canvas.to_blocks()
    pos = _mosaic_position(canvas)
    assert canvas._can_move_object(pos, -1)
    assert canvas._can_move_object(pos, 1)

    canvas._move_object(pos, -1)
    moved_pos = _mosaic_position(canvas)
    assert not canvas._can_move_object(moved_pos, -1)
    assert canvas._can_move_object(moved_pos, 1)

    canvas.undo()
    assert canvas.to_blocks() == original
    canvas.close()


def test_move_repaints_dropcap_after_document_rebuild():
    _app()
    canvas = BiggieCanvas()
    canvas.from_blocks([
        {"type": "para", "text": "Dropcap stays painted", "dropcap": True},
        {"type": "mosaic", "order": [1, 2], "layout": "two-equal"},
    ])
    canvas._move_object(_mosaic_position(canvas), -1)

    block = canvas.document().begin()
    while block.isValid() and block.text() != "Dropcap stays painted":
        block = block.next()
    assert block.isValid()
    first = QTextCursor(block)
    first.setPosition(block.position())
    first.setPosition(block.position() + 1, QTextCursor.KeepAnchor)
    second = QTextCursor(block)
    second.setPosition(block.position() + 1)
    second.setPosition(block.position() + 2, QTextCursor.KeepAnchor)
    assert first.charFormat().font().pointSizeF() > second.charFormat().font().pointSizeF()
    canvas.close()


# ===== SNAPSMACK EOF =====
