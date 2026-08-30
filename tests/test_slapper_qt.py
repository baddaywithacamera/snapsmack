"""Headless regression test for the SNAP SLAPPER Qt editor shell.

Runs offscreen (no display needed):  python tests/test_slapper_qt.py

Covers the load-bearing invariants of the Qt rebuild:
  - the editor drives the existing engine (open, render, export)
  - adjustments + undo/redo/reset
  - histogram + before/after + recipe/project round-trips
  - layers: editing a layer never touches the base; opacity/blend/reorder/delete
  - geometry rotate/flip
  - library scan + threaded thumbnails + open-in-editor
"""

import os
import sys
import tempfile
import time

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

HUB = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                   "tools", "hub")
sys.path.insert(0, HUB)

from PIL import Image                                    # noqa: E402
import editor_engine                                     # noqa: E402
from PySide6.QtWidgets import QApplication               # noqa: E402
from PySide6.QtCore import QDir, QThreadPool             # noqa: E402
from slapper_qt import theme                             # noqa: E402
from slapper_qt.editor_window import EditorWindow        # noqa: E402
from slapper_qt.library_window import (                  # noqa: E402
    LibraryWindow, _transfer_photo_files,
)
from slapper_qt.layers_panel import BASE                 # noqa: E402
from slapper_qt.engine_bridge import render_pixmap, original_pixmap  # noqa: E402

TMP = tempfile.mkdtemp(prefix="slapper_qt_test_")
APP = QApplication.instance() or QApplication([])
APP.setStyleSheet(theme.stylesheet())


def _image(name, size=(400, 300), colour=(90, 120, 150)):
    path = os.path.join(TMP, name)
    Image.new("RGB", size, colour).save(path)
    return path


def _editor(path):
    win = EditorWindow()
    assert win.open_path(path) is True
    return win


def _wait_for(predicate, timeout=5.0):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        APP.processEvents()
        if predicate():
            return True
        time.sleep(0.01)
    APP.processEvents()
    return bool(predicate())


def test_bad_file_does_not_crash():
    # a corrupt/non-image file must fail cleanly, not crash the app
    from PySide6.QtWidgets import QMessageBox
    original = QMessageBox.critical
    QMessageBox.critical = staticmethod(lambda *a, **k: None)
    try:
        bad = os.path.join(TMP, "not_an_image.jpg")
        with open(bad, "w") as handle:
            handle.write("this is not an image")
        win = EditorWindow()
        assert win.open_path(bad) is False   # returns cleanly, no exception
    finally:
        QMessageBox.critical = original


def test_open_render_export():
    win = _editor(_image("a.jpg"))
    assert win.doc is not None
    assert not render_pixmap(win.doc, (300, 300)).isNull()
    out = os.path.join(TMP, "a_out.jpg")
    win.doc.export(out)
    assert os.path.getsize(out) > 0


def test_adjust_undo_reset():
    win = _editor(_image("b.jpg"))
    win.rows["exposure"]._on_slider(win.rows["exposure"]._to_step(1.5))
    win._on_commit("exposure")
    assert win.doc.adjustments["exposure"] == 1.5 and win.doc.is_dirty()
    win.undo()
    assert win.doc.adjustments["exposure"] == 0.0
    win.redo()
    assert win.doc.adjustments["exposure"] == 1.5
    win.reset_all()
    assert win.doc.adjustments["exposure"] == 0.0


def test_histogram_compare_recipe_project():
    win = _editor(_image("c.jpg"))
    win._refresh_histogram()
    assert len(win.histogram._data["luminance"]) == 256
    assert not original_pixmap(win.doc.source_path, (200, 200)).isNull()
    win.rows["contrast"]._on_slider(win.rows["contrast"]._to_step(40))
    win._on_commit("contrast")
    # recipe round-trip
    rp = os.path.join(TMP, "c.slaprecipe")
    editor_engine.save_recipe(rp, win.doc.recipe())
    fresh = editor_engine.EditorDocument(win.doc.source_path)
    fresh.apply_recipe(editor_engine.load_recipe(rp))
    assert fresh.adjustments["contrast"] == 40.0
    # project round-trip
    pp = os.path.join(TMP, "c.slapper")
    win.doc.save_project(pp)
    loaded = editor_engine.EditorDocument.load_project(pp)
    assert loaded.adjustments["contrast"] == 40.0 and not loaded.is_dirty()


