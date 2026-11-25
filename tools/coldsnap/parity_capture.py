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
    from PIL import Image, ImageDraw
    from PySide6.QtCore import QTimer, Qt
    from PySide6.QtGui import QFont, QFontDatabase
    from PySide6.QtTest import QTest
    from PySide6.QtWidgets import QApplication, QDialog, QPushButton

    import sumna_offline as O
    from coldsnap_qt import biggie
    from coldsnap_qt.canvas import BiggieCanvas
    from coldsnap_qt.mode_take import MosaicPreview, TakeMode
    from coldsnap_qt.photo_tray import PhotoThumbTile

    out = Path(output_dir).resolve()
    out.mkdir(parents=True, exist_ok=True)
    home = out / "sandbox-home"
    shutil.rmtree(home, ignore_errors=True)
    home.mkdir(exist_ok=True)
    os.environ["SNAPSMACK_HOME"] = str(home)

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
        dialog._mosaic_reverse()
        reversed_order = dialog._mosaic_checked_indices()
        before_side_swap = list(reversed_order)
        wait_for(lambda: len(dialog._mosaic_preview._rects) == len(before_side_swap))
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
            "mosaic_reverse_arrangement": reversed_order == [3, 1, 2, 0],
            "mosaic_swap_to_other_side": side_swapped_order != before_side_swap
                and sorted(side_swapped_order) == sorted(before_side_swap),
        })
        dialog.reject()

    QTimer.singleShot(250, capture_mosaic)
    mode._mosaic_dialog([], "asymmetric", preselected_order=[0, 1, 2, 3])
    checks.update(captured)

    # Equal-aspect photographs isolate changes in the assigned span from
    # differences in the photographs. Drive the same modal as the editor uses.
    span_paths = []
    for i, color in enumerate(((33, 85, 120), (129, 71, 41), (55, 106, 64), (98, 55, 107))):
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
    canvas = BiggieCanvas()
    canvas.set_preview_width(240)
    canvas.resize(260, 680)
    canvas.set_bucket(span_paths)
    canvas.insert_mosaic([1, 2, 3, 4], "rows")
    dialog_requests = []
    canvas.mosaicEditRequested.connect(lambda *args: dialog_requests.append(args))
    canvas.show()

    def inline_boxes():
        for position, (tiles, buttons) in canvas._inline_mosaic_hits.items():
            if len(tiles) == 4:
                return position, {photo: list(box.getRect())
                                  for photo, (_, box) in tiles.items()}, buttons
        return None, {}, []

    inline = {}
    if wait_for(lambda: any(len(ids) == 4 for _, ids in inline_boxes()[2])):
        position, before, buttons = inline_boxes()
        inline["before"] = before
        cluster_button = next(box for box, ids in buttons if len(ids) == 4)
        inline["before_capture"] = canvas.grab().save(str(out / "06-inline-2x2-before.png"), "PNG")
        QTest.mouseClick(canvas.viewport(), Qt.LeftButton, pos=cluster_button.center())
        if wait_for(lambda: inline_boxes()[1] and inline_boxes()[1] != before):
            _, after, badges = inline_boxes()
            inline["after"] = after
            inline["after_capture"] = canvas.grab().save(str(out / "07-inline-2x2-after.png"), "PNG")
            inline["stored"] = canvas._object_at(position)[1]
            inline["removed_original_cluster"] = all(len(ids) < 4 for _, ids in badges)
    canvas.close()
    before = inline.get("before", {})
    after = inline.get("after", {})
    checks.update({
        "inline_original_group_is_2x2": len(before) == 4
            and len({box[0] for box in before.values()}) == 2
            and len({box[1] for box in before.values()}) == 2,
        "inline_break_changes_target_size": set(before) == set(after) == {1, 2, 3, 4}
            and after[1][2] > before[1][2],
        "inline_break_changed_saved_weight_with_no_dialog": not dialog_requests
            and inline.get("stored", {}).get("order") == [1, 2, 3, 4]
            and inline.get("stored", {}).get("weights") == {"1": 1},
        "inline_break_removes_original_cluster": bool(inline.get("removed_original_cluster")),
        "inline_break_captures": bool(inline.get("before_capture") and inline.get("after_capture")),
    })

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
        "pass": all(value for key, value in checks.items() if key != "mosaic_thumbnail_count"),
    }
    (out / "parity.json").write_text(json.dumps(payload, indent=2), encoding="utf-8")
    mode.close()
    app.processEvents()
    return 0 if payload["pass"] else 1


# ===== SNAPSMACK EOF =====
