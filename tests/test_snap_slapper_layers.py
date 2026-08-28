"""Regression checks for SNAP SLAPPER's custom layer workspace.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import os
import sys
import tempfile
import unittest

from PIL import Image


REPOSITORY_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HUB_ROOT = os.path.join(REPOSITORY_ROOT, "tools", "hub")
if HUB_ROOT not in sys.path:
    sys.path.insert(0, HUB_ROOT)

import editor_engine


class LayerWorkspaceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with open(os.path.join(HUB_ROOT, "editor_ui.py"), "r", encoding="utf-8") as handle:
            cls.ui_source = handle.read()

    def test_native_listbox_is_not_the_layer_workspace(self):
        build = self.ui_source.split("def _build_layers(self):", 1)[1].split(
            "def _build_presets(self):", 1)[0]
        self.assertNotIn("tk.Listbox", build)
        self.assertIn("self.layer_rows", build)
        self.assertIn("self.layer_canvas", build)

    def test_content_and_mask_are_independent_thumbnail_targets(self):
        self.assertIn('self._content_thumbnail(layer)', self.ui_source)
        self.assertIn('self._mask_thumbnail(layer)', self.ui_source)
        self.assertIn('layer_id, "content"', self.ui_source)
        self.assertIn('layer_id, "mask"', self.ui_source)
        self.assertIn('highlightbackground=ACCENT if selected else BORDER', self.ui_source)

    def test_rows_have_visibility_and_drag_controls(self):
        self.assertIn("toggle_layer_visibility", self.ui_source)
        self.assertIn("layer_drag_begin", self.ui_source)
        self.assertIn("layer_drag_motion", self.ui_source)
        self.assertIn("layer_drag_end", self.ui_source)
        self.assertIn('cursor="sb_v_double_arrow"', self.ui_source)

    def test_image_layer_adjustments_preserve_transparency(self):
        with tempfile.TemporaryDirectory() as folder:
            source_path = os.path.join(folder, "source.png")
            overlay_path = os.path.join(folder, "overlay.png")
            Image.new("RGB", (20, 20), (20, 40, 60)).save(source_path)
            overlay = Image.new("RGBA", (20, 20), (255, 0, 0, 0))
            overlay.putpixel((10, 10), (255, 0, 0, 255))
            overlay.save(overlay_path)
            document = editor_engine.EditorDocument(source_path)
            layer = document.add_image_layer(overlay_path)
            layer["adjustments"]["brightness"] = 20
            rendered = document.render()
            self.assertEqual(rendered.getpixel((0, 0)), (20, 40, 60))
            self.assertNotEqual(rendered.getpixel((10, 10)), (20, 40, 60))

    def test_image_layer_transform_moves_and_scales_pixels(self):
        with tempfile.TemporaryDirectory() as folder:
            source_path = os.path.join(folder, "source.png")
            overlay_path = os.path.join(folder, "overlay.png")
            Image.new("RGB", (100, 100), (0, 0, 0)).save(source_path)
            Image.new("RGBA", (10, 10), (255, 0, 0, 255)).save(overlay_path)
            document = editor_engine.EditorDocument(source_path)
            layer = document.add_image_layer(overlay_path)
            layer["transform"].update({"x": .8, "y": .2, "scale_x": 2, "scale_y": 2})
            rendered = document.render()
            self.assertEqual(rendered.getpixel((80, 20)), (255, 0, 0))
            self.assertEqual(rendered.getpixel((50, 50)), (0, 0, 0))

    def test_old_image_layer_without_transform_stays_centred(self):
        with tempfile.TemporaryDirectory() as folder:
            source_path = os.path.join(folder, "source.png")
            overlay_path = os.path.join(folder, "overlay.png")
            Image.new("RGB", (30, 30), (0, 0, 0)).save(source_path)
            Image.new("RGBA", (4, 4), (0, 255, 0, 255)).save(overlay_path)
            document = editor_engine.EditorDocument(source_path)
            layer = document.add_image_layer(overlay_path)
            layer.pop("transform")
            rendered = document.render()
            self.assertEqual(rendered.getpixel((15, 15)), (0, 255, 0))
            self.assertEqual(rendered.getpixel((0, 0)), (0, 0, 0))

    def test_layer_workspace_exposes_move_handles_and_mask_link(self):
        self.assertIn('("MOVE LAYER", "move_layer")', self.ui_source)
        self.assertIn('"scale"', self.ui_source)
        self.assertIn('"rotate"', self.ui_source)
        self.assertIn("toggle_mask_link", self.ui_source)
        self.assertIn("MASK MOVES WITH LAYER", self.ui_source)

    def test_transform_and_mask_state_survive_project_round_trip(self):
        with tempfile.TemporaryDirectory() as folder:
            source_path = os.path.join(folder, "source.png")
            overlay_path = os.path.join(folder, "overlay.png")
            project_path = os.path.join(folder, "layers.slapper")
            Image.new("RGB", (40, 30), (0, 0, 0)).save(source_path)
            Image.new("RGBA", (8, 8), (255, 0, 0, 255)).save(overlay_path)
            document = editor_engine.EditorDocument(source_path)
            layer = document.add_image_layer(overlay_path)
            layer["transform"].update(x=.7, y=.3, scale_x=1.7, scale_y=.8,
                                      rotation=27, flip_x=True)
            layer["mask_linked"] = False
            layer["mask_transform"].update(x=.25, y=.75, scale_x=.6, rotation=15)
            layer["mask"] = editor_engine._mask_to_text(Image.new("L", (40, 30), 180))
            document.save_project(project_path)
            reopened = editor_engine.EditorDocument.load_project(project_path)
            loaded = reopened.layers[0]
            self.assertEqual(loaded["transform"], layer["transform"])
            self.assertEqual(loaded["mask_transform"], layer["mask_transform"])
            self.assertFalse(loaded["mask_linked"])
            self.assertEqual(loaded["mask"], layer["mask"])

    def test_transform_is_one_undoable_state_change(self):
        with tempfile.TemporaryDirectory() as folder:
            source_path = os.path.join(folder, "source.png")
            overlay_path = os.path.join(folder, "overlay.png")
            Image.new("RGB", (20, 20)).save(source_path)
            Image.new("RGBA", (5, 5), (255, 255, 255, 255)).save(overlay_path)
            document = editor_engine.EditorDocument(source_path)
            layer = document.add_image_layer(overlay_path)
            layer["transform"]["x"] = .8
            document.record("Move image layer")
            self.assertTrue(document.undo())
            self.assertEqual(document.layers[0]["transform"]["x"], .5)
            self.assertTrue(document.redo())
            self.assertEqual(document.layers[0]["transform"]["x"], .8)

    def test_mask_link_and_independent_transform_are_undoable(self):
        with tempfile.TemporaryDirectory() as folder:
            source_path = os.path.join(folder, "source.png")
            overlay_path = os.path.join(folder, "overlay.png")
            Image.new("RGB", (20, 20)).save(source_path)
            Image.new("RGBA", (5, 5), (255, 255, 255, 255)).save(overlay_path)
            document = editor_engine.EditorDocument(source_path)
            layer = document.add_image_layer(overlay_path)
            layer["mask_linked"] = False
            layer["mask_transform"]["rotation"] = 45
            document.record("Transform independent mask")
            self.assertTrue(document.undo())
            self.assertTrue(document.layers[0]["mask_linked"])
            self.assertEqual(document.layers[0]["mask_transform"]["rotation"], 0)
            self.assertTrue(document.redo())
            self.assertFalse(document.layers[0]["mask_linked"])
            self.assertEqual(document.layers[0]["mask_transform"]["rotation"], 45)

    def test_unlinked_mask_has_an_independent_transform(self):
        mask = Image.new("L", (20, 20), 0)
        mask.putpixel((10, 10), 255)
        moved = editor_engine.EditorDocument._canvas_mask(
            mask, (20, 20), {"x": .75, "y": .5, "scale_x": 1, "scale_y": 1,
                             "rotation": 0, "flip_x": False, "flip_y": False})
        self.assertEqual(moved.getpixel((15, 10)), 255)
        self.assertEqual(moved.getpixel((10, 10)), 0)

    def test_text_layer_remains_editable_and_renders(self):
        with tempfile.TemporaryDirectory() as folder:
            source_path = os.path.join(folder, "source.png")
            project_path = os.path.join(folder, "text.slapper")
            Image.new("RGB", (200, 100), (0, 0, 0)).save(source_path)
            document = editor_engine.EditorDocument(source_path)
            layer = document.add_text_layer("HELLO", "Headline", font_size=28)
            layer["fill"] = [255, 0, 0, 255]
            rendered = document.render()
            self.assertNotEqual(rendered.getbbox(), None)
            red, green, _blue = rendered.split()
            self.assertIsNotNone(editor_engine.ImageChops.subtract(red, green).getbbox())
            document.save_project(project_path)
            reopened = editor_engine.EditorDocument.load_project(project_path)
            self.assertEqual(reopened.layers[0]["type"], "text")
            self.assertEqual(reopened.layers[0]["text"], "HELLO")
            self.assertEqual(reopened.layers[0]["font_size"], 28)

    def test_mask_and_text_controls_are_exposed(self):
        self.assertIn("RADIAL MASK", self.ui_source)
        self.assertIn("mask_brush_hardness", self.ui_source)
        self.assertIn("mask_brush_opacity", self.ui_source)
        self.assertIn("view_mask_grayscale", self.ui_source)
        self.assertIn("toggle_mask_enabled", self.ui_source)
        self.assertIn("delete_mask", self.ui_source)
        self.assertIn("add_text_layer", self.ui_source)
        self.assertIn("edit_text_layer", self.ui_source)

    def test_text_layout_properties_change_native_render(self):
        layer = {"type": "text", "text": "ONE TWO THREE", "font_size": 24,
                 "font_family": "Default", "font_path": "", "fill": [255, 255, 255, 255],
                 "stroke_fill": [0, 0, 0, 255], "stroke_width": 0,
                 "line_spacing": 8, "character_spacing": 4, "text_box_width": 70,
                 "align": "center", "background": True,
                 "background_fill": [12, 34, 56, 255], "background_padding": 6}
        rendered = editor_engine.EditorDocument._text_layer_image(layer)
        self.assertGreater(rendered.height, 24)
        self.assertEqual(rendered.getpixel((0, 0)), (12, 34, 56, 255))

    def test_selection_modes_and_brush_controls_are_visible(self):
        for value in ("replace", "add", "subtract", "intersect"):
            self.assertIn(f'"{value}"', self.ui_source)
        self.assertIn("mask_reverse", self.ui_source)
        self.assertIn("mask_feather", self.ui_source)
        self.assertIn("mask_brush_flow", self.ui_source)

    def test_x11_wheel_fallbacks_are_bound(self):
        self.assertIn('self.canvas.bind("<Button-4>"', self.ui_source)
        self.assertIn('self.canvas.bind("<Button-5>"', self.ui_source)
        self.assertIn('self.layer_canvas.bind("<Button-4>"', self.ui_source)
        self.assertIn('side_canvas.bind("<Button-5>"', self.ui_source)


# ===== SNAPSMACK EOF =====
