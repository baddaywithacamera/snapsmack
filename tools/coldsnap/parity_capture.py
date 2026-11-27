"""Built-executable parity capture for the COLD SNAP release gate.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

from contextlib import ExitStack, contextmanager
import json
import os
from pathlib import Path
import shutil
import tempfile



@contextmanager
def _replace_for_fixture(owner, name, replacement):
    original = getattr(owner, name)
    setattr(owner, name, replacement)
    try:
        yield
    finally:
        setattr(owner, name, original)



@contextmanager
def isolated_settings(directory):
    from PySide6.QtCore import QSettings

    from coldsnap_qt import block_tray, body_editor, mode_take

    def fixture_settings(org, app):
        return QSettings(str(directory / org / f"{app}.ini"), QSettings.IniFormat)

    with ExitStack() as stack:
        for module in (block_tray, body_editor, mode_take):
            stack.enter_context(_replace_for_fixture(module, "QSettings", fixture_settings))
        yield

def take_resize_checks(mode, output):
    """Exercise actual Visual and Text drag events in the frozen composer."""
    from PySide6.QtCore import QPoint, Qt
    from PySide6.QtGui import QColor, QPixmap
    from PySide6.QtTest import QTest
    from PySide6.QtWidgets import QApplication, QDialog

    from coldsnap_qt import biggie
    from coldsnap_qt.mode_take import _ImageWidthPreview

    app = QApplication.instance()
    mode.body._set_biggie(True)
    canvas = mode.body.canvas
    fixture = QPixmap(240, 120)
    fixture.fill(QColor("#1968aa"))
    canvas.set_image_resolver(lambda *_: fixture)
    original = "[img:7|100%|decorative]"
    canvas.from_blocks(biggie.parse_body(original))
    canvas.select_block_at(0)
    app.processEvents()
    handle = canvas._image_resize_handle
    if handle is None:
        return {"visual_image_resize": False, "text_image_resize": False}
    rect, _, width, column, _ = handle
    start = rect.center()
    end = start - QPoint(round(column * .1), 0)
    handle_visible = canvas.viewport().rect().contains(rect)
    QTest.mousePress(canvas.viewport(), Qt.LeftButton, pos=start)
    QTest.mouseRelease(canvas.viewport(), Qt.LeftButton, pos=end)
    visual = handle_visible and width == 100 and canvas.body_text() == "[img:7|80%|decorative]"
    canvas.grab().save(str(output / "visual-image-resize.png"), "PNG")

    mode.body._set_biggie(False)
    editor = mode.body.editor
    text = "Lead [img:7|40%|decorative] then [img:8|30]"
    editor.setPlainText(text)
    cursor = editor.document().find("40%")
    cursor.clearSelection()
    editor.setTextCursor(cursor)

    def drag_preview(dialog):
        dialog.show()
        app.processEvents()
        preview = dialog.findChild(_ImageWidthPreview)
        if preview is None or not preview._handle.isValid():
            return QDialog.Rejected
        start = preview._handle.center()
        end = start + QPoint(round((preview.width() - 26) * .1), 0)
        QTest.mousePress(preview, Qt.LeftButton, pos=start)
        QTest.mouseMove(preview, end)
        QTest.mouseRelease(preview, Qt.LeftButton, pos=end)
        dialog.grab().save(str(output / "text-image-resize.png"), "PNG")
        return QDialog.Accepted if preview.percent == 60 else QDialog.Rejected

    with _replace_for_fixture(QDialog, "exec", drag_preview):
        mode._resize_text_image()
    expected = text.replace("40%", "60%")
    mode.body._set_biggie(True)
    mode.body._set_biggie(False)
    return {"visual_image_resize": visual, "text_image_resize": editor.toPlainText() == expected}


def take_composer_checks(mode, files, *, check_renderer=False):
    """Exercise the real composer controls against a disposable queued post."""
    from PySide6.QtCore import QPoint, QPointF, Qt
    from PySide6.QtGui import QDragEnterEvent, QDropEvent, QMouseEvent
    from PySide6.QtTest import QTest
    from PySide6.QtWidgets import QApplication, QCheckBox, QDialog, QLineEdit, QListWidget, QMenu

    import sumna_offline as O
    from coldsnap_qt import biggie, photo_tray
    from sumna_post import SmacktalkPoster

    app = QApplication.instance()
    mode._clear()
    mode.body._set_biggie(True)
    mode._add_photo_paths(files, insert=False)
    mode.photos_tray.set_open(True)
    mode.title_edit.setText("Composer proof")
    mode.colophon_edit.setText("Made with a 50 mm lens.")
    mode._toggle_post_details(False)
    identities = [photo.asset_uuid for photo in mode._bucket]
    results = {"colophon_visible_with_details_closed": mode.colophon_edit.isVisible()}

    def last_asset():
        images = [block for block in mode.body.authoring_blocks() if block["type"] == "image"]
        return images[-1].get("asset_uuid") if images else None

    def choose(dialog):
        photos = dialog.findChild(QListWidget)
        photos.setCurrentRow(0)
        return QDialog.Accepted

    with _replace_for_fixture(QDialog, "exec", choose):
        menu = mode._build_add_block_menu()
        next(action for action in menu.actions() if action.text() == "Photo from tray").trigger()
        results["add_block_photo_from_tray"] = last_asset() == identities[0]
        next(action for action in mode._visual_block_actions() if action.tag == "IMG").callback()
        results["img_button"] = last_asset() == identities[1]

    tile = mode.bucket_col.itemAt(0).widget()
    QTest.mouseClick(tile, Qt.LeftButton, pos=tile.rect().center())
    mode.insert_into_post_btn.click()
    results["highlight_and_insert"] = last_asset() == identities[2]

    def drag(tile, editor):
        viewport = editor.viewport()
        point = editor.cursorRect().center()
        delivered = []

        class FixtureDrag:
            def __init__(self, source):
                self.source = source

            def setMimeData(self, mime):
                self.mime = mime

            def setPixmap(self, pixmap):
                pass

            def exec(self, action):
                enter = QDragEnterEvent(point, action, self.mime, Qt.LeftButton, Qt.NoModifier)
                app.sendEvent(viewport, enter)
                drop = QDropEvent(QPointF(point), action, self.mime, Qt.LeftButton, Qt.NoModifier)
                app.sendEvent(viewport, drop)
                delivered.append(enter.isAccepted() and drop.isAccepted())
                return drop.dropAction()

            def deleteLater(self):
                pass

        start = tile.rect().center()
        QTest.mousePress(tile, Qt.LeftButton, pos=start)
        same_tile = mode.bucket_col.itemAt(0).widget() is tile
        end = start + QPoint(QApplication.startDragDistance() + 2, 0)
        qend = QPointF(end)
        move = QMouseEvent(QMouseEvent.MouseMove, qend, QPointF(tile.mapToGlobal(end)),
                           Qt.NoButton, Qt.LeftButton, Qt.NoModifier)
        with _replace_for_fixture(photo_tray, "QDrag", FixtureDrag):
            app.sendEvent(tile, move)
        QTest.mouseRelease(tile, Qt.LeftButton, pos=end)
        return same_tile and delivered == [True]

    tile = mode.bucket_col.itemAt(0).widget()
    results["drag_biggie"] = drag(tile, mode.body.canvas) and last_asset() == identities[3]
    mode.body._set_biggie(False)
    tile = mode.bucket_col.itemAt(0).widget()
    results["drag_twiggy"] = drag(tile, mode.body.editor) and "[img:bucket:5|" in mode.body.editor.toPlainText()
    mode.body._set_biggie(True)
    mode.body.canvas.moveCursor(mode.body.canvas.textCursor().MoveOperation.End)
    tile = mode.bucket_col.itemAt(0).widget()

    def menu_action(tile, label):
        from PySide6 import QtWidgets
        from PySide6.QtGui import QContextMenuEvent

        class ChooseAction(QMenu):
            def exec(self, *args):
                return next(action for action in self.actions()
                            if label in action.text() and action.isEnabled())

        point = tile.rect().center()
        with _replace_for_fixture(QtWidgets, "QMenu", ChooseAction):
            tile.contextMenuEvent(QContextMenuEvent(QContextMenuEvent.Mouse, point, tile.mapToGlobal(point)))

    featured_index = tile._index
    mode._set_featured_photo((featured_index + 1) % len(mode._bucket))
    tile = mode.bucket_col.itemAt(0).widget()
    menu_action(tile, "Set as featured")
    results["tray_set_featured"] = mode._cover_idx == featured_index
    tile = mode.bucket_col.itemAt(0).widget()
    from PySide6.QtWidgets import QMessageBox
    removed = mode._bucket[tile._index]
    removed_indices = []
    tile.removeRequested.connect(removed_indices.append)
    with _replace_for_fixture(QMessageBox, "question", lambda *args: QMessageBox.Yes):
        menu_action(tile, "Remove from tray")
    results["tray_remove_action_emitted"] = len(removed_indices) == 1
    results["tray_remove_single_entry"] = len(mode._bucket) == 5
    results["tray_remove_correct_photo"] = all(photo.asset_uuid != removed.asset_uuid for photo in mode._bucket)
    results["tray_remove_original_preserved"] = Path(removed.local_path).is_file()


    first = mode._bucket[0]
    source = Path(first.local_path)
    variant = source.with_name("edited-composer-fixture.jpg")
    shutil.copy2(source, variant)
    first.original_path = str(source)
    first.local_path = str(variant)
    first.slapper_base_path = str(source)
    first.slapper_edited_path = str(variant)
    mode._refresh_bucket()

    suffixes = ("", ' target="_blank" rel="noopener"', ' rel="nofollow"',
                ' target="_blank" rel="noopener nofollow"')
    mode.body.canvas.from_blocks([{"type": "para", "text": "Links: "}])
    for index, suffix in enumerate(suffixes):
        def enter(dialog):
            edits = dialog.findChildren(QLineEdit)
            edits[0].setText(f"https://example.test/{index}?one=1&two=2")
            edits[1].setText(f"Link {index}")
            new_tab, nofollow = dialog.findChildren(QCheckBox)
            new_tab.setChecked(bool(index & 1))
            nofollow.setChecked(bool(index & 2))
            return QDialog.Accepted

        mode.body.canvas.insertPlainText(" ")
        with _replace_for_fixture(QDialog, "exec", enter):
            mode.body.canvas.insert_link()

    session = mode.rail.ensure_session()
    mode._save(True)
    draft = next((item for item in session.list_drafts() if item.title == "Composer proof"), None)
    results["queued"] = bool(draft and draft.status == O.ST_READY)
    if draft:
        saved_paths = [(im.original_path, im.slapper_base_path, im.slapper_edited_path)
                       for im in draft.images]
        for _ in range(5):
            session.add_draft(draft)
            draft = session.load_draft(draft.draft_id)
        results["saved_photo_copy_paths_stay_stable"] = saved_paths == [
            (im.original_path, im.slapper_base_path, im.slapper_edited_path) for im in draft.images
        ] and all(Path(path).is_file() for path in saved_paths[0])
        payload = SmacktalkPoster("https://example.test", "fixture").build_payload(draft, [1], 1)
        for index, suffix in enumerate(suffixes):
            markup = f'<a href="https://example.test/{index}?one=1&amp;two=2"{suffix}>Link {index}</a>'
            results[f"queued_link_options_{index}"] = markup in payload.get("content", "")
        results["queued_colophon"] = payload.get("colophon") == "Made with a 50 mm lens."
        mode._edit(draft)
        mode.body._set_biggie(False)
        mode.body._set_biggie(True)
        rebuilt = mode._build_draft(session)
        results["draft_roundtrip"] = rebuilt.colophon == draft.colophon and rebuilt.caption == draft.caption
        captioned = SmacktalkPoster("https://example.test", "fixture")._resolve_bucket_images(
            rebuilt.caption + "\n\n[img:12|full|center|caption=Caption proof]", [12],
        )
        results["captioned_post_keeps_clickable_link"] = (
            '<a href="https://example.test/0?one=1&amp;two=2"' in captioned
            and "&lt;a href" not in captioned
            and "<figcaption>Caption proof</figcaption>" in captioned
        )

        from coldsnap_qt import connect_panel, mode_take
        with ExitStack() as stack:
            stack.enter_context(_replace_for_fixture(
                mode, "app_config", lambda: {"url": "https://example.test", "smackpress_key": "fixture"},
            ))
            stack.enter_context(_replace_for_fixture(
                mode_take.QInputDialog, "getInt", lambda *args: (413, True),
            ))
            stack.enter_context(_replace_for_fixture(
                connect_panel, "verify_site_mode", lambda *args: True,
            ))
            mode._configure_signature()
            poster, _ = mode._poster_and_url()
            results["signature_block_in_visual_tray"] = any(
                action.tag == "SIG" for action in mode._visual_block_actions()
            )
            results["signature_default_is_site_specific"] = (
                mode._signature_default("") == 0
                and mode._signature_default("https://example.test/") == 413
                and mode._signature_default("https://elsewhere.test") == 0
            )
            body = [block for block in mode.body.authoring_blocks() if block["type"] != "signature"]
            signature = {"type": "signature", "mode": "custom", "light_image_id": 413}
            mode.body.canvas.from_blocks([*body, signature])
            signed = poster.build_payload(mode._build_draft(session), [1], 1)
            results["signature_reuses_existing_gallery_id"] = (
                signed.get("signature_image_id") == 413
                and signed.get("signature_light_image_id") == 413
                and signed["bucket_image_ids"] == [1]
            )
            canvas = mode.body.canvas
            canvas._remove_object(canvas.document().lastBlock().position())
            opted_out = O.Draft.from_dict(mode._build_draft(session).to_dict())
            fields = biggie.signature_fields_for_blocks(biggie.blocks_from_json(opted_out.body_blocks))
            results["signature_opt_out_survives_save"] = (
                len(fields) == 3 and all(value == 0 for value in fields.values())
                and all(poster.build_payload(opted_out, [1], 1).get(field) == 0 for field in fields)
            )
            mode._edit(rebuilt)

        from PySide6.QtWidgets import QMessageBox

        errors = []
        def fail_save(_session, _draft, **_options):
            raise OSError(22, "fixture image copy failed")

        def record_error(_widget, title, text):
            errors.append((title, text))
            return QMessageBox.Ok

        with _replace_for_fixture(O.Session, "add_draft", fail_save), \
                _replace_for_fixture(QMessageBox, "critical", record_error):
            mode.save_btn.click()
            mode.queue_btn.click()
        results["save_and_queue_show_errors_without_clearing"] = (
            len(errors) == 2 and "save this draft" in errors[0][1]
            and "queue this post" in errors[1][1] and "fixture image copy failed" in errors[1][1]
            and mode.title_edit.text() == draft.title and mode._build_draft(session).caption == draft.caption
        )

        if check_renderer:
            from coldsnap_qt.mosaic_browser import MosaicBrowser
            from PySide6.QtWebEngineCore import QWebEnginePage
            browser = MosaicBrowser(mode.body.canvas, measure_only=True)
            browser.failed.connect(mode.body.canvas._fail_inline_mosaic)
            browser.renderProcessTerminated.emit(
                QWebEnginePage.RenderProcessTerminationStatus.CrashedTerminationStatus, 1)
            results["renderer_failure_visible"] = (
                mode.canvas_warning.isVisible() and "renderer stopped" in mode.canvas_warning.text()
            )
            from shiboken6 import delete
            browser.stop()
            browser.close()
            delete(browser)

        from PySide6.QtCore import QTimer

        rail = mode.rail
        reason = "This import key is not bound to a system user. Regenerate it with a default author."
        rail.section.set_expanded(False)
        rail._bridge.finished.emit(0, 1, [(draft.title, reason)])
        results["send_error_visible_in_footer"] = (
            reason in rail.sync_status.text() and not rail.send_error_btn.isHidden()
            and rail.section.header.isChecked()
        )
        dialog_text = []

        def dismiss_send_error():
            warning = app.activeModalWidget()
            if isinstance(warning, QMessageBox):
                dialog_text.append(warning.text())
                warning.accept()

        QTimer.singleShot(0, dismiss_send_error)
        rail.send_error_btn.click()
        results["send_error_details_open"] = (
            len(dialog_text) == 1 and draft.title in dialog_text[0] and reason in dialog_text[0]
        )
        results["failed_send_does_not_clear_draft"] = session.load_draft(draft.draft_id).title == draft.title


    return results

def run(output_dir: str) -> int:
    out = Path(output_dir).resolve()
    out.mkdir(parents=True, exist_ok=True)
    home = out / "sandbox-home"
    shutil.rmtree(home, ignore_errors=True)
    home.mkdir(exist_ok=True)
    os.environ["SNAPSMACK_HOME"] = str(home)

    from PySide6.QtCore import QCoreApplication, QEvent
    from PySide6.QtTest import QTest
    from PySide6.QtWidgets import QApplication
    from shiboken6 import delete, isValid

    from coldsnap_qt.canvas import BiggieCanvas
    from coldsnap_qt.mosaic_browser import MosaicBrowser

    app = QApplication([])
    app.setQuitOnLastWindowClosed(False)

    def retire_views():
        for window in app.allWidgets():
            if isValid(window) and isinstance(window, MosaicBrowser):
                window.blockSignals(True)
                window.stop()
                window.close()
                window.deleteLater()
        QCoreApplication.sendPostedEvents(None, QEvent.DeferredDelete)
        QTest.qWait(50)

    overlap = []

    def guarded_init(view, *args, **kwargs):
        if any(isinstance(window, MosaicBrowser) for window in app.allWidgets()):
            import traceback
            overlap.append("".join(traceback.format_stack(limit=10)))
            raise RuntimeError("Parity capture attempted to open a second mosaic browser")
        original_init(view, *args, **kwargs)

    original_init = MosaicBrowser.__init__
    start_inline = BiggieCanvas._start_inline_mosaic

    def start_active_canvas(canvas):
        if not getattr(canvas, "_fixture_pause_inline", False):
            start_inline(canvas)

    try:
        with ExitStack() as stack:
            stack.enter_context(isolated_settings(home))
            stack.enter_context(_replace_for_fixture(MosaicBrowser, "__init__", guarded_init))
            stack.enter_context(_replace_for_fixture(BiggieCanvas, "_start_inline_mosaic", start_active_canvas))
            return _run_capture(out, app, retire_views, overlap)
    finally:
        for window in app.topLevelWidgets():
            if isValid(window):
                window.close()
        retire_views()
        for window in app.topLevelWidgets():
            if isValid(window):
                delete(window)
        app.quit()
        QCoreApplication.sendPostedEvents(None, QEvent.DeferredDelete)
        delete(app)


def _run_capture(out, app, retire_views, overlap):
    from PIL import Image, ImageDraw
    from PySide6.QtCore import QPoint, QTimer, Qt
    from PySide6.QtGui import QContextMenuEvent, QFont, QFontDatabase
    from PySide6.QtTest import QTest
    from PySide6.QtWidgets import QApplication, QDialog, QDialogButtonBox, QMenu, QPushButton, QScrollArea, QSplitter

    import sumna_offline as O
    from coldsnap_qt import biggie, theme
    from coldsnap_qt.canvas import BiggieCanvas
    from coldsnap_qt.mode_take import MosaicPreview, TakeMode
    from coldsnap_qt.photo_loader import PhotoLoader
    from coldsnap_qt.photo_tray import PhotoThumbTile

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
    mode.photos_tray.set_open(True)
    mode.body._set_biggie(True)
    app.processEvents()
    tiles = [mode.bucket_col.itemAt(index).widget() for index in range(mode.bucket_col.count())]
    tile_badges = [{"index": tile._index, "orientation": tile._orientation, "ratio": tile._ratio_text}
                  for tile in tiles if isinstance(tile, PhotoThumbTile)]
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

    buttons = [button.text() for button in mode.findChildren(QPushButton)]
    checks = {
        "ratio_badges": len(tile_badges) == len(paths) and all(
            tile["orientation"] in {"P", "L", "SQ"} for tile in tile_badges
        ),
        "featured_photo_control": "★ Featured photo (landing tile)" in buttons and mode._cover_idx == 1,
        "pixel_mirror_controls_absent": "⇆ Flip H" not in buttons and "⇅ Flip V" not in buttons,
        "block_count": len(mode.body.authoring_blocks()) == 4,
    }

    mode.hide()
    mode.body.canvas._fixture_pause_inline = True
    mode.body.canvas.setUpdatesEnabled(False)
    retire_views()
    mode.body.canvas._inline_browser = None
    mode.body.canvas._inline_browser_active = None
    mode.body.canvas._inline_browser_jobs.clear()

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
    retire_views()
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
    retire_views()
    captured_block = mode.body.authoring_blocks()[0]
    marker = biggie.serialize_blocks(mode._wire_blocks([captured_block]))
    mode.body.canvas.from_blocks(biggie.blocks_from_json(biggie.blocks_to_json([captured_block])))
    mode.body.canvas._pending_obj_pos = mode.body.canvas.document().begin().position()
    QTimer.singleShot(250, reopen_mosaic)
    mode._canvas_mosaic(span_ids, "asymmetric")
    retire_views()
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
        canvas._fixture_pause_inline = True
        canvas.close()
        canvas.deleteLater()
        retire_views()
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
    dialog_mode.body.canvas._fixture_pause_inline = True
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
        retire_views()
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
    post_canvas._fixture_pause_inline = True
    post_canvas.close()
    post_canvas.deleteLater()
    retire_views()

    # Drive the reported 15-photo trailing cluster and photo 28 through the
    # frozen dialog. Keep the proof on generated photos and a sandbox draft.
    trailing_mode = TakeMode(lambda: {"url": "https://trailing-mosaic-fixture.invalid"})
    trailing_mode.body.canvas._fixture_pause_inline = True
    trailing_mode.setStyleSheet(theme.stylesheet())
    app.processEvents()
    trailing_mode._clear()
    trailing_mode._preview_width = 1200
    trailing_mode.body._set_biggie(False)
    ratios = [.66, .55, 3, .66, .8, 2, 2, 1.5, 1.2, 3, 3] + [1.5] * 18
    for index, ratio in enumerate(ratios, 1):
        source = fixture_dir / f"trailing-{index:02}.jpg"
        size = (round(ratio * 800), 800)
        photo = Image.new("RGB", size, ((index * 31) % 256, 100, 130))
        ImageDraw.Draw(photo).text((35, 30), str(index), fill="white", font_size=160)
        photo.save(source)
        trailing_mode._bucket.append(O.DraftImage(
            local_path=str(source), filename=source.name, asset_uuid=f"trailing-{index}",
            width=size[0], height=size[1],
        ))
    trailing_mode.body.editor.setPlainText(
        "[mosaic=" + ",".join(map(str, range(1, 16))) + " layout=asymmetric]\n\n"
        "[img:bucket:29|full|center]"
    )
    trailing = {"tile_actions": {}, "tray_choices": []}
    tail_ids = {12, 13, 14, 15}

    def capture_trailing(dialog):
        dialog.resize(1280, 820)
        dialog.show()
        preview = dialog._mosaic_preview
        undo = next(button for button in dialog.findChildren(QPushButton) if button.text() == "Undo")
        cells = lambda: preview.computed_cells()["items"]
        has_tail = lambda: any({cell["id"] for cell in group} == tail_ids for group in preview._clusters)
        try:
            if not wait_for(lambda: len(cells()) == 15 and has_tail()):
                return QDialog.Rejected
            before = cells()
            QTest.qWait(220)
            trailing["before_capture"] = dialog.grab().save(str(out / "20-trailing-before.png"), "PNG")
            for label in ("Make larger", "Make smaller", "Break up"):
                for tile_id in sorted(tail_ids):
                    menu = preview._arrangement_menu(tile_id - 1)
                    action = next(action for action in menu.actions() if action.text() == label)
                    action.trigger()
                    key = f"{label} {tile_id}"
                    trailing["tile_actions"][key] = False
                    if not wait_for(lambda: len(cells()) == 15):
                        return QDialog.Rejected
                    after = cells()
                    prior_area = tile_area(before[tile_id - 1])
                    area = tile_area(after[tile_id - 1])
                    effect = {"Make larger": area > prior_area, "Make smaller": area < prior_area,
                              "Break up": not has_tail()}[label]
                    trailing["tile_actions"][key] = effect and after[:11] == before[:11] and (
                        [cell["id"] for cell in after] == list(range(1, 16)))
                    if tile_id == 14:
                        QTest.qWait(220)
                        dialog.grab().save(str(out / f"21-trailing-{label.lower().replace(' ', '-')}.png"), "PNG")
                    undo.click()
                    if not wait_for(lambda: cells() == before and has_tail()):
                        return QDialog.Rejected
            tile = dialog._mosaic_tiles[27]
            picker = next(scroll for scroll in dialog.findChildren(QScrollArea)
                          if scroll.widget().isAncestorOf(tile))
            picker.ensureWidgetVisible(tile)
            point = tile.rect().center()

            def choose_remove():
                menu = app.activePopupWidget()
                if isinstance(menu, QMenu):
                    action = next(action for action in menu.actions() if action.text() == "Remove from tray")
                    trailing["tray_choices"].append(action.text())
                    menu.grab().save(str(out / "22-tray-photo-28-menu.png"), "PNG")
                    QTest.mouseClick(menu, Qt.LeftButton, pos=menu.actionGeometry(action).center())
                    menu.close()

            QTimer.singleShot(0, choose_remove)
            QTimer.singleShot(1500, lambda: app.activePopupWidget() and app.activePopupWidget().close())
            tile.contextMenuEvent(QContextMenuEvent(QContextMenuEvent.Mouse, point, tile.mapToGlobal(point)))
            trailing["photo_28_hidden"] = 27 not in dialog._mosaic_tiles
            trailing["mosaic_selection_preserved"] = dialog._mosaic_checked_indices() == list(range(15))
            QTest.qWait(220)
            trailing["tray_capture"] = dialog.grab().save(str(out / "23-tray-photo-28-removed.png"), "PNG")
            return QDialog.Accepted
        finally:
            dialog.hide()

    QDialog.exec = capture_trailing
    try:
        trailing_mode._insert_mosaic()
        trailing["photo_28_removed"] = all(photo.asset_uuid != "trailing-28" for photo in trailing_mode._bucket)
        trailing["photo_28_file_kept"] = (fixture_dir / "trailing-28.jpg").is_file()
        blocks = trailing_mode.body.authoring_blocks()
        trailing["photo_29_reference_kept"] = len(trailing_mode._bucket) == 28 and (
            trailing_mode._bucket[27].asset_uuid == "trailing-29"
            and any(block.get("img_id") == "bucket:28" for block in blocks))
    finally:
        QDialog.exec = original_dialog_exec
        trailing_mode.close()
        retire_views()
    checks["trailing_quad_actions_on_all_four_tiles"] = len(trailing["tile_actions"]) == 12 and all(
        trailing["tile_actions"].values()) and trailing.get("before_capture", False)
    checks["dialog_removes_photo_28_without_losing_photo_29"] = all(trailing.get(key) for key in (
        "tray_choices", "photo_28_hidden", "mosaic_selection_preserved", "tray_capture", "photo_28_removed",
        "photo_28_file_kept", "photo_29_reference_kept",
    ))

    mode._remote_post_id = 42
    mode._remote_slug = "parity-gate"
    saved = mode._autosave_now()
    checks["autosave_without_errno22"] = bool(saved) and "could not save" not in mode.save_state.text().lower()
    checks["source_files_preserved"] = all(Path(path).is_file() for path in paths)

    composer_mode = TakeMode(lambda: {})
    composer_mode.resize(1440, 900)
    composer_mode.show()
    app.processEvents()
    checks.update(take_composer_checks(composer_mode, paths, check_renderer=True))

    from coldsnap_qt import biggie
    from sumna_offline import Draft, KIND_SMACKTALK, MODE_SMACKTALK
    from sumna_post import SmacktalkPoster

    site_body = (
        "<p>Before [mosaic:752] after</p>\n"
        "<figure>[img:57|full|center]<figcaption>Pontiac RPM</figcaption></figure>"
    )
    fixture_path = os.environ.get("COLD_SNAP_PARITY_PUBLISHED_BODY")
    if fixture_path:
        site_body = Path(fixture_path).read_bytes().decode("utf-8")
    imported_canvas = composer_mode.body.canvas
    imported_canvas.from_blocks(biggie.parse_site_html(site_body))
    app.processEvents()
    imported_blocks = composer_mode.body.authoring_blocks()
    canvas_text = imported_canvas.toPlainText()
    checks["published_mosaics_are_placeholders"] = (
        sum(block["type"] == "site_mosaic" for block in imported_blocks)
        == (5 if fixture_path else 1)
        and "[mosaic:" not in canvas_text and "<p>" not in canvas_text
    )
    checks["published_figure_retains_caption"] = any(
        block["type"] == "image" and block.get("show_caption")
        and "Pontiac RPM" in block.get("caption", "")
        for block in imported_blocks
    )
    imported = Draft(draft_id="parity-import", kind=KIND_SMACKTALK, mode=MODE_SMACKTALK,
                     body_blocks=biggie.blocks_to_json(imported_blocks))
    content, _ = SmacktalkPoster._wire_body(imported)
    checks["published_unchanged_send_preserves_body"] = content == site_body
    imported_canvas.grab().save(str(out / "published-import-parity.png"), "PNG")
    checks.update(take_resize_checks(composer_mode, out))
    composer_mode.close()

    checks["one_mosaic_browser_at_a_time"] = not overlap
    payload = {
        "build": __import__("_version").BUILD_VERSION,
        "checks": checks,
        "tile_badges": tile_badges,
        "mosaic_resize": span,
        "inline_break": inline,
        "inline_row_break": inline_row,
        "dialog_quilt": dialog_proof,
        "post_quilt": post_proof,
        "trailing_mosaic_and_tray": trailing,
        "browser_overlap_stacks": overlap,
        "pass": all(value for key, value in checks.items() if key != "mosaic_thumbnail_count"),
    }
    (out / "parity.json").write_text(json.dumps(payload, indent=2), encoding="utf-8")
    wait_for(lambda: all(not loader._pending for loader in mode.findChildren(PhotoLoader)))
    mode.close()
    app.processEvents()
    return 0 if payload["pass"] else 1


# ===== SNAPSMACK EOF =====
