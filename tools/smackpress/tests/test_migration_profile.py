"""Per-source interpretation stays data; repair mode updates existing posts."""

import tempfile
import unittest
import sys
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from smackpress import config


class MigrationProfileTests(unittest.TestCase):
    def test_profile_round_trips_by_normalized_source_url(self):
        with tempfile.TemporaryDirectory() as tmp, \
                mock.patch.object(config, "_app_dir", lambda: Path(tmp)):
            profile = {
                "signature_markers": ["closing-flourish"],
                "colophon_markers": ["travelling kit"],
            }
            config.save_migration_profile("HTTPS://Example.test/", profile, "2026-10-03T12:00:00Z")
            self.assertEqual(config.get_migration_profile("https://example.test"), profile)
            self.assertEqual(config.get_migration_profile("https://somewhere-else.test"), {})


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
