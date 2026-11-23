"""SS-1557: mosaic right-click actions change placement, never pixels.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import hashlib
import os
import sys

from PIL import Image
from PySide6.QtWidgets import QApplication, QDialog, QPushButton

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

import sumna_offline as O  # noqa: E402
from coldsnap_qt.mode_take import MosaicPreview, TakeMode  # noqa: E402


def _app():
    return QApplication.instance() or QApplication([])


def _photos(tmp_path, count=4):
    paths = []
    for index, size in enumerate(((900, 600), (600, 900), (800, 800), (1000, 600))[:count]):
        path = tmp_path / f"photo-{index}.jpg"
        Image.new("RGB", size, (40 + index * 30, 80, 120)).save(path)
        paths.append(str(path))
    return paths


def _digest(path):
    return hashlib.sha256(open(path, "rb").read()).hexdigest()


def _run_dialog(monkeypatch, mode, order, layout, action):
    def fake_exec(dialog):
        dialog._mosaic_preview.grab()
        action(dialog)
        return QDialog.Accepted

    monkeypatch.setattr(QDialog, "exec", fake_exec)
    return mode._mosaic_dialog(order, layout)


def test_right_click_arrangement_actions_only_reorder_saved_ids(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    _app()
    paths = _photos(tmp_path)
    before = [_digest(path) for path in paths]
    mode = TakeMode(lambda: {"url": "https://example.test"})
    mode._bucket = [O.DraftImage(local_path=path, original_path=path) for path in paths]
    try:
        moved = _run_dialog(
            monkeypatch, mode, [0, 1, 2, 3], "asymmetric",
            lambda dialog: dialog._mosaic_move_preview(2, -1),
        )
        assert moved == ([1, 3, 2, 4], "asymmetric")

        reversed_result = _run_dialog(
            monkeypatch, mode, [0, 1, 2, 3], "asymmetric",
            lambda dialog: dialog._mosaic_reverse(),
        )
        assert reversed_result == ([4, 3, 2, 1], "asymmetric")

        side_swapped = _run_dialog(
            monkeypatch, mode, [0, 1, 2, 3], "asymmetric",
            lambda dialog: dialog._mosaic_swap_side(0),
        )
        assert side_swapped[0] != [1, 2, 3, 4]
        assert sorted(side_swapped[0]) == [1, 2, 3, 4]

        assert [_digest(path) for path in paths] == before
        assert not any(image.edit_flip_h or image.edit_flip_v for image in mode._bucket)
    finally:
        mode.close()


def test_three_photo_reverse_changes_side_and_order(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    _app()
    paths = _photos(tmp_path, 3)
    mode = TakeMode(lambda: {"url": "https://example.test"})
    mode._bucket = [O.DraftImage(local_path=path, original_path=path) for path in paths]
    try:
        result = _run_dialog(
            monkeypatch, mode, [0, 1, 2], "one-left",
            lambda dialog: dialog._mosaic_reverse(),
        )
        assert result == ([3, 2, 1], "one-right")
    finally:
        mode.close()


def test_rearranged_order_reaches_the_saved_mosaic_block(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    _app()
    paths = _photos(tmp_path)
    mode = TakeMode(lambda: {"url": "https://example.test"})
    mode._bucket = [O.DraftImage(local_path=path, original_path=path) for path in paths]
    identities = [str(image.asset_uuid) for image in mode._bucket]
    try:
        mode.body._set_biggie(True)
        mode.body.canvas.from_blocks([
            {"type": "mosaic", "order": identities, "layout": "asymmetric"},
        ])
        block = mode.body.canvas.document().begin()
        hit = mode.body.canvas._object_at(block.position())
        mode.body.canvas._pending_obj_pos = hit[2]
        monkeypatch.setattr(
            mode, "_mosaic_dialog",
            lambda _order, _layout, preselected_order=None: ([4, 3, 2, 1], "asymmetric"),
        )
        mode._canvas_mosaic(identities, "asymmetric")
        saved = mode.body.authoring_blocks()
        assert saved == [{
            "type": "mosaic",
            "order": list(reversed(identities)),
            "layout": "asymmetric",
        }]
        assert not any(image.edit_flip_h or image.edit_flip_v for image in mode._bucket)
    finally:
        mode.close()


def test_unrequested_pixel_mirror_controls_are_absent(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    _app()
    mode = TakeMode(lambda: {"url": "https://example.test"})
    try:
        labels = {button.text() for button in mode.findChildren(QPushButton)}
        assert "⇆ Flip H" not in labels
        assert "⇅ Flip V" not in labels
        assert "Crop" in labels
    finally:
        mode.close()


def test_preview_right_click_names_arrangement_actions(tmp_path):
    app = _app()
    paths = _photos(tmp_path, 3)
    preview = MosaicPreview(content_width=760)
    preview.set_mosaic(list(enumerate(paths)), "one-left")
    preview.show()
    app.processEvents()
    preview.grab()
    menu = preview._arrangement_menu(preview._rects[0][0])
    labels = [action.text() for action in menu.actions() if not action.isSeparator()]
    assert labels == [
        "Move this image earlier",
        "Move this image later",
        "Swap this image to the other side",
        "Reverse arrangement",
    ]
    preview.close()


# ===== SNAPSMACK EOF =====
