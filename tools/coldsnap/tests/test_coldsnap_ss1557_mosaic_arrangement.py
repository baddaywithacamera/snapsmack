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
from PySide6.QtTest import QTest
from PySide6.QtWidgets import QApplication, QDialog, QPushButton

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

import sumna_offline as O  # noqa: E402
from coldsnap_qt import biggie  # noqa: E402
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


def _wait_for_preview(preview):
    for _ in range(400):
        items = preview.computed_cells()["items"]
        if items:
            return items
        QTest.qWait(20)
    raise AssertionError("Timed out waiting for browser geometry")


def _run_dialog(monkeypatch, mode, order, layout, action):
    def fake_exec(dialog):
        _wait_for_preview(dialog._mosaic_preview)
        action(dialog)
        return QDialog.Accepted

    monkeypatch.setattr(QDialog, "exec", fake_exec)
    return mode._mosaic_dialog(order, layout)


def test_right_click_mirrors_save_position_changes_without_reordering_ids(monkeypatch, tmp_path):
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
        assert reversed_result == ([1, 2, 3, 4], "asymmetric")
        assert mode._last_mosaic_mirrors == ["0:4"]

        side_swapped = _run_dialog(
            monkeypatch, mode, [0, 1, 2, 3], "asymmetric",
            lambda dialog: dialog._mosaic_swap_side(0),
        )
        assert side_swapped == ([1, 2, 3, 4], "asymmetric")
        assert mode._last_mosaic_mirrors == ["0:4"]

        assert [_digest(path) for path in paths] == before
        assert not any(image.edit_flip_h or image.edit_flip_v for image in mode._bucket)
    finally:
        mode.close()


def test_three_photo_reverse_mirrors_the_existing_preset(monkeypatch, tmp_path):
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
        assert result == ([1, 2, 3], "one-left")
        assert mode._last_mosaic_mirrors == ["0:3"]
    finally:
        mode.close()


