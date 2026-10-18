# SNAPSMACK_EOF_HEADER
"""Outbound relay-client boundary tests."""

import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(HERE))

from troll_booth.broker import Broker
from troll_booth.relay_client import RelayClient, RelayError


class TestRelayClient(unittest.TestCase):
    def test_relay_requires_https_and_strong_device_identity(self):
        broker = Broker(HERE)
        with self.assertRaises(RelayError):
            RelayClient(broker, "http://example.test", "workstation", "x" * 40)
        with self.assertRaises(RelayError):
            RelayClient(broker, "https://example.test", "bad device", "x" * 40)
        with self.assertRaises(RelayError):
            RelayClient(broker, "https://example.test", "workstation", "short")

    def test_job_still_passes_through_local_trust_policy(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            broker = Broker(HERE, audit_path=root / "audit.jsonl",
                            trust_path=root / "trust.json", require_trust=True)
            client = RelayClient(broker, "https://example.test", "workstation", "x" * 40)
            refused = client.execute({"id": "job", "actor": "claude",
                                      "tool": "log-flogger__gather",
                                      "arguments": {"days": 0}})
            self.assertFalse(refused["ok"])
            app = broker.discover("log-flogger")
            broker.trust_store.approve("log-flogger", app.executable_hash)
            allowed = client.execute({"id": "job", "actor": "claude",
                                      "tool": "log-flogger__gather",
                                      "arguments": {"days": 0}})
            self.assertTrue(allowed["ok"], allowed)

    def test_locked_session_never_registers_with_relay(self):
        broker = Broker(HERE)

        class RefusingNetworkClient(RelayClient):
            def register(self):
                raise AssertionError("locked client attempted network registration")

        messages = []
        client = RefusingNetworkClient(
            broker, "https://example.test", "workstation", "x" * 40,
            messages.append, authorized=lambda: False)
        client.run()
        self.assertIn("locked or expired", messages[-1])

    def test_worker_registers_claims_and_returns_bounded_job(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            broker = Broker(HERE, audit_path=root / "audit.jsonl",
                            trust_path=root / "trust.json", require_trust=True)
            app = broker.discover("log-flogger")
            broker.trust_store.approve("log-flogger", app.executable_hash)

            class FakeRelay(RelayClient):
                def __init__(self):
                    super().__init__(broker, "https://example.test", "workstation", "x" * 40)
                    self.calls = []

                def _request(self, path, body):
                    self.calls.append((path, body))
                    if path == "/agent/register":
                        return {"ok": True}
                    if path == "/agent/next":
                        return {"ok": True, "job": {"id": "job-1", "actor": "claude",
                                "tool": "log-flogger__gather", "arguments": {"days": 0}}}
                    if path == "/agent/result":
                        self.stop()
                        return {"ok": True}
                    raise AssertionError(path)

            client = FakeRelay()
            client.run()
            paths = [path for path, _body in client.calls]
            self.assertEqual(paths, ["/agent/register", "/agent/next", "/agent/result"])
            result = client.calls[-1][1]["result"]
            self.assertTrue(result["ok"], result)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
