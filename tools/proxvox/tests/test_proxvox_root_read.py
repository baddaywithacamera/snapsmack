"""Root-level host and all-container read protocol coverage. (SS-1542)

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import base64
import hashlib
import io
import json
import os
import shlex
import subprocess
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

    def exec_command(self, command, timeout=None):
        if timeout != 60:
            raise AssertionError("root read command must use the approved 60-second timeout")
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
            "ct-read 110 /etc/mysql/my.cnf": (0, {"ok": True, "data": {"content_base64": encoded}}),
            "host-read /root/.ssh/id_rsa": (0, {"ok": True, "data": {
                "path": "/root/.ssh/id_rsa", "size": 411,
                "sha256": "a" * 64, "content_policy": "metadata-sha256-only",
            }}),
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

    def test_write_key_returns_metadata_and_hash_and_is_audited_without_content(self):
        result = self.broker.read_file("/root/.ssh/id_rsa")
        self.assertEqual(result["sha256"], "a" * 64)
        self.assertEqual(result["content_policy"], "metadata-sha256-only")
        self.assertNotIn("content_base64", result)
        records = self.audit.records()
        self.assertEqual(records[-1]["action"], "root_read_result")
        self.assertEqual(records[-1]["detail"]["path"], "/root/.ssh/id_rsa")
        self.assertEqual(records[-1]["detail"]["content_policy"], "metadata-sha256-only")
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
            read = proxvox_readonly.dispatch("host-read /fixture/config.bin")
        self.assertEqual(base64.b64decode(read["content_base64"]), b"abc")
        with self.assertRaises(ValueError):
            proxvox_readonly.dispatch("host-write /tmp/x")

    def test_fixed_write_key_list_covers_host_cluster_ssh_and_tls_keys(self):
        protected = [
            "/etc/pve/priv/tfa.cfg",
            "/etc/pve/priv/authkey.key",
            "/etc/pve/priv/pve-root-ca.key",
            "/etc/pve/priv/acme/default/account.key",
            "/etc/pve/priv/ceph.client.admin.keyring",
            "/etc/pve/nodes/proxmox/pve-ssl.key",
            "/etc/pve/nodes/proxmox/pveproxy-ssl.key",
            "/etc/corosync/authkey",
            "/etc/ssh/ssh_host_ed25519_key",
            "/root/.ssh/id_ed25519",
            "/home/sean/.ssh/id_rsa",
            "/etc/ssl/private/site.key",
            "/etc/pki/tls/private/site.key",
            "/etc/letsencrypt/live/example.test/privkey.pem",
            "/etc/letsencrypt/archive/example.test/privkey3.pem",
            "/srv/certificates/site.p12",
            "/srv/certificates/site.pfx",
        ]
        for path in protected:
            with self.subTest(path=path):
                self.assertTrue(proxvox_readonly._is_write_key_path(path))
        self.assertFalse(proxvox_readonly._is_write_key_path("/root/.ssh/id_ed25519.pub"))
        self.assertFalse(proxvox_readonly._is_write_key_path("/etc/pve/user.cfg"))

    def test_wrapper_timeout_is_fixed_and_client_timeout_is_audited_clearly(self):
        with mock.patch.object(subprocess, "run", side_effect=subprocess.TimeoutExpired(["pct"], 60)):
            with self.assertRaisesRegex(ValueError, "timed out after 60 seconds"):
                proxvox_readonly._run(["pct"])

        class TimedOutClient:
            def exec_command(self, command, timeout=None):
                self.timeout = timeout
                raise TimeoutError("late")
            def close(self):
                self.closed = True

        timed_out = TimedOutClient()
        broker = RootReadBroker(
            "192.0.2.1", "proxvox-root-ro", self.audit,
            credential_reader=lambda site, target: "secret",
            ssh_factory=lambda host, username, password: timed_out,
        )
        with self.assertRaisesRegex(Refused, "timed out after 60 seconds"):
            broker.read_file("/etc/hosts")
        self.assertEqual(timed_out.timeout, 60)
        self.assertTrue(timed_out.closed)
        record = self.audit.records()[-1]
        self.assertEqual(record["action"], "root_read_timeout")
        self.assertEqual(record["detail"]["path"], "/etc/hosts")

    def test_container_symlink_is_resolved_before_write_key_policy_and_never_catted(self):
        calls = []

        def fake_run(argv):
            calls.append(argv)
            program = argv[4]
            if program == "/usr/bin/readlink":
                return b"/etc/pve/priv/tfa.cfg\n"
            if program == "/usr/bin/head":
                return b"totp-store"
            if program == "/usr/bin/stat":
                return b"123\t8180\t1700000000\tregular file\n"
            if program == "/usr/bin/sha256sum":
                return (b"b" * 64) + b"  /etc/pve/priv/tfa.cfg\n"
            self.fail("unexpected container command: " + repr(argv))

        with mock.patch.object(proxvox_readonly, "_run", side_effect=fake_run):
            result = proxvox_readonly.dispatch("ct-read 110 /tmp/looks-harmless")
        self.assertEqual(result["resolved_path"], "/etc/pve/priv/tfa.cfg")
        self.assertEqual(result["sha256"], "b" * 64)
        self.assertEqual(result["content_policy"], "metadata-sha256-only")
        self.assertFalse(any("/bin/cat" in command for command in calls))

    def test_actual_wrapper_process_returns_content_for_ordinary_and_only_hash_for_private_key(self):
        wrapper = os.path.join(os.path.dirname(__file__), "..", "server", "proxvox_readonly.py")
        ordinary = os.path.join(self.tmp, "ordinary config.txt")
        private = os.path.join(self.tmp, "custom-name.pem")
        with open(ordinary, "wb") as stream:
            stream.write(b"ordinary-content")
        key_bytes = b"-----BEGIN OPENSSH PRIVATE KEY-----\nnot-a-real-key\n"
        with open(private, "wb") as stream:
            stream.write(key_bytes)

        def drive(path):
            absolute = os.path.abspath(path).replace("\\", "/")
            if len(absolute) >= 3 and absolute[1:3] == ":/":
                absolute = absolute[2:]
            env = dict(os.environ)
            env["SSH_ORIGINAL_COMMAND"] = "host-read " + shlex.quote(absolute)
            run = subprocess.run(
                [sys.executable, wrapper], env=env, stdin=subprocess.DEVNULL,
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False,
            )
            self.assertEqual(run.returncode, 0, run.stderr.decode("utf-8", "replace"))
            return json.loads(run.stdout.decode("utf-8"))["data"]

        plain_result = drive(ordinary)
        self.assertEqual(base64.b64decode(plain_result["content_base64"]), b"ordinary-content")
        key_result = drive(private)
        self.assertEqual(key_result["content_policy"], "metadata-sha256-only")
        self.assertEqual(key_result["sha256"], hashlib.sha256(key_bytes).hexdigest())
        self.assertNotIn("content_base64", key_result)

    def test_complete_file_larger_than_old_ceiling_is_returned(self):
        content = b"x" * (16_777_216 + 1)
        path = os.path.join(self.tmp, "large.bin")
        with open(path, "wb") as stream:
            stream.write(content)
        with mock.patch.object(proxvox_readonly, "_absolute", return_value=path):
            read = proxvox_readonly.dispatch("host-read /fixture/large.bin")
        self.assertEqual(base64.b64decode(read["content_base64"]), content)
        self.replies["host-read /var/log/large.bin"] = (
            0, {"ok": True, "data": {"content_base64": base64.b64encode(content).decode()}},
        )
        self.assertEqual(self.broker.read_file("/var/log/large.bin"), content)


if __name__ == "__main__":
    unittest.main(verbosity=2)

# ===== SNAPSMACK EOF =====
