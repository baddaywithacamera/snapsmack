import base64
import json
import os
import sys
import tempfile
import unittest
from unittest import mock

SHARED = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "tools", "_shared"))
sys.path.insert(0, SHARED)

import snap_creds
import snap_vault


class SharedCredentialSecurityTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.store = os.path.join(self.tmp.name, "shared_creds.json")
        self.path_patch = mock.patch.object(snap_creds, "_store_path", return_value=self.store)
        self.path_patch.start()
        self.addCleanup(self.path_patch.stop)
        self.auth_patch = mock.patch.object(snap_creds.snap_home, "auth_dir",
                                           return_value=self.tmp.name)
        self.auth_patch.start()
        self.addCleanup(self.auth_patch.stop)
        snap_creds._initialized_for = os.path.abspath(self.tmp.name)
        snap_vault.init("SnapSmackSharedTest", self.tmp.name)
        snap_vault.enable("test passphrase")
        self.addCleanup(snap_vault.lock)

    def test_new_secret_is_encrypted_not_base64(self):
        snap_creds.set("api_key", "top-secret")
        with open(self.store, encoding="utf-8") as handle:
            raw = json.load(handle)["api_key"]
        self.assertTrue(raw.startswith("enc1:"))
        self.assertNotIn("top-secret", raw)
        self.assertEqual(snap_creds.get("api_key"), "top-secret")

    def test_locked_vault_refuses_downgrade(self):
        snap_creds.set("api_key", "top-secret")
        snap_vault.lock()
        with self.assertRaises(RuntimeError):
            snap_creds.set("api_key", "replacement")
        with open(self.store, encoding="utf-8") as handle:
            raw = json.load(handle)["api_key"]
        self.assertTrue(raw.startswith("enc1:"))

    def test_legacy_base64_migrates_after_unlock_without_delete_first(self):
        legacy = "b64:" + base64.b64encode(b"legacy-secret").decode("ascii")
        with open(self.store, "w", encoding="utf-8") as handle:
            json.dump({"api_key": legacy}, handle)
        snap_creds._migrate_legacy_values()
        with open(self.store, encoding="utf-8") as handle:
            raw = json.load(handle)["api_key"]
        self.assertTrue(raw.startswith("enc1:"))
        self.assertEqual(snap_creds.get("api_key"), "legacy-secret")

    @unittest.skipUnless(os.name == "nt", "Windows DPAPI test")
    def test_windows_protected_storage_round_trip_without_keyring(self):
        dpapi_dir = os.path.join(self.tmp.name, "dpapi")
        os.makedirs(dpapi_dir)
        snap_vault.init("SnapSmackSharedDPAPITest", dpapi_dir)
        # CI/service accounts may not load a Windows user profile, so exercise
        # the DPAPI storage path with the OS calls isolated at their boundary.
        protect = lambda data: b"dpapi:" + data
        unprotect = lambda data: data[len(b"dpapi:"):]
        with mock.patch.object(snap_vault, "_KEYRING_OK", False), \
             mock.patch.object(snap_vault, "_dpapi_protect", side_effect=protect), \
             mock.patch.object(snap_vault, "_dpapi_unprotect", side_effect=unprotect):
            self.assertTrue(snap_vault.keychain_available())
            snap_vault.enable("temporary random vault passphrase")
            self.assertTrue(snap_vault.store_machine_key_now())
            snap_vault.lock()
            self.assertTrue(snap_vault.unlock_with_machine_key())
            sealed = snap_vault.encrypt("snap-hq-key")
            self.assertEqual(snap_vault.decrypt(sealed), "snap-hq-key")

    def test_orphaned_vault_is_replaced_only_when_no_ciphertext_exists(self):
        snap_vault.lock()
        with open(self.store, "w", encoding="utf-8") as handle:
            json.dump({"hub_key": "b64:" + base64.b64encode(b"recover-me").decode()}, handle)
        with mock.patch.object(snap_vault, "keychain_available", return_value=True), \
             mock.patch.object(snap_vault, "unlock_with_machine_key", return_value=False), \
             mock.patch.object(snap_vault, "store_machine_key_now", return_value=True):
            snap_creds._initialized_for = None
            snap_creds.init()
        self.assertTrue(snap_vault.is_unlocked())
        self.assertEqual(snap_creds.get("hub_key"), "recover-me")
        with open(self.store, encoding="utf-8") as handle:
            self.assertTrue(json.load(handle)["hub_key"].startswith("enc1:"))

    def test_orphaned_vault_is_preserved_when_ciphertext_exists(self):
        snap_creds.set("hub_key", "must-not-destroy")
        snap_vault.lock()
        with open(snap_vault._meta_path(), "rb") as handle:
            meta_before = handle.read()
        with mock.patch.object(snap_vault, "keychain_available", return_value=True), \
             mock.patch.object(snap_vault, "unlock_with_machine_key", return_value=False):
            snap_creds._initialized_for = None
            snap_creds.init()
        self.assertFalse(snap_vault.is_unlocked())
        with open(snap_vault._meta_path(), "rb") as handle:
            self.assertEqual(handle.read(), meta_before)

    def test_explicit_replacement_archives_mixed_orphan_and_preserves_readable_values(self):
        snap_creds.set("lost_site_key", "encrypted-old-key")
        with open(self.store, "rb") as handle:
            encrypted_store = handle.read()
        snap_vault.lock()
        # A still-readable setting in the same mixed store must survive.
        data = json.loads(encrypted_store)
        data["gemini_api_key"] = "b64:" + base64.b64encode(b"gemini-stays").decode()
        with open(self.store, "w", encoding="utf-8") as handle:
            json.dump(data, handle)
        with open(self.store, "rb") as handle:
            mixed_store = handle.read()
        protect = lambda value: b"dpapi:" + value
        unprotect = lambda value: value[len(b"dpapi:"):]
        with mock.patch.object(snap_vault, "keychain_available", return_value=True), \
             mock.patch.object(snap_vault, "_dpapi_protect", side_effect=protect), \
             mock.patch.object(snap_vault, "_dpapi_unprotect", side_effect=unprotect):
            snap_creds.prepare_explicit_replacement()
            snap_creds.set("hub_key", "replacement-hub-key")
        self.assertEqual(snap_creds.get("hub_key"), "replacement-hub-key")
        self.assertEqual(snap_creds.get("gemini_api_key"), "gemini-stays")
        self.assertEqual(snap_creds.get("lost_site_key"), "")
        recovery_root = os.path.join(self.tmp.name, "recovery")
        archived = os.path.join(recovery_root, os.listdir(recovery_root)[0], "shared_creds.json")
        with open(archived, "rb") as handle:
            self.assertEqual(handle.read(), mixed_store)


if __name__ == "__main__":
    unittest.main()
