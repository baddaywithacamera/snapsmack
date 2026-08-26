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

# ===== SNAPSMACK EOF =====