def test_dialog_mirror_undo_redo_and_fit_preserve_geometry(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    _app()
    paths = _photos(tmp_path)
    mode = TakeMode(lambda: {"url": "https://example.test"})
    mode._bucket = [O.DraftImage(local_path=path, original_path=path) for path in paths]

    def exercise(dialog):
        dialog.show()
        preview = dialog._mosaic_preview
        before = _wait_for_preview(preview)
        menu = preview._arrangement_menu(0)
        next(action for action in menu.actions() if action.text() == "Swap this image to the other side").trigger()
        after = _wait_for_preview(preview)
        assert after != before
        for original, mirrored in zip(before, after):
            assert {**mirrored, "x": original["x"]} == original

        buttons = {button.text(): button for button in dialog.findChildren(QPushButton)}
        buttons["Undo"].click()
        assert _wait_for_preview(preview) == before
        buttons["Redo"].click()
        assert _wait_for_preview(preview) == after
        for label in ("100%", "+", "Fit"):
            buttons[label].click()
            assert all(preview._row_at(rect.center()) == row for row, rect in preview._rects)

    try:
        assert _run_dialog(monkeypatch, mode, [0, 1, 2, 3], "asymmetric", exercise) == (
            [1, 2, 3, 4], "asymmetric")
        assert mode._last_mosaic_mirrors == ["0:4"]
    finally:
        mode.close()


def test_post_body_mirror_is_undoable_and_survives_draft_serialization(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    _app()
    paths = _photos(tmp_path)
    mode = TakeMode(lambda: {"url": "https://example.test"})
    mode.body.set_bucket(paths)
    mode.body._set_biggie(True)
    canvas = mode.body.canvas
    try:
        original = {"type": "mosaic", "order": [1, 2, 3, 4], "layout": "asymmetric"}
        canvas.from_blocks([original])
        hit = canvas._object_at(canvas.document().begin().position())
        canvas._dispatch_inline_mosaic(hit, 1, "mirror", 0, [{"start": 0, "count": 4}], ())
        mirrored = {**original, "mirrors": ["0:4"]}
        assert mode.body.authoring_blocks() == [mirrored]
        assert biggie.parse_body(biggie.serialize_block(mirrored)) == [mirrored]
        canvas.undo()
        assert mode.body.authoring_blocks() == [original]
        canvas.redo()
        assert mode.body.authoring_blocks() == [mirrored]
        hit = canvas._object_at(canvas.document().begin().position())
        canvas._dispatch_inline_mosaic(hit, 1, "exclude", 0, (), ())
        assert mode.body.authoring_blocks() == [{**original, "order": [2, 3, 4]}]
        hit = canvas._object_at(canvas.document().begin().position())
        canvas._dispatch_inline_mosaic(hit, 2, "include", 0, (), (), [1])
        assert mode.body.authoring_blocks() == [{**original, "order": [2, 3, 4, 1]}]
        canvas.undo()
        assert mode.body.authoring_blocks() == [{**original, "order": [2, 3, 4]}]
        canvas.undo()
        assert mode.body.authoring_blocks() == [mirrored]
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
    _wait_for_preview(preview)
    menu = preview._arrangement_menu(preview._rects[0][0])
    labels = [action.text() for action in menu.actions() if not action.isSeparator()]
    assert labels == [
        "Move this image earlier",
        "Move this image later",
        "Swap this image to the other side",
        "Make larger",
        "Make smaller",
        "Reverse arrangement",
        "Break up",
        "Include selected tray photos",
        "Exclude this image",
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


def test_size_action_preserves_order_enlarges_span_and_survives_reopen(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    _app()
    paths = _photos(tmp_path)
    before = [_digest(path) for path in paths]
    mode = TakeMode(lambda: {"url": "https://example.test"})
    mode._bucket = [O.DraftImage(local_path=path, original_path=path) for path in paths]
    try:
        def _act(dialog):
            init_areas = [
                c["width"] * c["height"]
                for c in _wait_for_preview(dialog._mosaic_preview)
            ]
            assert max(init_areas) > min(init_areas) * 1.15
            before_area = init_areas[2]
            dialog._mosaic_resize(2, 1)
            assert dialog._mosaic_checked_indices() == [0, 1, 2, 3]
            assert dialog._mosaic_weights == {2: 1}
            tile = _wait_for_preview(dialog._mosaic_preview)[2]
            after_area = tile["width"] * tile["height"]
            assert after_area > before_area
            # Survives Suggest arrangement and Move earlier/later
            dialog._mosaic_suggest_btn.click()
            assert dialog._mosaic_weights == {2: 1}
            dialog._mosaic_move_preview(0, 1)
            assert dialog._mosaic_weights == {2: 1}

        result = _run_dialog(
            monkeypatch,
            mode,
            [0, 1, 2, 3],
            "asymmetric",
            _act,
        )
        saved_order, saved_layout = result
        assert saved_layout == "asymmetric"
        assert set(saved_order) == {1, 2, 3, 4}
        assert mode._last_mosaic_weights == {3: 1}
        assert [_digest(path) for path in paths] == before

        # Reopening from canvas preserves per-photo weight
        identities = [str(im.asset_uuid) for im in mode._bucket]
        mode.body._set_biggie(True)
        mode.body.canvas.from_blocks([
            {
                "type": "mosaic",
                "order": identities,
                "layout": "asymmetric",
                "weights": {identities[2]: 1},
            },
        ])
        block = mode.body.canvas.document().begin()
        hit = mode.body.canvas._object_at(block.position())
        assert hit[1]["weights"] == {identities[2]: 1}
        assert mode.body.authoring_blocks()[0]["weights"] == {identities[2]: 1}

        poster = object.__new__(SmacktalkPoster)
        calls = []
        poster.create_mosaic = lambda image_ids, **kwargs: (
            calls.append((list(image_ids), dict(kwargs))) or (77, "[mosaic:77]")
        )
        saved = {"type": "mosaic", "order": [identities[pos - 1] for pos in saved_order],
                 "layout": saved_layout, "weights": {identities[2]: 1}}
        marker = biggie.serialize_blocks(mode._wire_blocks([saved]))
        backup_draft = SimpleNamespace(images=mode._bucket, body_blocks=biggie.blocks_to_json([saved]))
        assert poster._body_text_for_sync(backup_draft) == marker
        assert marker.endswith(" weights=3:1]")
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
                "mosaic_weights": {"103": 1},
            },
        )]
    finally:
        mode.close()


# ===== SNAPSMACK EOF =====
