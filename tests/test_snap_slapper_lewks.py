"""Regression checks for SNAP SLAPPER's curated stock LEWKS."""

# SNAPSMACK_EOF_HEADER

import os
import sys
import unittest

from PIL import Image

HUB = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "tools", "hub"))
if HUB not in sys.path:
    sys.path.insert(0, HUB)

import built_in_lewks
import editor_engine


class BuiltInLewksTests(unittest.TestCase):
    def test_stock_pack_has_distinct_ids_names_and_required_categories(self):
        lewks = built_in_lewks.all_lewks()
        self.assertGreaterEqual(len(lewks), 12)
        self.assertEqual(len({item["id"] for item in lewks}), len(lewks))
        self.assertEqual(len({item["name"] for item in lewks}), len(lewks))
        categories = {item["category"] for item in lewks}
        self.assertTrue({"Clean + Corrective", "Film + Print", "Black + White",
                         "Portrait", "Landscape + Weather", "Night + Neon",
                         "Experimental"}.issubset(categories))

    def test_every_stock_lewk_renders(self):
        source = Image.new("RGB", (96, 64), (110, 135, 175))
        for item in built_in_lewks.all_lewks():
            with self.subTest(lewk=item["id"]):
                output = editor_engine.apply_adjustments(
                    source, built_in_lewks.recipe(item["id"])["layers"][0]["adjustments"])
                self.assertEqual(output.size, source.size)

    def test_zero_strength_is_neutral(self):
        for item in built_in_lewks.all_lewks():
            recipe = built_in_lewks.recipe(item["id"], 0)
            self.assertEqual(recipe["adjustments"], editor_engine.DEFAULT_ADJUSTMENTS)
            self.assertEqual(recipe["layers"][0]["opacity"], 0)

    def test_strength_is_bounded_and_provenance_is_recorded(self):
        low = built_in_lewks.recipe("parking-lot-disco", -50)
        high = built_in_lewks.recipe("parking-lot-disco", 500)
        self.assertEqual(low["lewk"]["strength"], 0)
        self.assertEqual(high["lewk"]["strength"], 100)
        self.assertEqual(high["lewk"]["provenance"], "built-in")
        self.assertEqual(high["layers"][0]["opacity"], 1)


class ApplyingALewkPreservesWorkTests(unittest.TestCase):
    """Applying a LEWK must stack on top of the photographer's edits, never
    reset the base adjustments or duplicate layers (spec: a LEWK does not
    flatten existing edits)."""

    def _document(self):
        return editor_engine.EditorDocument("no-such-file.jpg")

    def test_apply_lewk_keeps_base_adjustments_and_adds_one_layer(self):
        document = self._document()
        document.adjustments["exposure"] = 0.35
        document.adjustments["contrast"] = 20
        layers = built_in_lewks.recipe("golden-hourglass", 80)["layers"]
        document.stack_layers(layers)
        # Base edits survive untouched.
        self.assertEqual(document.adjustments["exposure"], 0.35)
        self.assertEqual(document.adjustments["contrast"], 20)
        # Exactly one LEWK layer was added on top.
        self.assertEqual(len(document.layers), 1)
        self.assertEqual(document.layers[0]["lewk"]["id"], "golden-hourglass")

    def test_stacking_two_lewks_does_not_reset_or_collapse(self):
        document = self._document()
        document.adjustments["shadows"] = 15
        document.stack_layers(built_in_lewks.recipe("silver-teeth")["layers"])
        document.stack_layers(built_in_lewks.recipe("noir-means-noir")["layers"])
        self.assertEqual(document.adjustments["shadows"], 15)
        self.assertEqual(len(document.layers), 2)

    def test_paste_adjustments_replaces_base_without_touching_layers(self):
        document = self._document()
        document.stack_layers(built_in_lewks.recipe("frost-warning")["layers"])
        copied = dict(editor_engine.DEFAULT_ADJUSTMENTS)
        copied["temperature"] = -22
        document.set_adjustments(copied)
        self.assertEqual(document.adjustments["temperature"], -22)
        # The existing layer is left exactly as it was — no append, no duplicate.
        self.assertEqual(len(document.layers), 1)

# ===== SNAPSMACK EOF =====