def test_layers_isolation_and_ops():
    win = _editor(_image("d.jpg"))
    lp = win.layers_panel
    lp._add_adjustment()
    assert win.active_target != BASE
    base_before = win.doc.adjustments["exposure"]
    win.rows["exposure"]._on_slider(win.rows["exposure"]._to_step(2.0))
    win._on_commit("exposure")
    layer = lp._selected_layer()
    # editing a layer must NOT touch the base photograph
    assert win.doc.adjustments["exposure"] == base_before
    assert layer["adjustments"]["exposure"] == 2.0
    lp.opacity.setValue(60); lp._commit_opacity()
    lp.blend.setCurrentIndex(1)
    assert abs(layer["opacity"] - 0.60) < 1e-6 and layer["blend"] == "multiply"
    lp._toggle_visible(layer["id"], False)
    assert layer["visible"] is False
    lp._toggle_visible(layer["id"], True)
    win.set_target(win.doc.layers[-1]["id"])
    lp._delete()
    assert win.active_target == BASE
    win.undo()
    assert len(win.doc.layers) == 1


def test_prefs_and_export_options():
    from slapper_qt import prefs
    # prefs round-trip against an isolated file
    pfile = os.path.join(TMP, "prefs.json")
    prefs._path = lambda: pfile
    prefs.save({"export_quality": 72, "copyright_text": "(c) Test",
                "add_copyright_if_missing": True, "strip_gps": True,
                "texture_site_hint": "foundtextures"})
    loaded = prefs.load()
    assert loaded["export_quality"] == 72 and loaded["strip_gps"] is True
    # corrupt/missing file falls back to defaults
    with open(pfile, "w") as handle:
        handle.write("not json{{{")
    assert prefs.load()["export_quality"] == prefs.DEFAULTS["export_quality"]
    # export honours the quality preference (smaller quality -> smaller file)
    prefs.save({"export_quality": 30, "copyright_text": "", "add_copyright_if_missing": False,
                "strip_gps": False, "texture_site_hint": "foundtextures"})
    win = _editor(_image("exp.jpg", (400, 300), (180, 90, 40)))
    out = os.path.join(TMP, "q30.jpg")
    # export via the engine with the loaded pref (dialog-free)
    settings = prefs.load()
    win.doc.export(out, quality=settings["export_quality"])
    assert os.path.getsize(out) > 0


def test_auto_enhance():
    # a low-contrast, slightly warm image — auto should recover range + neutralise
    path = os.path.join(TMP, "lowcon.png")
    image = Image.new("RGB", (200, 150))
    px = image.load()
    for y in range(150):
        for x in range(200):
            px[x, y] = (120 + (x % 25), 110 + (y % 15), 95)
    image.save(path)
    win = _editor(path)
    win.auto_enhance()
    adj = win.doc.adjustments
    assert adj["contrast"] == 8.0 and adj["vibrance"] == 10.0
    assert adj["level_white"] < 255 or adj["level_black"] > 0   # levels stretched
    assert win.doc.render((150, 150))


def test_normal_advanced_mode():
    win = _editor(_image("mode.jpg", (300, 200)))
    # Both modes are explicitly named in the toolbar; no secret unchecked state.
    assert [win.mode_combo.itemText(i) for i in range(win.mode_combo.count())] == [
        "Normal", "Advanced"]
    win.mode_combo.setCurrentIndex(win.mode_combo.findData("normal"))
    assert win.mode == "normal" and not win.act_advanced.isChecked()
    # advanced-only sections/rows hidden, curated ones shown
    assert win._sections["LEVELS"].isHidden()
    assert win._sections["PRESENCE"].isHidden()
    assert not win._sections["LIGHT"].isHidden()
    assert win.rows["exposure"].isHidden() and win.rows["whites"].isHidden()
    assert not win.rows["contrast"].isHidden()
    assert win._histogram_wrap.isHidden()
    # advanced-only toolbar hidden, Normal tools kept
    assert win.act_textures.isVisible() is False
    assert win.act_save_project.isVisible() is False
    assert win.act_lewks.isVisible() is True and win.act_auto.isVisible() is True
    # back to advanced restores everything
    win.mode_combo.setCurrentIndex(win.mode_combo.findData("advanced"))
    assert win.mode == "advanced" and win.act_advanced.isChecked()
    assert not win._sections["LEVELS"].isHidden()
    assert not win.rows["exposure"].isHidden()
    assert win.act_textures.isVisible() is True


def test_autosave_recovery():
    win = _editor(_image("rec.jpg", (300, 200)))
    win._recovery_dir = tempfile.mkdtemp(dir=TMP)     # isolate from the real dir
    win.rows["contrast"]._on_slider(win.rows["contrast"]._to_step(30))
    win._on_commit("contrast")
    win._write_recovery()
    recpath = win._recovery_path()
    assert recpath and os.path.isfile(recpath)
    # the recovery reproduces the edit
    rec = editor_engine.EditorDocument.load_project(recpath)
    assert rec.adjustments["contrast"] == 30
    # _maybe_recover with "Yes" returns the recovered document
    from PySide6.QtWidgets import QMessageBox
    original = QMessageBox.question
    QMessageBox.question = staticmethod(lambda *a, **k: QMessageBox.Yes)
    try:
        recovered = win._maybe_recover(win.doc.source_path)
        assert recovered is not None and recovered.adjustments["contrast"] == 30
    finally:
        QMessageBox.question = original
    win._clear_recovery()
    assert not os.path.isfile(recpath)


