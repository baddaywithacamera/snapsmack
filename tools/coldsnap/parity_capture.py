"""Built-executable parity capture for the COLD SNAP release gate.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import json
import os
from pathlib import Path
import shutil
import tempfile


def run(output_dir: str) -> int:
    out = Path(output_dir).resolve()
    out.mkdir(parents=True, exist_ok=True)
    home = out / "sandbox-home"
    shutil.rmtree(home, ignore_errors=True)
    home.mkdir(exist_ok=True)
    os.environ["SNAPSMACK_HOME"] = str(home)

    from PIL import Image, ImageDraw
    from PySide6.QtCore import QPoint, QTimer, Qt
    from PySide6.QtGui import QFont, QFontDatabase
    from PySide6.QtTest import QTest
    from PySide6.QtWidgets import QApplication, QDialog, QDialogButtonBox, QMenu, QPushButton, QSplitter

    import sumna_offline as O
    from coldsnap_qt import biggie, theme
    from coldsnap_qt.canvas import BiggieCanvas
    from coldsnap_qt.mode_take import MosaicPreview, TakeMode
    from coldsnap_qt.photo_loader import PhotoLoader
    from coldsnap_qt.photo_tray import PhotoThumbTile

    app = QApplication.instance() or QApplication([])
    if app.platformName() == "offscreen" and os.name == "nt":
        font_path = Path(os.environ.get("WINDIR", r"C:\Windows")) / "Fonts" / "segoeui.ttf"
        font_id = QFontDatabase.addApplicationFont(str(font_path))
        families = QFontDatabase.applicationFontFamilies(font_id)
        if families:
            app.setFont(QFont(families[0], 10))
    mode = TakeMode(lambda: {})
    mode.resize(1440, 900)
    mode.show()
    app.processEvents()

    fixture_dir = Path(tempfile.mkdtemp(prefix="coldsnap-parity-"))
    sizes = [(1200, 800), (800, 1200), (900, 900), (1400, 800), (800, 1100), (1100, 760)]
    paths = []
    for index, size in enumerate(sizes):
        path = fixture_dir / f"parity-{index + 1}.jpg"
        Image.new("RGB", size, (35 + index * 28, 80 + index * 17, 145 - index * 12)).save(path)
        paths.append(str(path))

    mode._add_photo_paths(paths, insert=False)
    for image, size in zip(mode._bucket, sizes):
        image.width, image.height = size
    mode._cover_idx = 1
    mode._selected_photo_idx = 1
    mode._refresh_bucket()
    mode._populate_photo_tools()
    mode.body._set_biggie(True)
    identities = [str(image.asset_uuid) for image in mode._bucket]
    mode.body.canvas.from_blocks([
        {"type": "para", "text": "The first paragraph proves the editor keeps readable space between blocks."},
        {"type": "image", "asset_uuid": identities[0], "img_id": "asset:" + identities[0], "width_ratio": 0.72},
        {"type": "para", "text": "The second paragraph remains separate below the photograph."},
        {"type": "mosaic", "order": identities[1:5], "layout": "asymmetric"},
    ])
    mode.title_edit.setText("COLD SNAP parity gate")
    app.processEvents()
    mode.grab().save(str(out / "01-editor-parity.png"), "PNG")

    tiles = mode.findChildren(PhotoThumbTile)
    tile_badges = [
        {"index": tile._index, "orientation": tile._orientation, "ratio": tile._ratio_text}
        for tile in tiles
    ]
    buttons = [button.text() for button in mode.findChildren(QPushButton)]
    checks = {
        "ratio_badges": len({tile._index for tile in tiles}) >= 6 and all(
            tile._orientation in {"P", "L", "SQ"}
            for tile in tiles
        ),
        "featured_photo_control": "★ Featured photo (landing tile)" in buttons and mode._cover_idx == 1,
        "pixel_mirror_controls_absent": "⇆ Flip H" not in buttons and "⇅ Flip V" not in buttons,
        "block_count": len(mode.body.authoring_blocks()) == 4,
    }

    captured = {}

    def wait_for(check):
        for _ in range(250):
            if check():
                return True
            QTest.qWait(20)
        return False

    def capture_mosaic():
        dialog = app.activeModalWidget()
        if not isinstance(dialog, QDialog):
            return
        dialog.grab().save(str(out / "02-mosaic-parity.png"), "PNG")
        dialog_buttons = [button.text() for button in dialog.findChildren(QPushButton)]
        dialog_tiles = dialog.findChildren(PhotoThumbTile)
        initial_order = dialog._mosaic_checked_indices()
        dialog._mosaic_move_preview(2, -1)
        moved_order = dialog._mosaic_checked_indices()
        preview = dialog._mosaic_preview
        wait_for(lambda: len(preview._rects) == len(moved_order))
        dialog._mosaic_reverse()
        reversed_order = dialog._mosaic_checked_indices()
        reverse_mirrors = preview._mirrored_sections
        wait_for(lambda: len(preview._rects) == len(reversed_order))
        dialog._mosaic_swap_side(0)
        side_swapped_order = dialog._mosaic_checked_indices()
        captured.update({
            "mosaic_maximizable": bool(dialog.windowFlags() & Qt.WindowMaximizeButtonHint),
            "mosaic_wysiwyg": bool(dialog.findChildren(MosaicPreview)),
            "mosaic_photo_thumbnails": len({tile._index for tile in dialog_tiles}) >= 4,
            "mosaic_thumbnail_count": len({tile._index for tile in dialog_tiles}),
            "mosaic_move_controls": "Move earlier" in dialog_buttons and "Move later" in dialog_buttons,
            "mosaic_move_inside_arrangement": initial_order == [0, 1, 2, 3]
                and moved_order == [0, 2, 1, 3],
            "mosaic_reverse_arrangement": reversed_order == moved_order and bool(reverse_mirrors),
            "mosaic_swap_to_other_side": side_swapped_order == moved_order
                and preview._mirrored_sections != reverse_mirrors,
        })
        dialog.reject()

    QTimer.singleShot(250, capture_mosaic)
    mode._mosaic_dialog([], "asymmetric", preselected_order=[0, 1, 2, 3])
    checks.update(captured)

    # Equal-aspect photographs isolate changes in the assigned span from
    # differences in the photographs. Drive the same modal as the editor uses.
    span_paths = []
    span_colors = ((33, 85, 120), (129, 71, 41), (55, 106, 64), (98, 55, 107))
    for i, color in enumerate(span_colors):
        path = fixture_dir / f"span-{i + 1}.jpg"
        Image.new("RGB", (1200, 800), color).save(path)
        span_paths.append(str(path))
    mode._add_photo_paths(span_paths, insert=False)
    span_indices = list(range(len(mode._bucket) - 4, len(mode._bucket)))
    span_ids = [str(mode._bucket[i].asset_uuid) for i in span_indices]
    mode.body.canvas.from_blocks([{"type": "mosaic", "order": span_ids, "layout": "asymmetric"}])
    span = {}

    def rects(preview):
        return [[round(tile[key], 3) for key in ("x", "y", "width", "height")]
                for tile in preview.computed_cells()["items"]]

    def save_preview(dialog, name):
        preview = dialog._mosaic_preview
        if not wait_for(lambda: len(rects(preview)) == 4):
            return []
        preview.grab().save(str(out / name), "PNG")
        return rects(preview)

    def resize_mosaic():
        dialog = app.activeModalWidget()
        if not isinstance(dialog, QDialog):
            return
        preview = dialog._mosaic_preview
        preview.set_content_width(760)
        span["order_before"] = dialog._mosaic_checked_indices()
        span["before"] = save_preview(dialog, "03-mosaic-before.png")
        dialog._mosaic_resize(2, 1)
        span["order_after"] = dialog._mosaic_checked_indices()
        span["larger"] = save_preview(dialog, "04-mosaic-larger.png")
        dialog._mosaic_resize(2, -1)
        wait_for(lambda: len(rects(preview)) == 4)
        span["smaller"] = rects(preview)
        dialog._mosaic_resize(2, 1)
        dialog.accept()

    def reopen_mosaic():
        dialog = app.activeModalWidget()
        if not isinstance(dialog, QDialog):
            return
        preview = dialog._mosaic_preview
        preview.set_content_width(760)
        span["reopened_order"] = dialog._mosaic_checked_indices()
        span["reopened"] = save_preview(dialog, "05-mosaic-reopened.png")
        dialog.reject()

    mode.body.canvas._pending_obj_pos = mode.body.canvas.document().begin().position()
    QTimer.singleShot(250, resize_mosaic)
    mode._canvas_mosaic(span_ids, "asymmetric")
    captured_block = mode.body.authoring_blocks()[0]
    marker = biggie.serialize_blocks(mode._wire_blocks([captured_block]))
    mode.body.canvas.from_blocks(biggie.blocks_from_json(biggie.blocks_to_json([captured_block])))
    mode.body.canvas._pending_obj_pos = mode.body.canvas.document().begin().position()
    QTimer.singleShot(250, reopen_mosaic)
    mode._canvas_mosaic(span_ids, "asymmetric")
    span_ready = all(len(span.get(key, [])) == 4
                     for key in ("before", "larger", "smaller", "reopened"))
    target = span["larger"][2] if span_ready else [0, 0, 0, 0]
    smaller_tile = span["larger"][0] if span_ready else [0, 0, 0, 0]
    checks.update({
        "mosaic_browser_geometry_ready": span_ready,
        "default_quilt_has_mixed_spans": len({(tile[2], tile[3]) for tile in span["before"]}) > 1,
        "larger_mosaic_tile_spans_two_by_two": abs(target[2] - 2 * smaller_tile[2] - 4) < 1
            and abs(target[3] - 2 * smaller_tile[3]) < 4,
        "larger_mosaic_reflows_neighbors": span_ready
            and any(span["before"][i] != span["larger"][i] for i in (0, 1, 3)),
        "smaller_mosaic_returns_to_authored_size": span["smaller"] == span["before"],
        "mosaic_resize_preserves_picker_order": span["order_after"] == span["order_before"] == span["reopened_order"],
        "mosaic_sizes_survive_reopen": span["reopened"] == span["larger"],
        "mosaic_sizes_reach_publish_marker": marker.endswith(f" weights={span_indices[2] + 1}:1]"),
    })

    # Exercise the production post-body widget in the frozen application.
    # Four 3:2 photos at this fixed test width start as exactly two rows.
    for number, name in enumerate(span_paths, 1):
        with Image.open(name) as source:
            labelled = source.copy()
        ImageDraw.Draw(labelled).text(
            (600, 400), str(number), fill="white", anchor="mm",
            font_size=160,
        )
        labelled.save(name)
    def inline_boxes(canvas):
        for position, (tiles, buttons) in canvas._inline_mosaic_hits.items():
            if len(tiles) == 4:
                return position, {photo: list(box.getRect())
                                  for photo, (_, box) in tiles.items()}, buttons
        return None, {}, []

    def save_inline_frame(canvas, boxes, name):
        def all_photos_painted():
            frame = canvas.grab().toImage()
            ratio = frame.devicePixelRatio()
            for photo, (x, y, w, h) in boxes.items():
                pixel = frame.pixelColor(round((x + w * .80) * ratio),
                                         round((y + h * .80) * ratio))
                actual = (pixel.red(), pixel.green(), pixel.blue())
                if any(abs(actual[i] - span_colors[photo - 1][i]) > 12 for i in range(3)):
                    return False
            return frame.save(str(out / name), "PNG")

        return wait_for(all_photos_painted)

    def capture_inline_break(width, layout, before_name, after_name):
        canvas = BiggieCanvas()
        canvas.set_preview_width(width)
        canvas.resize(width + 20, 1100)
        canvas.set_bucket(span_paths)
        canvas.insert_mosaic([1, 2, 3, 4], layout)
        dialog_requests = []
        canvas.mosaicEditRequested.connect(lambda *args: dialog_requests.append(args))
        canvas.show()
        result = {}
        if wait_for(lambda: any(len(ids) == 4 for _, ids in inline_boxes(canvas)[2])):
            position, before, buttons = inline_boxes(canvas)
            result["before"] = before
            cluster_button = next(box for box, ids in buttons if len(ids) == 4)
            result["before_capture"] = save_inline_frame(canvas, before, before_name)
            QTest.mouseClick(canvas.viewport(), Qt.LeftButton, pos=cluster_button.center())
            if wait_for(lambda: inline_boxes(canvas)[1] and inline_boxes(canvas)[1] != before):
                _, after, badges = inline_boxes(canvas)
                result["after"] = after
                result["after_capture"] = save_inline_frame(canvas, after, after_name)
                result["stored"] = canvas._object_at(position)[1]
                result["removed_original_cluster"] = not badges
        result["opened_dialog"] = bool(dialog_requests)
        wait_for(lambda: all(not loader._pending for loader in canvas.findChildren(PhotoLoader)))
        canvas.close()
        return result

    inline = capture_inline_break(240, "rows", "06-inline-2x2-before.png", "07-inline-2x2-after.png")
    inline_row = capture_inline_break(760, "columns", "08-inline-row-before.png", "09-inline-row-after.png")
    before = inline.get("before", {})
    after = inline.get("after", {})
    checks.update({
        "inline_original_group_is_2x2": len(before) == 4
            and len({box[0] for box in before.values()}) == 2
            and len({box[1] for box in before.values()}) == 2,
        "inline_break_changes_target_size": set(before) == set(after) == {1, 2, 3, 4}
            and after[1][2] > before[1][2],
        "inline_break_changed_saved_weight_with_no_dialog": not inline["opened_dialog"]
            and inline.get("stored", {}).get("order") == [1, 2, 3, 4]
            and inline.get("stored", {}).get("weights") == {"1": 1, "4": -1},
        "inline_break_removes_original_cluster": bool(inline.get("removed_original_cluster")),
        "inline_break_captures": bool(inline.get("before_capture") and inline.get("after_capture")),
        "inline_row_starts_with_four_equal_tiles": len(inline_row.get("before", {})) == 4
            and len({tuple(box[1:]) for box in inline_row["before"].values()}) == 1,
        "inline_row_break_clears_every_cluster": bool(inline_row.get("removed_original_cluster"))
            and inline_row.get("stored", {}).get("order") == [1, 2, 3, 4]
            and inline_row.get("stored", {}).get("weights") == {"1": 1, "4": -1}
            and not inline_row["opened_dialog"],
        "inline_row_break_captures": bool(inline_row.get("before_capture") and inline_row.get("after_capture")),
    })

    # Exercise the actual Edit Mosaic menu and the long preview separately
    # from the inline four-photo cases above.
    dialog_mode = TakeMode(lambda: {"url": "https://mosaic-fixture.invalid"})
    dialog_mode.setStyleSheet(theme.stylesheet())
    app.processEvents()
    # The frozen runtime can already have recovered an earlier fixture draft.
    dialog_mode._clear()
    dialog_mode._preview_width = 800
    for index in range(53):
        size = (800 if index == 0 else 900, 600)
        source = fixture_dir / f"quilt-{index + 1:02}.jpg"
        fixture = Image.new("RGB", size, ((42 + index * 51) % 256,
                                         (67 + index * 27) % 256, (88 + index * 19) % 256))
        ImageDraw.Draw(fixture).text((32, 32), f"{index + 2:02}", fill="white", font_size=150)
        fixture.save(source)
        photo = O.DraftImage(local_path=str(source), filename=source.name)
        photo.width, photo.height = size
        dialog_mode._bucket.append(photo)
    # Another group already has an authored size, so the leading quad uses
    # the shared weighted reference width before the leading tile is edited.
    dialog_mode._pending_mosaic_weights = {4: 1}
    dialog_proof = {}
    original_dialog_exec = QDialog.exec

    def capture_dialog(dialog):
        dialog.resize(1032, 820)
        dialog.show()
        preview = dialog.findChild(MosaicPreview)
        splitter = dialog.findChild(QSplitter)
        try:
            dialog_proof["picker_count"] = len(dialog_mode._bucket)
            if not wait_for(lambda: len(preview.computed_cells()["items"]) == 14):
                return QDialog.Rejected
            QTest.qWait(220)
            dialog_proof["before"] = preview.computed_cells()
            dialog_proof["before_capture"] = dialog.grab().save(str(out / "10-dialog-quilt-before.png"), "PNG")
            point = preview._rects[0][1].center()
            menu = preview._arrangement_menu(preview._row_at(point))
            next(action for action in menu.actions() if action.text() == "Make larger").trigger()
            if not wait_for(lambda: preview._weights.get("1") == 1
                            and len(preview.computed_cells()["items"]) == 14):
                return QDialog.Rejected
            QTest.qWait(220)
            dialog_proof["after"] = preview.computed_cells()
            dialog_proof["after_capture"] = dialog.grab().save(str(out / "11-dialog-quilt-after.png"), "PNG")
            dialog_proof["weights"] = preview._weights
            dialog_proof["fit_scale"] = preview._fit_scale
            dialog_proof["pane_widths"] = splitter.sizes()
            dialog_proof["right_picker"] = (splitter.orientation() == Qt.Horizontal
                                             and splitter.widget(1).verticalScrollBar().maximum() > 0)
            dialog_proof["fits_preview"] = (preview.width() <= preview._fit_viewport.width()
                                             and preview.height() <= preview._fit_viewport.height())
            # A tall lead in a mostly 3:2 quilt makes the exact 1 + 3 stack.
            mirror_lead = fixture_dir / "quilt-mirror-01.jpg"
            image = Image.new("RGB", (750, 900), (42, 67, 88))
            ImageDraw.Draw(image).text((32, 32), "02", fill="white", font_size=150)
            image.save(mirror_lead)
            dialog_mode._bucket[0].local_path = str(mirror_lead)
            preview.set_mosaic([(0, str(mirror_lead), ""), *preview._tiles[1:]],
                               "asymmetric", weights=preview._weights)
            if not wait_for(lambda: len(preview.computed_cells()["items"]) == 14):
                return QDialog.Rejected
            dialog_proof["mirror_base"] = preview.computed_cells()

            def save_painted_stack(filename):
                origin = preview.mapTo(dialog, QPoint())

                def painted():
                    capture = dialog.grab()
                    frame = capture.toImage()
                    ratio = capture.devicePixelRatio()
                    for photo, rect in preview._rects[:4]:
                        x = origin.x() + rect.left() + rect.width() * .72
                        y = origin.y() + rect.top() + rect.height() * .72
                        pixel = frame.pixelColor(round(x * ratio), round(y * ratio))
                        actual = (pixel.red(), pixel.green(), pixel.blue())
                        expected = ((42 + photo * 51) % 256, (67 + photo * 27) % 256, (88 + photo * 19) % 256)
                        if any(abs(a - b) > 12 for a, b in zip(actual, expected)):
                            return False
                    return capture.save(str(out / filename), "PNG")

                return wait_for(painted)

            dialog_proof["mirror_base_capture"] = save_painted_stack("12-dialog-quilt-original-stack.png")
            photo_menu = preview._arrangement_menu(preview._rects[0][0])
            labels = [action.text() for action in photo_menu.actions() if not action.isSeparator()]
            dialog_proof["photo_menu"] = labels
            photo_menu.popup(preview.mapToGlobal(preview._rects[0][1].center()))
            app.processEvents()
            dialog_proof["menu_capture"] = photo_menu.grab().save(str(out / "12-dialog-photo-menu.png"), "PNG")
            photo_menu.hide()
            next(action for action in photo_menu.actions()
                 if action.text() == "Swap this image to the other side").trigger()
            if not wait_for(lambda: len(preview.computed_cells()["items"]) == 14):
                return QDialog.Rejected
            dialog_proof["mirrored"] = preview.computed_cells()
            dialog_proof["mirror_capture"] = save_painted_stack("13-dialog-quilt-mirrored.png")
            controls = {button.text(): button for button in dialog.findChildren(QPushButton)}
            controls["Undo"].click()
            dialog_proof["undo"] = wait_for(lambda: preview.computed_cells() == dialog_proof["mirror_base"])
            dialog_proof["undo_capture"] = save_painted_stack("14-dialog-quilt-undo.png")
            controls["Redo"].click()
            dialog_proof["redo"] = wait_for(lambda: preview.computed_cells() == dialog_proof["mirrored"])
            controls["100%"].click()
            dialog_proof["zoom_hit"] = preview._fit_scale == 1.0 and all(
                preview._row_at(rect.center()) == row for row, rect in preview._rects)
            dialog_proof["zoom_capture"] = dialog.grab().save(str(out / "15-dialog-quilt-zoom.png"), "PNG")
            controls["Fit"].click()
            dialog_proof["fit_hit"] = preview._fit_scale < 1.0 and all(
                preview._row_at(rect.center()) == row for row, rect in preview._rects)
            buttons = dialog.findChild(QDialogButtonBox)
            QTest.mouseClick(buttons.button(QDialogButtonBox.Ok), Qt.LeftButton)
            return dialog.result()
        finally:
            if dialog.isVisible():
                dialog.reject()

    QDialog.exec = capture_dialog
    try:
        result = dialog_mode._mosaic_dialog(list(range(14)), "asymmetric")
        dialog_proof["confirmed_order_and_layout"] = result
        dialog_proof["confirmed_weights"] = dict(getattr(dialog_mode, "_last_mosaic_weights", {}))
        dialog_proof["confirmed_mirrors"] = list(getattr(dialog_mode, "_last_mosaic_mirrors", ()))
    finally:
        QDialog.exec = original_dialog_exec
        dialog_mode.close()
    quad_before = dialog_proof.get("before", {}).get("items", [])[:4]
    quad_after = dialog_proof.get("after", {}).get("items", [])[:4]
    quilt_after = dialog_proof.get("mirror_base", {}).get("items", [])
    quilt_mirrored = dialog_proof.get("mirrored", {}).get("items", [])

    def same_photo_size_and_y(old, new):
        return old["id"] == new["id"] and all(abs(old[field] - new[field]) < 0.04
                                               for field in ("y", "width", "height"))

    def tile_area(tile):
        return tile["width"] * tile["height"]

    checks.update({
        "long_dialog_quad_becomes_a_span": len(quad_before) == 4 and len(quad_after) == 4
            and sum(tile["x"] < 1 for tile in quad_before) == 2
            and sum(tile["x"] < 1 for tile in quad_after) == 1
            and quad_before[0]["width"] <= max(tile["width"] for tile in quad_before[1:]) * 1.1
            and tile_area(quad_after[0]) > tile_area(quad_before[0]) * 1.25
            and tile_area(quad_after[0]) > max(map(tile_area, quad_after[1:])) * 1.5,
        "long_dialog_full_preview_with_right_picker": bool(dialog_proof.get("right_picker"))
            and bool(dialog_proof.get("fits_preview"))
            and 0 < dialog_proof.get("fit_scale", 0) < 1
            and dialog_proof["pane_widths"][0] > 4 * dialog_proof["pane_widths"][1],
        "long_dialog_keeps_14_photos_and_saves_both_weights": dialog_proof.get("weights") == {"1": 1, "5": 1}
            and dialog_proof.get("picker_count") == 53
            and dialog_proof.get("confirmed_weights") == {1: 1, 5: 1}
            and dialog_proof.get("confirmed_order_and_layout") == (list(range(1, 15)), "asymmetric")
            and [tile["id"] for tile in dialog_proof.get("after", {}).get("items", [])] == list(range(1, 15)),
        "long_dialog_captures": bool(dialog_proof.get("before_capture") and dialog_proof.get("after_capture")),
        "long_dialog_mirrors_the_four_tile_group": len(quilt_after) == len(quilt_mirrored) == 14
            and all(same_photo_size_and_y(old, new) for old, new in zip(quilt_after, quilt_mirrored))
            and all(old["x"] != new["x"] for old, new in zip(quilt_after[:4], quilt_mirrored[:4]))
            and quilt_after[4:] == quilt_mirrored[4:]
            and len({round(tile["x"]) for tile in quilt_after[1:4]}) == 1
            and len({round(tile["y"]) for tile in quilt_after[1:4]}) == 3
            and len({round(tile["x"]) for tile in quilt_mirrored[1:4]}) == 1
            and quilt_mirrored[0]["x"] > quilt_mirrored[1]["x"]
            and dialog_proof.get("confirmed_mirrors") == ["0:4"],
        "long_dialog_undo_redo_and_zoom": all(dialog_proof.get(key) for key in
            ("undo", "redo", "zoom_hit", "fit_hit", "menu_capture", "mirror_base_capture",
             "mirror_capture", "undo_capture", "zoom_capture")),
    })

    post_canvas = BiggieCanvas()
    post_canvas.setStyleSheet(theme.stylesheet())
    post_canvas.set_preview_width(800)
    post_canvas.resize(825, 2100)
    post_canvas.set_bucket([str(fixture_dir / "quilt-mirror-01.jpg"),
                            *(str(fixture_dir / f"quilt-{index:02}.jpg") for index in range(2, 15))])
    post_canvas.insert_mosaic(list(range(1, 15)), "asymmetric", weights={1: 1, 5: 1})
    post_canvas.show()

    def post_mosaic():
        return next(((pos, tiles) for pos, (tiles, _badges) in post_canvas._inline_mosaic_hits.items()
                     if len(tiles) == 14), (None, {}))

    post_proof = {}

    def save_painted_post(filename):
        def painted():
            tiles = post_mosaic()[1]
            if len(tiles) != 14:
                return False
            capture = post_canvas.viewport().grab()
            frame = capture.toImage()
            ratio = capture.devicePixelRatio()
            for photo, (_identity, rect) in tiles.items():
                x = round((rect.left() + rect.width() * .72) * ratio)
                y = round((rect.top() + rect.height() * .72) * ratio)
                if not frame.valid(x, y):
                    return False
                pixel = frame.pixelColor(x, y)
                index = int(photo) - 1
                expected = ((42 + index * 51) % 256, (67 + index * 27) % 256, (88 + index * 19) % 256)
                if any(abs(a - b) > 12 for a, b in zip(pixel.getRgb()[:3], expected)):
                    return False
            return capture.save(str(out / filename), "PNG")

        return wait_for(painted)

    if wait_for(lambda: bool(post_mosaic()[1])):
        position, tiles = post_mosaic()
        point = tiles[1][1].center()
        post_proof["before_capture"] = save_painted_post("16-post-quilt-before.png")

        menu = QMenu(post_canvas)
        if post_canvas._add_inline_photo_actions(menu, post_canvas._object_at(position), point):
            post_proof["menu_matches"] = [action.text() for action in menu.actions()] == dialog_proof.get("photo_menu")
            menu.ensurePolished()
            menu.resize(menu.sizeHint())
            post_proof["menu_capture"] = menu.grab().save(str(out / "17-post-photo-menu.png"), "PNG")
            next(action for action in menu.actions()
                 if action.text() == "Swap this image to the other side").trigger()
        mirrored = post_canvas._object_at(position)[1]
        post_proof["stored"] = mirrored.get("mirrors") == ["0:4"]
        if wait_for(lambda: bool(post_mosaic()[1]) and post_mosaic()[1][1][1].left() > point.x()):
            post_proof["after_capture"] = save_painted_post("18-post-quilt-mirrored.png")
        post_canvas.undo()
        post_proof["undo"] = not post_canvas._object_at(position)[1].get("mirrors")
        post_canvas.redo()
        post_proof["redo"] = post_canvas._object_at(position)[1].get("mirrors") == ["0:4"]
    checks["post_body_photo_menu_mirror_and_undo"] = all(post_proof.get(key) for key in
        ("menu_matches", "menu_capture", "before_capture", "after_capture", "stored", "undo", "redo"))
    wait_for(lambda: all(not loader._pending for loader in post_canvas.findChildren(PhotoLoader)))
    post_canvas.close()

    mode._remote_post_id = 42
    mode._remote_slug = "parity-gate"
    saved = mode._autosave_now()
    checks["autosave_without_errno22"] = bool(saved) and "could not save" not in mode.save_state.text().lower()
    checks["source_files_preserved"] = all(Path(path).is_file() for path in paths)

    payload = {
        "build": __import__("_version").BUILD_VERSION,
        "checks": checks,
        "tile_badges": tile_badges,
        "mosaic_resize": span,
        "inline_break": inline,
        "inline_row_break": inline_row,
        "dialog_quilt": dialog_proof,
        "post_quilt": post_proof,
        "pass": all(value for key, value in checks.items() if key != "mosaic_thumbnail_count"),
    }
    (out / "parity.json").write_text(json.dumps(payload, indent=2), encoding="utf-8")
    wait_for(lambda: all(not loader._pending for loader in mode.findChildren(PhotoLoader)))
    mode.close()
    app.processEvents()
    return 0 if payload["pass"] else 1


# ===== SNAPSMACK EOF =====
