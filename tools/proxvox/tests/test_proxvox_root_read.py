"""Root-level host and all-container read protocol coverage. (SS-1542)

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import base64
import io
import json
import os
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from proxvox.audit import AuditBuffer  # noqa: E402
from proxvox.broker import Refused  # noqa: E402
from proxvox.root_read import RootReadBroker  # noqa: E402
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "server")))
import proxvox_readonly  # noqa: E402


class _Channel:
    def __init__(self, status): self.status = status
    def recv_exit_status(self): return self.status


class _Stream(io.BytesIO):
    def __init__(self, value, status=0):
        super().__init__(value)
        self.channel = _Channel(status)


class _SSH:
    def __init__(self, replies):
        self.replies = replies
        self.commands = []
        self.closed = False

    def exec_command(self, command, timeout=30):
        self.commands.append(command)
        status, payload = self.replies[command]
        return None, _Stream(json.dumps(payload).encode(), status), _Stream(b"")

    def close(self): self.closed = True


class RootReadTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="proxvox-root-read-")
        self.audit = AuditBuffer(os.path.join(self.tmp, "audit.jsonl"))
        encoded = base64.b64encode(b"root-only-config").decode()
        self.replies = {
            "inventory": (0, {"ok": True, "data": {"containers": [{"vmid": 102}, {"vmid": 106}, {"vmid": 110}]}}),
            "host-list /etc/pve": (0, {"ok": True, "data": {"entries": ["nodes", "storage.cfg"]}}),
            "ct-read 110 /etc/mysql/my.cnf 8388608": (0, {"ok": True, "data": {"content_base64": encoded}}),
            "host-read /root/.ssh/id_rsa 8388608": (2, {"ok": False, "error": "server policy refused"}),
        }
        self.clients = []

        def factory(host, user, password):
            self.assertEqual((host, user, password), ("192.168.1.10", "proxvox-root-ro", "stored"))
            client = _SSH(self.replies)
            self.clients.append(client)
            return client

        self.broker = RootReadBroker(
            "192.168.1.10", "proxvox-root-ro", self.audit,
            credential_reader=lambda *_: "stored", ssh_factory=factory,
        )

    def test_inventory_host_and_arbitrary_container_reads(self):
        self.assertEqual([row["vmid"] for row in self.broker.inventory()], [102, 106, 110])
        self.assertEqual(self.broker.listdir("/etc/pve"), ["nodes", "storage.cfg"])
        self.assertEqual(self.broker.read_file("/etc/mysql/my.cnf", vmid=110), b"root-only-config")
        self.assertTrue(all(client.closed for client in self.clients))

    def test_server_refusal_is_audited_without_content(self):
        with self.assertRaises(Refused):
            self.broker.read_file("/root/.ssh/id_rsa")
        records = self.audit.records()
        self.assertEqual(records[-1]["action"], "root_read_refused")
        self.assertNotIn("root-only-config", str(records))

    def test_server_wrapper_has_no_shell_or_write_protocol(self):
        with open(os.path.join(os.path.dirname(__file__), "..", "server", "proxvox_readonly.py"), encoding="utf-8") as stream:
            source = stream.read()
        self.assertIn("shell=False", source)
        self.assertNotIn("shell=True", source)
        for verb in ("host-write", "ct-write", "delete", "rename", "chmod"):
            self.assertNotIn('"' + verb + '"', source)
        self.assertIn("SSH_ORIGINAL_COMMAND", source)

    def test_server_wrapper_reads_host_paths_and_refuses_unknown_verbs(self):
        folder = os.path.join(self.tmp, "root view")
        os.makedirs(folder)
        path = os.path.join(folder, "config.bin")
        with open(path, "wb") as stream:
            stream.write(b"abc")
        with mock.patch.object(proxvox_readonly, "_absolute", return_value=folder):
            listed = proxvox_readonly.dispatch("host-list /fixture")
        self.assertEqual(listed["entries"], ["config.bin"])
        with mock.patch.object(proxvox_readonly, "_absolute", return_value=path):
            read = proxvox_readonly.dispatch("host-read /fixture/config.bin 3")
        self.assertEqual(base64.b64decode(read["content_base64"]), b"abc")
        with self.assertRaises(ValueError):
            proxvox_readonly.dispatch("host-write /tmp/x")

    def test_oversize_request_is_refused_before_ssh(self):
        with self.assertRaises(Refused):
            self.broker.read_file("/var/log/syslog", maximum_bytes=16_777_217)
        self.assertEqual(self.clients, [])


if __name__ == "__main__":
    unittest.main(verbosity=2)

# ===== SNAPSMACK EOF =====
