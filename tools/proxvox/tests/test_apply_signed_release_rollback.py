"""Filesystem rollback regression checks for a PROX VOX signed site update.

# SNAPSMACK_EOF_HEADER
"""

import json
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "server"))

from apply_signed_release import restore_files, snapshot_files


class SignedReleaseRollbackTest(unittest.TestCase):
    def setUp(self):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        self.site = Path(scratch.name) / "site"
        self.state = Path(scratch.name) / "backup"
        self.site.mkdir()
        self.state.mkdir()

    def test_exact_directory_restore_and_removal_of_created_tree(self):
        migrations = self.site / "migrations"
        migrations.mkdir()
        (migrations / "old.php").write_bytes(b"original")
        added = self.site / "added"
        inventory = snapshot_files(self.site, [migrations, added], self.state)

        (migrations / "old.php").write_bytes(b"replaced")
        (migrations / "new.php").write_bytes(b"new")
        added.mkdir()
        (added / "new.php").write_bytes(b"new")

        restore_files(self.site, self.state, inventory)
        self.assertEqual([file.name for file in migrations.iterdir()], ["old.php"])
        self.assertEqual((migrations / "old.php").read_bytes(), b"original")
        self.assertFalse(added.exists())

    def test_failure_reports_affected_path_and_continues_restore(self):
        restored = self.site / "a.php"
        broken = self.site / "b"
        restored.write_bytes(b"original")
        broken.mkdir()
        inventory = snapshot_files(self.site, [restored, broken], self.state)
        (self.state / "files" / "b").rmdir()
        (broken / "release.php").write_bytes(b"release file")
        restored.write_bytes(b"replaced")

        with self.assertRaisesRegex(RuntimeError, "1 file paths"):
            restore_files(self.site, self.state, inventory)
        report = json.loads((self.state / "file-rollback.json").read_bytes())
        self.assertEqual(report["restored"], ["a.php"])
        self.assertEqual(set(report["failed"]), {"b"})
        self.assertEqual(restored.read_bytes(), b"original")
        self.assertTrue((broken / "release.php").is_file())


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
