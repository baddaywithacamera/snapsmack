"""Executed P1b coverage for in-container read-only access. (SS-1542)

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import io
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from proxvox.audit import AuditBuffer  # noqa: E402
from proxvox.broker import Refused  # noqa: E402
from proxvox.container_read import (  # noqa: E402
    ContainerReadBroker, ContainerTarget, DatabaseTarget,
)
from proxvox.credentials import (  # noqa: E402
    NoToken, load_container_secret, save_container_secret,
)


class _SFTP:
    def __init__(self, audit, files, resolutions=None):
        self.audit = audit
        self.files = files
        self.closed = False
        self.resolutions = resolutions or {}

    def normalize(self, path):
        return self.resolutions.get(path, path)

    def listdir(self, path):
        assert self.audit.records()[-1]["action"] == "container_read"
        return ["z.php", "a.php"]

    def open(self, path, mode):
        assert mode == "rb"
        assert self.audit.records()[-1]["action"] == "container_read"
        return io.BytesIO(self.files[path])

    def close(self):
        self.closed = True


class _SSH:
    def __init__(self, audit, files, resolutions=None):
        self.sftp = _SFTP(audit, files, resolutions)
        self.closed = False

    def open_sftp(self):
        return self.sftp

    def close(self):
        self.closed = True


class _Cursor:
    description = (("file_path",), ("skin_id",))

    def __init__(self, audit):
        self.audit = audit
        self.executed = []

    def execute(self, sql, params=()):
        assert self.audit.records()[-1]["action"] == "container_db_read"
        self.executed.append((sql, params))

    def fetchmany(self, amount):
        return [("skins/glide/style.css", "glide")]

    def close(self):
        pass


class _DB:
    def __init__(self, audit):
        self.cursor_value = _Cursor(audit)
        self.rolled_back = False
        self.closed = False

    def cursor(self):
        return self.cursor_value

    def rollback(self):
        self.rolled_back = True

    def close(self):
        self.closed = True


class P1bTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.mkdtemp(prefix="proxvox-p1b-")
        self.audit = AuditBuffer(os.path.join(self.directory, "audit.jsonl"))
        self.files = {"/var/www/sites/mccormickphotography.com/index.php": b"<?php"}
        self.secrets = {
            ("proxvox", "ct102_ssh"): "ssh-secret",
            ("proxvox", "mysql_ro"): "db-secret",
        }
        self.ssh_clients = []
        self.resolutions = {}
        self.db = None
        target = ContainerTarget(
            "ct102", "192.168.1.12", "proxvox-ro", "ct102_ssh",
            ("/var/www/sites", "/var/log/apache2", "/etc/apache2", "/etc/php"),
        )
        database = DatabaseTarget("192.168.1.20", "proxvox_ro")

        def ssh_factory(_target, password):
            self.assertEqual(password, "ssh-secret")
            client = _SSH(self.audit, self.files, self.resolutions)
            self.ssh_clients.append(client)
            return client

        def mysql_factory(_target, ssh_password, _database, db_password, schema):
            self.assertEqual((ssh_password, db_password, schema),
                             ("ssh-secret", "db-secret", "portalproject"))
            self.db = _DB(self.audit)
            return self.db

        self.broker = ContainerReadBroker(
            [target], database, self.audit,
            credential_reader=lambda site, kind: self.secrets.get((site, kind)),
            ssh_factory=ssh_factory, mysql_factory=mysql_factory,
        )

    def test_lists_and_reads_only_inside_declared_roots(self):
        self.assertEqual(
            self.broker.listdir("ct102", "/var/www/sites/mccormickphotography.com"),
            ["a.php", "z.php"],
        )
        self.assertEqual(
            self.broker.read_file(
                "ct102", "/var/www/sites/mccormickphotography.com/index.php"
            ),
            b"<?php",
        )
        self.assertTrue(all(client.closed for client in self.ssh_clients))
        with self.assertRaises(Refused):
            self.broker.read_file("ct102", "/home/sean/.ssh/id_rsa")
        with self.assertRaises(Refused):
            self.broker.read_file("ct102", "/var/www/sites/../../etc/shadow")
        refused = [row for row in self.audit.records()
                   if row["action"] == "container_read_refused"]
        self.assertEqual(len(refused), 2)

    def test_symlink_escape_is_resolved_and_refused_before_open(self):
        link = "/var/www/sites/site/config-link"
        self.resolutions[link] = "/etc/shadow"
        with self.assertRaises(Refused):
            self.broker.read_file("ct102", link)
        self.assertEqual(self.audit.records()[-1]["action"], "container_read_refused")

    def test_complete_file_larger_than_old_ceiling_is_returned(self):
        path = "/var/www/sites/large.bin"
        content = b"x" * (8_388_608 + 1)
        self.files[path] = content
        self.assertEqual(self.broker.read_file("ct102", path), content)

    def test_integrity_select_is_read_only_audited_and_rolled_back(self):
        result = self.broker.select(
            "ct102", "portalproject",
            "SELECT file_path, skin_id FROM snap_file_manifest WHERE skin_id = %s",
            ("glide",),
        )
        self.assertEqual(result["rows"], [("skins/glide/style.css", "glide")])
        self.assertTrue(self.db.rolled_back)
        self.assertTrue(self.db.closed)
        statements = [row[0] for row in self.db.cursor_value.executed]
        self.assertEqual(statements[:2], [
            "SET SESSION TRANSACTION READ ONLY", "START TRANSACTION READ ONLY",
        ])
        self.assertNotIn("SELECT file_path", str(self.audit.records()))

    def test_every_write_shape_is_refused_before_a_connection(self):
        before = len(self.ssh_clients)
        for sql in (
            "UPDATE snap_settings SET setting_val='1'",
            "DELETE FROM snap_likes",
            "SELECT 1; DROP TABLE snap_images",
            "SELECT * FROM snap_images FOR UPDATE",
            "SELECT LOAD_FILE('/etc/passwd')",
            "SELECT 1 INTO OUTFILE '/tmp/x'",
        ):
            with self.assertRaises(Refused, msg=sql):
                self.broker.select("ct102", "portalproject", sql)
        self.assertEqual(len(self.ssh_clients), before)
        self.assertIsNone(self.db)
        self.assertEqual(
            len([row for row in self.audit.records()
                 if row["action"] == "container_db_refused"]), 6
        )

    def test_credentials_have_one_native_store_and_no_fallback(self):
        written = []
        save_container_secret(
            "ct106_ssh", "owner-entered",
            writer=lambda site, kind, value: (written.append((site, kind, value)), True)[1],
        )
        self.assertEqual(written, [("proxvox", "ct106_ssh", "owner-entered")])
        self.assertEqual(
            load_container_secret("ct102_ssh", reader=lambda *_: "stored"), "stored"
        )
        with self.assertRaises(NoToken):
            load_container_secret("ct102_ssh", reader=lambda *_: "")
        with self.assertRaises(NoToken):
            save_container_secret("not-a-kind", "value", writer=lambda *_: True)

    def test_provisioning_grants_full_read_without_container_etc_acl(self):
        with open(
                os.path.join(os.path.dirname(__file__), "..", "PROVISIONING.md"),
                encoding="utf-8") as stream:
            plan = stream.read()
        with open(
                os.path.join(os.path.dirname(__file__), "..", "server", "provision-ct110.sh"),
                encoding="utf-8") as stream:
            database_installer = stream.read()
        container_installers = ""
        for target in ("ct102", "ct106"):
            with open(
                    os.path.join(os.path.dirname(__file__), "..", "server",
                                 "provision-%s.sh" % target),
                    encoding="utf-8") as stream:
                container_installers += stream.read()
        self.assertEqual(database_installer.count("GRANT SELECT, SHOW VIEW ON *.*"), 2)
        self.assertNotIn("setfacl -m u:proxvox-ro:x /etc", container_installers)
        self.assertNotIn("! -name '*.key'", container_installers)
        self.assertNotIn("must print nothing", plan.lower())
        self.assertIn("metadata plus SHA-256", plan)
        self.assertIn("source-bound read-only MariaDB accounts", plan)


if __name__ == "__main__":
    unittest.main(verbosity=2)

# ===== SNAPSMACK EOF =====
