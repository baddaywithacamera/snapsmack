import os
import sys
import tempfile
import unittest
from unittest import mock


HERE = os.path.dirname(os.path.dirname(__file__))
SHARED = os.path.abspath(os.path.join(HERE, "..", "_shared"))
sys.path[:0] = [HERE, SHARED]

import profile_manager
import snap_prompts
import snap_site_settings


class SharedSiteSelectionTests(unittest.TestCase):
    def test_canonical_profile_exposes_hq_prompt_and_upload_folder(self):
        with tempfile.TemporaryDirectory() as root:
            store = os.path.join(root, "site_settings.json")
            with mock.patch.object(snap_site_settings, "_local_path", return_value=store):
                snap_site_settings.save_local(
                    "https://example.test", {"handoff_dir": root})
                selected = profile_manager._canonical_to_sybu({
                    "name": "Example",
                    "site_url": "https://example.test",
                    "portable": {
                        "prompt": "Describe this site's photographs.",
                        "max_width_landscape": 2500,
                        "max_height_portrait": 1850,
                        "jpeg_quality": 88,
                        "image_resize_enabled": False,
                        "export_sharpen": "medium",
                    },
                })

                self.assertEqual(selected["prompt"], "Describe this site's photographs.")
                self.assertEqual(selected["upload_dir"], os.path.join(root, "upload"))
                self.assertEqual(selected["max_width_landscape"], 2500)
                self.assertEqual(selected["max_height_portrait"], 1850)
                self.assertEqual(selected["jpeg_quality"], 88)
                self.assertFalse(selected["image_resize_enabled"])
                self.assertEqual(selected["export_sharpen"], "medium")

    def test_profile_round_trip_preserves_shared_portable_fields(self):
        canonical = profile_manager._sybu_to_canonical({
            "name": "Example",
            "url": "https://example.test",
            "portable": {"prompt": "Keep me"},
            "portable_sync": {"synced_at": "2026-09-03T00:00:00Z"},
        })
        self.assertEqual(canonical["portable"]["prompt"], "Keep me")
        self.assertEqual(canonical["portable_sync"]["synced_at"],
                         "2026-09-03T00:00:00Z")

    def test_site_prompt_falls_back_to_shared_prompt_pool(self):
        with mock.patch.object(
                snap_prompts, "load",
                return_value={"example.test": "Prompt saved through SNAP HQ."}):
            selected = profile_manager._canonical_to_sybu({
                "name": "Example",
                "site_url": "https://example.test",
                "portable": {"prompt": ""},
            })

        self.assertEqual(selected["prompt"], "Prompt saved through SNAP HQ.")

    def test_portable_prompt_wins_over_shared_prompt_pool(self):
        with mock.patch.object(
                snap_prompts, "load",
                return_value={"example.test": "Older pooled prompt."}):
            selected = profile_manager._canonical_to_sybu({
                "name": "Example",
                "site_url": "https://example.test",
                "portable": {"prompt": "Current synced prompt."},
            })

        self.assertEqual(selected["prompt"], "Current synced prompt.")


if __name__ == "__main__":
    unittest.main()
