# SNAPSMACK_EOF_HEADER
"""SMACK DEALER catalogue and launch-boundary tests."""

import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(HERE))

from dealer import catalog


class TestDealer(unittest.TestCase):
    def test_catalog_has_distinct_admin_apps(self):
        apps = catalog(Path(__file__).resolve().parents[2])
        names = [app.name for app in apps]
        self.assertEqual(len(names), len(set(names)))
        self.assertIn("TROLL BOOTH", names)
        self.assertIn("CRONOMETER", names)
        self.assertIn("SMACK UP YOUR BACKUP", names)

    def test_missing_app_is_reported_not_invented(self):
        with tempfile.TemporaryDirectory() as directory:
            apps = catalog(Path(directory))
            self.assertTrue(any(app.installed_path() is None for app in apps))


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
