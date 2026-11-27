"""COLD SNAP width applies to the canvas and survives the CMS wire path.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical Python SNAPSMACK EOF marker.
"""

from types import SimpleNamespace

from coldsnap_qt import biggie
from coldsnap_qt.mode_take import TakeMode


def test_explicit_resize_changes_only_the_width_token():
    for original, expected in (
        ("[img:5|40]", "[img:5|75]"),
        ("[img: 5 | 40% | decorative]", "[img: 5 | 75% | decorative]"),
        ("[img:g7|wall|right|caption=x]", "[img:g7|wall|right|75|caption=x]"),
        ("[img:bucket:1|decorative]", "[img:bucket:1|75|decorative]"),
    ):
        assert biggie.serialize_blocks(biggie.parse_body(original)) == original
        assert biggie.resize_image_shortcode(original, 75) == expected


def test_authored_local_width_is_wired_as_cms_percent():
    block = {"type": "image", "img_id": "asset:one", "asset_uuid": "one",
             "width_ratio": .5, "placement": "block", "size": "wall", "align": "center"}
    draft = SimpleNamespace(_bucket=[SimpleNamespace(asset_uuid="one")])
    wired = TakeMode._wire_blocks(draft, [block])
    assert biggie.image_width_percent(block) == 50
    assert biggie.serialize_blocks(wired) == "[img:bucket:1|wall|center|50]"
    assert biggie.image_width_percent({"width": "10%"}) == 20
    assert biggie.image_width_percent({"width": "120"}) == 100
    assert biggie.image_width_percent({"size": "wall"}) is None


def test_visual_edge_drag_updates_existing_width_and_keeps_decorative():
    from PySide6.QtCore import QPoint, Qt
    from PySide6.QtTest import QTest
    from PySide6.QtWidgets import QApplication
    from coldsnap_qt.canvas import BiggieCanvas

    app = QApplication.instance() or QApplication([])
    canvas = BiggieCanvas()
    canvas.resize(760, 420)
    canvas.set_preview_width(720)
    canvas.from_blocks(biggie.parse_body("[img:5|40%|decorative]"))
    canvas.show()
    canvas.select_block_at(0)
    app.processEvents()
    rect, _, initial, column_width, _ = canvas._image_resize_handle
    assert initial == 40
    start = rect.center()
    end = start + QPoint(round(column_width * .1), 0)
    QTest.mousePress(canvas.viewport(), Qt.LeftButton, pos=start)
    QTest.mouseMove(canvas.viewport(), end)
    QTest.mouseRelease(canvas.viewport(), Qt.LeftButton, pos=end)
    assert canvas.body_text() == "[img:5|60%|decorative]"

    canvas.from_blocks(biggie.parse_body("[img:5|100|decorative]"))
    canvas.select_block_at(0)
    app.processEvents()
    rect, _, initial, column_width, _ = canvas._image_resize_handle
    assert initial == 100
    assert canvas.viewport().rect().contains(rect)
    start = rect.center()
    end = start - QPoint(round(column_width * .1), 0)
    QTest.mousePress(canvas.viewport(), Qt.LeftButton, pos=start)
    QTest.mouseRelease(canvas.viewport(), Qt.LeftButton, pos=end)
    assert canvas.body_text() == "[img:5|80|decorative]"
    canvas.close()


def test_twiggy_edge_drags_only_shortcode_under_cursor(monkeypatch, tmp_path):
    from PySide6.QtCore import QPoint, Qt
    from PySide6.QtGui import QColor, QPixmap
    from PySide6.QtTest import QTest
    from PySide6.QtWidgets import QDialog
    from coldsnap_qt.mode_take import _ImageWidthPreview
    from test_ss1514_open_published_preserves_draft import _FakePoster, _setup_mode

    app, mode = _setup_mode(monkeypatch, tmp_path, _FakePoster([], {}))
    editor = mode.body.editor
    mode.body._set_biggie(False)
    original = "😀 before [img:5|40%|decorative] after [img:6|30]"
    editor.setPlainText(original)
    cursor = editor.document().find("40%")
    cursor.clearSelection()
    editor.setTextCursor(cursor)
    image = QPixmap(240, 120)
    image.fill(QColor("#1968aa"))
    mode.body.canvas.set_image_resolver(lambda image_id, _width: image if image_id == "5" else None)

    def interact(dialog):
        dialog.show()
        app.processEvents()
        preview = dialog.findChild(_ImageWidthPreview)
        assert preview is not None and preview._handle.isValid()
        start = preview._handle.center()
        end = start + QPoint(round((preview.width() - 26) * .1), 0)
        QTest.mousePress(preview, Qt.LeftButton, pos=start)
        QTest.mouseMove(preview, end)
        QTest.mouseRelease(preview, Qt.LeftButton, pos=end)
        assert preview.percent == 60
        return QDialog.Accepted

    try:
        monkeypatch.setattr(QDialog, "exec", lambda _dialog: QDialog.Rejected)
        mode._resize_text_image()
        assert editor.toPlainText() == original
        monkeypatch.setattr(QDialog, "exec", interact)
        mode._resize_text_image()
        assert editor.toPlainText() == original.replace("40%", "60%")
        mode.body._set_biggie(True)
        mode.body._set_biggie(False)
        assert editor.toPlainText() == original.replace("40%", "60%")
    finally:
        mode.close()


# ===== SNAPSMACK EOF =====