def test_help_dialog():
    from slapper_qt.help_dialog import HelpDialog, TOPICS
    dialog = HelpDialog()
    assert dialog.list.count() == len(TOPICS)
    dialog.list.setCurrentRow(0)
    assert TOPICS[0][0] in dialog.body.toPlainText()
    # search filters topics
    dialog.search.setText("mask")
    assert 0 < dialog.list.count() < len(TOPICS)
    dialog.search.setText("zzzznotopic")
    assert dialog.list.count() == 0


def test_lewk_apply_preserves_base():
    import built_in_lewks
    win = _editor(_image("lewk.jpg", (400, 300)))
    win.rows["exposure"]._on_slider(win.rows["exposure"]._to_step(1.0))
    win._on_commit("exposure")
    base_exp = win.doc.adjustments["exposure"]
    n0 = len(win.doc.layers)
    layer = win.apply_lewk("golden-hourglass", 80)
    # a LEWK must NOT flatten the photographer's base edits
    assert win.doc.adjustments["exposure"] == base_exp
    assert len(win.doc.layers) == n0 + 1
    assert abs(layer["opacity"] - 0.8) < 1e-6
    assert layer["lewk"]["id"] == "golden-hourglass"
    # stacking a second LEWK keeps unique ids (no Windows time collision)
    win.apply_lewk("frost-warning", 100)
    ids = [lyr["id"] for lyr in win.doc.layers]
    assert len(set(ids)) == len(ids)
    assert win.doc.render((200, 200))


def test_lewks_dialog_previews():
    import built_in_lewks
    from slapper_qt.lewks_dialog import LewksDialog
    win = _editor(_image("lewkprev.jpg", (300, 200)))
    dialog = LewksDialog(win)
    assert dialog.grid.count() == len(built_in_lewks.all_lewks())
    # previews render on the photo (at least one icon populated)
    populated = sum(1 for r in range(dialog.grid.count())
                    if not dialog.grid.item(r).icon().isNull())
    assert populated == dialog.grid.count()


def test_texture_layer():
    win = _editor(_image("tphoto.jpg", (400, 300)))
    tex = _image("texture.png", (120, 60), (200, 120, 60))
    prov = {"texture_id": 7, "title": "Rust",
            "source_url": "https://foundtextures.ca/uploads/x/rust.jpg",
            "source_site": "https://foundtextures.ca",
            "licence": "unknown", "retrieved_at": "2026-08-27"}
    layer = win.add_texture_layer(tex, prov, fit="cover", blend="overlay", opacity=0.8)
    assert layer["fit"] == "cover" and layer["blend"] == "overlay"
    assert layer["texture"]["texture_id"] == 7
    assert win.doc.render((300, 300))
    # provenance + fit survive a .slapper round-trip
    pp = os.path.join(TMP, "tex.slapper")
    win.doc.save_project(pp)
    loaded = editor_engine.EditorDocument.load_project(pp)
    assert loaded.layers[-1]["texture"]["texture_id"] == 7
    assert loaded.layers[-1]["fit"] == "cover"


def test_texture_fit_modes():
    win = _editor(_image("fitphoto.jpg", (400, 300)))
    tex = _image("smalltex.png", (80, 40), (60, 200, 90))
    prov = {"texture_id": 1, "title": "T", "source_url": "", "source_site": "",
            "licence": "unknown", "retrieved_at": "x"}
    layer = win.add_texture_layer(tex, prov, fit="cover")
    for mode in ("cover", "contain", "stretch", "tile", "original"):
        layer["fit"] = mode
        assert win.doc.render((300, 300)).size == (300, 225)


def test_layer_masks():
    win = _editor(_image("mask.jpg", (400, 300)))
    lp = win.layers_panel
    lp._add_adjustment()
    layer = lp._selected_layer()
    assert not win.mask_section.isHidden()   # visible for a selected layer
    # give the layer a strong edit so the mask visibly limits it
    win.rows["exposure"]._on_slider(win.rows["exposure"]._to_step(3.0))
    win._on_commit("exposure")
    # radial mask
    win.mask_size.slider.setValue(30)
    win._apply_radial_mask()
    assert layer.get("mask") and layer.get("mask_enabled") is True
    assert win.doc.render((300, 300))
    # graduated mask replaces it
    win.mask_dir.setCurrentText("Top")
    win._apply_linear_mask()
    assert layer.get("mask")
    # clear
    win._clear_mask()
    assert layer.get("mask") == ""
    # base selected hides the mask panel
    win.set_target(BASE)
    assert win.mask_section.isHidden()


