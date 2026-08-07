"""Adversarial regression tests for SECAUDIT 037.

These tests intentionally exercise failure paths, not just successful migrations.
They use only unittest so they also run in the packaged build environment.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


from __future__ import annotations

import base64
import json
import os
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest import mock


APP_DIR = Path(__file__).resolve().parents[1]
if str(APP_DIR) not in sys.path:
    sys.path.insert(0, str(APP_DIR))

import backup_engine
import cloud_client
import config as config_module
import profile_manager
import sftp_client
import sync_manager


class _FakeVault:
    """Small deterministic vault double; ciphertext is key-bound, not secure."""

    def __init__(self):
        self.enabled = False
        self.unlocked = False
        self.key = None
        self.machine_key = None

    def is_enabled(self):
        return self.enabled

    def is_unlocked(self):
        return self.unlocked

    @staticmethod
    def is_encrypted(value):
        return isinstance(value, str) and value.startswith("enc1:")

    def encrypt(self, value):
        if not (self.enabled and self.unlocked):
            raise RuntimeError("vault unavailable")
        payload = base64.urlsafe_b64encode(value.encode("utf-8")).decode("ascii")
        return f"enc1:{self.key}:{payload}"

    def decrypt(self, value):
        prefix = f"enc1:{self.key}:"
        if not self.unlocked or not value.startswith(prefix):
            raise RuntimeError("wrong key")
        payload = value[len(prefix):]
        return base64.urlsafe_b64decode(payload.encode("ascii")).decode("utf-8")

    def enable(self, passphrase, store_machine_key=False):
        if self.enabled or not passphrase:
            raise RuntimeError("cannot enable")
        self.enabled = self.unlocked = True
        self.key = passphrase
        if store_machine_key:
            self.machine_key = passphrase

    def unlock(self, passphrase):
        self.unlocked = self.enabled and passphrase == self.key
        return self.unlocked

    def change_passphrase(self, old, new):
        if not self.unlock(old) or not new:
            return False
        self.key = new
        return True

    def disable(self):
        if not self.unlocked:
            raise RuntimeError("locked")
        self.enabled = self.unlocked = False
        self.key = None

    def has_machine_key(self):
        return self.machine_key is not None

    def store_machine_key_now(self):
        if not self.unlocked:
            return False
        self.machine_key = self.key
        return True

    def clear_machine_key(self):
        self.machine_key = None

    def lock(self):
        self.unlocked = False


class CredentialMigrationTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.profiles = self.root / "profiles"
        self.profiles.mkdir()
        self.sync_jobs = self.root / "sync_jobs"
        self.sync_jobs.mkdir()
        self.config_file = self.root / "config.ini"
        self.creds = self.root / "google.json"
        self.creds.write_text("{}", encoding="utf-8")
        self.global_creds = self.root / "global-only.json"
        self.source_creds = self.root / "sync-source-only.json"
        self.dest_creds = self.root / "sync-dest-only.json"
        for path in (self.global_creds, self.source_creds, self.dest_creds):
            path.write_text("{}", encoding="utf-8")
        self.tokens = []
        for creds in (self.creds, self.global_creds, self.source_creds, self.dest_creds):
            stem = str(creds)[:-5]
            self.tokens.extend(Path(stem + suffix) for suffix in
                               ("_token.json", "_readonly_token.json", "_box_token.json"))
        for i, path in enumerate(self.tokens):
            path.write_text(json.dumps({"access_token": f"access-{i}",
                                        "refresh_token": f"refresh-{i}"}),
                            encoding="utf-8")

        self.vault = _FakeVault()
        self.pm_dir = mock.patch.object(profile_manager, "PROFILES_DIR", str(self.profiles))
        self.pm_journal = mock.patch.object(
            profile_manager, "_MIGRATION_JOURNAL", str(self.root / "vault-migration.json"))
        self.sm_dir = mock.patch.object(sync_manager, "SYNC_JOBS_DIR", str(self.sync_jobs))
        self.cfg_path = mock.patch.object(config_module, "CONFIG_FILE", str(self.config_file))
        self.pm_vault = mock.patch.object(profile_manager, "secret_vault", self.vault)
        self.sm_vault = mock.patch.object(sync_manager, "secret_vault", self.vault)
        self.cc_vault = mock.patch.object(cloud_client, "secret_vault", self.vault,
                                          create=True)
        self.pm_dir.start(); self.pm_journal.start(); self.sm_dir.start(); self.cfg_path.start()
        self.pm_vault.start(); self.sm_vault.start(); self.cc_vault.start()
        self.addCleanup(self.pm_dir.stop); self.addCleanup(self.pm_journal.stop)
        self.addCleanup(self.pm_vault.stop)
        self.addCleanup(self.sm_dir.stop); self.addCleanup(self.cfg_path.stop)
        self.addCleanup(self.sm_vault.stop); self.addCleanup(self.cc_vault.stop)
        self.addCleanup(self.tmp.cleanup)

        cfg = config_module.load()
        cfg["cloud"]["credentials_file"] = str(self.global_creds)
        config_module.save(cfg)

        sync_manager.save_job({
            "name": "cloud-copy",
            "source_provider": "google_drive",
            "source_credentials_file": str(self.source_creds),
            "source_folder": "src",
            "source_b2_key_id": "source-id",
            "source_b2_app_key": "source-secret",
            "dest_provider": "box",
            "dest_credentials_file": str(self.dest_creds),
            "dest_folder": "dst",
            "dest_b2_key_id": "dest-id",
            "dest_b2_app_key": "dest-secret",
        })

        profile_manager.save_profile({
            "name": "one", "ftp_pass": "ftp-one", "snap_admin_pass": "admin-one",
            "api_key": "api-one", "cloud_credentials_file": str(self.creds),
        })
        profile_manager.save_profile({
            "name": "two", "ftp_pass": "ftp-two", "snap_admin_pass": "admin-two",
            "api_key": "api-two", "cloud_credentials_file": str(self.creds),
        })

    def _raw_profiles(self):
        return [json.loads(p.read_text(encoding="utf-8"))
                for p in sorted(self.profiles.glob("*.json"))]

    def _raw_jobs(self):
        return [json.loads(p.read_text(encoding="utf-8"))
                for p in sorted(self.sync_jobs.glob("*.json"))]

    def _assert_tokens_sealed(self):
        for path in self.tokens:
            raw = json.loads(path.read_text(encoding="utf-8"))
            self.assertIn("suyb_sealed", raw, path.name)
            self.assertNotIn("refresh-", path.read_text(encoding="utf-8"), path.name)

    def test_enable_change_disable_migrates_profiles_and_all_token_cache_types(self):
        profile_manager.enable_encryption("old")
        self.assertTrue(all(p["api_key"].startswith("enc1:old:") for p in self._raw_profiles()))
        for job in self._raw_jobs():
            for field in sync_manager._SECRET_FIELDS:
                self.assertTrue(job[field].startswith("enc1:old:"), field)
        self._assert_tokens_sealed()

        self.assertTrue(profile_manager.change_encryption_passphrase("old", "new"))
        self.assertTrue(all(p["api_key"].startswith("enc1:new:") for p in self._raw_profiles()))
        for job in self._raw_jobs():
            for field in sync_manager._SECRET_FIELDS:
                self.assertTrue(job[field].startswith("enc1:new:"), field)
        self._assert_tokens_sealed()
        for path in self.tokens:
            self.assertIn("enc1:new:", path.read_text(encoding="utf-8"))

        profile_manager.disable_encryption()
        self.assertTrue(all(not p["api_key"].startswith("enc1:") for p in self._raw_profiles()))
        for job in self._raw_jobs():
            for field in sync_manager._SECRET_FIELDS:
                self.assertFalse(job[field].startswith("enc1:"), field)
        for path in self.tokens:
            raw = json.loads(path.read_text(encoding="utf-8"))
            self.assertNotIn("suyb_sealed", raw, path.name)
            self.assertIn("refresh_token", raw, path.name)

    def test_failed_enable_rolls_back_every_file_and_vault_state(self):
        before_profiles = {p: p.read_bytes() for p in self.profiles.glob("*.json")}
        before_jobs = {p: p.read_bytes() for p in self.sync_jobs.glob("*.json")}
        before_tokens = {p: p.read_bytes() for p in self.tokens}
        original_replace = os.replace
        writes = 0

        def fail_during_commit(src, dst):
            nonlocal writes
            if str(dst).endswith(".json"):
                writes += 1
                if writes == 2:
                    raise OSError("injected disk failure")
            return original_replace(src, dst)

        with mock.patch("os.replace", side_effect=fail_during_commit):
            with self.assertRaises(OSError):
                profile_manager.enable_encryption("new")

        self.assertFalse(self.vault.enabled)
        for path, content in {**before_profiles, **before_jobs, **before_tokens}.items():
            self.assertEqual(content, path.read_bytes(), path.name)

    def test_failed_rekey_preserves_old_key_and_all_old_ciphertext(self):
        profile_manager.enable_encryption("old")
        before = {p: p.read_bytes() for p in [*self.profiles.glob("*.json"),
                                               *self.sync_jobs.glob("*.json"), *self.tokens]}
        original_replace = os.replace
        writes = 0

        def fail_during_commit(src, dst):
            nonlocal writes
            if str(dst).endswith(".json"):
                writes += 1
                if writes == 2:
                    raise OSError("injected disk failure")
            return original_replace(src, dst)

        with mock.patch("os.replace", side_effect=fail_during_commit):
            with self.assertRaises(OSError):
                profile_manager.change_encryption_passphrase("old", "new")

        self.assertEqual("old", self.vault.key)
        for path, content in before.items():
            self.assertEqual(content, path.read_bytes(), path.name)

    def test_failed_disable_preserves_enabled_vault_and_ciphertext(self):
        profile_manager.enable_encryption("old", store_machine_key=True)
        before = {p: p.read_bytes() for p in [*self.profiles.glob("*.json"),
                                               *self.sync_jobs.glob("*.json"), *self.tokens]}
        original_replace = os.replace
        writes = 0

        def fail_during_commit(src, dst):
            nonlocal writes
            if str(dst).endswith(".json"):
                writes += 1
                if writes == 2:
                    raise OSError("injected disk failure")
            return original_replace(src, dst)

        with mock.patch("os.replace", side_effect=fail_during_commit):
            with self.assertRaises(OSError):
                profile_manager.disable_encryption()

        self.assertTrue(self.vault.enabled)
        self.assertEqual("old", self.vault.key)
        self.assertEqual("old", self.vault.machine_key,
                         "failed disable erased the unattended-unlock cache")
        for path, content in before.items():
            self.assertEqual(content, path.read_bytes(), path.name)

    def test_failed_rekey_restores_existing_machine_key_cache(self):
        profile_manager.enable_encryption("old", store_machine_key=True)
        self.assertEqual("old", self.vault.machine_key)
        original_replace = os.replace
        writes = 0

        def fail_during_commit(src, dst):
            nonlocal writes
            if str(dst).endswith(".json"):
                writes += 1
                if writes == 2:
                    raise OSError("injected disk failure")
            return original_replace(src, dst)

        with mock.patch("os.replace", side_effect=fail_during_commit):
            with self.assertRaises(OSError):
                profile_manager.change_encryption_passphrase("old", "new")
        self.assertEqual("old", self.vault.key)
        self.assertEqual("old", self.vault.machine_key,
                         "failed re-key left stale new machine key cached")

    def test_failed_enable_restores_preexisting_stale_machine_key_state(self):
        self.vault.machine_key = "preexisting-stale-cache"
        original_replace = os.replace
        writes = 0

        def fail_during_commit(src, dst):
            nonlocal writes
            if str(dst).endswith(".json"):
                writes += 1
                if writes == 2:
                    raise OSError("injected disk failure")
            return original_replace(src, dst)

        with mock.patch("os.replace", side_effect=fail_during_commit):
            with self.assertRaises(OSError):
                profile_manager.enable_encryption("new", store_machine_key=True)
        self.assertEqual("preexisting-stale-cache", self.vault.machine_key)


class TokenFailClosedTests(unittest.TestCase):
    def test_enabled_vault_encryption_error_never_returns_or_writes_plaintext(self):
        vault = types.SimpleNamespace(
            is_enabled=lambda: True,
            is_unlocked=lambda: True,
            encrypt=mock.Mock(side_effect=RuntimeError("crypto failure")),
        )
        secret = '{"refresh_token":"DO-NOT-WRITE"}'
        with tempfile.TemporaryDirectory() as td, \
                mock.patch.dict(sys.modules, {"secret_vault": vault}), \
                mock.patch.object(cloud_client, "secret_vault", vault, create=True):
            path = Path(td) / "token.json"
            with self.assertRaises(RuntimeError):
                cloud_client._write_token(str(path), secret)
            self.assertFalse(path.exists(), "plaintext token file was created")

    def test_enabled_but_locked_vault_never_writes_plaintext_token(self):
        vault = types.SimpleNamespace(
            is_enabled=lambda: True,
            is_unlocked=lambda: False,
        )
        secret = '{"refresh_token":"DO-NOT-WRITE"}'
        with tempfile.TemporaryDirectory() as td, \
                mock.patch.dict(sys.modules, {"secret_vault": vault}), \
                mock.patch.object(cloud_client, "secret_vault", vault, create=True):
            path = Path(td) / "token.json"
            with self.assertRaises(RuntimeError):
                cloud_client._write_token(str(path), secret)
            self.assertFalse(path.exists(), "locked vault caused plaintext token write")

    def test_enabled_but_locked_vault_never_downgrades_profile_to_legacy_storage(self):
        vault = _FakeVault()
        vault.enabled = True
        vault.unlocked = False
        vault.key = "still-secret"
        with tempfile.TemporaryDirectory() as td, \
                mock.patch.object(profile_manager, "PROFILES_DIR", td), \
                mock.patch.object(profile_manager, "secret_vault", vault):
            with self.assertRaises(RuntimeError):
                profile_manager.save_profile({
                    "name": "locked", "ftp_pass": "ftp-secret",
                    "snap_admin_pass": "admin-secret", "api_key": "api-secret",
                })
            self.assertFalse((Path(td) / "locked.json").exists())

    def test_enabled_but_locked_vault_never_writes_plaintext_sync_job(self):
        vault = _FakeVault()
        vault.enabled = True
        vault.unlocked = False
        vault.key = "still-secret"
        with tempfile.TemporaryDirectory() as td, \
                mock.patch.object(sync_manager, "SYNC_JOBS_DIR", td), \
                mock.patch.object(sync_manager, "secret_vault", vault):
            job = sync_manager.new_job_template()
            job.update({"name": "locked", "source_b2_key_id": "id",
                        "source_b2_app_key": "DO-NOT-WRITE"})
            with self.assertRaises(RuntimeError):
                sync_manager.save_job(job)
            self.assertFalse((Path(td) / "locked.json").exists())


class LocalPathContainmentTests(unittest.TestCase):
    def test_backup_destination_rejects_windows_and_posix_traversal(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td) / "media"
            root.mkdir()
            bad = [
                "../outside.jpg", "a/../../outside.jpg", "/etc/passwd",
                r"..\outside.jpg", r"C:\Windows\win.ini", r"\\server\share\x",
            ]
            for supplied in bad:
                with self.subTest(supplied=supplied):
                    with self.assertRaises((ValueError, RuntimeError)):
                        backup_engine._safe_local_download_path(str(root), supplied)

    def test_backup_destination_accepts_nested_relative_path_inside_root(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td) / "media"
            root.mkdir()
            result = Path(backup_engine._safe_local_download_path(
                str(root), "uploads/2026/photo.jpg"))
            self.assertEqual(root.resolve(), result.resolve().parents[2])


class SftpTofuTests(unittest.TestCase):
    def test_unwritable_pin_store_rejects_unknown_host_even_when_tofu_requested(self):
        policy = object()
        reject = object()
        client = mock.Mock()
        client.connect.side_effect = RuntimeError("stop after policy inspection")
        fake_paramiko = types.SimpleNamespace(
            SSHClient=mock.Mock(return_value=client),
            AutoAddPolicy=mock.Mock(return_value=policy),
            RejectPolicy=mock.Mock(return_value=reject),
        )
        target = sftp_client.SFTPClient("example.invalid", "user", auto_add_host_key=True)
        with mock.patch.object(sftp_client, "paramiko", fake_paramiko), \
                mock.patch("builtins.open", side_effect=PermissionError("read only")):
            with self.assertRaises(RuntimeError):
                target.connect()
        client.set_missing_host_key_policy.assert_called_once_with(reject)
        client.load_system_host_keys.assert_called_once()


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
