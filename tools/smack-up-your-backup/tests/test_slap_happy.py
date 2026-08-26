"""SLAP HAPPY discovery, selection, and incremental-package regressions."""

# SNAPSMACK_EOF_HEADER

import json
import os
import sys
import tempfile
import unittest
import zipfile

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

import slap_happy


class SlapHappyTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.home = self.tmp.name
        self.config = os.path.join(self.home, "config_files", "snap-slapper")
        self.catalog = os.path.join(self.home, "shared_library")
        self.photos = os.path.join(self.home, "my photos")
        self.output = os.path.join(self.home, "backup-output")
        self.saved = os.path.join(self.home, "finished work")
        for path in (self.config, self.catalog, self.photos, self.saved):
            os.makedirs(path)
        with open(os.path.join(self.config, "settings.json"), "w", encoding="utf-8") as f:
            f.write('{"theme":"mean"}')
        with open(os.path.join(self.catalog, "index.json"), "w", encoding="utf-8") as f:
            f.write('{"photos":1}')
        with open(os.path.join(self.photos, "one.jpg"), "wb") as f:
            f.write(b"photo-one")
        with open(os.path.join(self.photos, "edit.slapper"), "w", encoding="utf-8") as f:
            f.write('{"format":1}')
        with open(os.path.join(self.saved, "finished.jpg"), "wb") as f:
            f.write(b"finished")
        with open(os.path.join(self.config, "backup_contract.json"), "w", encoding="utf-8") as f:
            json.dump({"format": 1, "settings_dir": self.config,
                       "catalog_dir": self.catalog, "image_roots": [self.photos],
                       "saved_roots": [self.saved]}, f)

    def tearDown(self):
        self.tmp.cleanup()

    def test_contract_declares_settings_catalog_and_images(self):
        contract = slap_happy.discover_contract(self.home)
        self.assertEqual(contract["config_dir"], self.config)
        self.assertEqual(contract["catalog_dir"], self.catalog)
        self.assertEqual(contract["image_roots"], [self.photos])
        self.assertEqual(contract["saved_roots"], [self.saved])

    def test_legacy_bare_folder_list_is_discovered(self):
        os.remove(os.path.join(self.config, "backup_contract.json"))
        with open(os.path.join(self.config, "library_folders.json"), "w", encoding="utf-8") as f:
            json.dump([self.photos], f)
        self.assertEqual(slap_happy.discover_contract(self.home)["image_roots"], [self.photos])

    def test_component_picker_separates_photos_and_projects(self):
        contract = slap_happy.discover_contract(self.home)
        photos = slap_happy.selected_files(contract, ["photos"])
        projects = slap_happy.selected_files(contract, ["projects"])
        self.assertEqual([os.path.basename(x["source"]) for x in photos], ["one.jpg", "finished.jpg"])
        self.assertEqual([os.path.basename(x["source"]) for x in projects], ["edit.slapper"])

    def test_incremental_contains_only_changes_and_records_deletions(self):
        state = os.path.join(self.home, "suyb-state.json")
        first = slap_happy.create_backup(self.home, self.output, "incremental", ["photos"],
                                         state_path=state, destination_key="drive-a")
        self.assertEqual(first["changed"], 2)
        second = slap_happy.create_backup(self.home, self.output, "incremental", ["photos"],
                                          state_path=state, destination_key="drive-a")
        self.assertEqual(second["changed"], 0)
        with zipfile.ZipFile(second["path"]) as package:
            self.assertEqual(package.namelist(), ["SLAP-HAPPY-MANIFEST.json"])
        os.remove(os.path.join(self.photos, "one.jpg"))
        third = slap_happy.create_backup(self.home, self.output, "incremental", ["photos"],
                                         state_path=state, destination_key="drive-a")
        self.assertEqual(third["deleted"], 1)

    def test_destinations_keep_independent_incremental_baselines(self):
        state = os.path.join(self.home, "suyb-state.json")
        slap_happy.create_backup(self.home, self.output, "incremental", ["photos"],
                                 state_path=state, destination_key="drive-a")
        other = slap_happy.create_backup(self.home, self.output, "incremental", ["photos"],
                                         state_path=state, destination_key="drive-b")
        self.assertEqual(other["changed"], 2)

    def test_unverified_cloud_run_does_not_advance_state(self):
        state = os.path.join(self.home, "suyb-state.json")
        pending = slap_happy.create_backup(self.home, self.output, "incremental", ["photos"],
                                           state_path=state, destination_key="cloud", commit=False)
        retry = slap_happy.create_backup(self.home, self.output, "incremental", ["photos"],
                                         state_path=state, destination_key="cloud", commit=False)
        self.assertEqual(retry["changed"], 2)
        slap_happy.commit_backup_state(pending)
        done = slap_happy.create_backup(self.home, self.output, "incremental", ["photos"],
                                        state_path=state, destination_key="cloud", commit=False)
        self.assertEqual(done["changed"], 0)

# ===== SNAPSMACK EOF =====
