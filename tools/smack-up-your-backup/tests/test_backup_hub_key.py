"""
Smack Up Your Backup — backup_hub_key resolver regression tests (546D).

Locks in config.effective_backup_key(): the ONE fleet hub key wins when The Hub
has published it, and the profile's own key is used (unchanged behaviour) when it
has not. This is the non-breaking guarantee — an install with no backup_hub_key
must resolve EXACTLY the same key it always did.

unittest only, so it runs in the packaged build environment too.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

from __future__ import annotations

import sys
import unittest
from pathlib import Path

APP_DIR = Path(__file__).resolve().parents[1]
if str(APP_DIR) not in sys.path:
    sys.path.insert(0, str(APP_DIR))

import config


class EffectiveBackupKey(unittest.TestCase):
    def setUp(self):
        # Save/restore the real shared-store reader so each test controls it.
        self._orig = config.shared_cred

    def tearDown(self):
        config.shared_cred = self._orig

    def _hub(self, value):
        config.shared_cred = lambda key, default="": (value if key == "backup_hub_key" else default)

    def test_no_hub_key_falls_back_to_profile(self):
        """Today's default: no backup_hub_key → the profile's own key, untouched."""
        self._hub("")
        prof = {"api_key": "profile-key-abc"}
        self.assertEqual(config.effective_backup_key(prof), "profile-key-abc")

    def test_hub_key_overrides_profile(self):
        """When The Hub publishes one fleet key, it wins for every profile."""
        self._hub("HUB-FLEET-KEY")
        prof = {"api_key": "profile-key-abc"}
        self.assertEqual(config.effective_backup_key(prof), "HUB-FLEET-KEY")

    def test_no_key_anywhere_is_empty(self):
        self._hub("")
        self.assertEqual(config.effective_backup_key({}), "")
        self.assertEqual(config.effective_backup_key(None), "")

    def test_hub_key_wins_even_when_profile_has_none(self):
        self._hub("HUB-FLEET-KEY")
        self.assertEqual(config.effective_backup_key({}), "HUB-FLEET-KEY")


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
