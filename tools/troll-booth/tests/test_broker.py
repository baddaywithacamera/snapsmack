"""TROLL BOOTH broker policy and real discovery tests."""

import json
import os
import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(HERE))

from troll_booth.broker import Broker
from troll_booth.policy import PolicyError, builtin_policies


class TestTrollBooth(unittest.TestCase):
    def test_builtin_policy_is_read_only(self):
        for app in builtin_policies().values():
            self.assertTrue(app.capabilities)
            self.assertTrue(all(cap.mode == "read" for cap in app.capabilities.values()))

    def test_unknown_and_untyped_arguments_are_rejected(self):
        cap = builtin_policies()["log-flogger"].capabilities["analyze"]
        with self.assertRaises(PolicyError):
            cap.argv({"root": "C:/secret"})
        with self.assertRaises(PolicyError):
            cap.argv({"limit": "25"})
        with self.assertRaises(PolicyError):
            cap.argv({"limit": 251})

    def test_ticket_id_rejects_shell_text(self):
        cap = builtin_policies()["ticket-wicket"].capabilities["show"]
        with self.assertRaises(PolicyError):
            cap.argv({"id": "SS-1042; whoami"})
        self.assertEqual(cap.argv({"id": "SS-1042"}),
                         ["show", "SS-1042", "--json"])

    def test_real_apps_are_discoverable(self):
        with tempfile.TemporaryDirectory() as directory:
            broker = Broker(HERE, audit_path=Path(directory) / "audit.jsonl")
            rows = broker.list_apps()
            self.assertEqual({row["id"] for row in rows},
                             {"log-flogger", "ticket-wicket"})
            self.assertTrue(all(row["healthy"] for row in rows), rows)

    def test_safe_real_invocation_is_audited(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            old_home = os.environ.get("SNAPSMACK_HOME")
            os.environ["SNAPSMACK_HOME"] = str(root / "home")
            try:
                broker = Broker(HERE, audit_path=root / "audit.jsonl")
                result = broker.invoke("log-flogger", "gather", {"days": 1},
                                       "codex", "test discovery", "TW-TEST")
            finally:
                if old_home is None:
                    os.environ.pop("SNAPSMACK_HOME", None)
                else:
                    os.environ["SNAPSMACK_HOME"] = old_home
            self.assertTrue(result["ok"], result)
            records = [json.loads(line) for line in
                       (root / "audit.jsonl").read_text().splitlines()]
            self.assertEqual(records[-1]["actor"], "codex")
            self.assertEqual(records[-1]["capability"], "gather")
            self.assertEqual(records[-1]["ticket_id"], "TW-TEST")


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
