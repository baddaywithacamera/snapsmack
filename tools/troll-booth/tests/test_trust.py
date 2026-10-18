# SNAPSMACK_EOF_HEADER
"""Executable approval tests."""

import json
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(HERE))

from troll_booth.broker import Broker
from troll_booth.trust import TrustStore


class TestTrust(unittest.TestCase):
    def test_changed_executable_loses_approval(self):
        with tempfile.TemporaryDirectory() as directory:
            store = TrustStore(Path(directory) / "trusted.json")
            first = "a" * 64
            second = "b" * 64
            store.approve("example", first)
            self.assertTrue(store.is_approved("example", first))
            self.assertFalse(store.is_approved("example", second))

    def test_required_trust_blocks_then_allows_real_app(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            trust_path = root / "trusted.json"
            broker = Broker(HERE, audit_path=root / "audit.jsonl",
                            trust_path=trust_path, require_trust=True)
            refused = broker.invoke("log-flogger", "gather", {"days": 0}, "codex")
            self.assertFalse(refused["ok"])
            self.assertIn("not been approved", refused["error"])
            app = broker.discover("log-flogger")
            broker.trust_store.approve("log-flogger", app.executable_hash)
            allowed = broker.invoke("log-flogger", "gather", {"days": 0}, "codex")
            self.assertTrue(allowed["ok"], allowed)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
