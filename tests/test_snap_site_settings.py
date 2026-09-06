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
                self.assertEqual(os.path.dirname(paths["completed"]), root)
                self.assertNotEqual(paths["upload"], paths["completed"])
                self.assertTrue(os.path.isdir(paths["upload"]))
                self.assertTrue(os.path.isdir(paths["completed"]))
                self.assertEqual(paths["done"], paths["completed"])
                data = json.load(open(store, encoding="utf-8"))
                self.assertNotIn("portable", json.dumps(data))

    def test_workflow_root_derives_one_url_folder_per_blog(self):
        with tempfile.TemporaryDirectory() as root:
            store = os.path.join(root, "site_settings.json")
            workflow = os.path.join(root, "finished-images")
            with mock.patch.object(settings, "_local_path", return_value=store):
                settings.save_workflow_root(workflow)
                one = settings.handoff_paths("https://photos.example/a", create=True)
                two = settings.handoff_paths("https://journal.example", create=True)
                self.assertNotEqual(one["handoff_dir"], two["handoff_dir"])
                self.assertEqual(os.path.dirname(one["handoff_dir"]), workflow)
                self.assertEqual(os.path.dirname(one["upload"]), one["handoff_dir"])
                self.assertEqual(os.path.dirname(one["completed"]), one["handoff_dir"])
                self.assertTrue(os.path.isdir(one["upload"]))
                self.assertTrue(os.path.isdir(one["completed"]))


if __name__ == "__main__":
    unittest.main()
