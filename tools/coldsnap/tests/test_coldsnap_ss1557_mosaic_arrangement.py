"""SS-1557: mosaic right-click actions change placement, never pixels.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import hashlib
import os
import sys
from types import SimpleNamespace

from PIL import Image
from PySide6.QtWidgets import QApplication, QDialog, QPushButton

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

import sumna_offline as O  # noqa: E402
from coldsnap_qt.mode_take import (  # noqa: E402
    MosaicPreview, TakeMode, _mosaic_area_profile, _resize_mosaic_order,
)
from sumna_post import SmacktalkPoster  # noqa: E402


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
        "Make larger",
        "Make smaller",
        "Reverse arrangement",
    ]
    preview.close()


def test_make_larger_and_smaller_reuse_real_spans_and_are_reversible(tmp_path):
    paths = _photos(tmp_path)
    order = [0, 1, 2, 3]
    target = 2
    path_for = lambda index: paths[index]

    larger, layout, changed = _resize_mosaic_order(
        order, target, path_for, larger=True,
    )
    before_area = _mosaic_area_profile(order, path_for, layout)[order.index(target)]
    larger_area = _mosaic_area_profile(larger, path_for, layout)[larger.index(target)]
    assert changed is True
    assert layout == "asymmetric"
    assert larger_area > before_area

    smaller, smaller_layout, changed_back = _resize_mosaic_order(
        larger, target, path_for, larger=False,
    )
    smaller_area = _mosaic_area_profile(smaller, path_for, smaller_layout)[smaller.index(target)]
    assert changed_back is True
    assert smaller == order
    assert smaller_area < larger_area


def test_three_photo_size_action_uses_the_real_one_left_spans(tmp_path):
    paths = _photos(tmp_path)[:3]
    order = [0, 1, 2]
    target = 1
    path_for = lambda index: paths[index]

    larger, layout, changed = _resize_mosaic_order(
        order, target, path_for, larger=True,
    )
    large_area = _mosaic_area_profile(larger, path_for, layout)[larger.index(target)]
    smaller, smaller_layout, changed_back = _resize_mosaic_order(
        larger, target, path_for, larger=False,
    )
    small_area = _mosaic_area_profile(smaller, path_for, smaller_layout)[smaller.index(target)]

    assert changed is True
    assert changed_back is True
    assert layout == smaller_layout == "one-left"
    assert smaller == order
    assert large_area > small_area


def test_size_action_saves_order_and_publishes_that_same_order(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    _app()
    paths = _photos(tmp_path)
    before = [_digest(path) for path in paths]
    mode = TakeMode(lambda: {"url": "https://example.test"})
    mode._bucket = [O.DraftImage(local_path=path, original_path=path) for path in paths]
    try:
        result = _run_dialog(
            monkeypatch,
            mode,
            [0, 1, 2, 3],
            "square",
            lambda dialog: dialog._mosaic_resize(2, 1),
        )
        saved_order, saved_layout = result
        assert saved_layout == "asymmetric"
        assert saved_order != [1, 2, 3, 4]
        assert [_digest(path) for path in paths] == before

        poster = object.__new__(SmacktalkPoster)
        calls = []
        poster.create_mosaic = lambda image_ids, **kwargs: (
            calls.append((list(image_ids), dict(kwargs))) or (77, "[mosaic:77]")
        )
        marker = "[mosaic=%s layout=%s]" % (
            ",".join(map(str, saved_order)), saved_layout,
        )
        resolved, ids = poster._resolve_mosaics(
            marker,
            [101, 102, 103, 104],
            SimpleNamespace(title="Same-size mosaic", mosaic_gap=4),
        )
        assert resolved == "[mosaic:77]"
        assert ids == [77]
        assert calls == [(
            [[101, 102, 103, 104][position - 1] for position in saved_order],
            {
                "title": "Same-size mosaic",
                "gap": 4,
                "layout": "asymmetric",
                "gallery_shuffle": False,
            },
        )]
    finally:
        mode.close()


# ===== SNAPSMACK EOF =====
