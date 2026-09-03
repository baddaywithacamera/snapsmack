import json
import os
import sys
import tempfile
import unittest
from unittest import mock

SHARED = os.path.join(os.path.dirname(__file__), "..", "tools", "_shared")
sys.path.insert(0, os.path.abspath(SHARED))
import snap_site_settings as settings


class SiteSettingsTests(unittest.TestCase):
    def test_portable_defaults_and_validation(self):
        value = settings.validate_portable({})
        self.assertEqual(value["max_width_landscape"], 3840)
        self.assertEqual(value["max_height_portrait"], 2160)
        self.assertEqual(value["jpeg_quality"], 85)
        with self.assertRaises(ValueError):
            settings.validate_portable({"jpeg_quality": 101})
        with self.assertRaises(ValueError):
            settings.validate_portable({"handoff_dir": "must-not-be-portable"})

    def test_local_handoff_folders_are_siblings(self):
        with tempfile.TemporaryDirectory() as root:
            store = os.path.join(root, "site_settings.json")
            with mock.patch.object(settings, "_local_path", return_value=store):
                settings.save_local("https://example.test", {"handoff_dir": root})
                paths = settings.handoff_paths("https://example.test", create=True)
                self.assertEqual(os.path.dirname(paths["upload"]), root)
                self.assertEqual(os.path.dirname(paths["done"]), root)
                self.assertNotEqual(paths["upload"], paths["done"])
                self.assertTrue(os.path.isdir(paths["upload"]))
                self.assertTrue(os.path.isdir(paths["done"]))
                data = json.load(open(store, encoding="utf-8"))
                self.assertNotIn("portable", json.dumps(data))


if __name__ == "__main__":
    unittest.main()
