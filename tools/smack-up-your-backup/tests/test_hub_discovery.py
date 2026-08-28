"""
Smack Up Your Backup — hub_discovery regression tests.

Covers the PURE LOGIC of the hub-centric backup rework (one key, whole fleet):
spoke filtering, and the profile auto-fill that turns a hub's discovered node
list into SUYB profiles. The network/GUI halves cannot run headless, so these
lock in the parts that can: key preference, global-cloud inheritance, per-blog
backup dirs, and hub-first ordering.

They use only unittest so they also run in the packaged build environment.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path

APP_DIR = Path(__file__).resolve().parents[1]
if str(APP_DIR) not in sys.path:
    sys.path.insert(0, str(APP_DIR))

import hub_discovery
from hub_discovery import (
    HubDiscovery,
    build_profiles_from_spokes,
    resolve_cloud_config,
    _safe_dirname,
    _name_from_url,
)


class _FakeDiscovery(HubDiscovery):
    """HubDiscovery with the network call stubbed to a canned suyb-data payload."""

    def __init__(self, payload):
        super().__init__("https://hub.example", api_key="suyb_key")
        self._payload = payload

    def fetch_suyb_data(self):  # override — no HTTP
        return self._payload


class DiscoverSpokesTests(unittest.TestCase):
    def test_filters_to_spokes_only(self):
        payload = {
            "ok": True,
            "site_url": "https://hub.example",
            "site_name": "Hub",
            "cloud_config": {"provider": "google_drive", "folder_id": "FOLDER"},
            "backup_status": {},
            "multisite": {
                "nodes": [
                    {"role": "hub", "site_url": "https://hub.example", "site_name": "Hub"},
                    {"role": "spoke", "site_url": "https://a.example", "site_name": "A"},
                    {"role": "spoke", "site_url": "https://b.example", "site_name": "B"},
                ]
            },
        }
        hub_info, spokes = _FakeDiscovery(payload).discover_spokes()
        self.assertEqual(hub_info["site_name"], "Hub")
        self.assertEqual(hub_info["cloud_config"]["folder_id"], "FOLDER")
        self.assertEqual([s["site_name"] for s in spokes], ["A", "B"])

    def test_empty_node_list_is_safe(self):
        payload = {"ok": True, "site_url": "https://hub.example", "multisite": {"nodes": []}}
        hub_info, spokes = _FakeDiscovery(payload).discover_spokes()
        self.assertEqual(spokes, [])


class BuildProfilesTests(unittest.TestCase):
    def setUp(self):
        self.hub_info = {
            "site_url": "https://hub.example",
            "site_name": "Hub Site",
            "cloud_config": {"provider": "google_drive", "folder_id": "HUBFOLDER"},
            "backup_status": {},
        }
        self.spokes = [
            {
                "role": "spoke",
                "site_url": "https://a.example/",
                "site_name": "Alpha",
                "api_key_backup": "BACKUP_A",
                "api_key_local": "LOCAL_A",
            },
            {
                "role": "spoke",
                "site_url": "https://b.example",
                "site_name": "Bravo",
                "api_key_backup": "",          # not re-joined since scoped-key release
                "api_key_local": "LOCAL_B",
            },
        ]

    def _build(self, **kw):
        return build_profiles_from_spokes(
            self.hub_info, self.spokes, spoke_configs={}, **kw
        )

    def test_hub_profile_is_first(self):
        profiles = self._build(hub_api_key="HUBKEY")
        self.assertEqual(profiles[0]["name"], "Hub Site")
        self.assertEqual(profiles[0]["api_key"], "HUBKEY")
        self.assertEqual(len(profiles), 3)  # hub + 2 spokes

    def test_prefers_least_privilege_backup_key(self):
        profiles = self._build()
        alpha = next(p for p in profiles if p["name"] == "Alpha")
        self.assertEqual(alpha["api_key"], "BACKUP_A")

    def test_falls_back_to_local_key_when_no_backup_key(self):
        profiles = self._build()
        bravo = next(p for p in profiles if p["name"] == "Bravo")
        self.assertEqual(bravo["api_key"], "LOCAL_B")

    def test_all_profiles_inherit_one_global_cloud_credential(self):
        # cloud_credentials_file must stay empty so every profile inherits the
        # single global Drive credential — the whole point of the rework.
        for p in self._build():
            self.assertEqual(p["cloud_credentials_file"], "")
            self.assertEqual(p["backup_method"], "cloud")

    def test_global_cloud_provider_overrides_per_site(self):
        profiles = self._build(global_cloud={"cloud_provider": "onedrive"})
        for p in profiles:
            self.assertEqual(p["cloud_provider"], "onedrive")

    def test_per_blog_backup_dir_is_namespaced(self):
        profiles = self._build(default_backup_dir=os.path.join("X", "backups"))
        alpha = next(p for p in profiles if p["name"] == "Alpha")
        self.assertEqual(
            alpha["backup_dir"], os.path.join("X", "backups", "Alpha")
        )

    def test_profile_template_keeps_safe_staging_dir_when_base_unset(self):
        alpha = next(p for p in self._build() if p["name"] == "Alpha")
        self.assertEqual(
            alpha.get("backup_dir", ""),
            os.path.join((os.environ.get("SNAPSMACK_HOME") or "").strip()
                         or r"C:\snapsmack", "staging"),
        )


class ResolveCloudConfigTests(unittest.TestCase):
    """The 'enter Drive info once IF NOT PRESENT ALREADY' resolver."""

    def test_fully_configured_globally_asks_nothing(self):
        gc = {
            "cloud_provider": "google_drive",
            "cloud_credentials_file": "/keys/drive.json",
            "cloud_folder_id": "GLOBALFOLDER",
        }
        r = resolve_cloud_config(gc, hub_cloud={"provider": "google_drive", "folder_id": "HUBFOLDER"})
        self.assertTrue(r["ready"])
        self.assertEqual(r["missing"], [])
        self.assertEqual(r["credentials_file"], "/keys/drive.json")
        # global wins over the hub's advertised folder
        self.assertEqual(r["folder_id"], "GLOBALFOLDER")
        self.assertEqual(r["source"]["folder_id"], "global")

    def test_folder_auto_fills_from_hub_when_global_lacks_it(self):
        gc = {"cloud_provider": "google_drive", "cloud_credentials_file": "/keys/drive.json", "cloud_folder_id": ""}
        r = resolve_cloud_config(gc, hub_cloud={"provider": "google_drive", "folder_id": "HUBFOLDER"})
        self.assertTrue(r["ready"])
        self.assertEqual(r["folder_id"], "HUBFOLDER")
        self.assertEqual(r["source"]["folder_id"], "hub")

    def test_credential_is_only_ever_local(self):
        # Hub advertises a folder but no global credential exists → must ask for
        # the credential (the hub can never supply it), but NOT the folder.
        r = resolve_cloud_config({}, hub_cloud={"provider": "google_drive", "folder_id": "HUBFOLDER"})
        self.assertFalse(r["ready"])
        self.assertEqual(r["missing"], ["credentials_file"])
        self.assertEqual(r["folder_id"], "HUBFOLDER")

    def test_nothing_configured_asks_for_both(self):
        r = resolve_cloud_config({}, hub_cloud={})
        self.assertFalse(r["ready"])
        self.assertEqual(r["missing"], ["credentials_file", "folder_id"])
        # provider still defaults so the wizard need not ask for it
        self.assertEqual(r["provider"], "google_drive")
        self.assertEqual(r["source"]["provider"], "default")

    def test_none_provider_is_not_treated_as_configured(self):
        gc = {"cloud_provider": "none", "cloud_credentials_file": "", "cloud_folder_id": ""}
        r = resolve_cloud_config(gc, hub_cloud={"provider": "onedrive", "folder_id": "HF"})
        self.assertEqual(r["provider"], "onedrive")
        self.assertEqual(r["source"]["provider"], "hub")

    def test_handles_none_inputs(self):
        r = resolve_cloud_config(None, None)
        self.assertFalse(r["ready"])
        self.assertEqual(r["provider"], "google_drive")


class HelperTests(unittest.TestCase):
    def test_safe_dirname_strips_separators(self):
        self.assertEqual(_safe_dirname("a/b:c\\d"), "a_b_c_d")

    def test_safe_dirname_empty_falls_back(self):
        self.assertEqual(_safe_dirname("   "), "blog")

    def test_name_from_url(self):
        self.assertEqual(_name_from_url("https://www.foo.example/x"), "foo.example")


if __name__ == "__main__":
    unittest.main()
# ===== SNAPSMACK EOF =====
