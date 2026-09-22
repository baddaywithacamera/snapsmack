"""SECAUDIT 054 — the credential vault is MANDATORY for posting-capable keys
(Sean, 2026-09-18). SYBU and COLD SNAP config.ini never hold a key as base64:
sealed by the shared vault or not written at all."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import base64
import configparser
import importlib
import os
import sys
import tempfile
import unittest
from unittest import mock

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SHARED = os.path.join(ROOT, "tools", "_shared")


class _Base(unittest.TestCase):
    TOOL = ""          # 'sybu' | 'coldsnap'

    def setUp(self):
        if not self.TOOL:
            self.skipTest("abstract base")
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        os.environ["SNAPSMACK_HOME"] = self.tmp.name
        self.addCleanup(lambda: os.environ.pop("SNAPSMACK_HOME", None))
        for p in (SHARED, os.path.join(ROOT, "tools", self.TOOL)):
            if p not in sys.path:
                sys.path.insert(0, p)
        for m in ("snap_creds", "snap_vault", "snap_home", "config"):
            sys.modules.pop(m, None)
        self.config = importlib.import_module("config")
        self.creds = importlib.import_module("snap_creds")
        self.vault = importlib.import_module("snap_vault")
        self.ini = os.path.join(self.tmp.name, "config.ini")
        p = mock.patch.object(self.config, "_config_path", lambda: self.ini)
        p.start(); self.addCleanup(p.stop)

    def _unlocked_vault(self):
        """A real vault, unlocked with a passphrase — no keychain needed."""
        self.creds.init()
        if not self.vault.is_enabled():
            self.vault.enable("test-passphrase", store_machine_key=False)
        if not self.vault.is_unlocked():
            self.vault.unlock("test-passphrase")
        self.assertTrue(self.vault.is_unlocked())

    def _ini_auth(self):
        cfg = configparser.ConfigParser(); cfg.read(self.ini)
        return dict(cfg["auth"]) if cfg.has_section("auth") else {}

    def test_key_is_sealed_not_base64(self):
        self._unlocked_vault()
        data = {"url": "https://pixhellated.ca", "api_key": "k" * 64, "remember": False}
        self.config.save(data)
        auth = self._ini_auth()
        self.assertTrue(auth["api_key"].startswith("enc1:"), auth["api_key"][:20])
        self.assertNotEqual(auth["api_key"], base64.b64encode(("k" * 64).encode()).decode())
        self.assertNotIn("vault_warning", data)
        self.assertEqual(self.config.load()["api_key"], "k" * 64)

    def test_legacy_base64_still_reads(self):
        self._unlocked_vault()
        cfg = configparser.ConfigParser()
        cfg["site"] = {"url": "https://pixhellated.ca"}
        cfg["auth"] = {"api_key": base64.b64encode(b"legacy-key").decode(), "password": "", "remember": "False"}
        with open(self.ini, "w", encoding="utf-8") as f:
            cfg.write(f)
        self.assertEqual(self.config.load()["api_key"], "legacy-key")

    def test_locked_vault_never_writes_the_key(self):
        self.creds.init()
        with mock.patch.object(self.vault, "is_unlocked", lambda: False):
            data = {"url": "https://pixhellated.ca", "api_key": "k" * 64, "remember": False}
            self.config.save(data)
        auth = self._ini_auth()
        self.assertEqual(auth.get("api_key", ""), "")
        self.assertIn("vault_warning", data)
        self.assertIn("session only", data["vault_warning"])


class SybuConfigVaultTests(_Base):
    TOOL = "sybu"


class ColdSnapConfigVaultTests(_Base):
    TOOL = "coldsnap"

    def test_smackpress_key_sealed_too(self):
        self._unlocked_vault()
        data = {"url": "https://pixhellated.ca", "api_key": "a" * 64, "smackpress_key": "s" * 64, "remember": False}
        self.config.save(data)
        auth = self._ini_auth()
        self.assertTrue(auth["smackpress_key"].startswith("enc1:"))
        self.assertEqual(self.config.load()["smackpress_key"], "s" * 64)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