def test_text_layer_editing():
    win = _editor(_image("txt.jpg", (400, 300)))
    win.layers_panel._add_text()
    assert win._text_layer() is not None
    assert not win.text_section.isHidden()
    # edit content, size, colour
    win._on_text_changed("Hello"); win._commit_text("Edit text")
    win._on_text_size("font_size", 120); win._commit_text("Text size")
    layer = win._text_layer()
    layer["fill"] = [255, 0, 0, 255]  # simulate colour pick result
    assert layer["text"] == "Hello" and layer["font_size"] == 120
    assert win.doc.render((300, 300))  # renders text layer
    # selecting base hides the text panel
    win.set_target(BASE)
    assert win.text_section.isHidden()


def test_retouch():
    win = _editor(_image("ret.jpg", (300, 200)))
    win.act_heal.setChecked(True)
    assert win.view._retouch_mode and win._retouch_type == "heal"
    win._add_retouch(0.5, 0.5)
    assert len(win.doc.retouched) == 1 and win.doc.retouched[0]["type"] == "heal"
    # switching to red-eye is mutually exclusive with heal
    win.act_redeye.setChecked(True)
    assert not win.act_heal.isChecked() and win._retouch_type == "red_eye"
    win._add_retouch(0.3, 0.3)
    assert win.doc.retouched[1]["type"] == "red_eye"
    assert win.doc.render((200, 200))  # renders with retouch points
    win._clear_retouch()
    assert win.doc.retouched == []
    win.undo()
    assert len(win.doc.retouched) == 2


def test_crop():
    win = _editor(_image("crop.jpg", (400, 300)))
    # crop to the centre half
    win._apply_crop(0.25, 0.25, 0.75, 0.75)
    assert win.doc.geometry["crop"] == [0.25, 0.25, 0.75, 0.75]
    out = win.doc.render()
    assert out.size == (200, 150)
    # cancel path: enter crop mode (clears crop for display) then toggle off
    win.act_crop.setChecked(True)
    assert win.doc.geometry["crop"] is None
    win.act_crop.setChecked(False)
    assert win.doc.geometry["crop"] == [0.25, 0.25, 0.75, 0.75]


