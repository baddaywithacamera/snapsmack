"""
Exit package inside a backup: TYSWY's engine runs from SUYB against the fake
site, writes exit/ with canonical archive + WordPress + Ghost, and the backup
engine is wired to call it and zip it. Failure never fails the backup.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====
Last non-empty line of this file MUST match the line above.
"""
import os
import sys
import tempfile
import threading
import unittest
from unittest import mock

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
TYSWY = os.path.normpath(os.path.join(ROOT, "..", "take-your-shit-with-you"))
for p in (ROOT, os.path.join(ROOT, "..", "_shared")):
    if p not in sys.path:
        sys.path.insert(0, p)
for p in (TYSWY, os.path.join(TYSWY, "tests")):     # after SUYB: both tools have a config.py
    if p not in sys.path:
        sys.path.append(p)

import exit_package                       # noqa: E402
import backup_engine                      # noqa: E402


class ExitPackageTests(unittest.TestCase):

    def test_writes_archive_wordpress_and_ghost_into_exit(self):
        from test_wordpress_adapter import FakeSite, sample_tables, sample_media   # TYSWY's fake site
        import tyswy_client as tc
        site = FakeSite(sample_tables(), sample_media())
        with tempfile.TemporaryDirectory() as backup_dir:
            real = tc.TyswyClient

            def fake_client(url, key, **kw):
                kw.pop("session", None)
                return real(url, key, session=site, max_retries=0, **{k: v for k, v in kw.items() if k in ("app_version",)})

            with mock.patch.object(tc, "TyswyClient", side_effect=fake_client):
                summary = exit_package.write_exit_package(
                    "https://fauxlaroid.fyi", "k" * 64, backup_dir, on_log=lambda m: None,
                    cancel_event=threading.Event())
        self.assertTrue(summary["ok"], summary.get("errors"))
        self.assertIn("wordpress", summary["adapters"])
        self.assertIn("ghost", summary["adapters"])
        self.assertTrue(summary["path"].endswith(os.sep + "exit") or summary["path"].endswith("/exit"))

    def test_a_failure_is_reported_not_raised(self):
        with tempfile.TemporaryDirectory() as backup_dir:
            summary = exit_package.write_exit_package(
                "https://127.0.0.1:9", "k" * 64, backup_dir, on_log=lambda m: None)
        self.assertFalse(summary["ok"])
        self.assertTrue(summary["errors"])

    def test_engine_is_wired_and_zips_the_exit_folder(self):
        src = open(os.path.join(ROOT, "backup_engine.py"), encoding="utf-8").read()
        self.assertIn('self.profile.get("exit_package")', src)
        self.assertIn("exit_module.write_exit_package(", src)
        self.assertIn('os.path.join("exit", os.path.relpath(full, exit_dir))', src)
        # off by default, in the profile schema
        pm = open(os.path.join(ROOT, "profile_manager.py"), encoding="utf-8").read()
        self.assertIn('"exit_package":          False', pm)
        # cancelling the backup cancels the exit engine too
        self.assertIn("self._exit_cancel.set()", src)

    def test_engine_default_profile_has_the_flag_off(self):
        import profile_manager
        defaults = getattr(profile_manager, "DEFAULT_PROFILE", None) or {}
        self.assertFalse(bool(defaults.get("exit_package", False)))


if __name__ == "__main__":
    unittest.main()
# ===== SNAPSMACK EOF =====
