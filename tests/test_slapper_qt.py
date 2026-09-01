"""Headless regression test for the SNAP SLAPPER Qt editor shell.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.

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
os.environ["SNAPSMACK_HOME"] = tempfile.mkdtemp(prefix="slapper_qt_home_")

HUB = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                   "tools", "hub")
sys.path.insert(0, HUB)
sys.path.insert(0, os.path.join(os.path.dirname(HUB), "_shared"))

from PIL import Image, ImageChops                        # noqa: E402
import editor_engine                                     # noqa: E402
from PySide6.QtWidgets import QApplication, QPushButton  # noqa: E402
from PySide6.QtCore import QDir, QThreadPool, QRectF, QPointF  # noqa: E402
from PySide6.QtTest import QTest                         # noqa: E402
from slapper_qt import theme                             # noqa: E402
from slapper_qt.editor_window import EditorWindow, _default_export_name  # noqa: E402
from slapper_qt.library_window import (                  # noqa: E402
    LibraryWindow, _transfer_photo_files,
)
from slapper_qt.layers_panel import BASE                 # noqa: E402
from slapper_qt.layer_styles_dialog import LayerStylesDialog  # noqa: E402
from slapper_qt.engine_bridge import render_pixmap, original_pixmap  # noqa: E402
from slapper_qt.output_tools import create_contact_sheet, SlideshowDialog  # noqa: E402

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
    # Tests invoke recovery explicitly. Leaving every prior test window's timer
    # running causes unrelated event-loop checks to autosave dozens of projects.
    win._recovery_timer.stop()
    win._recovery_dir = None
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
    win.mode_combo.setCurrentIndex(win.mode_combo.findData("advanced"))
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


def test_preview_renders_layer_stack_once_even_with_histogram():
    win = _editor(_image("single-render.jpg", (320, 240)))
    win.mode_combo.setCurrentIndex(win.mode_combo.findData("advanced"))
    calls = 0
    real_render = win.doc.render

    def counted_render(*args, **kwargs):
        nonlocal calls
        calls += 1
        return real_render(*args, **kwargs)

    win.doc.render = counted_render
    win._render_preview()
    assert calls == 1
    assert win.histogram._data and len(win.histogram._data["luminance"]) == 256
    # The consolidated editor has separate lower-resolution drag paths. Invoke
    # them directly so missing merge-time imports cannot hide in Qt timer logs.
    win._render_drag_preview()
    win._render_perspective_preview()


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
    assert not win.rows["vibrance"].isHidden()
    assert not win.rows["vignette"].isHidden()
    assert not win.rows["clarity"].isHidden()
    assert not win.rows["dehaze"].isHidden()
    assert not win.rows["texture"].isHidden()
    assert not win.split_shadow_btn.icon().isNull()
    assert win.split_shadow_btn.styleSheet() == ""
    assert win.rows["vignette_feather"].isHidden()
    assert win.grain_darken_check.isHidden()
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


def test_context_sensitive_toolbars():
    win = _editor(_image("context-bars.jpg", (300, 200)))

    assert [action.text() for action in win._context_selectors.values()] == [
        "EDIT", "RETOUCH", "LOOKS", "OUTPUT", "VIEW"]

    def visible_tools():
        return [action.text() for action in win.context_toolbar.actions()
                if not action.isSeparator()]

    assert visible_tools() == ["Crop", "Auto", "Reset All", "Before/After"]
    win._context_selectors["retouch"].trigger()
    assert visible_tools() == ["Heal", "Red-Eye"]
    win._context_selectors["looks"].trigger()
    assert visible_tools() == [
        "LEWKS…", "LEWK AGAIN…", "Filters…", "Textures…", "Save Recipe", "Apply Recipe"]
    win._context_selectors["output"].trigger()
    assert visible_tools() == ["Save Project", "Export…", "Blog Copy…"]
    win._context_selectors["view"].trigger()
    assert visible_tools() == [
        "Zoom −", "Zoom +", "Fit", "100%", "Filmstrip", "Preferences", "Help"]

    # Normal mode keeps the chosen workspace but removes Advanced-only tools.
    win._context_selectors["looks"].trigger()
    win.mode_combo.setCurrentIndex(win.mode_combo.findData("normal"))
    assert visible_tools() == ["LEWKS…", "LEWK AGAIN…"]


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


def test_teach_me_uses_real_lewk_steps_and_makes_editable_copy():
    import built_in_lewks
    from slapper_qt.teach_me_dialog import TeachMeDialog, actions_for
    win = _editor(_image("teachme.jpg", (360, 240)))
    lewk = built_in_lewks.get("golden-hourglass")
    actions = actions_for(lewk)
    taught_values = {}
    for action in actions:
        taught_values.update(action["values"])
    assert taught_values == lewk["adjustments"]
    dialog = TeachMeDialog(win, lewk, 80)
    assert dialog.steps.count() == len(actions)
    # Selecting a lesson walks the preview cumulatively through the real stack.
    dialog.steps.setCurrentRow(0)
    assert dialog._enabled_values() == actions[0]["values"]
    assert dialog._enabled_values(before_selected=True) == {}
    first_render = dialog._render().tobytes()
    dialog.steps.setCurrentRow(dialog.steps.count() - 1)
    assert dialog._enabled_values() == taught_values
    assert dialog._render().tobytes() != first_render
    assert dialog.values.text()
    assert dialog.values.isHidden()              # lesson first, numbers on request
    assert "Why:" in dialog.why.text() and "Contrast:" not in dialog.why.text()
    dialog.show_settings.setChecked(True)
    assert not dialog.values.isHidden()
    original_count = len(win.doc.layers)
    dialog._make_editable()
    assert len(win.doc.layers) == original_count + 1
    assert win.doc.layers[-1]["lewk"]["id"] == "golden-hourglass"

    hue_lesson = actions_for({"adjustments": {"col_hue_blue": 25}})
    assert hue_lesson[0]["id"] == "colour-mix"
    assert "colour wheel" in __import__(
        "slapper_qt.teach_me_dialog", fromlist=["explain_action"]
    ).explain_action(hue_lesson[0]).lower()


def test_texture_layer():
    old_home = os.environ.get("SNAPSMACK_HOME")
    asset_home = tempfile.mkdtemp(prefix="slapper_assets_", dir=TMP)
    os.environ["SNAPSMACK_HOME"] = asset_home
    win = _editor(_image("tphoto.jpg", (400, 300)))
    tex = _image("texture.png", (120, 60), (200, 120, 60))
    prov = {"texture_id": 7, "title": "Rust",
            "source_url": "https://foundtextures.ca/uploads/x/rust.jpg",
            "source_page_url": "https://foundtextures.ca/rust",
            "highres_download_url": "https://drive.google.com/file/d/rust123/view",
            "source_site": "https://foundtextures.ca",
            "licence": "Free for commercial use", "rights_status": "clear",
            "retrieved_at": "2026-08-27"}
    layer = win.add_texture_layer(tex, prov, fit="cover", blend="overlay", opacity=0.8)
    assert layer["fit"] == "cover" and layer["blend"] == "overlay"
    assert layer["texture"]["texture_id"] == 7
    assert layer["asset_ref"]["key"] == "foundtextures:7"
    assert layer["asset_ref"]["origin"] == "first-party"
    assert win.doc.render((300, 300))
    # provenance + fit survive a .slapper round-trip
    pp = os.path.join(TMP, "tex.slapper")
    win.doc.save_project(pp)
    project_json = editor_engine._read_project_document(pp)
    saved_texture = project_json["layers"][-1]["texture"]
    assert saved_texture["title"] == "Rust"
    assert saved_texture["source_page_url"] == "https://foundtextures.ca/rust"
    assert saved_texture["highres_download_url"].endswith("/rust123/view")
    assert saved_texture["rights_status"] == "clear"
    loaded = editor_engine.EditorDocument.load_project(pp)
    assert loaded.layers[-1]["texture"]["texture_id"] == 7
    assert loaded.layers[-1]["fit"] == "cover"
    # Recipes/LEWKS retain the recoverable reference, never local paths or bytes.
    recipe_layer = win.doc.recipe()["layers"][-1]
    assert recipe_layer["asset_ref"]["source_url"] == "https://foundtextures.ca/rust"
    assert "path" not in recipe_layer
    import texture_assets
    assert texture_assets.resolve(recipe_layer["asset_ref"]) == os.path.abspath(tex)
    # A missing third-party asset fails loudly with its name and stack position.
    missing = editor_engine.EditorDocument(win.doc.source_path)
    bad = missing.add_image_layer(os.path.join(TMP, "gone.png"), "Borrowed paper")
    bad["asset_ref"] = {"key": "external:nope", "name": "Borrowed paper",
                        "origin": "third-party", "source_url": "https://example.test/paper"}
    try:
        missing.render((100, 100))
        assert False, "missing texture must not silently render"
    except FileNotFoundError as error:
        assert "Borrowed paper" in str(error) and "layer 1" in str(error)
    if old_home is None:
        os.environ.pop("SNAPSMACK_HOME", None)
    else:
        os.environ["SNAPSMACK_HOME"] = old_home


def test_found_textures_rights_metadata_and_filter():
    import found_textures
    url = found_textures.search_url(
        "https://foundtextures.ca", "rust", rights="clear")
    assert "rights=clear" in url
    textures, total = found_textures.parse_response({"photos": [{
        "id": 9, "title": "Rust", "thumb_url": "/thumbs/a_rust.jpg",
        "licence": "Free for commercial use", "rights_status": "clear",
        "source_page_url": "https://foundtextures.ca/rust",
        "highres_download_url": "https://drive.google.com/file/d/abc_123/view",
    }]}, "https://foundtextures.ca")
    assert total == 1
    assert textures[0]["rights_status"] == "clear"
    prov = found_textures.provenance(textures[0])
    assert prov["rights_status"] == "clear"
    assert prov["source_page_url"].endswith("/rust")
    assert found_textures.highres_fetch_url(
        textures[0]["highres_download_url"]).endswith("id=abc_123")


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


def test_colour_range_mask_selects_hue_and_survives_editor_render():
    from PIL import ImageOps
    from slapper_qt import masks

    sample = Image.new("RGB", (100, 40), (255, 0, 0))
    sample.paste((0, 0, 255), (50, 0, 100, 40))
    selected = masks.colour_range_mask(sample, 0, 24, 10, 0, 100, 8)
    assert selected.getpixel((20, 20)) > selected.getpixel((80, 20))
    inverted = masks.colour_range_mask(sample, 0, 24, 10, 0, 100, 8,
                                       invert=True)
    assert ImageOps.invert(selected).tobytes() == inverted.tobytes()

    win = _editor(_image("colour-mask.jpg", (400, 300), (220, 40, 40)))
    win.layers_panel._add_adjustment()
    layer = win.layers_panel._selected_layer()
    win._select_mask_type("colour")
    win.mask_hue.slider.setValue(0)
    win.mask_hue_range.slider.setValue(35)
    win._apply_colour_mask()
    assert layer.get("mask") and layer.get("mask_kind") == "colour"
    assert win.doc.render((300, 300))


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


def test_selected_text_layer_moves_directly_on_canvas():
    win = _editor(_image("move-layer.jpg", (400, 300)))
    win.layers_panel._add_text()
    layer = win._text_layer()
    before = dict(layer["transform"])
    assert win.view._layer_move_mode
    win._move_active_layer(.10, -.05, False)
    win._move_active_layer(0, 0, True)
    assert layer["transform"]["x"] == before["x"] + .10
    assert layer["transform"]["y"] == before["y"] - .05
    assert win.doc.history[-1]["label"] == "Move layer"
    assert win.doc.render((300, 300))
    win.set_target(BASE)
    assert not win.view._layer_move_mode


def test_contact_sheet_and_slideshow_outputs():
    first = _image("sheet-one.jpg", (400, 260), (180, 30, 30))
    second = _image("sheet-two.jpg", (260, 400), (30, 90, 190))
    output = os.path.join(TMP, "contact.jpg")
    assert create_contact_sheet([first, second], output, columns=2) == output
    with Image.open(output) as result:
        assert result.width > result.height and result.mode == "RGB"
    show = SlideshowDialog([first, second], interval_ms=60000)
    assert show.index == 0 and show.timer.isActive()
    show.next_photo()
    assert show.index == 1
    show.previous_photo()
    assert show.index == 0
    show.close()


def test_raw_handoff_uses_safe_process_arguments():
    from unittest.mock import patch
    from slapper_qt import raw_handoff
    from slapper_qt.library_window import IMAGE_EXTENSIONS

    assert editor_engine.photo_manager.RAW_EXTENSIONS <= IMAGE_EXTENSIONS
    with patch("slapper_qt.raw_handoff.subprocess.Popen") as popen:
        raw_handoff.launch("C:/Apps/RawTherapee/rawtherapee.exe",
                           "C:/Photos/a photo.nef")
    args, kwargs = popen.call_args
    assert args[0] == [os.path.abspath("C:/Apps/RawTherapee/rawtherapee.exe"),
                       os.path.abspath("C:/Photos/a photo.nef")]
    assert kwargs["shell"] is False


def test_local_blog_copy_contract_is_safe_and_auditable():
    import json
    from slapper_qt import publishing_contract

    source = _image("blog-master.jpg", (1200, 800), (70, 120, 170))
    source_hash = editor_engine.photo_manager.content_hash(source)
    staging = os.path.join(TMP, "blog-stage")
    os.makedirs(staging)
    profile = {
        "name": "Example Blog", "site_url": "https://example.test",
        "extras": {"local_uploads_dir": staging,
                   "capabilities": {"contract_version": 1,
                                    "max_image_width": 640,
                                    "max_image_height": 480,
                                    "preferred_extension": ".jpg",
                                    "preferred_quality": 88,
                                    "strip_gps": True}},
    }
    target, manifest_path, manifest = publishing_contract.prepare(
        editor_engine.EditorDocument(source), profile)
    assert os.path.isfile(target) and os.path.isfile(manifest_path)
    assert editor_engine.photo_manager.content_hash(source) == source_hash
    with Image.open(target) as output:
        assert output.width <= 640 and output.height <= 480
    with open(manifest_path, encoding="utf-8") as handle:
        stored = json.load(handle)
    assert stored == manifest and stored["status"] == "prepared"
    assert stored["source_sha256"] == source_hash
    # Repeating is collision-safe and cannot silently overwrite the first copy.
    second, _, _ = publishing_contract.prepare(
        editor_engine.EditorDocument(source), profile)
    assert second != target and os.path.isfile(target)


def test_layered_psd_export_is_parseable_and_preserves_composite():
    from PIL import ImageChops
    from psd_tools import PSDImage
    from slapper_qt.psd_export import export_layered_psd

    source = _image("psd-source.jpg", (220, 150), (80, 115, 155))
    source_hash = editor_engine.photo_manager.content_hash(source)
    doc = editor_engine.EditorDocument(source)
    adjustment = doc.add_adjustment_layer("Brighter")
    adjustment["adjustments"]["brightness"] = 25
    text = doc.add_text_layer("PSD", name="Title", font_size=28)
    text["transform"]["x"] = .65
    target = os.path.join(TMP, "layered-output.psd")
    assert export_layered_psd(doc, target) == target
    assert editor_engine.photo_manager.content_hash(source) == source_hash

    parsed = PSDImage.open(target)
    assert parsed.size == (220, 150)
    assert len(parsed) == len(doc.layers) + 2
    names = [layer.name for layer in parsed]
    assert names[0].startswith("00 Base image")
    assert names[-1].startswith("SNAP SLAPPER Composite")
    assert sum(1 for layer in parsed if layer.visible) == 1
    expected = doc.render().convert("RGB")
    actual = parsed.composite(force=True).convert("RGB")
    assert ImageChops.difference(expected, actual).getbbox() is None


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


def test_interactive_crop_overlay_and_explicit_apply():
    win = _editor(_image("crop-overlay.jpg", (400, 300)))
    win._context_selectors["edit"].trigger()
    win.act_crop.setChecked(True)
    assert win.doc.geometry["crop"] is None
    assert win.view._crop_rect_item is not None
    assert len(win.view._crop_handles) == 8
    assert len(win.view._crop_grid) == 4
    assert len(win.view._crop_shades) == 4
    assert win._crop_controls_action in win.context_toolbar.actions()

    win.crop_aspect.setCurrentIndex(win.crop_aspect.findData(1.0))
    rect = win.view._crop_rect_item.rect()
    assert abs(rect.width() - rect.height()) < 1
    win.crop_aspect.setCurrentIndex(win.crop_aspect.findData(3 / 2))
    landscape = win.view._crop_rect_item.rect()
    assert abs(landscape.width() / landscape.height() - 1.5) < .01
    # Every resize handle must preserve the ratio, including pulls that hit a
    # photograph boundary. Edge handles were previously able to make 3:2 tall.
    win.view._crop_aspect = 1.5
    scene = win.view._scene.sceneRect()
    start = QRectF(100, 80, 180, 120)
    probes = {
        "n": QPointF(190, scene.top()),
        "s": QPointF(190, scene.bottom()),
        "e": QPointF(scene.right(), 140),
        "w": QPointF(scene.left(), 140),
        "nw": scene.topLeft(),
        "ne": scene.topRight(),
        "se": scene.bottomRight(),
        "sw": scene.bottomLeft(),
    }
    for handle, point in probes.items():
        strict = win.view._aspect_crop_rect(point, handle, start)
        assert abs(strict.width() / strict.height() - 1.5) < .0001, handle
        assert scene.contains(strict), handle
    win._swap_crop_orientation()
    portrait = win.view._crop_rect_item.rect()
    assert abs(portrait.width() / portrait.height() - (2 / 3)) < .01
    # Fine crosshair paths replace the old filled lime squares.
    assert all(type(handle).__name__ == "QGraphicsPathItem"
               for handle in win.view._crop_handles)
    # Crop edges magnetise to the photograph boundary within eight screen px.
    near = QRectF(scene.left() + win.view._crop_snap_distance() / 2,
                  scene.top() + 30, 100, 100)
    snapped = win.view._snap_crop_rect(near, "move")
    assert abs(snapped.left() - scene.left()) < .01
    # A corner rotation is committed through geometry without exiting crop.
    win._rotate_from_crop(2.5)
    assert win.doc.geometry["rotation"] == 2.5
    assert win.view._crop_rect_item is not None
    win._commit_crop()
    assert win.doc.geometry["crop"] is not None
    assert not win.act_crop.isChecked()
    assert win.view._crop_rect_item is None


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
    # The checkbox starts with a photographic colour mix, not flat desaturation.
    assert win.doc.adjustments["bw_orange"] > 0
    assert win.doc.adjustments["bw_blue"] < 0
    for key, _degree in editor_engine.BW_BANDS:
        win.doc.adjustments[key] = 0
        win.rows[key].set_value(0)
    # Explicitly neutral bands remain a predictable plain grayscale.
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
    assert win.perspective_v_row.resolution == .1
    assert win.perspective_h_row.slider.objectName() == "PrecisionSlider"
    win._on_perspective("perspective_vertical", 1.7)
    assert win.doc.geometry["perspective_vertical"] == 1.7
    win._commit_perspective("Vertical perspective")
    win.perspective_h_row.value_label.setText("-2.4")
    win.perspective_h_row._commit_typed_value()
    assert abs(win.doc.geometry["perspective_horizontal"] - (-2.4)) < .001


def test_perspective_geometry_and_project_round_trip():
    path = _image("perspective.jpg", (320, 240))
    source_hash = editor_engine.photo_manager.content_hash(path)
    doc = editor_engine.EditorDocument(path)
    neutral = doc.render()

    doc.geometry["perspective_vertical"] = 45.0
    doc.geometry["perspective_horizontal"] = -25.0
    corrected = doc.render()
    assert corrected.size != neutral.size

    doc.geometry["perspective_edges"] = "transparent"
    doc.geometry["perspective_corners"] = [
        [0.08, 0.04], [0.96, 0.0], [0.90, 0.94], [0.02, 1.0]]
    transparent = doc.render()
    assert transparent.mode == "RGBA" and transparent.size == (320, 240)
    assert transparent.getchannel("A").getextrema()[0] == 0

    project = os.path.join(TMP, "perspective.slapper")
    doc.save_project(project)
    with __import__("zipfile").ZipFile(project) as archive:
        names = set(archive.namelist())
        required = {
            "mimetype", "manifest.json", "project.json", "README.txt",
            "metadata/original-exif.json", "metadata/provenance.json",
            "metadata/dependencies.json", "metadata/checksums.json",
            "schemas/project-schema.json", "previews/composite.tif",
            "previews/thumbnail.jpg",
        }
        assert required <= names
        manifest = __import__("json").loads(archive.read("manifest.json"))
        assert manifest["format_version"] == 2
        assert manifest["layer_order"]
        original_entry = manifest["original"]["archive_path"]
        assert original_entry == "original/original.jpg"
        with open(path, "rb") as source:
            assert archive.read(original_entry) == source.read()
        assert manifest["original"]["sha256"] == source_hash
        assert archive.read("README.txt").startswith(
            b"This .slapper file is a standard ZIP/ZIP64 archive.")
        for layer_id in manifest["layer_order"]:
            assert f"layers/{layer_id}/layer.json" in names
        editor_engine._validate_project_archive(project)
    loaded = editor_engine.EditorDocument.load_project(project)
    assert loaded.geometry["perspective_vertical"] == 45.0
    assert loaded.geometry["perspective_horizontal"] == -25.0
    assert loaded.geometry["perspective_corners"] == doc.geometry["perspective_corners"]
    assert ImageChops.difference(doc.render(), loaded.render()).getbbox() is None
    portable_project = os.path.join(TMP, "portable.slapper")
    portable_source = _image("portable-source.tif", (90, 60))
    portable = editor_engine.EditorDocument(portable_source)
    portable.save_project(portable_project)
    original_hash = editor_engine.photo_manager.content_hash(portable_source)
    os.remove(portable_source)
    reopened = editor_engine.EditorDocument.load_project(portable_project)
    assert editor_engine.photo_manager.content_hash(reopened.source_path) == original_hash
    recipe_target = editor_engine.EditorDocument(path)
    recipe_target.apply_recipe(doc.recipe())
    assert recipe_target.geometry["perspective_vertical"] == 45.0
    assert recipe_target.geometry["perspective_corners"] == doc.geometry["perspective_corners"]
    assert editor_engine.photo_manager.content_hash(path) == source_hash

    opened = EditorWindow()
    assert opened.open_project_path(project)
    opened.doc.project_path = os.path.join(
        TMP, "LDS Chapel at Dusk, Maplewood Drive, Strathmore, AB, 2026-08-28.slapper")
    assert _default_export_name(opened.doc) == (
        "LDS Chapel at Dusk, Maplewood Drive, Strathmore, AB, 2026-08-28.jpg")
    assert opened.doc.geometry["perspective_vertical"] == 45.0

    win = _editor(path)
    win._on_perspective("perspective_vertical", 30)
    win._on_perspective("perspective_horizontal", -10)
    win._move_perspective_corner(0, .12, .08, True)
    assert win.doc.geometry["perspective_vertical"] == 30
    assert win.doc.geometry["perspective_horizontal"] == -10
    assert win.doc.geometry["perspective_corners"][0] == [.12, .08]
    win.free_perspective_btn.setChecked(True)
    assert win.view._perspective_mode and len(win.view._perspective_handles) == 4
    assert len(win.view._perspective_grid) == 4
    win._reset_geometry()
    assert win.doc.geometry["perspective_vertical"] == 0.0
    assert win.doc.geometry["perspective_corners"][0] == [0.0, 0.0]


def test_library_scan_and_open():
    folder = tempfile.mkdtemp(prefix="slaplib_", dir=TMP)
    sub = os.path.join(folder, "sub"); os.makedirs(sub)
    for i in range(3):
        Image.new("RGB", (200, 150), (i * 60, 100, 120)).save(
            os.path.join(folder, f"p{i}.jpg"))
    Image.new("RGB", (100, 100), (10, 10, 10)).save(os.path.join(sub, "deep.png"))
    lib = LibraryWindow()
    assert lib._restore_maximized is True
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
    opened = len(lib._editors)
    lib._open_item(lib.list.item(0))
    assert len(lib._editors) == opened, "the same activation must not open a duplicate editor"


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


def test_fit_preview_refreshes_after_window_layout():
    # A file opened before show() initially sees a tiny provisional viewport.
    # Once layout settles, Fit must re-render rather than stretch that proxy.
    path = _image("layout_fit.jpg", size=(2400, 1600))
    win = EditorWindow()
    win.resize(480, 320)
    assert win.open_path(path)
    first_width = win.view._item.pixmap().width()
    win.resize(1600, 1000)
    win.show()
    QTest.qWait(250)
    APP.processEvents()
    refreshed_width = win.view._item.pixmap().width()
    assert refreshed_width > first_width
    assert not win._zoom_actual and win.view._fitting
    win.close()


def test_window_state_colour_chrome_and_library_captions():
    from slapper_qt import prefs
    original_load, original_save = prefs.load, prefs.save
    stored = dict(prefs.DEFAULTS)
    stored.update({"editor_maximized": True, "library_maximized": True})
    prefs.load = lambda: dict(stored)
    prefs.save = lambda values: stored.update(values) or dict(values)
    try:
        win = EditorWindow()
        assert win._restore_maximized is True
        assert win.split_shadow_btn.objectName() == "SwatchBtn"
        assert win.split_mid_btn.objectName() == "SwatchBtn"
        assert win.split_hi_btn.objectName() == "SwatchBtn"
        css = theme.stylesheet()
        assert "QPushButton#SwatchBtn" in css
        assert f"background: {theme.CANVAS}" in css
        assert f"color: {theme.ACCENT}" in css
        assert f"border: 1px solid {theme.ACCENT}" in css

        lib = LibraryWindow()
        icon = lib.list.iconSize()
        cell = lib.list.gridSize()
        assert cell.height() >= icon.height() + 60
        assert cell.width() > icon.width()
        lib._set_thumbnail_size(220)
        assert lib.list.gridSize().height() >= 280
        lib.close()
        win.close()
    finally:
        prefs.load, prefs.save = original_load, original_save


def test_directory_preferences_survive_save_defaults():
    from slapper_qt import prefs
    for key in ("library_folder", "projects_folder", "exports_folder"):
        assert key in prefs.DEFAULTS


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
    assert win.filmstrip.isVisibleTo(win) or win._filmstrip_visible
    win.filmstrip_handle.click()
    assert not win._filmstrip_visible and "Show" in win.filmstrip_handle.text()
    win.filmstrip_handle.click()
    assert win._filmstrip_visible and "Hide" in win.filmstrip_handle.text()
    # Filmstrip owns a deliberately small private pool so a huge shoot cannot
    # starve unrelated application work. Do not wait on the global pool here.
    win.filmstrip._pool.waitForDone(5000)
    for _ in range(20):
        APP.processEvents(); time.sleep(0.01)
    # activating the other frame opens it (clean doc → no discard dialog)
    win.filmstrip._activate(win.filmstrip._items[os.path.abspath(second)])
    APP.processEvents()
    assert os.path.abspath(win.doc.source_path) == os.path.abspath(second)


def test_filmstrip_queues_thumbnails_when_scrolled():
    folder = tempfile.mkdtemp(prefix="slapper_strip_scroll_", dir=TMP)
    paths = []
    for index in range(60):
        path = os.path.join(folder, f"{index:03d}.jpg")
        Image.new("RGB", (40, 30), (index, 80, 120)).save(path)
        paths.append(os.path.abspath(path))
    win = _editor(paths[0])
    strip = win.filmstrip
    strip.show_for(paths[0])
    assert paths[-1] not in strip._queued
    strip.horizontalScrollBar().setValue(strip.horizontalScrollBar().maximum())
    QTest.qWait(100)
    APP.processEvents()
    assert paths[-1] in strip._queued
    strip._pool.waitForDone(5000)


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
    lib._show_folder_in_tree(folder)
    assert os.path.normcase(os.path.abspath(lib.tree_model.rootPath())) == \
        os.path.normcase(os.path.abspath(os.path.dirname(folder)))
    assert os.path.normcase(os.path.abspath(
        lib.tree_model.filePath(lib.tree.currentIndex()))) == \
        os.path.normcase(os.path.abspath(folder))
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

    # A large folder queues only the visible thumbnail window and look-ahead;
    # off-screen files must not flood the worker pool.
    lib._paths = [os.path.join(folder, f"future-{i:04}.jpg") for i in range(600)]
    lib._icons.clear()
    lib._thumb_queued.clear()
    lib._populate()
    assert _wait_for(lambda: lib.list.count() == 600)
    assert 0 < len(lib._thumb_queued) < 600


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
    state = editor_engine.EditorDocument(first).snapshot()
    state["adjustments"]["contrast"] = 23
    catalog.save_edit_state(first, state)
    assert catalog.load_edit_state(first)["adjustments"]["contrast"] == 23
    renamed = os.path.join(directory, "renamed.jpg")
    os.rename(first, renamed)
    catalog.move_path(first, renamed)
    assert catalog.details(renamed)["rating"] == 5
    assert renamed in catalog.albums["Pets"] and first not in catalog.albums["Pets"]
    assert catalog.load_edit_state(renamed)["adjustments"]["contrast"] == 23
    catalog.record_operation("rename", [(first, renamed)])
    undone = catalog.undo_last_move()
    assert undone and os.path.isfile(first) and not os.path.exists(renamed)

    lib = LibraryWindow()
    for action in (lib.act_import, lib.act_restore_trash,
                   lib.act_rotate_left, lib.act_find_duplicates):
        assert action is not None
    assert lib.catalog_dock.windowTitle() == "PHOTO INFO"


def test_editor_automatically_restores_catalogued_edits():
    from slapper_qt.catalog import Catalog
    directory = tempfile.mkdtemp(prefix="slapper_edit_catalog_", dir=TMP)
    path = _image("catalogued-edit.jpg", (240, 160))
    catalog = Catalog(directory)

    first = _editor(path)
    first.catalog = catalog
    first.doc.adjustments["exposure"] = 1.25
    first.doc.geometry["rotation"] = 2.4
    first.doc.record("Catalogue persistence test")
    first._write_catalog_state()
    assert not first.doc.is_dirty()
    assert not any(name.endswith(".slapper") for name in os.listdir(directory))

    reopened = _editor(path)
    reopened.catalog = catalog
    assert reopened.open_path(path)
    assert reopened.doc.adjustments["exposure"] == 1.25
    assert reopened.doc.geometry["rotation"] == 2.4
    assert not reopened.doc.is_dirty()


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
    compact = editor_engine.apply_adjustments(
        base, {"vignette": -60, "vignette_size": 0, "vignette_feather": 20})
    broad = editor_engine.apply_adjustments(
        base, {"vignette": -60, "vignette_size": 100, "vignette_feather": 20})
    # A broader clear centre leaves an off-centre sample brighter.
    assert sum(broad.getpixel((25, 45))) > sum(compact.getpixel((25, 45)))
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
                {"col_hue_green": 100},                     # HSL hue
                {"col_sat_green": -100},                     # HSL saturation
                {"col_lum_red": 80},                         # HSL luminance
                {"split_midtone": [255, 0, 0], "split_midtone_amount": 80},
                {"glow_amount": 80}):                        # placed glow
        assert list(editor_engine.apply_adjustments(base, adj).getdata()) != ref
    # every new control is wired into the rail
    win = _editor(_image("colour.jpg", (200, 150)))
    for key in ("col_hue_green", "col_sat_red", "col_lum_blue", "glow_amount", "glow_x",
                "split_midtone_amount"):
        assert key in win.rows, key
    # Hue mix is a normal adjustment: it remains editable on a generic layer
    # and survives project/recipe serialization through the adjustment dict.
    win.layers_panel._add_adjustment()
    win.rows["col_hue_green"]._on_slider(75)
    win._on_commit("col_hue_green")
    assert win.active_adjustments()["col_hue_green"] == 75
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


def test_editable_filter_layers_and_project_roundtrip():
    from PIL import ImageChops
    import slapper_filters
    path = _image("filter_layers.jpg", (180, 120), (90, 125, 165))
    for kind in slapper_filters.FILTER_DEFAULTS:
        doc = editor_engine.EditorDocument(path)
        original = doc.render()
        layer = doc.add_filter_layer(kind)
        filtered = doc.render()
        if kind not in {"gaussian_blur", "motion_blur", "radial_blur"}:
            # A perfectly flat test image correctly remains flat when blurred.
            assert ImageChops.difference(original, filtered).getbbox(), kind
        # Amount zero is an exact visual identity.
        layer["settings"]["amount"] = 0
        assert ImageChops.difference(original, doc.render()).getbbox() is None
        layer["settings"]["amount"] = 50
        first = doc.render(); second = doc.render()
        assert ImageChops.difference(first, second).getbbox() is None, kind

    doc = editor_engine.EditorDocument(path)
    layer = doc.add_filter_layer("orton")
    layer["settings"]["radius"] = 23
    project = os.path.join(TMP, "filters.slapper")
    doc.save_project(project)
    loaded = editor_engine.EditorDocument.load_project(project)
    assert loaded.layers[0]["type"] == "filter"
    assert loaded.layers[0]["settings"]["radius"] == 23
    assert ImageChops.difference(doc.render(), loaded.render()).getbbox() is None
    recipe = doc.recipe()
    target = editor_engine.EditorDocument(path)
    target.apply_recipe(recipe)
    assert target.layers[0]["type"] == "filter"

    # Light leak exposes its colour/seed controls and moves directly on canvas.
    win = _editor(path)
    leak = win.doc.add_filter_layer("light_leak")
    win.set_target(leak["id"])
    assert win.view._layer_move_mode
    old_position = leak["settings"]["position"]
    win._move_active_layer(0, .1, False)
    win._move_active_layer(0, 0, True)
    assert leak["settings"]["position"] == old_position + 10
    warm = slapper_filters.apply_filter(original, "light_leak", {"warmth": 100})
    cool = slapper_filters.apply_filter(original, "light_leak", {"warmth": -100})
    assert ImageChops.difference(warm, cool).getbbox()

    source_hash = editor_engine.photo_manager.content_hash(path)
    batch_dir = os.path.join(TMP, "filter-batch")
    outputs = editor_engine.batch_apply([path], recipe, batch_dir)
    assert len(outputs) == 1 and os.path.isfile(outputs[0])
    assert editor_engine.photo_manager.content_hash(path) == source_hash


def test_blur_filter_layers():
    from PIL import ImageChops, ImageDraw
    import slapper_filters
    image = Image.new("RGB", (180, 120), (18, 22, 28))
    draw = ImageDraw.Draw(image)
    draw.rectangle((24, 25, 72, 94), fill=(245, 220, 40))
    draw.line((90, 8, 160, 108), fill=(30, 220, 245), width=5)
    gaussian = slapper_filters.apply_filter(
        image, "gaussian_blur", {"amount": 100, "radius": 10})
    motion = slapper_filters.apply_filter(
        image, "motion_blur", {"amount": 100, "length": 30, "angle": 35})
    spin = slapper_filters.apply_filter(
        image, "radial_blur", {"amount": 100, "strength": 24, "mode": "spin"})
    zoom = slapper_filters.apply_filter(
        image, "radial_blur", {"amount": 100, "strength": 24, "mode": "zoom"})
    for result in (gaussian, motion, spin, zoom):
        assert ImageChops.difference(image, result).getbbox()
        assert result.size == image.size
    assert ImageChops.difference(spin, zoom).getbbox()
    # Amount zero is a non-destructive identity for every blur.
    for kind in ("gaussian_blur", "motion_blur", "radial_blur"):
        off = slapper_filters.apply_filter(image, kind, {"amount": 0})
        assert ImageChops.difference(image, off).getbbox() is None
    from slapper_qt.filters_dialog import RANGES
    assert all(kind in slapper_filters.FILTER_NAMES for kind in
               ("gaussian_blur", "motion_blur", "radial_blur"))
    assert "angle" in RANGES and "center_x" in RANGES and "center_y" in RANGES
    from slapper_qt.filters_dialog import FiltersDialog
    win = _editor(_image("filter-cleanup.jpg", (220, 160)))
    gallery = FiltersDialog(win)
    buttons = [button.text() for button in gallery.findChildren(QPushButton)]
    for label in ("Gaussian Blur", "Motion Blur", "Radial Blur"):
        assert label in buttons


def test_svg_watermark_layer_renders_at_output_size():
    from PIL import ImageChops
    base = _image("svg-base.png", (320, 200), (20, 30, 40))
    svg = os.path.join(TMP, "watermark.svg")
    with open(svg, "w", encoding="utf-8") as handle:
        handle.write('''<svg xmlns="http://www.w3.org/2000/svg" width="240" height="80" viewBox="0 0 240 80">
        <rect width="240" height="80" fill="none"/><text x="12" y="55" font-size="42"
        font-family="sans-serif" fill="white" fill-opacity="0.75">SNAPSMACK</text></svg>''')
    doc = editor_engine.EditorDocument(base)
    original = doc.render()
    layer = doc.add_image_layer(svg, "Vector watermark")
    layer["fit"] = "contain"
    rendered = doc.render()
    assert rendered.size == original.size
    assert ImageChops.difference(original.convert("RGB"), rendered.convert("RGB")).getbbox()
    assert editor_engine._open_layer_image(svg, (640, 240)).size == (640, 240)


def test_qt_layer_styles_apply_and_cancel():
    win = _editor(_image("layer-styles.jpg", (180, 140)))
    win.layers_panel._add_text()
    layer = win.layers_panel._selected_layer()
    assert layer is not None
    assert win.layers_panel.styles_btn.text() == "LAYER STYLES…"

    history_before = len(win.doc.history)
    dialog = LayerStylesDialog(win, layer, win.layers_panel)
    dialog.shadow.setChecked(True)
    dialog.shadow_blur.setValue(14)
    dialog.shadow_offset.setValue(9)
    dialog.stroke.setValue(4)
    dialog.glow.setValue(11)
    dialog.overlay.setChecked(True)
    dialog.overlay_opacity.setValue(42)
    dialog.accept()
    styles = layer["styles"]
    assert styles["shadow"] is True
    assert styles["shadow_blur"] == 14
    assert styles["shadow_offset"] == 9
    assert styles["stroke"] == 4
    assert styles["glow"] == 11
    assert styles["color_overlay"] is True
    assert styles["overlay_opacity"] == .42
    assert len(win.doc.history) == history_before + 1
    assert win.doc.history[-1]["label"] == "Layer styles"

    committed = dict(styles)
    cancelled = LayerStylesDialog(win, layer, win.layers_panel)
    cancelled.stroke.setValue(17)
    cancelled.shadow.setChecked(False)
    cancelled.reject()
    assert layer["styles"] == committed
    assert len(win.doc.history) == history_before + 1


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
    assert any("+" in seq.toString() for seq in win.act_zoom_in.shortcuts())
    assert any("-" in seq.toString() for seq in win.act_zoom_out.shortcuts())
    assert win.act_lewks.shortcut().toString().lower().endswith("k")
    fitted = win.view.transform().m11()
    win.act_zoom_in.trigger()
    assert win.view.transform().m11() > fitted and not win.view._fitting
    win.act_fit.trigger()
    assert win.view._fitting
    # tooltips now advertise the shortcut
    assert "Ctrl" in win.act_lewks.toolTip()
    # help gained the new topics
    titles = [t for t, _ in TOPICS]
    for want in ("Photo Filter", "Keyboard",
                 "Tone curve, split tone, colour mix, and glow"):
        assert want in titles, want
    assert "glass-box" in dict(TOPICS)["LEWKS"].lower()


def test_panomerge_command_and_library_action():
    from slapper_qt.panomerge import XpanoEngine, build_command
    one = _image("pano-01.jpg", (160, 120), (90, 110, 140))
    two = _image("pano-02.jpg", (160, 120), (100, 120, 150))
    output = os.path.join(TMP, "merged panorama.tif")
    fake = os.path.join(TMP, "Xpano.exe")
    with open(fake, "wb") as handle:
        handle.write(b"fake")
    engine = XpanoEngine("XPANO", (fake,))
    command = build_command(engine, [one, two], output)
    assert command == [fake, os.path.abspath(one), os.path.abspath(two),
                       f"--output={os.path.abspath(output)}"]
    try:
        build_command(engine, [one], output)
        assert False, "one photograph must not be accepted"
    except ValueError:
        pass
    library = LibraryWindow()
    assert library.act_panomerge.text() == "PANOMERGE…"
    assert "XPANO" in library.act_panomerge.toolTip()


def test_lewk_again_is_integrated_and_rejects_unsafe_recipe_content():
    import json
    import lewk_again
    win = _editor(_image("lewk-again.jpg", (160, 120)))
    assert win.act_lewk_again.text() == "LEWK AGAIN…"
    assert win.act_lewk_again in win._toolbar_contexts["looks"]
    safe = lewk_again.validate_response(json.dumps({
        "name": "WINTER DOCUMENTARY",
        "description": "Cool and restrained.",
        "adjustments": {"temperature": -12, "vibrance": -8, "shadows": 18},
        "filters": [{"type": "film_grain", "settings": {"amount": 12}}],
        "explanation": ["Cools colour without flattening skin."],
    }), "TEST", "test-model", "cool winter")
    assert len(safe["layers"]) == 2
    assert safe["layers"][0]["adjustments"]["temperature"] == -12
    win.apply_generated_lewk(safe)
    assert win.doc.layers[-1]["filter_type"] == "film_grain"
    for payload in (
        {"adjustments": {"run_script": "oops"}},
        {"adjustments": {}, "filters": [{"type": "shell", "settings": {}}]},
    ):
        try:
            lewk_again.validate_response(json.dumps(payload))
            assert False, "unsafe LEWK content must be rejected"
        except ValueError:
            pass


def main():
    tests = [v for k, v in sorted(globals().items()) if k.startswith("test_")]
    for test in tests:
        test()
        print(f"  ok  {test.__name__}")
    print(f"\nSNAP SLAPPER Qt: {len(tests)} tests passed")


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