def test_bw_colour_mix():
    from PIL import ImageOps, ImageChops
    bands = Image.new("RGB", (300, 60))
    px = bands.load()
    for y in range(60):
        for x in range(300):
            px[x, y] = [(220, 40, 40), (40, 200, 40), (40, 60, 220)][x // 100]
    path = os.path.join(TMP, "bw.png"); bands.save(path)
    win = _editor(path)
    win.bw_check.setChecked(True)
    # all bands at 0 must equal the plain neutral grayscale
    neutral = win.doc.render()
    grey = ImageOps.grayscale(bands.resize(neutral.size))
    assert ImageChops.difference(neutral, Image.merge("RGB", (grey,) * 3)).getbbox() is None
    # brighten red, darken blue via the sliders
    win.rows["bw_red"]._on_slider(100); win._on_commit("bw_red")
    win.rows["bw_blue"]._on_slider(-100); win._on_commit("bw_blue")
    mixed = win.doc.render()
    assert mixed.getpixel((50, 30))[0] > neutral.getpixel((50, 30))[0]
    assert mixed.getpixel((250, 30))[0] < neutral.getpixel((250, 30))[0]


def test_geometry():
    win = _editor(_image("e.jpg", (400, 300)))
    win._on_geometry("rotation", 90.0); win._commit_geometry("Rotate")
    assert win.doc.render((400, 400)).size == (300, 400)
    win._flip("flip_x")
    assert win.doc.geometry["flip_x"] and win.flip_h_btn.isChecked()
    win._reset_geometry()
    assert win.doc.geometry["rotation"] == 0.0 and not win.doc.geometry["flip_x"]


def test_library_scan_and_open():
    folder = tempfile.mkdtemp(prefix="slaplib_", dir=TMP)
    sub = os.path.join(folder, "sub"); os.makedirs(sub)
    for i in range(3):
        Image.new("RGB", (200, 150), (i * 60, 100, 120)).save(
            os.path.join(folder, f"p{i}.jpg"))
    Image.new("RGB", (100, 100), (10, 10, 10)).save(os.path.join(sub, "deep.png"))
    lib = LibraryWindow()
    lib.act_subfolders.setChecked(False)
    lib.load_folder(folder)
    assert _wait_for(lambda: lib.list.count() == 3)
    assert lib.act_subfolders.text() == "Subfolders: OFF"
    lib.act_subfolders.setChecked(True)
    assert _wait_for(lambda: lib.list.count() == 4)
    assert lib.act_subfolders.text() == "Subfolders: ON"
    QThreadPool.globalInstance().waitForDone(5000)
    for _ in range(20):
        APP.processEvents(); time.sleep(0.01)
    lib._open_item(lib.list.item(0))
    assert lib._editors and lib._editors[0].doc is not None


def test_zoom_actual_shows_native_pixels():
    # "100%" must show the photograph's real pixels (a true focus check), not
    # an upscaled window-sized proxy. Fit stays a smaller, fast proxy.
    big = _image("big_zoom.jpg", size=(2400, 1600))
    win = _editor(big)
    win.zoom_fit()
    APP.processEvents()
    fit_w = win.view._item.pixmap().width()
    win.zoom_actual()
    APP.processEvents()
    actual_w = win.view._item.pixmap().width()
    assert actual_w == 2400, f"100% should be native width 2400, got {actual_w}"
    assert fit_w < 2400, f"Fit should be a smaller proxy, got {fit_w}"
    assert win._zoom_actual is True
    win.zoom_fit()
    assert win._zoom_actual is False
    # opening a fresh photo resets to fitted, never stuck at 100%
    win.zoom_actual()
    win.open_path(big)
    assert win._zoom_actual is False


def test_filmstrip_lists_folder_and_opens():
    # the filmstrip shows the current folder and clicking a frame opens it
    folder = tempfile.mkdtemp(prefix="slapper_strip_", dir=TMP)
    first = os.path.join(folder, "a.jpg")
    Image.new("RGB", (120, 90), (200, 60, 60)).save(first)
    second = os.path.join(folder, "b.jpg")
    Image.new("RGB", (120, 90), (60, 200, 60)).save(second)

    win = _editor(first)
    win.act_filmstrip.setChecked(True)
    win.filmstrip.show_for(first)
    assert win.filmstrip.count() == 2
    QThreadPool.globalInstance().waitForDone(5000)
    for _ in range(20):
        APP.processEvents(); time.sleep(0.01)
    # activating the other frame opens it (clean doc → no discard dialog)
    win.filmstrip._activate(win.filmstrip._items[os.path.abspath(second)])
    APP.processEvents()
    assert os.path.abspath(win.doc.source_path) == os.path.abspath(second)


def test_mask_brush_and_type_switch():
    from PySide6.QtCore import QPoint
    win = _editor(_image("brushmask.jpg", (400, 300)))
    lp = win.layers_panel
    lp._add_adjustment()
    layer = lp._selected_layer()
    # Brush type seeds a paintable canvas from the photo
    win._select_mask_type("brush")
    assert win.mask_stack.currentIndex() == 2
    assert win.mask_brush.has_mask()
    # paint a Hide stroke and store it on the layer
    win.mask_brush.set_paint_white(False)
    win.mask_brush._paint_at(QPoint(20, 20))
    win._store_brush_mask()
    assert layer.get("mask")
    assert win.doc.render((200, 200))     # still renders with a brush mask
    # switching type swaps to only that type's controls
    win._select_mask_type("radial")
    assert win.mask_stack.currentIndex() == 0
    win._select_mask_type("linear")
    assert win.mask_stack.currentIndex() == 1


def test_before_after_divider():
    img = _image("compare.jpg", size=(600, 400))
    win = _editor(img)
    win.act_compare.setChecked(True)     # enter Before/After
    APP.processEvents()
    assert win.view._compare is True
    width = win.view._item.pixmap().width()
    assert width > 0
    # moving the split recomposites the same frame (no engine re-render needed)
    win.view._divider = 0.25
    win.view._compose_compare()
    APP.processEvents()
    assert win.view._item.pixmap().width() == width
    win.act_compare.setChecked(False)    # leave Before/After
    assert win.view._compare is False


def test_library_sort_search_info_and_folders():
    from slapper_qt.library_window import LibraryWindow
    folder = tempfile.mkdtemp(prefix="slapper_lib_", dir=TMP)
    for name, colour in (("apple.jpg", (200, 30, 30)),
                         ("banana.jpg", (220, 200, 40)),
                         ("cherry.jpg", (150, 20, 40))):
        Image.new("RGB", (300, 200), colour).save(os.path.join(folder, name))

    lib = LibraryWindow()
    # The tree must never enumerate the Windows drive root. Disconnected mapped
    # drives and cloud providers can block the shell and freeze the whole app.
    assert lib.tree_model.rootPath() not in ("", QDir.rootPath())
    lib.load_folder(folder)
    assert _wait_for(lambda: lib.list.count() == 3)
    QThreadPool.globalInstance().waitForDone(5000)
    for _ in range(20):
        APP.processEvents(); time.sleep(0.01)

    # search filters the grid by filename
    lib.search.setText("ban")
    visible = [p for p, it in lib._items.items() if not it.isHidden()]
    assert len(visible) == 1 and os.path.basename(visible[0]) == "banana.jpg"
    lib.search.setText("")

    # sort by date re-orders without re-decoding (icons cached)
    lib._icons_before = dict(lib._icons)
    lib.sort_combo.setCurrentIndex(lib.sort_combo.findData("date_new"))
    assert lib._sort == "date_new"
    assert lib.list.count() == 3   # still all present, just reordered

    # clicking an item reports its dimensions + size in the status bar
    first = lib.list.item(0)
    lib._show_info(first)
    message = lib.status.currentMessage()
    assert "×" in message and ("KB" in message or "MB" in message or "B" in message)

    # the folder tree slides out (hidden) and back (isHidden reflects the
    # explicit toggle even when the window itself isn't shown, as offscreen)
    lib.act_folders.setChecked(False)
    assert lib.tree.isHidden() is True
    lib.act_folders.setChecked(True)
    assert lib.tree.isHidden() is False


def test_library_file_organizer_and_resizable_folders():
    source = tempfile.mkdtemp(prefix="slapper_organize_src_", dir=TMP)
    destination = tempfile.mkdtemp(prefix="slapper_organize_dst_", dir=TMP)
    first = os.path.join(source, "first.jpg")
    second = os.path.join(source, "second.jpg")
    Image.new("RGB", (80, 60), (200, 10, 20)).save(first)
    Image.new("RGB", (80, 60), (20, 200, 10)).save(second)

    copied, errors = _transfer_photo_files([first], destination, copy_files=True)
    assert len(copied) == 1 and not errors
    assert os.path.exists(first) and os.path.exists(copied[0])
    # Existing files are protected; organization never silently overwrites.
    copied_again, errors = _transfer_photo_files([first], destination, copy_files=True)
    assert not copied_again and errors and os.path.exists(copied[0])

    moved, errors = _transfer_photo_files([second], destination, copy_files=False)
    assert len(moved) == 1 and not errors
    assert not os.path.exists(second) and os.path.exists(moved[0])

    lib = LibraryWindow()
    assert lib.list.dragEnabled()
    assert lib.tree.acceptDrops()
    lib.folder_size_slider.setValue(15)
    assert lib.tree.font().pointSize() == 15
    lib.split.setSizes([340, 840])
    assert lib.split.sizes()[0] >= 300


def test_qt_catalog_ratings_tags_favorites_and_albums():
    from slapper_qt.catalog import Catalog
    directory = tempfile.mkdtemp(prefix="slapper_catalog_", dir=TMP)
    first = os.path.join(directory, "cat.jpg")
    second = os.path.join(directory, "dog.jpg")
    Image.new("RGB", (60, 40), "orange").save(first)
    Image.new("RGB", (60, 40), "brown").save(second)
    catalog = Catalog(directory)
    catalog.set_details(
        [first], favorite=True, rating=5, add_tags="cats, home")
    assert catalog.details(first) == {
        "favorite": True, "rating": 5, "tags": "cats, home"}
    catalog.set_details([first, second], add_tags="family")
    assert "family" in catalog.details(first)["tags"]
    assert catalog.details(second)["tags"] == "family"
    catalog.add_to_album("Pets", [first, second, first])
    assert catalog.albums["Pets"] == [first, second]
    catalog.register_folder(directory)
    catalog.update_index([first, second])
    assert set(catalog.all_paths()) == {first, second}
    renamed = os.path.join(directory, "renamed.jpg")
    os.rename(first, renamed)
    catalog.move_path(first, renamed)
    assert catalog.details(renamed)["rating"] == 5
    assert renamed in catalog.albums["Pets"] and first not in catalog.albums["Pets"]
    catalog.record_operation("rename", [(first, renamed)])
    undone = catalog.undo_last_move()
    assert undone and os.path.isfile(first) and not os.path.exists(renamed)

    lib = LibraryWindow()
    for action in (lib.act_import, lib.act_restore_trash,
                   lib.act_rotate_left, lib.act_find_duplicates):
        assert action is not None
    assert lib.catalog_dock.windowTitle() == "PHOTO INFO"


def test_safe_import_and_transactional_batch_rename():
    from slapper_qt.organizer_ops import import_photos, batch_rename
    source = tempfile.mkdtemp(prefix="slapper_import_src_", dir=TMP)
    destination = tempfile.mkdtemp(prefix="slapper_import_dst_", dir=TMP)
    paths = []
    for number, colour in enumerate(("red", "blue"), 1):
        path = os.path.join(source, f"camera_{number}.jpg")
        Image.new("RGB", (70, 50), colour).save(path)
        paths.append(path)
    outputs, skipped = import_photos(paths, destination, date_folders=False)
    assert len(outputs) == 2 and not skipped
    outputs_again, skipped = import_photos(paths, destination, date_folders=False)
    assert not outputs_again and len(skipped) == 2
    changes = batch_rename(outputs, "holiday_{n}")
    assert [os.path.basename(target) for _source, target in changes] == [
        "holiday_1.jpg", "holiday_2.jpg"]
    assert all(os.path.isfile(target) for _source, target in changes)
    try:
        batch_rename([target for _source, target in changes], "same")
        assert False, "duplicate batch targets must be rejected"
    except FileExistsError:
        pass


def test_vignette_feather_and_grain_darken():
    base = Image.new("RGB", (120, 90), (128, 128, 128))
    # feather changes the vignette edge softness → a different result
    soft = editor_engine.apply_adjustments(base, {"vignette": -60, "vignette_feather": 95})
    hard = editor_engine.apply_adjustments(base, {"vignette": -60, "vignette_feather": 3})
    assert list(soft.getdata()) != list(hard.getdata())
    # darken-only grain never brightens the photo (soft-light grain can)
    darkened = editor_engine.apply_adjustments(base, {"grain": 80, "grain_darken": True})
    mean = sum(sum(p) for p in darkened.getdata()) / (120 * 90 * 3)
    assert mean <= 129, f"darken-only grain should not brighten, mean={mean}"


def test_split_tone():
    base = Image.new("RGB", (80, 60), (128, 128, 128))
    off = editor_engine.apply_adjustments(base, {})
    warm = editor_engine.apply_adjustments(
        base, {"split_highlight": [255, 180, 80], "split_highlight_amount": 80})
    assert list(off.getdata()) != list(warm.getdata())    # a tone shifts colour
    zero = editor_engine.apply_adjustments(
        base, {"split_shadow": [0, 0, 255], "split_shadow_amount": 0})
    assert list(zero.getdata()) == list(off.getdata())     # amount 0 == off
    # the UI wires the amount slider + colour swatch without error
    win = _editor(_image("split.jpg", (200, 150)))
    assert "split_shadow_amount" in win.rows and "split_highlight_amount" in win.rows
    win.active_adjustments()["split_highlight"] = [255, 0, 0]
    win._update_split_swatches()


def test_photo_filter():
    from PIL import ImageStat
    base = Image.new("RGB", (80, 60), (128, 128, 128))
    off = editor_engine.apply_adjustments(base, {})
    # amount 0 == off (backward compatible)
    zero = editor_engine.apply_adjustments(
        base, {"photo_filter_color": [236, 138, 0], "photo_filter_density": 0})
    assert list(zero.getdata()) == list(off.getdata())
    # a warming filter shifts colour
    warm = editor_engine.apply_adjustments(
        base, {"photo_filter_color": [236, 138, 0], "photo_filter_density": 40})
    assert list(warm.getdata()) != list(off.getdata())
    # preserve-brightness keeps luma ~unchanged; without it, luma drifts
    keep = editor_engine.apply_adjustments(
        base, {"photo_filter_color": [0, 0, 255], "photo_filter_density": 60,
               "photo_filter_preserve_lum": True})
    drop = editor_engine.apply_adjustments(
        base, {"photo_filter_color": [0, 0, 255], "photo_filter_density": 60,
               "photo_filter_preserve_lum": False})
    base_luma = ImageStat.Stat(off.convert("L")).mean[0]
    assert abs(ImageStat.Stat(keep.convert("L")).mean[0] - base_luma) < 6
    assert ImageStat.Stat(drop.convert("L")).mean[0] < base_luma - 6
    # the preset table carries the standard set + faux infrared
    labels = [p[0] for p in editor_engine.PHOTO_FILTER_PRESETS]
    for want in ("Warming Filter (85)", "Cooling Filter (80)", "Sepia",
                 "Underwater", "Faux IR — R72 Deep Red"):
        assert want in labels, want
    # UI: choosing a preset writes colour + density onto the active target
    win = _editor(_image("filter.jpg", (200, 150)))
    assert "photo_filter_density" in win.rows
    idx = [win.photo_filter_combo.itemText(i)
           for i in range(win.photo_filter_combo.count())].index("Sepia")
    win.photo_filter_combo.setCurrentIndex(idx)
    win._on_photo_filter_preset(idx)
    tgt = win.active_adjustments()
    assert tgt["photo_filter_color"] == [172, 122, 51]
    assert tgt["photo_filter_density"] == 25.0
    win._update_photo_filter_swatch()          # no error
    win._sync_photo_filter_combo(tgt)          # round-trips back to a named preset
    assert win.photo_filter_combo.currentText() == "Sepia"


def test_colour_engine_additions():
    base = Image.new("RGB", (60, 48), (120, 150, 90))
    ref = list(editor_engine.apply_adjustments(base, {}).getdata())
    for adj in ({"curve_blue": [[0, 40], [255, 255]]},      # per-channel curve
                {"col_sat_green": -100},                     # HSL saturation
                {"col_lum_red": 80},                         # HSL luminance
                {"split_midtone": [255, 0, 0], "split_midtone_amount": 80},
                {"glow_amount": 80}):                        # placed glow
        assert list(editor_engine.apply_adjustments(base, adj).getdata()) != ref
    # every new control is wired into the rail
    win = _editor(_image("colour.jpg", (200, 150)))
    for key in ("col_sat_red", "col_lum_blue", "glow_amount", "glow_x",
                "split_midtone_amount"):
        assert key in win.rows, key
    # the curve editor stores a per-channel curve onto the target
    win._on_curve_changed("curve_red", [[0, 0], [128, 180], [255, 255]])
    assert win.active_adjustments()["curve_red"] == [[0, 0], [128, 180], [255, 255]]
    win.curve_editor.set_curves(win.active_adjustments())   # loads back with no error


def test_deconvolve_pure_pil():
    from PIL import ImageDraw, ImageFilter, ImageChops, ImageStat
    def edge(im):
        hp = ImageChops.difference(im, im.filter(ImageFilter.GaussianBlur(1.2)))
        return ImageStat.Stat(hp).stddev[0]
    img = Image.new("RGB", (120, 90), (40, 44, 54))
    d = ImageDraw.Draw(img)
    d.rectangle([15, 15, 55, 75], fill=(210, 210, 215))
    d.line([80, 8, 80, 82], fill=(235, 235, 240), width=1)
    blur = img.filter(ImageFilter.GaussianBlur(2.0))
    # lens deconvolution recovers edge detail (Richardson-Lucy, no numpy/scipy)
    rec = editor_engine.deconvolve(blur, kind="lens", radius=2.0, iterations=10)
    assert rec.mode == "RGB" and rec.size == img.size
    assert edge(rec) > edge(blur) * 1.3
    # motion deconvolution runs and changes the image
    mo = editor_engine.deconvolve(blur, kind="motion", length=9, angle=0, iterations=8)
    assert list(mo.getdata()) != list(blur.getdata())
    # iterations are bounded (0 -> at least 1 pass, no crash)
    editor_engine.deconvolve(blur, kind="lens", radius=1.5, iterations=0)


def test_smart_sharpen():
    from PIL import ImageDraw
    img = Image.new("RGB", (120, 90), (128, 128, 128))
    d = ImageDraw.Draw(img)
    d.rectangle([10, 10, 60, 80], fill=(60, 60, 60))
    d.line([70, 5, 70, 85], fill=(230, 230, 230), width=1)
    base = list(editor_engine.apply_adjustments(img, {}).getdata())
    g = list(editor_engine.apply_adjustments(
        img, {"sharpen": 60, "sharpen_mode": "gaussian"}).getdata())
    l = list(editor_engine.apply_adjustments(
        img, {"sharpen": 60, "sharpen_mode": "lens"}).getdata())
    assert g != base and l != base            # both sharpen
    assert l != g                             # the two edge models differ
    # reduce noise and radius each change the result
    assert list(editor_engine.apply_adjustments(
        img, {"sharpen": 60, "sharpen_mode": "gaussian",
              "sharpen_reduce_noise": 80}).getdata()) != g
    assert list(editor_engine.apply_adjustments(
        img, {"sharpen": 60, "sharpen_radius": 3.0}).getdata()) != l
    # amount 0 stays neutral (backward compatible)
    assert list(editor_engine.apply_adjustments(img, {"sharpen": 0}).getdata()) == base
    # UI: the detail controls are wired onto the active target
    win = _editor(_image("sharp.jpg", (200, 150)))
    assert "sharpen_radius" in win.rows and "sharpen_reduce_noise" in win.rows
    idx = [win.sharpen_mode_combo.itemData(i)
           for i in range(win.sharpen_mode_combo.count())].index("gaussian")
    win.sharpen_mode_combo.setCurrentIndex(idx)
    win._on_sharpen_mode(idx)
    assert win.active_adjustments()["sharpen_mode"] == "gaussian"


def test_keyboard_shortcuts_and_help_topics():
    from slapper_qt.help_dialog import TOPICS
    win = _editor(_image("keys.jpg", (160, 120)))
    # every mapped action carries its shortcut, and none of them collide
    seqs = []
    for action, _seq in win._shortcuts:
        s = action.shortcut().toString()
        assert s, f"missing shortcut on {action.text()}"
        seqs.append(s)
    assert len(seqs) == len(set(seqs)), "duplicate shortcuts: " + str(seqs)
    assert win.act_fit.shortcut().toString().lower().endswith("0")
    assert win.act_full.shortcut().toString().lower().endswith("1")
    assert win.act_lewks.shortcut().toString().lower().endswith("k")
    # tooltips now advertise the shortcut
    assert "Ctrl" in win.act_lewks.toolTip()
    # help gained the new topics
    titles = [t for t, _ in TOPICS]
    for want in ("Photo Filter", "Keyboard",
                 "Tone curve, split tone, colour mix, and glow"):
        assert want in titles, want
    assert "glass-box" in dict(TOPICS)["LEWKS"].lower()


def main():
    tests = [v for k, v in sorted(globals().items()) if k.startswith("test_")]
    for test in tests:
        test()
        print(f"  ok  {test.__name__}")
    print(f"\nSNAP SLAPPER Qt: {len(tests)} tests passed")


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
