"""Regression coverage for the trailing mosaic cluster and dialog photo tray.

SNAPSMACK_EOF_HEADER: the last non-empty line is the canonical EOF marker.
"""

import pytest
from PIL import Image
from PySide6.QtCore import QPoint, Qt, QTimer
from PySide6.QtGui import QContextMenuEvent
from PySide6.QtTest import QTest
from PySide6.QtWidgets import QApplication, QDialog, QMenu

from test_coldsnap_ss1557_mosaic_arrangement import (
    O, TakeMode, _app, _run_dialog, _wait_for_preview,
)


def _bucket(tmp_path, ratios):
    images = []
    for index, ratio in enumerate(ratios, 1):
        path = tmp_path / f"photo-{index}.jpg"
        width = round(ratio * 800)
        Image.new("RGB", (width, 800), (index * 8, 80, 120)).save(path)
        images.append(O.DraftImage(
            local_path=str(path), filename=path.name,
            asset_uuid=f"photo-{index}", width=width, height=800,
        ))
    return images


@pytest.mark.parametrize("tile", [12, 13, 14, 15])
@pytest.mark.parametrize("action_text", ["Make larger", "Make smaller", "Break up"])
def test_trailing_quad_actions_change_rendered_geometry(monkeypatch, tmp_path, tile, action_text):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    _app()
    mode = TakeMode(lambda: {})
    mode._preview_width = 1200
    mode._bucket = _bucket(tmp_path, [
        .66, .55, 3, .66, .8, 2, 2, 1.5, 1.2, 3, 3, 1.5, 1.5, 1.5, 1.5,
    ])
    target = {12, 13, 14, 15}

    def exercise(dialog):
        preview = dialog._mosaic_preview
        before = _wait_for_preview(preview)
        assert any({cell["id"] for cell in group} == target for group in preview._clusters)
        menu = preview._arrangement_menu(tile - 1)
        action = next(action for action in menu.actions() if action.text() == action_text)
        assert action.isEnabled()
        action.trigger()
        after = _wait_for_preview(preview)
        assert [cell["id"] for cell in after] == list(range(1, 16))
        assert [cell for cell in after if cell["id"] in target] != [
            cell for cell in before if cell["id"] in target
        ]
        assert after[:11] == before[:11]
        if action_text == "Break up":
            assert not any({cell["id"] for cell in group} == target for group in preview._clusters)
        else:
            original, edited = before[tile - 1], after[tile - 1]
            before_area = original["width"] * original["height"]
            after_area = edited["width"] * edited["height"]
            if action_text == "Make larger":
                assert after_area > before_area
            else:
                assert after_area < before_area

    try:
        _run_dialog(monkeypatch, mode, list(range(15)), "asymmetric", exercise)
    finally:
        mode.close()


@pytest.mark.parametrize("action_text", ["Remove from tray", "★ Set as featured (landing tile)"])
@pytest.mark.parametrize("accept_edits", [True, False])
def test_edit_mosaic_tray_photo_28_actions(monkeypatch, tmp_path, action_text, accept_edits):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    _app()
    mode = TakeMode(lambda: {})
    mode._bucket = _bucket(tmp_path, [1.5] * 29)
    photo = mode._bucket[27]
    position = QPoint(20, 20)

    selected = []

    def choose_remove():
        menu = QApplication.activePopupWidget()
        if isinstance(menu, QMenu):
            action = next(action for action in menu.actions() if action.text() == action_text)
            selected.append(action.text())
            QTest.mouseClick(menu, Qt.LeftButton, pos=menu.actionGeometry(action).center())

    def exercise(dialog):
        tray_tile = dialog._mosaic_tiles[27]
        QTimer.singleShot(0, choose_remove)
        tray_tile.contextMenuEvent(QContextMenuEvent(
            QContextMenuEvent.Mouse, position, tray_tile.mapToGlobal(position),
        ))
        assert selected == [action_text]
        if action_text == "Remove from tray":
            assert all(tile._asset_uuid != photo.asset_uuid for tile in dialog._mosaic_tiles.values())
        else:
            assert dialog._mosaic_tiles[27]._is_cover
        assert dialog._mosaic_checked_indices() == [0, 1, 2, 28]

    try:
        def accept(dialog):
            _wait_for_preview(dialog._mosaic_preview)
            exercise(dialog)
            assert mode._bucket[27] is photo
            return QDialog.Accepted if accept_edits else QDialog.Rejected

        monkeypatch.setattr(QDialog, "exec", accept)
        mode.body._set_biggie(False)
        mode.body.editor.setPlainText(
            "[mosaic=1,2,3,29 layout=asymmetric]\n\n[img:bucket:29|full|center]"
        )
        mode._insert_mosaic()
        removed = accept_edits and action_text == "Remove from tray"
        expected_order = [1, 2, 3, 28 if removed else 29]
        blocks = mode.body.authoring_blocks()
        assert blocks[0]["order"] == expected_order
        assert blocks[1]["raw"] == f"[img:bucket:{expected_order[-1]}|full|center]"
        assert (photo not in mode._bucket) == removed
        assert (mode._cover_idx == 27) == (accept_edits and action_text.startswith("★"))
        assert (tmp_path / photo.filename).is_file()
    finally:
        mode.close()


@pytest.mark.parametrize(("action_text", "expected"), [
    ("Move this image earlier", [2, 1, 3, 4]),
    ("Move this image later", [1, 3, 2, 4]),
    ("Swap this image to the other side", [1, 2, 3, 4]),
    ("Reverse arrangement", [1, 2, 3, 4]),
    ("Include selected tray photos", [1, 2, 3, 4, 5]),
    ("Exclude this image", [1, 3, 4]),
])
def test_other_preview_menu_actions(monkeypatch, tmp_path, action_text, expected):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    _app()
    mode = TakeMode(lambda: {})
    mode._bucket = _bucket(tmp_path, [1.5, .8, 1, 1.4, 1.5])
    mode.select_tray_photos([4])

    def exercise(dialog):
        preview = dialog._mosaic_preview
        before = _wait_for_preview(preview)
        menu = preview._arrangement_menu(1)
        action = next(action for action in menu.actions() if action.text() == action_text)
        assert action.isEnabled()
        action.trigger()
        assert _wait_for_preview(preview) != before

    try:
        result = _run_dialog(monkeypatch, mode, [0, 1, 2, 3], "asymmetric", exercise)
        assert result[0] == expected
        expected_layout = "one-left" if action_text == "Exclude this image" else "asymmetric"
        assert result[1] == expected_layout
        if action_text in {"Swap this image to the other side", "Reverse arrangement"}:
            assert mode._last_mosaic_mirrors == ["0:4"]
    finally:
        mode.close()


# ===== SNAPSMACK EOF =====
