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
            "ct-read 110 /etc/mysql/my.cnf": (0, {"ok": True, "data": {
                "size": 16, "content_policy": "chunked", "chunk_bytes": 1_048_576,
            }}),
            "ct-read-chunk 110 /etc/mysql/my.cnf 0 1048576": (0, {"ok": True, "data": {
                "content_base64": encoded, "bytes": 16, "eof": True,
            }}),
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
        self.assertEqual(read["content_policy"], "chunked")
        self.assertNotIn("content_base64", read)
        with mock.patch.object(proxvox_readonly, "_absolute", return_value=path):
            chunk = proxvox_readonly.dispatch("host-read-chunk /fixture/config.bin 0 1048576")
        self.assertEqual(base64.b64decode(chunk["content_base64"]), b"abc")
        self.assertTrue(chunk["eof"])
        with self.assertRaises(ValueError):
            proxvox_readonly.dispatch("host-write /tmp/x")

    def test_fixed_write_key_list_covers_host_cluster_ssh_and_tls_keys(self):
        protected = [
            "/etc/pve/priv/tfa.cfg",
            "/etc/pve/priv/authkey.key",
            "/etc/pve/priv/pve-root-ca.key",
            "/etc/pve/priv/acme/default/account.key",
            "/etc/pve/priv/ceph.client.admin.keyring",
            "/etc/pve/priv/a-future-file-with-an-unknown-name",
            "/tmp/root-view/etc/pve/priv/future/deeply-nested.secret",
            "/var/lib/pve-cluster/config.db",
            "/var/lib/pve-cluster/config.db-wal",
            "/var/lib/pve-cluster/config.db-shm",
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
        self.assertTrue(proxvox_readonly._is_write_key_path("/etc/pve/priv/tfa.cfg.pub"))
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

    def test_actual_wrapper_process_chunks_ordinary_and_withholds_every_secret_tree_file(self):
        wrapper = os.path.join(os.path.dirname(__file__), "..", "server", "proxvox_readonly.py")
        ordinary = os.path.join(self.tmp, "ordinary config.txt")
        private = os.path.join(self.tmp, "custom-name.pem")
        secret_tree = os.path.join(self.tmp, "etc", "pve", "priv", "future", "unknown.bin")
        database = os.path.join(self.tmp, "var", "lib", "pve-cluster", "config.db-wal")
        os.makedirs(os.path.dirname(secret_tree))
        os.makedirs(os.path.dirname(database))
        with open(ordinary, "wb") as stream:
            stream.write(b"ordinary-content")
        key_bytes = b"-----BEGIN OPENSSH PRIVATE KEY-----\nnot-a-real-key\n"
        with open(private, "wb") as stream:
            stream.write(key_bytes)
        with open(secret_tree, "wb") as stream:
            stream.write(b"future-secret-with-no-recognized-header")
        with open(database, "wb") as stream:
            stream.write(b"pmxcfs-database-companion")

        def drive(command, path):
            absolute = os.path.abspath(path).replace("\\", "/")
            if len(absolute) >= 3 and absolute[1:3] == ":/":
                absolute = absolute[2:]
            env = dict(os.environ)
            env["SSH_ORIGINAL_COMMAND"] = command.format(path=shlex.quote(absolute))
            run = subprocess.run(
                [sys.executable, wrapper], env=env, stdin=subprocess.DEVNULL,
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False,
            )
            self.assertEqual(run.returncode, 0, run.stderr.decode("utf-8", "replace"))
            return json.loads(run.stdout.decode("utf-8"))["data"]

        plain_info = drive("host-read {path}", ordinary)
        self.assertEqual(plain_info["content_policy"], "chunked")
        self.assertNotIn("content_base64", plain_info)
        plain_chunk = drive("host-read-chunk {path} 0 1048576", ordinary)
        self.assertEqual(base64.b64decode(plain_chunk["content_base64"]), b"ordinary-content")
        for path, expected_hash in (
                (private, hashlib.sha256(key_bytes).hexdigest()),
                (secret_tree, hashlib.sha256(b"future-secret-with-no-recognized-header").hexdigest()),
                (database, hashlib.sha256(b"pmxcfs-database-companion").hexdigest())):
            with self.subTest(path=path):
                result = drive("host-read {path}", path)
                self.assertEqual(result["content_policy"], "metadata-sha256-only")
                self.assertEqual(result["sha256"], expected_hash)
                self.assertNotIn("content_base64", result)

    def test_large_file_is_chunked_without_loading_it_into_host_memory(self):
        path = os.path.join(self.tmp, "large.bin")
        with open(path, "wb") as stream:
            stream.seek((512 * 1024 * 1024) - 1)
            stream.write(b"z")
        with mock.patch.object(proxvox_readonly, "_absolute", return_value=path):
            info = proxvox_readonly.dispatch("host-read /fixture/large.bin")
        self.assertEqual(info["size"], 512 * 1024 * 1024)
        self.assertEqual(info["content_policy"], "chunked")
        self.assertNotIn("content_base64", info)
        with mock.patch.object(proxvox_readonly, "_absolute", return_value=path):
            first = proxvox_readonly.dispatch("host-read-chunk /fixture/large.bin 0 1048576")
        self.assertEqual(first["bytes"], 1_048_576)
        self.assertFalse(first["eof"])

        content = b"x" * (1_048_576 + 17)
        self.replies["host-read /var/log/large.bin"] = (
            0, {"ok": True, "data": {
                "size": len(content), "content_policy": "chunked", "chunk_bytes": 1_048_576,
            }},
        )
        self.replies["host-read-chunk /var/log/large.bin 0 1048576"] = (
            0, {"ok": True, "data": {
                "content_base64": base64.b64encode(content[:1_048_576]).decode(),
                "bytes": 1_048_576, "eof": False,
            }},
        )
        self.replies["host-read-chunk /var/log/large.bin 1048576 1048576"] = (
            0, {"ok": True, "data": {
                "content_base64": base64.b64encode(content[1_048_576:]).decode(),
                "bytes": 17, "eof": True,
            }},
        )
        sink = io.BytesIO()
        self.assertEqual(self.broker.read_to("/var/log/large.bin", sink), len(content))
        self.assertEqual(sink.getvalue(), content)
        records = self.audit.records()
        chunk_records = [row for row in records if row["action"] == "root_read_result"
                         and row["detail"].get("operation") == "host-read-chunk"]
        self.assertEqual([row["detail"]["path"] for row in chunk_records],
                         ["/var/log/large.bin", "/var/log/large.bin"])


if __name__ == "__main__":
    unittest.main(verbosity=2)

# ===== SNAPSMACK EOF =====
