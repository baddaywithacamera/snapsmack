# SNAPSMACK_EOF_HEADER
"""MCP adapter tests."""

import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(HERE))

from troll_booth.broker import Broker
from troll_booth.mcp import McpAdapter


class TestMcp(unittest.TestCase):
    def broker(self, root: Path) -> Broker:
        broker = Broker(HERE, audit_path=root / "audit.jsonl",
                        trust_path=root / "trusted.json", require_trust=True)
        app = broker.discover("log-flogger")
        broker.trust_store.approve("log-flogger", app.executable_hash)
        return broker

    def test_initialize_and_tools_list(self):
        with tempfile.TemporaryDirectory() as directory:
            adapter = McpAdapter(self.broker(Path(directory)))
            status, initialized = adapter.handle({
                "jsonrpc": "2.0", "id": 1, "method": "initialize",
                "params": {"protocolVersion": "2025-11-25"}}, "claude")
            self.assertEqual(status, 200)
            self.assertEqual(initialized["result"]["serverInfo"]["name"], "TROLL BOOTH")
            _status, listed = adapter.handle(
                {"jsonrpc": "2.0", "id": 2, "method": "tools/list"}, "claude")
            names = {tool["name"] for tool in listed["result"]["tools"]}
            self.assertIn("log-flogger__gather", names)
            self.assertNotIn("ticket-wicket__queue", names)

    def test_tool_call_uses_authenticated_actor(self):
        with tempfile.TemporaryDirectory() as directory:
            adapter = McpAdapter(self.broker(Path(directory)))
            _status, called = adapter.handle({
                "jsonrpc": "2.0", "id": 3, "method": "tools/call",
                "params": {"name": "log-flogger__gather", "arguments": {"days": 0}}},
                "claude")
            self.assertFalse(called["result"]["isError"], called)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
