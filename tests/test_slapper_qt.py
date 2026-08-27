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
from PySide6.QtCore import QThreadPool                   # noqa: E402
from slapper_qt import theme                             # noqa: E402
from slapper_qt.editor_window import EditorWindow        # noqa: E402
from slapper_qt.library_window import LibraryWindow      # noqa: E402
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
    assert lib.list.count() == 3
    lib.act_subfolders.setChecked(True)
    lib.load_folder(folder)
    assert lib.list.count() == 4
    QThreadPool.globalInstance().waitForDone(5000)
    for _ in range(20):
        APP.processEvents(); time.sleep(0.01)
    lib._open_item(lib.list.item(0))
    assert lib._editors and lib._editors[0].doc is not None


def main():
    tests = [v for k, v in sorted(globals().items()) if k.startswith("test_")]
    for test in tests:
        test()
        print(f"  ok  {test.__name__}")
    print(f"\nSNAP SLAPPER Qt: {len(tests)} tests passed")


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
